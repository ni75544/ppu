<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchBlackout;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\DispatchReasonText;
use Drupal\custom_webform_handlers\Dispatch\LocalWallClock;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two staff surfaces that are not Form API pages.
 *
 * One is a listing. Blackouts need one of their own because a Blackout on the board
 * is drawn as an occupied span and carries no identifier — deliberately, since the
 * board's job is to show what the day holds rather than to expose row identity — so
 * there is nowhere on it to hang an edit or a withdrawal. This page is that
 * somewhere, and it reads through the operations seam's projections like every
 * other staff screen.
 *
 * The other is the endpoint the board's drag handler posts to, and it is worth
 * being explicit about what it is not. It is not a move. It accepts a Reservation
 * identifier and a proposed local date and time, and there is no field in its
 * payload for a Dispatch Window, a duration, a route, a repositioning figure or a
 * price — not because they are validated away, but because nothing reads them. The
 * server reloads the Reservation, rebuilds its Dispatch Request from the submission
 * that owns it, and re-proves the whole plan under the calendar locks. A drag is a
 * proposal; the answer comes from the planner or not at all.
 *
 * So there is no planning algorithm in this file. Both methods hand their input to
 * DispatchOperations and render what comes back, which is the same rule the forms
 * follow, and the reason a refusal reads the same wherever an operator meets it.
 */
final class DispatchOperationsController extends ControllerBase {

  /**
   * How many days of closures the listing shows when nothing narrower is asked for.
   */
  private const MINIMUM_SPAN_DAYS = 30;

  /**
   * The widest span the listing will build, however configuration is set.
   */
  private const MAXIMUM_SPAN_DAYS = 180;

  public function __construct(
    private readonly DispatchOperations $operations,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_operations'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Lists the closures in force on one Resource, with the operations on each.
   */
  public function blackouts(Request $request): array {
    $requested = $request->query->get('resource', '');
    $profile = $this->operations->profile(is_string($requested) ? $requested : '');
    if ($profile === NULL) {
      // An identifier naming no Resource this installation operates is answered as
      // itself, not as the configured Resource: an operator must not be shown one
      // vehicle's closures under another vehicle's name.
      return $this->uncached([
        '#theme' => 'item_list',
        '#title' => $this->t('No such Resource'),
        '#items' => [$this->t('That Resource is not one this site operates, so it has no closures to list.')],
      ]);
    }

    [$from, $to, $startDate] = $this->range($request, $profile);
    $blackouts = $this->operations->blackoutsFor($profile->resourceId, $from, $to);

    $build['summary'] = [
      '#markup' => $this->t('<p>@resource · closures in force from @from, read in @zone. Withdrawn closures are kept as history and are not listed here.</p>', [
        '@resource' => $profile->resourceId,
        '@from' => $startDate,
        '@zone' => $profile->timezone,
      ]),
    ];

    $build['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Withhold time'),
      '#url' => Url::fromRoute('custom_webform_handlers.dispatch_blackout_add', [], [
        'query' => ['resource' => $profile->resourceId, 'date' => $startDate],
      ]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['blackouts'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Local date'),
        $this->t('Withheld'),
        $this->t('Reason'),
        $this->t('Recorded'),
        $this->t('Operations'),
      ],
      '#rows' => $this->blackoutRows($blackouts, $profile),
      '#empty' => $this->t('No time is withheld on @resource in this span.', [
        '@resource' => $profile->resourceId,
      ]),
    ];

    $build['board'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to the board'),
      '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner', [], [
        'query' => ['resource' => $profile->resourceId],
      ]),
    ];

    return $this->uncached($build);
  }

  /**
   * Answers the board's drag with the planner's answer, and never with its own.
   *
   * Three facts are read from the payload and every other key in it is ignored: the
   * Reservation being proposed for a new time, and the local date and time it is
   * proposed to arrive at. A duration, a Dispatch Window, a travel figure or a route
   * cannot be smuggled in, because nothing here looks for one and there is no
   * parameter on the operation to carry it. Whatever the drag handler believes about
   * the journey, the server measures it again.
   *
   * The reply is deliberately thin. On success it names the new Reservation and the
   * one it retired, so the board can say what happened before it reloads; on refusal
   * it carries the machine reason, the same sentence the forms print, and whether
   * another time is even worth trying. It never carries an address, a customer, a
   * price or a neighbouring job, so a drag handler cannot become a way of reading
   * the day around a Reservation that a listing would not disclose.
   *
   * A refusal has changed nothing at all, which is why it is reported as an HTTP
   * error rather than a success carrying bad news: the board must not paint the job
   * in its proposed place on the strength of a 200.
   */
  public function replanEndpoint(Request $request): JsonResponse {
    $payload = $this->payload($request);
    $reservationId = isset($payload['reservation_id']) && is_numeric($payload['reservation_id'])
      ? (int) $payload['reservation_id']
      : 0;
    $date = isset($payload['date']) && is_string($payload['date']) ? trim($payload['date']) : '';
    $time = isset($payload['time']) && is_string($payload['time'])
      ? LocalWallClock::normaliseTime($payload['time'])
      : NULL;

    // The shape of the proposal, and nothing about whether it can be driven. Every
    // physical question — hours, breaks, closures, repositioning, the horizon, the
    // return to base, and who else owns that part of the day — is answered inside
    // the operation, under the calendar locks, against the rows as they stand.
    if ($reservationId < 1 || !LocalWallClock::isDate($date) || $time === NULL) {
      return $this->refusal(DispatchReason::InvalidRequest);
    }

    $result = $this->operations->replan($reservationId, $date, $time);
    if (!$result->isMoved() || $result->slot === NULL) {
      return $this->refusal($result->reason);
    }

    return new JsonResponse([
      'status' => 'moved',
      'reservation' => $result->reservationId,
      'superseded' => $result->supersededReservationId,
      'date' => $result->slot->localDate(),
      'time' => $result->slot->localTime(),
      'message' => (string) $this->t('Proved and moved to @date at @time. Reservation @old is retired.', [
        '@date' => $result->slot->localDate(),
        '@time' => $result->slot->localTime(),
        '@old' => $result->supersededReservationId,
      ]),
    ]);
  }

  /**
   * Renders the closures as rows, each carrying the operations that apply to it.
   *
   * Only the identifier, the span, the reason an operator typed and the recording
   * time are shown, because that is all a closure is: withheld time. There is no
   * customer, no submission and no price on one, so there is nothing here to
   * project down from.
   */
  private function blackoutRows(array $blackouts, ResourceProfile $profile): array {
    $rows = [];
    foreach ($blackouts as $blackout) {
      if (!$blackout instanceof DispatchBlackout) {
        continue;
      }

      $rows[] = [
        'date' => $blackout->localDate(),
        'withheld' => $this->t('@from until @until', [
          '@from' => $blackout->localStart()->format('H:i'),
          '@until' => $blackout->localEnd()->format('H:i'),
        ]),
        'reason' => $blackout->reason === '' ? $this->t('None recorded') : $blackout->reason,
        'recorded' => $this->localise($blackout->created, $profile),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Revise'),
                'url' => Url::fromRoute('custom_webform_handlers.dispatch_blackout_edit', [
                  'dispatch_blackout_id' => $blackout->blackoutId,
                ], ['query' => ['resource' => $blackout->resourceId]]),
              ],
              'withdraw' => [
                'title' => $this->t('Lift'),
                'url' => Url::fromRoute('custom_webform_handlers.dispatch_blackout_withdraw', [
                  'dispatch_blackout_id' => $blackout->blackoutId,
                ], ['query' => ['resource' => $blackout->resourceId]]),
              ],
            ],
          ],
        ],
      ];
    }

    return $rows;
  }

  /**
   * The span of days the listing covers, as two instants and the day it opens on.
   *
   * A closure listing that showed everything ever recorded would be a history page,
   * and history is not what an operator needs when they are trying to find out why
   * a Tuesday will not book. So the window starts on the day being asked about —
   * today unless the board linked here with one — and runs as far ahead as bookings
   * can be taken, because a closure beyond the horizon cannot affect an offer.
   *
   * The horizon is clamped at both ends. A site configured with a two-day horizon
   * would otherwise produce a listing too short to plan with, and one configured
   * with a decade would build a page nobody can read.
   *
   * Midnight is resolved through PHP's own normalisation rather than the strict
   * parser the write paths use: this is a read window, and a zone whose clocks jump
   * at midnight should shift the boundary by an hour rather than refuse the page.
   */
  private function range(Request $request, ResourceProfile $profile): array {
    $zone = $profile->timezoneObject();
    $requested = $request->query->get('date', '');
    $requested = is_string($requested) ? trim($requested) : '';
    $localToday = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($zone)
      ->format('Y-m-d');

    try {
      $start = new \DateTimeImmutable(
        (LocalWallClock::isDate($requested) ? $requested : $localToday) . ' 00:00',
        $zone,
      );
    }
    catch (\Exception) {
      $start = new \DateTimeImmutable($localToday . ' 00:00', $zone);
    }

    $span = min(self::MAXIMUM_SPAN_DAYS, max(self::MINIMUM_SPAN_DAYS, $profile->bookingHorizonDays));

    return [
      $start->getTimestamp(),
      $start->modify('+' . $span . ' days')->getTimestamp(),
      $start->format('Y-m-d'),
    ];
  }

  /**
   * Renders one stored instant in the Resource's own zone, never the site's.
   */
  private function localise(int $timestamp, ResourceProfile $profile): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($profile->timezoneObject())
      ->format('Y-m-d H:i');
  }

  /**
   * Marks a staff page as never cacheable, for the same reason the board is.
   *
   * What is withheld changes the moment an operator withholds it, and a page served
   * from cache would show a span as open that the vehicle is no longer available in.
   * The query arguments are declared as contexts as well, so that a future decision
   * to cache this page cannot accidentally serve one Resource's closures under
   * another Resource's name.
   */
  private function uncached(array $build): array {
    $build['#cache'] = [
      'max-age' => 0,
      'contexts' => ['url.query_args:resource', 'url.query_args:date', 'user.permissions'],
    ];

    return $build;
  }

  /**
   * Reads the posted JSON body, treating anything unreadable as an empty proposal.
   *
   * A malformed body is not an exception worth raising: it names no Reservation, so
   * it is refused as the invalid request it is, in the same words as a request that
   * named one badly.
   */
  private function payload(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Reports a refusal, in the planner's words and with an HTTP status to match.
   *
   * The status codes divide refusals by what an operator can do about them rather
   * than by where they came from: a request naming nothing usable is 400, a
   * Reservation that is not there to move is 404, and everything else is 409,
   * because everything else means the day itself said no. The board treats all
   * three the same way — show the sentence, reload the authoritative rows — so the
   * distinction is for logs and for anything that later speaks to this endpoint.
   */
  private function refusal(DispatchReason $reason): JsonResponse {
    $status = match ($reason) {
      DispatchReason::InvalidRequest => 400,
      DispatchReason::ReservationNotFound => 404,
      default => 409,
    };

    return new JsonResponse([
      'status' => 'refused',
      'reason' => $reason->value,
      'message' => (string) DispatchReasonText::staff($reason),
      'another_time_may_work' => DispatchReasonText::anotherTimeMayWork($reason),
    ], $status);
  }

}
