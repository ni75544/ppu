<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchBoard;
use Drupal\custom_webform_handlers\Dispatch\DispatchBoardReaderInterface;
use Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry;
use Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail;
use Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotificationText;
use Drupal\custom_webform_handlers\Dispatch\Notify\NotificationRecord;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusText;
use Drupal\custom_webform_handlers\Review\ReviewState;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the Dispatch Planner board, and changes nothing itself.
 *
 * This controller is the whole administrative surface of the Dispatch Planner,
 * and it is deliberately incapable of changing anything. It reads through
 * DispatchBoardReaderInterface, which has exactly two read operations, so there
 * is no claim, confirm, release, renew, expire, blackout, retime, or reprice
 * reachable from here — not even by mistake, because the seam it depends on does
 * not expose one. Capacity is changed through the planner and the Resource
 * Calendar Guard; a board that could bypass them would defeat the serialization
 * those exist to provide.
 *
 * What it does carry is links, and a link is not an operation. Each one points at
 * a Form API page, or at the Replan endpoint on DispatchOperationsController, and
 * every one of those re-proves the whole plan under the calendar locks before it
 * writes anything. So the board can say what an operator may do without acquiring
 * the power to do it, and a refusal reads the same whether it was reached from a
 * row here, from the menu, or by typing the path. Links are rendered only for an
 * operator who holds the permission guarding their target, so somebody who may
 * read the board is never offered a control that would only refuse them.
 *
 * What it must not hold is as important as what it does. There is no database
 * Connection, no reservation store, no Webform storage, no planner, and no
 * PlanningNeighbour anywhere in its dependencies. The last one matters most:
 * neighbour addresses belong to other customers and exist only so the planner can
 * measure repositioning, so nothing on this page can reach them.
 *
 * Everything it renders arrives finished. DispatchBoard and
 * DispatchReservationDetail are value objects with no entity, no row, no Hold
 * token, and no owner hash inside them, so the render arrays below cannot walk
 * back into the database and cannot leak a secret they never receive.
 *
 * The board is complete in the first response. Segment geometry is computed here
 * as percentages and written into inline styles, the lane is a decorative summary
 * of the table beside it, and every operation is reachable with scripting off. The
 * one script that may be attached adds dragging to rows that already link to the
 * same operation, and all it is given in drupalSettings is two paths — the endpoint
 * and the token route — no schedule, no identifiers, no addresses, nothing a
 * scriptless page would not have rendered anyway.
 */
final class DispatchPlannerAdminController extends ControllerBase {

  /**
   * Characters a ?resource= value may have before it is refused outright.
   *
   * A long value is rejected, never trimmed to length. Truncating an identifier
   * could turn something malformed into something that happens to match a real
   * Resource, which is the one outcome a bound on untrusted input must not have.
   */
  private const RESOURCE_ID_MAX_LENGTH = 128;

  /**
   * Local days the day view covers.
   */
  private const DAY_SPAN = 1;

  /**
   * Constructs the board controller.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoardReaderInterface $reader
   *   The read seam. The only route to the schedule this class has.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory $profileFactory
   *   Supplies the Resource to show when the query string names none. The reader
   *   refuses to substitute a default Resource itself, on purpose, so the choice
   *   of default is made here where it is visible.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats UTC timestamps in the Resource's own zone. Every time on this page
   *   is local to the Resource, because that is the only zone in which a working
   *   day means anything.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Supplies the instant "today" is resolved from, before any board exists to
   *   ask.
   */
  public function __construct(
    private readonly DispatchBoardReaderInterface $reader,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('custom_webform_handlers.dispatch_board_reader'),
      $container->get('custom_webform_handlers.dispatch_profile_factory'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * The board's front door: one week from the requested day.
   *
   * A week is the default because a Resource is dispatched against a week, and
   * because DispatchBoard caps a board at seven local days anyway.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Carries the optional bounded ?resource= and ?date= parameters.
   *
   * @return array
   *   A render array.
   */
  public function board(Request $request): array {
    return $this->renderBoard($request, DispatchBoard::MAX_LOCAL_DAYS);
  }

  /**
   * The same week, at a path that says so.
   *
   * Kept distinct from board() so a bookmarked or shared week URL stays a week
   * even if the front door's default span is ever reconsidered.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Carries the optional bounded ?resource= and ?date= parameters.
   *
   * @return array
   *   A render array.
   */
  public function week(Request $request): array {
    return $this->renderBoard($request, DispatchBoard::MAX_LOCAL_DAYS);
  }

  /**
   * One local day, for reading a single day's dispatch closely.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Carries the optional bounded ?resource= and ?date= parameters.
   *
   * @return array
   *   A render array.
   */
  public function day(Request $request): array {
    return $this->renderBoard($request, self::DAY_SPAN);
  }

  /**
   * Shows administrator-safe detail for one confirmed Reservation.
   *
   * The page displays a DispatchReservationDetail, and the operations it offers are
   * links to pages of their own. Nothing is submitted from here and no form is built
   * here: the detail seam is read-only, so a control on this page can only ever be a
   * way of reaching an operation that proves itself somewhere else.
   *
   * A Hold and a missing row are both answered with 404, which is the reader's
   * behaviour and not a decision taken here. An unfinished anonymous attempt has
   * no detail anyone is entitled to read, and answering it differently from a
   * missing row would turn this page into a way to enumerate which identifiers are
   * currently held.
   *
   * @param int $dispatch_reservation_id
   *   The claim to describe. Constrained to digits by the route as well.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no confirmed Reservation carries that identifier.
   */
  public function reservation(int $dispatch_reservation_id): array {
    $detail = $this->reader->reservation($dispatch_reservation_id);
    if (!$detail instanceof DispatchReservationDetail) {
      throw new NotFoundHttpException();
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to the Dispatch Planner board'),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner', [], [
          'query' => [
            'resource' => $detail->resourceId,
            'date' => $this->localFormat($detail->windowStart, $detail->timezone, 'Y-m-d'),
          ],
        ]),
      ],
      'detail' => $this->detailTable($detail),
      'operations' => $this->detailOperations($detail),
    ];
    $build['#attached']['library'][] = 'custom_webform_handlers/dispatch_planner_board';

    return $this->withLiveCacheMetadata($build);
  }

  /**
   * Reads one board and assembles the page around it.
   *
   * The order matters. The Resource and the date are resolved and bounded before
   * the reader is called at all, so a malformed parameter produces a refusal
   * instead of a query — a board must never be widened to every Resource or every
   * date because its input could not be read.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request carrying the optional parameters.
   * @param int $days
   *   Local days to cover, starting at the requested date.
   *
   * @return array
   *   A render array.
   */
  private function renderBoard(Request $request, int $days): array {
    // The configured Resource is resolved first, because ?resource= being absent
    // has to mean "the Resource this site dispatches" and never "all of them".
    $profile = $this->profileFactory->create();
    $zone = $profile === NULL ? new \DateTimeZone('UTC') : $profile->timezoneObject();

    $resourceId = $this->requestedResource($request, $profile === NULL ? '' : $profile->resourceId);
    if ($resourceId === NULL) {
      return $this->refused($this->t('That Resource identifier is longer than any Resource this site can have. No board was read for it, and it was not shortened into one that could be found.'));
    }

    $from = $this->requestedDate($request, $zone);
    if ($from === NULL) {
      return $this->refused($this->t('That date could not be read. Use a date=YYYY-MM-DD value naming one day on the Resource’s own calendar.'));
    }

    $to = $days > 1 ? $from->modify('+' . ($days - 1) . ' day') : $from;
    if (!$to instanceof \DateTimeImmutable) {
      return $this->refused($this->t('That date could not be read as the start of a span.'));
    }

    $board = $this->reader->board($resourceId, $from, $to);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner']],
      'notices' => $this->noticeSection($board),
    ];
    $build['#attached']['library'][] = 'custom_webform_handlers/dispatch_planner_board';
    $this->attachOperations($build);

    // An unavailable board says why and shows nothing else. There is no fallback
    // to a default Resource or a default range: an administrator must not be shown
    // a schedule they did not ask for and might act on.
    if (!$board->isAvailable()) {
      return $this->withLiveCacheMetadata($build);
    }

    $build['meta'] = $this->metaSection($board);
    $build['resource_status'] = $this->statusSection($board);
    $build['nav'] = $this->navigationSection($board, $days);
    $build['legend'] = $this->legendSection();
    foreach ($board->localDates as $date) {
      $build['day_' . $date] = $this->daySection($board, $date);
    }
    $build['queue'] = $this->manualReviewSection($board);
    $build['notifications'] = $this->notificationSection($board);

    return $this->withLiveCacheMetadata($build);
  }

  /**
   * Builds the page shown when a parameter could not be read.
   *
   * Refusing is the safe answer, so it is a real page rather than an exception:
   * the operator is told which parameter was unusable and the reader was never
   * called, so nothing was queried on the strength of input nobody could parse.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   What could not be read.
   *
   * @return array
   *   A render array.
   */
  private function refused($message): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner']],
      'refused' => [
        '#theme' => 'item_list',
        '#title' => $this->t('No board was read'),
        '#items' => [$message],
      ],
      'restart' => [
        '#type' => 'link',
        '#title' => $this->t('Show the current week for the configured Resource'),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner'),
      ],
    ];
    $build['#attached']['library'][] = 'custom_webform_handlers/dispatch_planner_board';

    return $this->withLiveCacheMetadata($build);
  }

  /**
   * Marks a build as true only at the instant it was rendered.
   *
   * Nothing on this page may be cached. A Hold lapses when its expiry passes, and
   * no cache tag is invalidated when that happens because no row changes — so a
   * cached board would keep showing withheld capacity that has since been
   * released. The query contexts keep one Resource's week out of another's cache
   * entry, and the permission context keeps the board out of a response that a
   * user without the permission could be served.
   *
   * @param array $build
   *   The render array.
   *
   * @return array
   *   The same array, with its cache metadata set.
   */
  private function withLiveCacheMetadata(array $build): array {
    $build['#cache'] = [
      'max-age' => 0,
      'contexts' => ['url.query_args', 'url.path', 'user.permissions'],
    ];

    return $build;
  }

  /**
   * Tells whether this operator may change the schedule, not merely read it.
   *
   * Reading the board and operating it are separate permissions on purpose, so this
   * is asked before any control is rendered. It is only ever used to decide whether
   * to draw one: the answer here grants nothing, because every target checks the
   * same permission itself, on its own route, where it cannot be forgotten.
   *
   * @return bool
   *   TRUE when the operations may be offered.
   */
  private function mayManage(): bool {
    return $this->currentUser()->hasPermission('manage dispatch schedule');
  }

  /**
   * Tells whether this operator may price requests, which is a different job.
   *
   * Deliberately not implied by mayManage(), and not implying it. Specification item
   * 7 separates the two authorities because the roles are separate people: a
   * scheduler moves vans and a person who quotes work commits the business to a
   * figure, and a site that wants one person doing both grants both permissions
   * rather than having the code assume it.
   *
   * Like mayManage(), the answer grants nothing. It decides whether a control is
   * drawn; the route behind every control checks the same permission itself.
   *
   * @return bool
   *   TRUE when the review operations may be offered.
   */
  private function mayReview(): bool {
    return $this->currentUser()->hasPermission('review dispatch quotes');
  }

  /**
   * Tells whether this operator may see where the vehicle actually goes.
   *
   * Specification item 8's second split, and the reason it is separate from reading
   * the board: one address identifies a job, and a day's worth of pickup and delivery
   * pairs is the route. Somebody checking whether the afternoon is full does not need
   * either, and a role that shows them anyway cannot be given to a depot.
   *
   * @return bool
   *   TRUE when exact addresses may be shown.
   */
  private function mayViewAddresses(): bool {
    return $this->currentUser()->hasPermission('view dispatch exact addresses');
  }

  /**
   * Tells whether this operator may see money.
   *
   * Kept apart from the authority to set a price, which is mayReview(): one writes an
   * offer and this reads what became of it, and a scheduler who quotes work is not
   * automatically a person who may audit takings.
   *
   * @return bool
   *   TRUE when financial figures may be shown.
   */
  private function mayViewFinancials(): bool {
    return $this->currentUser()->hasPermission('view dispatch financial details');
  }

  /**
   * Says that a fact exists and this role does not include it.
   *
   * Withholding is stated rather than performed by omission, which matters for the
   * same reason routeText() distinguishes "not measured" from zero: a row that simply
   * vanished would read as "no address was recorded", and an operator would go looking
   * for a fault in the data instead of asking for the permission they are missing.
   *
   * @return mixed
   *   A cell value.
   */
  private function withheld() {
    return $this->t('Not shown to your role');
  }

  /**
   * Attaches the drag layer, and only for an operator who could already act.
   *
   * The script is an enhancement over links that already work, so it is attached
   * last and never depended on. Two settings are published, and both are paths: the
   * endpoint a proposal is posted to, and the route a session CSRF token is fetched
   * from. The token itself is deliberately not published — the script asks for its
   * own when it first needs one, which keeps a session-scoped secret out of the
   * markup entirely, and matches how the customer-facing widget already works.
   *
   * Nothing about the schedule is published. The script reads the identifiers and
   * times it needs from the rendered rows, which are the same facts a scriptless
   * board already shows, so attaching it cannot disclose anything the page did not.
   *
   * @param array $build
   *   The board build, by reference.
   */
  private function attachOperations(array &$build): void {
    if (!$this->mayManage()) {
      return;
    }

    $build['#attached']['library'][] = 'custom_webform_handlers/dispatch_board_ops';
    $build['#attached']['drupalSettings']['cwhDispatchBoard'] = [
      'replanUrl' => Url::fromRoute('custom_webform_handlers.dispatch_replan_endpoint')
        ->toString(TRUE)
        ->getGeneratedUrl(),
      'csrfTokenUrl' => Url::fromRoute('system.csrftoken')
        ->toString(TRUE)
        ->getGeneratedUrl(),
    ];
  }

  /**
   * Reads the bounded ?resource= parameter.
   *
   * The value is passed through verbatim when it is within bounds: whether it
   * names a Resource this site has is the reader's judgement, not this class's, and
   * the reader answers an unknown one with an empty board rather than a wider one.
   * A non-string value — the array a `resource[]=` query produces — is refused
   * outright rather than coerced, because coercion is how "unreadable" quietly
   * becomes "some other Resource".
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $default
   *   The configured Resource, used when the parameter is absent or empty.
   *
   * @return string|null
   *   The Resource to read, or NULL when the parameter cannot be used at all.
   */
  private function requestedResource(Request $request, string $default): ?string {
    $values = $request->query->all();
    if (!array_key_exists('resource', $values)) {
      return $default;
    }
    if (!is_string($values['resource'])) {
      return NULL;
    }

    $raw = trim($values['resource']);
    if ($raw === '') {
      return $default;
    }

    return strlen($raw) > self::RESOURCE_ID_MAX_LENGTH ? NULL : $raw;
  }

  /**
   * Reads the bounded ?date= parameter as local midnight in the Resource's zone.
   *
   * Both a shape check and a round trip, because the first alone accepts
   * 2026-02-31 and PHP would roll it forward into March without complaint. A date
   * that does not survive being reformatted is refused rather than corrected.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \DateTimeZone $zone
   *   The Resource's zone, which is the only zone its days exist in.
   *
   * @return \DateTimeImmutable|null
   *   The first local day to show, or NULL when the parameter was unreadable.
   */
  private function requestedDate(Request $request, \DateTimeZone $zone): ?\DateTimeImmutable {
    $values = $request->query->all();
    if (!array_key_exists('date', $values)) {
      return $this->localToday($zone);
    }
    if (!is_string($values['date'])) {
      return NULL;
    }

    $raw = trim($values['date']);
    if ($raw === '') {
      return $this->localToday($zone);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
      return NULL;
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);

    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $raw ? $parsed : NULL;
  }

  /**
   * Midnight starting the current local day for the Resource.
   *
   * @param \DateTimeZone $zone
   *   The Resource's zone.
   *
   * @return \DateTimeImmutable
   *   Local midnight of today.
   */
  private function localToday(\DateTimeZone $zone): \DateTimeImmutable {
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($zone);
    $midnight = $this->localMidnight($now->format('Y-m-d'), $zone);

    return $midnight ?? $now;
  }

  /**
   * Midnight starting one local date, or NULL when the date cannot be read.
   *
   * @param string $date
   *   A Y-m-d local date.
   * @param \DateTimeZone $zone
   *   The Resource's zone.
   *
   * @return \DateTimeImmutable|null
   *   Local midnight, or NULL.
   */
  private function localMidnight(string $date, \DateTimeZone $zone): ?\DateTimeImmutable {
    $midnight = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

    return $midnight instanceof \DateTimeImmutable ? $midnight : NULL;
  }

  /**
   * Formats a stored UTC timestamp in the Resource's own zone.
   *
   * Never the site or the viewer's zone. A dispatch day belongs to the Resource,
   * and an operator reading 08:00 has to be reading the Resource's 08:00.
   *
   * @param int $timestamp
   *   A UTC timestamp.
   * @param string $timezone
   *   The Resource's IANA zone name.
   * @param string $format
   *   A PHP date format.
   *
   * @return string
   *   The formatted local value.
   */
  private function localFormat(int $timestamp, string $timezone, string $format): string {
    return $this->dateFormatter->format($timestamp, 'custom', $format, $timezone);
  }

  /**
   * Formats a time for one day's table, dating it when it falls outside that day.
   *
   * A padded interval can start before midnight or end after it, so a bare 23:45
   * would be read as this day's late evening when it is the previous day's. The
   * date is shown whenever the value is not on the day being read.
   *
   * @param int $timestamp
   *   A UTC timestamp.
   * @param string $date
   *   The local Y-m-d date whose table this is.
   * @param string $timezone
   *   The Resource's zone.
   *
   * @return string
   *   Either 'H:i' or 'Y-m-d H:i'.
   */
  private function timeOnDay(int $timestamp, string $date, string $timezone): string {
    $local = $this->localFormat($timestamp, $timezone, 'Y-m-d H:i');

    return str_starts_with($local, $date . ' ') ? substr($local, 11) : $local;
  }

  /**
   * Renders a number of seconds as whole local minutes.
   *
   * @param int $seconds
   *   A duration in seconds.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The duration in minutes.
   */
  private function minutesLabel(int $seconds) {
    return $this->t('@minutes min', ['@minutes' => (int) round($seconds / 60)]);
  }

  /**
   * Renders the board's notices, if it carries any.
   *
   * The reader returns machine keys and leaves the wording to whoever is showing
   * them, which is the seam that lets an unavailable board be reported precisely
   * instead of as a blank page.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   The board.
   *
   * @return array
   *   A render array, empty when there is nothing to report.
   */
  private function noticeSection(DispatchBoard $board): array {
    if ($board->notices === []) {
      return [];
    }

    $items = [];
    foreach ($board->notices as $notice) {
      $items[] = $this->noticeMessage($notice);
    }

    return [
      '#theme' => 'item_list',
      '#title' => $board->isAvailable() ? $this->t('About this board') : $this->t('No board could be read'),
      '#items' => $items,
    ];
  }

  /**
   * Phrases one notice key.
   *
   * An unrecognised key is shown as itself rather than swallowed: a board that
   * grew a new state should say so plainly instead of looking complete.
   *
   * @param string $notice
   *   One of DispatchBoard's NOTICE_* values.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The wording for an operator.
   */
  private function noticeMessage(string $notice) {
    return match ($notice) {
      DispatchBoard::NOTICE_NO_PROFILE => $this->t('No Resource is configured, so there is no calendar to read. Configure the Dispatch Planner first.'),
      DispatchBoard::NOTICE_UNKNOWN_RESOURCE => $this->t('That is not the Resource this site dispatches. Nothing was read for it, and the board was not widened to another Resource.'),
      DispatchBoard::NOTICE_INVALID_RANGE => $this->t('That range could not be read as one or more of the Resource’s local days.'),
      DispatchBoard::NOTICE_RANGE_CLAMPED => $this->t('The request covered more than seven local days and was cut to seven. Page forward for the rest.'),
      DispatchBoard::NOTICE_MANUAL_REVIEW_TRUNCATED => $this->t('More quotes are waiting on Manual Review than this list shows. Work through these and reload for the rest.'),
      DispatchBoard::NOTICE_MANUAL_REVIEW_UNCONFIGURED => $this->t('At least one dispatch form records no quote status, so quotes stalled on it cannot be listed here. The log names the form.'),
      default => $this->t('The board reported a condition this page has no wording for: @notice', ['@notice' => $notice]),
    };
  }

  /**
   * States the calendar facts the rest of the page is read against.
   *
   * The generation time is part of the board, not decoration. Hold expiry moves
   * with no row changing and no cache tag to invalidate, so a board is only true
   * as of the instant it was read, and saying when that was is the only honest way
   * to present it.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   *
   * @return array
   *   A render array.
   */
  private function metaSection(DispatchBoard $board): array {
    $resource = $board->resource;

    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['cwh-dispatch-planner__meta']],
      '#value' => $this->t('Resource @resource · @timezone local time · capacity @capacity · @grid min grid · read-only. Read at @generated: a Hold lapses on the clock, so reload rather than trusting a page that has been open a while.', [
        '@resource' => $resource->resourceId,
        '@timezone' => $resource->timezone,
        '@capacity' => $resource->capacity,
        '@grid' => $resource->gridMinutes,
        '@generated' => $this->localFormat($board->generatedAt, $resource->timezone, 'Y-m-d H:i'),
      ]),
    ];
  }

  /**
   * Builds one navigation link, keeping the Resource in the query string.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The visible text.
   * @param string $route
   *   The route to link to.
   * @param string $resourceId
   *   The Resource to stay on. Paging must not silently change Resource.
   * @param string|null $date
   *   A Y-m-d local date, or NULL to let the target default to today.
   *
   * @return array
   *   A link render element.
   */
  private function navLink($title, string $route, string $resourceId, ?string $date): array {
    $query = ['resource' => $resourceId];
    if ($date !== NULL) {
      $query['date'] = $date;
    }

    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute($route, [], ['query' => $query]),
    ];
  }

  /**
   * Builds paging that moves by exactly the span on screen.
   *
   * The step is the number of days the board actually covers rather than the number
   * requested, so paging from a board that was cut to seven days lands on the
   * eighth and nothing is skipped over.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   * @param int $days
   *   The span this view renders, which decides where paging stays.
   *
   * @return array
   *   A render array.
   */
  private function navigationSection(DispatchBoard $board, int $days): array {
    $resource = $board->resource;
    // A board with no local days has nothing to page away from. That should not be
    // reachable for an available board, which is exactly why it is checked rather
    // than assumed: the alternative to returning no navigation here is a fatal on
    // a missing first date, and a read-only screen must degrade, not break.
    if ($board->localDates === []) {
      return [];
    }

    $start = $this->localMidnight($board->localDates[0], $resource->timezoneObject());
    if (!$start instanceof \DateTimeImmutable) {
      return [];
    }

    $span = max(1, count($board->localDates));
    $isDay = $days === self::DAY_SPAN;
    $here = $isDay ? 'custom_webform_handlers.dispatch_planner_day' : 'custom_webform_handlers.dispatch_planner_week';
    $previous = $start->modify('-' . $span . ' day');
    $next = $start->modify('+' . $span . ' day');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__nav']],
    ];
    if ($previous instanceof \DateTimeImmutable) {
      $build['previous'] = $this->navLink($this->t('◀ Earlier'), $here, $resource->resourceId, $previous->format('Y-m-d'));
    }
    $build['today'] = $this->navLink($this->t('Today'), $here, $resource->resourceId, NULL);
    if ($next instanceof \DateTimeImmutable) {
      $build['next'] = $this->navLink($this->t('Later ▶'), $here, $resource->resourceId, $next->format('Y-m-d'));
    }
    $build['switch'] = $isDay
      ? $this->navLink($this->t('Show the week from here'), 'custom_webform_handlers.dispatch_planner_week', $resource->resourceId, $start->format('Y-m-d'))
      : $this->navLink($this->t('Show this first day alone'), 'custom_webform_handlers.dispatch_planner_day', $resource->resourceId, $start->format('Y-m-d'));

    return $build;
  }

  /**
   * Explains the lane's four fills, in the words the table also uses.
   *
   * Colour is never the only signal. Every segment on the lane carries its own
   * label, and the table repeats the kind in a column of its own, so the legend is
   * a convenience rather than the key to reading the board.
   *
   * @return array
   *   A render array.
   */
  private function legendSection(): array {
    return [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['cwh-dispatch-planner__legend']],
      '#items' => [
        [
          '#markup' => $this->t('Dispatch Window — the confirmed Reservation itself'),
          '#wrapper_attributes' => ['class' => ['cwh-legend--window']],
        ],
        [
          '#markup' => $this->t('Reserved margin — Repositioning and operational buffer either side of a Window'),
          '#wrapper_attributes' => ['class' => ['cwh-legend--margin']],
        ],
        [
          '#markup' => $this->t('Temporary Hold — blocked time and expiry only'),
          '#wrapper_attributes' => ['class' => ['cwh-legend--hold']],
        ],
        [
          '#markup' => $this->t('Blackout — closed by configuration'),
          '#wrapper_attributes' => ['class' => ['cwh-legend--blackout']],
        ],
      ],
    ];
  }

  /**
   * Builds one local day: the picture, then the same day as text.
   *
   * The day's own midnights are computed in the Resource's zone rather than by
   * adding 86400 seconds, so the 23-hour and 25-hour days a clock change produces
   * are the right length and every segment on them lands where it belongs.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   * @param string $date
   *   The local Y-m-d date to render.
   *
   * @return array
   *   A render array.
   */
  private function daySection(DispatchBoard $board, string $date): array {
    $resource = $board->resource;
    $zone = $resource->timezoneObject();
    $opens = $this->localMidnight($date, $zone);
    $closes = $opens instanceof \DateTimeImmutable ? $opens->modify('+1 day') : NULL;
    if (!$opens instanceof \DateTimeImmutable || !$closes instanceof \DateTimeImmutable) {
      return [];
    }

    $dayStart = $opens->getTimestamp();
    $dayEnd = $closes->getTimestamp();
    $entries = $this->entriesOn($board, $dayStart, $dayEnd);
    $dayLabel = $this->localFormat($dayStart, $resource->timezone, 'l j F Y');

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['cwh-dispatch-planner__day'],
        // The day a drag may propose, named on the day itself. The script reads
        // these two rather than parsing the heading, and a page without the script
        // is unaffected by their presence.
        'data-cwh-day' => $date,
        'data-cwh-day-label' => $dayLabel,
      ],
      'date' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['cwh-dispatch-planner__date']],
        '#value' => $dayLabel,
      ],
    ];

    // A legacy claim that could not be proved closes the day to self-booking. The
    // board knows it, so it is said here: an operator seeing free time on the lane
    // and no explanation would reasonably report the planner as broken.
    if (in_array($date, $board->legacyUnplannedDates, TRUE)) {
      $build['legacy'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['cwh-dispatch-planner__legacy']],
        '#value' => $this->t('Closed to self-booking: a claim on this day predates the Dispatch Planner and its exact interval could not be proved. Free time on the lane below is not bookable.'),
      ];
    }

    $build['hours'] = $this->hoursSection();
    $build['lane'] = $this->laneSection($entries, $dayStart, $dayEnd);

    // Withholding time is offered per day because that is how an operator thinks
    // about it — this Tuesday morning, not a date typed into an empty field — and
    // the form opens on this day because the link says which one it is.
    if ($this->mayManage()) {
      $build['withhold'] = [
        '#type' => 'link',
        '#title' => $this->t('Withhold time on @date', ['@date' => $date]),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_blackout_add', [], [
          'query' => ['resource' => $resource->resourceId, 'date' => $date],
        ]),
        '#attributes' => ['class' => ['button', 'button--small', 'cwh-dispatch-planner__withhold']],
      ];
    }

    $build['tablewrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__tablewrap']],
      'table' => $this->dayTable($entries, $date, $resource),
    ];

    return $build;
  }

  /**
   * Selects the segments that take time out of one local day.
   *
   * Overlap is judged on occupied time, not on the Dispatch Window, because a
   * Window that starts at 09:00 with fifteen minutes of repositioning before it
   * takes 08:45 out of the day and belongs on that day's reading.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   * @param int $dayStart
   *   Local midnight in UTC.
   * @param int $dayEnd
   *   The next local midnight in UTC, exclusive.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry[]
   *   The day's segments, earliest occupied time first.
   */
  private function entriesOn(DispatchBoard $board, int $dayStart, int $dayEnd): array {
    $entries = array_values(array_filter(
      $board->timeline,
      static fn (DispatchTimelineEntry $entry): bool => $entry->occupiedStart() < $dayEnd && $entry->occupiedEnd() > $dayStart
    ));

    usort(
      $entries,
      static fn (DispatchTimelineEntry $a, DispatchTimelineEntry $b): int => [$a->occupiedStart(), $a->occupiedEnd()] <=> [$b->occupiedStart(), $b->occupiedEnd()]
    );

    return $entries;
  }

  /**
   * Builds the lane's hour marks.
   *
   * Decorative, like the lane itself, and hidden from assistive technology for the
   * same reason: the table states every time in full, so announcing a row of bare
   * hour numbers would add noise and no information.
   *
   * @return array
   *   A render array.
   */
  private function hoursSection(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['cwh-dispatch-planner__hours'],
        'aria-hidden' => 'true',
      ],
    ];
    foreach (['00:00', '06:00', '12:00', '18:00', '24:00'] as $index => $hour) {
      $build['hour_' . $index] = ['#type' => 'html_tag', '#tag' => 'span', '#value' => $hour];
    }

    return $build;
  }

  /**
   * Builds the proportional picture of one day.
   *
   * The geometry is computed here, on the server, as percentages of the day's real
   * length, and written into an inline style. Nothing in that style comes from the
   * request: it is arithmetic on stored timestamps, so the lane is complete in the
   * first response and there is no script to wait for and none to trust.
   *
   * The lane is hidden from assistive technology. It is a second rendering of the
   * table below it and carries no fact the table omits, so exposing it would make
   * every row be announced twice, once without its context.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry[] $entries
   *   The day's segments.
   * @param int $dayStart
   *   Local midnight in UTC.
   * @param int $dayEnd
   *   The next local midnight in UTC, exclusive.
   *
   * @return array
   *   A render array.
   */
  private function laneSection(array $entries, int $dayStart, int $dayEnd): array {
    $span = max(1, $dayEnd - $dayStart);
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['cwh-dispatch-planner__lane'],
        'aria-hidden' => 'true',
      ],
    ];

    $index = 0;
    foreach ($entries as $entry) {
      foreach ($this->piecesFor($entry) as $piece) {
        $from = max($dayStart, $piece['start']);
        $until = min($dayEnd, $piece['end']);
        if ($until <= $from) {
          continue;
        }

        $build['segment_' . $index++] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => [
              'cwh-dispatch-planner__segment',
              'cwh-dispatch-planner__segment--' . $piece['fill'],
            ],
            'style' => sprintf(
              'left:%.4f%%;width:%.4f%%;',
              ($from - $dayStart) / $span * 100,
              ($until - $from) / $span * 100
            ),
          ],
          '#value' => $piece['label'],
        ];
      }
    }

    return $build;
  }

  /**
   * Splits one segment into the fills the lane draws it with.
   *
   * A Reservation is drawn as up to three pieces so the reserved margin either
   * side of the Dispatch Window is visible as margin rather than as service time.
   * A Hold is one piece: its repositioning is already folded into the blocked span
   * by the store, and separating it out would describe the shape of a service
   * nobody has confirmed yet.
   *
   * Labels are fixed strings. Nothing stored — no state, no blackout reason, no
   * address — is written onto the lane, so the only text there is text this class
   * wrote itself.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   *
   * @return array[]
   *   Pieces, each with start, end, fill, and label keys.
   */
  private function piecesFor(DispatchTimelineEntry $entry): array {
    if ($entry->type === DispatchTimelineEntry::TYPE_HOLD) {
      return [[
        'start' => $entry->occupiedStart(),
        'end' => $entry->occupiedEnd(),
        'fill' => 'hold',
        'label' => $this->t('Hold'),
      ]];
    }
    if ($entry->type === DispatchTimelineEntry::TYPE_BLACKOUT) {
      return [[
        'start' => $entry->occupiedStart(),
        'end' => $entry->occupiedEnd(),
        'fill' => 'blackout',
        'label' => $this->t('Blackout'),
      ]];
    }
    if ($entry->type !== DispatchTimelineEntry::TYPE_RESERVATION) {
      return [[
        'start' => $entry->occupiedStart(),
        'end' => $entry->occupiedEnd(),
        'fill' => 'margin',
        'label' => $this->t('Blocked'),
      ]];
    }

    $pieces = [];
    if ($entry->inboundRepositionSeconds > 0) {
      $pieces[] = [
        'start' => $entry->occupiedStart(),
        'end' => $entry->startTimestamp,
        'fill' => 'margin',
        'label' => $this->t('Margin'),
      ];
    }
    $pieces[] = [
      'start' => $entry->startTimestamp,
      'end' => $entry->endTimestamp,
      'fill' => 'window',
      'label' => $this->t('Reservation'),
    ];
    if ($entry->outboundRepositionSeconds > 0) {
      $pieces[] = [
        'start' => $entry->endTimestamp,
        'end' => $entry->occupiedEnd(),
        'fill' => 'margin',
        'label' => $this->t('Margin'),
      ];
    }

    return $pieces;
  }

  /**
   * Renders one day as a table, which is the authoritative reading of it.
   *
   * Everything the lane shows is here in words, plus what a coloured bar cannot
   * carry: the state, a Blackout's reason, the identifiers, and a Hold's expiry.
   * This is what a screen reader, a narrow screen, and a printed page all get, so
   * it is not a fallback but the primary rendering.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry[] $entries
   *   The day's segments, in order.
   * @param string $date
   *   The local Y-m-d date being rendered.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $resource
   *   The Resource, for its zone and its identifier.
   *
   * @return array
   *   A table render array.
   */
  private function dayTable(array $entries, string $date, ResourceProfile $resource): array {
    // Asked once for the whole table rather than per row: whether an operator may
    // act does not change between two rows of the same day, and a column that
    // appeared for some rows and not others would read as a missing operation
    // rather than as an absent permission.
    $mayManage = $this->mayManage();

    $rows = [];
    foreach ($entries as $entry) {
      $cells = [
        $this->t('@from – @until', [
          '@from' => $this->timeOnDay($entry->occupiedStart(), $date, $resource->timezone),
          '@until' => $this->timeOnDay($entry->occupiedEnd(), $date, $resource->timezone),
        ]),
        $this->windowCell($entry, $date, $resource->timezone),
        $this->marginCell($entry),
        $this->kindLabel($entry),
        ['data' => ['#plain_text' => $this->stateText($entry)]],
        $this->reservationCell($entry),
        $this->submissionCell($entry->webformId, $entry->submissionId),
        $entry->expiresAt === NULL ? '—' : $this->timeOnDay($entry->expiresAt, $date, $resource->timezone),
      ];

      if ($mayManage) {
        $cells[] = $this->operationsCell($entry, $resource, $date);
      }

      $rows[] = ['data' => $cells] + ($mayManage ? $this->rowAttributes($entry, $resource) : []);
    }

    $header = [
      $this->t('Occupied'),
      $this->t('Dispatch Window'),
      $this->t('Reserved margin'),
      $this->t('Kind'),
      $this->t('State or reason'),
      $this->t('Reservation'),
      $this->t('Submission'),
      $this->t('Hold expires'),
    ];
    if ($mayManage) {
      $header[] = $this->t('Operations');
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#caption' => $this->t('Everything taking time out of Resource @resource on @date, in @timezone local time. Occupied time includes Repositioning and the operational buffer.', [
        '@resource' => $resource->resourceId,
        '@date' => $date,
        '@timezone' => $resource->timezone,
      ]),
      '#empty' => $this->t('Nothing is on the calendar for this day.'),
    ];
  }

  /**
   * The operations a row may offer, which differ by what the row actually is.
   *
   * Only a confirmed Reservation gets both. A Blackout is drawn from an entry that
   * carries no identifier — the board shows what the day holds, not which row holds
   * it — so there is nothing here to edit or lift, and the cell points at the
   * listing where closures do have identity, opened on this day. A Hold is offered
   * nothing at all, for a stricter reason: the board is deliberately not told which
   * Hold it is looking at. One lapses on its own, and freeing one early is done from
   * the claim's own path by somebody who already knows which claim it is.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $resource
   *   The Resource, for the identifier its links carry.
   * @param string $date
   *   The local date being rendered.
   *
   * @return mixed
   *   A cell value.
   */
  private function operationsCell(DispatchTimelineEntry $entry, ResourceProfile $resource, string $date) {
    if ($entry->type === DispatchTimelineEntry::TYPE_BLACKOUT) {
      return [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('Manage closures'),
          '#url' => Url::fromRoute('custom_webform_handlers.dispatch_blackouts', [], [
            'query' => ['resource' => $resource->resourceId, 'date' => $date],
          ]),
        ],
      ];
    }

    if ($entry->type !== DispatchTimelineEntry::TYPE_RESERVATION || $entry->dispatchReservationId === NULL) {
      return '—';
    }

    return [
      'data' => [
        '#type' => 'operations',
        '#links' => [
          'replan' => [
            'title' => $this->t('Replan'),
            'url' => Url::fromRoute('custom_webform_handlers.dispatch_reservation_replan', [
              'dispatch_reservation_id' => $entry->dispatchReservationId,
            ]),
          ],
          'release' => [
            'title' => $this->t('Free capacity'),
            'url' => Url::fromRoute('custom_webform_handlers.dispatch_claim_release', [
              'dispatch_reservation_id' => $entry->dispatchReservationId,
            ]),
          ],
        ],
      ],
    ];
  }

  /**
   * The attributes that make one row draggable, and the reason there are only two.
   *
   * A drag proposes a day. The Reservation identifier says what is being proposed,
   * and the arrival time it already has is carried along unchanged, so dropping a
   * job on Thursday asks for the same hour on Thursday and nothing more. Changing
   * the hour is the Replan form's job, which the same row links to.
   *
   * That is the whole payload, and it is the whole payload on purpose: there is no
   * window here, no duration, no travel figure and no price, so a script cannot
   * assert a journey. The server re-measures the route, re-derives the window and
   * re-proves repositioning against the neighbours the day really has.
   *
   * A row with no identifier — a Hold, a Blackout, a legacy blocked span — gets
   * nothing, so it cannot be picked up at all.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $resource
   *   The Resource, whose zone is used when a row records none of its own.
   *
   * @return array
   *   Row attributes, or an empty array for a row that may not be moved.
   */
  private function rowAttributes(DispatchTimelineEntry $entry, ResourceProfile $resource): array {
    if ($entry->type !== DispatchTimelineEntry::TYPE_RESERVATION
      || $entry->dispatchReservationId === NULL
      || $entry->pickupArrival === NULL) {
      return [];
    }

    return [
      'class' => ['cwh-dispatch-planner__movable'],
      'data-cwh-reservation' => (string) $entry->dispatchReservationId,
      'data-cwh-time' => $this->localFormat(
        $entry->pickupArrival,
        $entry->timezone ?? $resource->timezone,
        'H:i',
      ),
    ];
  }

  /**
   * The Dispatch Window cell, which only a confirmed Reservation has.
   *
   * A Hold is deliberately blank here. Its blocked span is already in the occupied
   * column, and naming a Window inside it would describe a service that has not
   * been confirmed and may never be.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   * @param string $date
   *   The local date being rendered.
   * @param string $timezone
   *   The Resource's zone.
   *
   * @return mixed
   *   A cell value.
   */
  private function windowCell(DispatchTimelineEntry $entry, string $date, string $timezone) {
    if ($entry->type !== DispatchTimelineEntry::TYPE_RESERVATION) {
      return '—';
    }

    return $this->t('@from – @until', [
      '@from' => $this->timeOnDay($entry->startTimestamp, $date, $timezone),
      '@until' => $this->timeOnDay($entry->endTimestamp, $date, $timezone),
    ]);
  }

  /**
   * The reserved margin cell: Repositioning and operational buffer together.
   *
   * The snapshot records one padding figure for each side of the Window, with
   * repositioning and the operational buffer already summed into it, so this cell
   * names both and splits neither. Reporting a split the stored facts do not
   * contain would be an invention, not a detail.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   *
   * @return mixed
   *   A cell value.
   */
  private function marginCell(DispatchTimelineEntry $entry) {
    if ($entry->inboundRepositionSeconds <= 0 && $entry->outboundRepositionSeconds <= 0) {
      return '—';
    }

    return $this->t('@before before · @after after', [
      '@before' => $this->minutesLabel($entry->inboundRepositionSeconds),
      '@after' => $this->minutesLabel($entry->outboundRepositionSeconds),
    ]);
  }

  /**
   * Names the kind of a segment, so colour is never the only signal.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The kind, in words.
   */
  private function kindLabel(DispatchTimelineEntry $entry) {
    return match ($entry->type) {
      DispatchTimelineEntry::TYPE_RESERVATION => $this->t('Confirmed Reservation'),
      DispatchTimelineEntry::TYPE_HOLD => $this->t('Temporary Hold'),
      DispatchTimelineEntry::TYPE_BLACKOUT => $this->t('Blackout'),
      default => $this->t('Blocked'),
    };
  }

  /**
   * The stored state and, for a Blackout, its reason.
   *
   * Both are stored strings, so this is the one cell whose text the site's own data
   * decides. It is returned as text and rendered as plain text by the caller, never
   * as markup, because a Blackout reason is free text somebody typed.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   *
   * @return string
   *   The state, the reason, both, or an em dash.
   */
  private function stateText(DispatchTimelineEntry $entry): string {
    $parts = [];
    if ($entry->state !== NULL && trim($entry->state) !== '') {
      $parts[] = trim($entry->state);
    }
    if ($entry->reason !== NULL && trim($entry->reason) !== '') {
      $parts[] = trim($entry->reason);
    }

    return $parts === [] ? '—' : implode(' · ', $parts);
  }

  /**
   * Links a confirmed Reservation to its own read-only detail page.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry $entry
   *   The segment.
   *
   * @return mixed
   *   A cell value.
   */
  private function reservationCell(DispatchTimelineEntry $entry) {
    if ($entry->type !== DispatchTimelineEntry::TYPE_RESERVATION || $entry->dispatchReservationId === NULL) {
      return '—';
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $this->t('Reservation @id', ['@id' => $entry->dispatchReservationId]),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner_reservation', [
          'dispatch_reservation_id' => $entry->dispatchReservationId,
        ]),
      ],
    ];
  }

  /**
   * Links a Webform submission only when the site's own access check allows it.
   *
   * The check is asked of Drupal rather than reimplemented, and asked without
   * loading anything: routing an access check by name upcasts the parameters and
   * runs the submission entity's real access rules, so an operator who may read the
   * board but not that form's submissions sees the identifier and no link.
   *
   * An unknown route or an identifier that cannot be upcast is refused the same
   * way, which is why this is safe on a site where the Webform UI is absent.
   *
   * @param string|null $webformId
   *   The Webform the submission belongs to, if the segment records one.
   * @param int|null $submissionId
   *   The submission, if the segment records one.
   *
   * @return mixed
   *   A cell value: a link, a bare identifier, or an em dash.
   */
  private function submissionCell(?string $webformId, ?int $submissionId) {
    if ($submissionId === NULL || $submissionId <= 0) {
      return '—';
    }

    $identifier = $this->t('Submission @id', ['@id' => $submissionId]);
    if ($webformId === NULL || trim($webformId) === '') {
      return $identifier;
    }

    try {
      $url = Url::fromRoute('entity.webform_submission.canonical', [
        'webform' => $webformId,
        'webform_submission' => $submissionId,
      ]);
      if ($url->access()) {
        return ['data' => ['#type' => 'link', '#title' => $identifier, '#url' => $url]];
      }
    }
    catch (\Exception) {
      // No link, then. The identifier below is still useful to an operator who has
      // another way to look it up, and inventing a URL that may not resolve or may
      // not be permitted would be worse than showing none.
    }

    return $identifier;
  }

  /**
   * Renders the Manual Review queue, kept firmly off the lanes.
   *
   * This is work waiting on a human, not occupancy. It sits on no day and takes no
   * time from the Resource, so it is rendered after every day, under its own
   * heading, with the same sentence repeated as the table's caption. An operator
   * must not be able to mistake a stalled quote for a booked job.
   *
   * The queue is deliberately not filtered to the days on screen: unscheduled work
   * has no date to filter by, and scoping it would hide it the moment somebody
   * paged past the day it arrived.
   *
   * Every row is a Review Request, so the Status column says where that request has
   * got to rather than repeating the saved quote status, which is now the same value
   * on every row and therefore distinguishes nothing. Two permissions decide what
   * the row offers, and they are asked separately because they authorise different
   * things: pricing a job is not moving a van. An operator with one sees only that
   * one's operations, and an operator with neither sees no Operations column at all.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   *
   * @return array
   *   A render array.
   */
  private function manualReviewSection(DispatchBoard $board): array {
    $timezone = $board->resource->timezone;
    $heading = $this->t('Unscheduled — does not consume capacity.');
    $mayManage = $this->mayManage();
    $mayReview = $this->mayReview();

    $rows = [];
    foreach ($board->manualReview as $entry) {
      $cells = [
        $this->submissionCell($entry->webformId, $entry->submissionId),
        ['data' => ['#plain_text' => $entry->webformId]],
        $entry->submittedTimestamp > 0 ? $this->localFormat($entry->submittedTimestamp, $timezone, 'Y-m-d H:i') : '—',
        $this->reviewStateCell($entry),
        $this->reasonsCell($entry->reasons),
      ];

      if ($mayManage || $mayReview) {
        $cells[] = $this->queueOperationsCell($entry, $board->resource->resourceId, $mayReview, $mayManage);
      }

      $rows[] = ['data' => $cells];
    }

    $header = [
      $this->t('Submission'),
      $this->t('Form'),
      $this->t('Arrived'),
      $this->t('Status'),
      $this->t('Why it stalled'),
    ];
    if ($mayManage || $mayReview) {
      $header[] = $this->t('Operations');
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__queue']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $heading,
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#caption' => $heading,
        '#empty' => $this->t('No quote is waiting on Manual Review.'),
      ],
    ];
  }

  /**
   * Says where one waiting request has got to, in the two states that can wait.
   *
   * The queue is read from the Review Requests and returns open work only, so
   * Submitted and UnderReview are the whole of what should arrive here. The
   * difference between them is the one an operator needs before touching a row:
   * nobody has taken this job, or somebody has and is pricing it now. That is what
   * stops two people quoting the same work.
   *
   * Anything else is a fault rather than a state to describe, so it is shown as its
   * own stored value in plain text. An approved offer on this list would mean the
   * queue had started including work that is waiting on a customer, and inventing a
   * sentence for it would hide that; the raw value invites the question.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry $entry
   *   The waiting request.
   *
   * @return mixed
   *   A cell value.
   */
  private function reviewStateCell(DispatchManualReviewEntry $entry) {
    return match ($entry->reviewState) {
      ReviewState::Submitted => $this->t('Waiting — nobody has taken it'),
      ReviewState::UnderReview => $this->t('Being priced by staff'),
      default => ['data' => ['#plain_text' => $entry->reviewState->value]],
    };
  }

  /**
   * The operations a waiting request offers, each to the permission that owns it.
   *
   * Pricing and scheduling are separate authorities and this cell keeps them
   * separate. The first four operations are the Review Request's own transitions and
   * are offered on 'review dispatch quotes'; they consume no capacity, take no
   * payment, and change nothing about a driver's day — approving is an offer, and
   * only a paid checkout schedules anything. The last is the existing staff booking
   * path, offered on 'manage dispatch schedule', and it is the only one here that can
   * reach the calendar.
   *
   * Which of the first four appear depends on the state, because a transition that
   * the manager would refuse must not be drawn as though it would work: a request
   * nobody has taken can be taken, one somebody has taken can be priced, and either
   * can be refused or withdrawn. Nothing is enforced here — every target re-checks
   * its own permission and asks the manager whether its transition is legal, on its
   * own route, where it cannot be forgotten. This decides what to draw and nothing
   * more.
   *
   * The Resource travels in the booking link's query because the queue is not scoped
   * to one: a stalled quote has no day and no vehicle yet, so the board's own
   * Resource is what the confirmation opens on. The review links carry no Resource,
   * because a review is about a submission and not about a vehicle.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry $entry
   *   The waiting request.
   * @param string $resourceId
   *   The Resource whose board this queue is shown beneath.
   * @param bool $mayReview
   *   Whether this operator may price requests.
   * @param bool $mayManage
   *   Whether this operator may change the schedule.
   *
   * @return mixed
   *   A cell value.
   */
  private function queueOperationsCell(DispatchManualReviewEntry $entry, string $resourceId, bool $mayReview, bool $mayManage) {
    $links = [];

    if ($mayReview) {
      if ($entry->reviewState === ReviewState::Submitted) {
        $links['begin'] = [
          'title' => $this->t('Take this on'),
          'url' => Url::fromRoute('custom_webform_handlers.review_begin', ['review_id' => $entry->reviewId]),
        ];
      }
      if ($entry->reviewState === ReviewState::UnderReview) {
        $links['approve'] = [
          'title' => $this->t('Set a price'),
          'url' => Url::fromRoute('custom_webform_handlers.review_approve', ['review_id' => $entry->reviewId]),
        ];
      }

      $links['decline'] = [
        'title' => $this->t('Decline'),
        'url' => Url::fromRoute('custom_webform_handlers.review_decline', ['review_id' => $entry->reviewId]),
      ];
      $links['cancel'] = [
        'title' => $this->t('Cancel'),
        'url' => Url::fromRoute('custom_webform_handlers.review_cancel', ['review_id' => $entry->reviewId]),
      ];
    }

    // Only with a submission to book. A request whose submission has been deleted is
    // still listed, because somebody is still owed an answer on it, but there is
    // nothing left for the booking form to derive a journey from.
    if ($mayManage && $entry->submissionId > 0) {
      $links['book'] = [
        'title' => $this->t('Book this'),
        'url' => Url::fromRoute('custom_webform_handlers.dispatch_manual_review_confirm', [
          'dispatch_submission_id' => $entry->submissionId,
        ], ['query' => ['resource' => $resourceId]]),
      ];
    }

    if ($links === []) {
      return '—';
    }

    return ['data' => ['#type' => 'operations', '#links' => $links]];
  }

  /**
   * Renders why a quote stalled, from the reader's allow-listed reason keys.
   *
   * The reader has already reduced the quote engine's own breakdown to a short list
   * of keys, discarding the free text a provider's exception can carry — a request
   * URL, a key, an address. So the wording here is this class's own, and an
   * unrecognised key is shown as a key rather than being trusted as a sentence.
   *
   * @param string[] $reasons
   *   Allow-listed reason keys.
   *
   * @return mixed
   *   A cell value.
   */
  private function reasonsCell(array $reasons) {
    if ($reasons === []) {
      return '—';
    }

    $items = [];
    foreach ($reasons as $reason) {
      $items[] = match ($reason) {
        'missing_route_addresses' => $this->t('The route addresses were incomplete.'),
        'quote_calculation_failed' => $this->t('The price could not be calculated.'),
        'quote_provider_error' => $this->t('The distance or price provider failed. The detail is in the log, not here.'),
        default => $this->t('Recorded reason: @reason', ['@reason' => $reason]),
      };
    }

    return ['data' => ['#theme' => 'item_list', '#items' => $items]];
  }

  /**
   * Renders what staff last reported about the Resource, and what it does not mean.
   *
   * The panel sits beside the schedule and never inside it. A report is an observation
   * made by a person under a permission check: it is the best account of what is
   * happening on the road, and it is not evidence about capacity. The days below are the
   * evidence, so the heading says so and the two are never merged into one column an
   * operator could read as agreement.
   *
   * Letting the two disagree visibly is the point. A vehicle reported unavailable with
   * three confirmed bookings still on the day is exactly the situation somebody needs to
   * see, and a design in which a status closed the calendar would have hidden it by
   * making the schedule agree.
   *
   * The history is shown, not just the latest line, because a correction here is a newer
   * report rather than an edit — so the earlier claim, and who made it, survives only in
   * the rows underneath.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   *
   * @return array
   *   A render array.
   */
  private function statusSection(DispatchBoard $board): array {
    $timezone = $board->resource->timezone;
    $heading = $this->t('Reported status — an observation, not capacity.');

    $rows = [];
    foreach ($board->statusHistory as $entry) {
      $rows[] = [
        'data' => [
          $this->localFormat($entry->recordedAt, $timezone, 'Y-m-d H:i'),
          ResourceStatusText::label($entry->state),
          $this->statusReservationCell($entry),
          $entry->note === '' ? '—' : ['data' => ['#plain_text' => $entry->note]],
          $this->reporterCell($entry),
        ],
      ];
    }

    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__status']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $heading],
      'current' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->currentStatusText($board),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Reported'),
          $this->t('State'),
          $this->t('Booking'),
          $this->t('Note'),
          $this->t('By'),
        ],
        '#rows' => $rows,
        '#caption' => $heading,
        '#empty' => $this->t('Nothing has been reported for this Resource.'),
      ],
    ];

    if ($this->mayManage()) {
      $section['report'] = [
        '#type' => 'link',
        '#title' => $this->t('Record a report'),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_resource_status', [], [
          'query' => ['resource' => $board->resource->resourceId],
        ]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $section;
  }

  /**
   * One sentence naming the current report, or saying plainly that there is none.
   *
   * The absence of a report is stated as an absence. Reading "nothing reported" as
   * "available" is the single most likely misreading of this panel, so the sentence
   * refuses it in as many words.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  private function currentStatusText(DispatchBoard $board) {
    $current = $board->resourceStatus;
    if ($current === NULL) {
      return $this->t('Nothing has been reported. That is not the same as available — nobody has said, and only the schedule below has been proved.');
    }

    return $this->t('Last reported @state at @when by @who. That report withheld no time and moved no booking.', [
      '@state' => ResourceStatusText::label($current->state),
      '@when' => $this->localFormat($current->recordedAt, $board->resource->timezone, 'Y-m-d H:i'),
      '@who' => $this->reporterName($current),
    ]);
  }

  /**
   * Names who made one report, without asserting an account still exists.
   *
   * The stored name is used rather than a fresh account lookup, because that is what
   * the audit row is for: an account can be renamed or deleted, and the report has to
   * keep meaning what it meant. A row from a later non-human source names the source
   * instead, so a telematics feed can never be shown as a member of staff.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus $status
   *   The report.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A name for a placeholder, escaped by whatever renders it.
   */
  private function reporterName(ResourceStatus $status) {
    if (!$status->isStaffAsserted()) {
      return $status->source;
    }

    return $status->actorName === '' ? $this->t('an account since removed') : $status->actorName;
  }

  /**
   * The reporter as a table cell, with the stored name kept as plain text.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus $status
   *   The report.
   *
   * @return mixed
   *   A cell value.
   */
  private function reporterCell(ResourceStatus $status) {
    if (!$status->isStaffAsserted()) {
      return ['data' => ['#plain_text' => $status->source]];
    }

    return $status->actorName === ''
      ? $this->t('an account since removed')
      : ['data' => ['#plain_text' => $status->actorName]];
  }

  /**
   * Links the booking a report was about, when it named one.
   *
   * The four working states name a job and the two vehicle states do not, so an em dash
   * here is the ordinary case rather than missing data.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus $status
   *   The report.
   *
   * @return mixed
   *   A cell value.
   */
  private function statusReservationCell(ResourceStatus $status) {
    if ($status->dispatchReservationId === NULL) {
      return '—';
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $this->t('Reservation @id', ['@id' => $status->dispatchReservationId]),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner_reservation', [
          'dispatch_reservation_id' => $status->dispatchReservationId,
        ]),
      ],
    ];
  }

  /**
   * Renders what the site tried to tell people, and what it cannot claim.
   *
   * Outcomes and identifiers only. There is no recipient column and no message column,
   * because neither is stored: an address is resolved when a message is composed and
   * dropped again, and the composed text is never written down at all. So this table
   * can be shown to anybody already entitled to the board without disclosing an
   * address, a price, or a sentence a customer read.
   *
   * "Handed to mail" is as strong as the wording gets. Acceptance by the transport is
   * not delivery to a person, and a board claiming otherwise would be making a promise
   * about somebody else's mail server.
   *
   * Not filtered to the days on screen. The question this answers is whether the people
   * involved were told, which does not stop being interesting because an operator paged
   * forward a week.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchBoard $board
   *   An available board.
   *
   * @return array
   *   A render array.
   */
  private function notificationSection(DispatchBoard $board): array {
    $timezone = $board->resource->timezone;
    $heading = $this->t('Notifications — what was announced, not what was written.');

    $rows = [];
    foreach ($board->notifications as $record) {
      $rows[] = [
        'data' => [
          $this->localFormat($record->recordedAt, $timezone, 'Y-m-d H:i'),
          DispatchNotificationText::event($record->event),
          DispatchNotificationText::audience($record->audience),
          DispatchNotificationText::outcome($record->outcome),
          $this->notificationSubjectCell($record),
          $this->minutesLabel($record->queueDelay()),
          $record->detail === '' ? '—' : ['data' => ['#plain_text' => $record->detail]],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__notifications']],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $heading],
      'attention' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->attentionText($board->notificationsNeedingAttention),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Attempted'),
          $this->t('Event'),
          $this->t('Written for'),
          $this->t('Result'),
          $this->t('About'),
          $this->t('Waited'),
          $this->t('Detail'),
        ],
        '#rows' => $rows,
        '#caption' => $heading,
        '#empty' => $this->t('Nothing has been announced for this Resource.'),
      ],
    ];
  }

  /**
   * States how much of the recent history needs looking at.
   *
   * Bounded by the same window the table shows, and worded to say so, because "no
   * failures" over ten rows and "no failures ever" are different claims and only the
   * first one is true here.
   *
   * A skipped message counts. Silence that was decided on purpose still means a
   * customer was not told, and the one failure mode this panel exists to catch is a
   * configuration that looks switched on and quietly sends nothing.
   *
   * @param int $count
   *   Attempts in the shown window that did not reach anybody.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  private function attentionText(int $count) {
    if ($count < 1) {
      return $this->t('Every attempt shown below was handed to mail. That is acceptance by the mail system, not proof anybody read it.');
    }

    return $this->formatPlural(
      $count,
      '1 of the attempts shown below did not reach anybody. A failure or a skipped message means somebody was not told, so the booking may need a telephone call.',
      '@count of the attempts shown below did not reach anybody. A failure or a skipped message means somebody was not told, so those bookings may need a telephone call.'
    );
  }

  /**
   * Names what one notification was about, linking the booking when there was one.
   *
   * A Manual Review notice and a closure have no Reservation to name — that is the
   * point of both — so the submission identifier stands in for the first and an em dash
   * for the second. The submission is named and not linked here, because the history
   * does not record which Webform owns it and inventing a URL to find out would be a
   * guess on an administrative screen.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationRecord $record
   *   The history row.
   *
   * @return mixed
   *   A cell value.
   */
  private function notificationSubjectCell(NotificationRecord $record) {
    if ($record->dispatchReservationId !== NULL) {
      return [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('Reservation @id', ['@id' => $record->dispatchReservationId]),
          '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner_reservation', [
            'dispatch_reservation_id' => $record->dispatchReservationId,
          ]),
        ],
      ];
    }

    return $this->submissionCell(NULL, $record->submissionId);
  }

  /**
   * Renders one confirmed Reservation as facts, and states none of the operations.
   *
   * Every value here comes from DispatchReservationDetail, which the store built as
   * an administrator-safe projection: no Hold token, no owner hash, no neighbour's
   * address. The table itself remains a reading and nothing else — the operations
   * sit beside it, as links, so that a page describing a claim and a page changing
   * one stay visibly different things.
   *
   * Safe for an administrator to hold is not the same as safe for every one of them
   * to read, which is specification item 8. Three rows are narrower than the rest:
   * the two exact addresses, and the money. Each is asked for once, before the table
   * is built, because whether this operator may see an address does not change
   * between the pickup row and the delivery row. A role without the grant still gets
   * the row and is told the value is withheld, so the page reads as "you may not see
   * this" rather than as "this was never recorded".
   *
   * The Route row is deliberately not among them. Distance and duration are the
   * service summary — what the job is — and the permission that covers addresses says
   * so in as many words: it grants the full addresses "rather than the distance and
   * service summary alone". Withholding the shape of the work from the people running
   * it would leave the read-only role unable to do the reading it exists for.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail $detail
   *   The safe detail.
   *
   * @return array
   *   A render array.
   */
  private function detailTable(DispatchReservationDetail $detail): array {
    $zone = $detail->timezone;
    $mayViewAddresses = $this->mayViewAddresses();
    $mayViewFinancials = $this->mayViewFinancials();
    $rows = [
      [$this->t('Reservation'), ['data' => ['#plain_text' => (string) $detail->dispatchReservationId]]],
      [$this->t('Resource'), ['data' => ['#plain_text' => $detail->resourceId]]],
      [$this->t('State'), ['data' => ['#plain_text' => $detail->state]]],
      [$this->t('Local timezone'), ['data' => ['#plain_text' => $zone]]],
      [
        $this->t('Dispatch Window'),
        $this->t('@from – @until', [
          '@from' => $this->localFormat($detail->windowStart, $zone, 'Y-m-d H:i'),
          '@until' => $this->localFormat($detail->windowEnd, $zone, 'Y-m-d H:i'),
        ]),
      ],
      [
        $this->t('Occupied, margin included'),
        $this->t('@from – @until', [
          '@from' => $this->localFormat($detail->occupiedStart(), $zone, 'Y-m-d H:i'),
          '@until' => $this->localFormat($detail->occupiedEnd(), $zone, 'Y-m-d H:i'),
        ]),
      ],
      [
        $this->t('Reserved margin'),
        $this->t('@before before · @after after, Repositioning and operational buffer together', [
          '@before' => $this->minutesLabel($detail->inboundRepositionSeconds),
          '@after' => $this->minutesLabel($detail->outboundRepositionSeconds),
        ]),
      ],
      [$this->t('Pickup arrival'), $this->localFormat($detail->pickupArrival, $zone, 'Y-m-d H:i')],
      [$this->t('Pickup'), $mayViewAddresses ? ['data' => ['#plain_text' => $detail->pickupLocation]] : $this->withheld()],
      [$this->t('Delivery'), $mayViewAddresses ? ['data' => ['#plain_text' => $detail->deliveryLocation]] : $this->withheld()],
      [$this->t('Route'), $this->routeText($detail)],
      [$this->t('Price snapshot'), $mayViewFinancials ? $this->priceText($detail) : $this->withheld()],
      [$this->t('Form'), ['data' => ['#plain_text' => $detail->webformId]]],
      [$this->t('Submission'), $this->submissionCell($detail->webformId, $detail->submissionId)],
      [$this->t('Claimed'), $this->localFormat($detail->created, $zone, 'Y-m-d H:i')],
      [$this->t('Last changed'), $this->localFormat($detail->changed, $zone, 'Y-m-d H:i')],
    ];

    return [
      '#type' => 'table',
      '#header' => [$this->t('Fact'), $this->t('Value')],
      '#rows' => $rows,
      '#caption' => $this->t('Reservation @id on Resource @resource. Every value here is a reading of what was proved; changing any of it happens on the operation pages, never in this table.', [
        '@id' => $detail->dispatchReservationId,
        '@resource' => $detail->resourceId,
      ]),
      '#empty' => $this->t('No detail is recorded.'),
    ];
  }

  /**
   * The operations on one Reservation, as links to pages that prove their own work.
   *
   * Nothing is offered to an operator without the permission those pages require,
   * which is why the whole section disappears rather than rendering controls that
   * would refuse: a page that shows a Replan link to somebody who cannot Replan is
   * describing a capability the site does not grant them.
   *
   * Both operations are destructive in different senses — one retires the row and
   * writes a replacement, the other stops the vehicle being sent at all — so both
   * land on a page that says what will happen before anything is committed, and
   * neither can be triggered by following a link.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail $detail
   *   The safe detail.
   *
   * @return array
   *   A render array, empty when this operator may only read.
   */
  private function detailOperations(DispatchReservationDetail $detail): array {
    if (!$this->mayManage()) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-dispatch-planner__operations']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Operations'),
      ],
      'replan' => [
        '#type' => 'link',
        '#title' => $this->t('Replan this Reservation'),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_reservation_replan', [
          'dispatch_reservation_id' => $detail->dispatchReservationId,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
      'release' => [
        '#type' => 'link',
        '#title' => $this->t('Free this capacity'),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_claim_release', [
          'dispatch_reservation_id' => $detail->dispatchReservationId,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
      'blackouts' => [
        '#type' => 'link',
        '#title' => $this->t('Closures on @resource', ['@resource' => $detail->resourceId]),
        '#url' => Url::fromRoute('custom_webform_handlers.dispatch_blackouts', [], [
          'query' => [
            'resource' => $detail->resourceId,
            'date' => $this->localFormat($detail->windowStart, $detail->timezone, 'Y-m-d'),
          ],
        ]),
      ],
    ];
  }

  /**
   * The route the Reservation was planned against, when one was measured.
   *
   * Both figures are optional in the snapshot, because a claim can be confirmed
   * from a quote whose provider call failed. Missing is shown as missing rather
   * than as zero: a zero-kilometre route is a fact, and "we never measured" is a
   * different one.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail $detail
   *   The safe detail.
   *
   * @return mixed
   *   A cell value.
   */
  private function routeText(DispatchReservationDetail $detail) {
    if ($detail->routeSeconds === NULL && $detail->routeMeters === NULL) {
      return $this->t('Not measured');
    }

    return $this->t('@minutes · @distance', [
      '@minutes' => $detail->routeSeconds === NULL ? $this->t('duration not measured') : $this->minutesLabel($detail->routeSeconds),
      '@distance' => $detail->routeMeters === NULL
        ? $this->t('distance not measured')
        : $this->t('@km km', ['@km' => number_format($detail->routeMeters / 1000, 1)]),
    ]);
  }

  /**
   * The price this Reservation was confirmed at, as it was recorded.
   *
   * The snapshot identifier is shown beside the amount because it is what ties the
   * claim to the quote it came from. Nothing here is recalculated: repricing on a
   * read would make the board disagree with what the customer was told.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail $detail
   *   The safe detail.
   *
   * @return mixed
   *   A cell value.
   */
  private function priceText(DispatchReservationDetail $detail) {
    if ($detail->priceSnapshotCents === NULL) {
      return $this->t('No price was recorded');
    }

    return $this->t('@amount @currency, snapshot @snapshot', [
      '@amount' => number_format($detail->priceSnapshotCents / 100, 2),
      '@currency' => $detail->priceSnapshotCurrency ?? '',
      '@snapshot' => $detail->priceSnapshotId ?? '',
    ]);
  }

}
