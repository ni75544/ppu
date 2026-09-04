<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Reservation\DatabaseReservationManager;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationRecord;
use Drupal\custom_webform_handlers\Reservation\ReservationReconciliation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows administrators what the reservation table currently holds.
 *
 * This screen reads and links; it changes nothing. Blocking and releasing are
 * separate routed forms, so no reservation state can turn over on a GET request.
 * Every value rendered here comes from a ReservationRecord, which cannot carry
 * the active slot key or either secret hash.
 */
final class ReservationAdminController extends ControllerBase {

  /**
   * Reservation records shown on one page of the list.
   *
   * A page is deliberately far smaller than the manager's own hard ceiling: the
   * ceiling exists to stop an unbounded query, while this is what an
   * administrator can actually read at once. The pager covers the rest, so a
   * bounded page no longer means unreachable records.
   */
  const PAGE_SIZE = 25;

  /**
   * The Webform whose schedule this dashboard describes by default.
   */
  const DEFAULT_WEBFORM = 'hg';

  /**
   * Days of schedule shown when no range is requested.
   *
   * Deliberately short. A dashboard that lists every day to the horizon buries
   * this week's problem in three months of empty rows.
   */
  const SCHEDULE_DAYS = 14;

  /**
   * The longest schedule range an administrator may ask for at once.
   */
  const SCHEDULE_MAX_DAYS = 62;

  public function __construct(
    private readonly ReservationManagerInterface $reservationManager,
    private readonly ReservationDefinitionProvider $definitionProvider,
    private readonly ReservationReconciliation $reconciliation,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.reservation_manager'),
      $container->get('custom_webform_handlers.reservation_definition_provider'),
      $container->get('custom_webform_handlers.reservation_reconciliation'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Lists reservations, newest first, under the requested filters.
   */
  public function overview(Request $request): array {
    $filters = [
      'state' => (string) $request->query->get('state', ''),
      'webform_id' => (string) $request->query->get('webform_id', ''),
      'resource_id' => (string) $request->query->get('resource_id', ''),
      'date' => (string) $request->query->get('date', ''),
    ];

    // Count and page through the same filter seam. Asking the manager for both
    // the total and the page means the pager can never describe a different set
    // of records than the table shows, and no query logic is restated here.
    // A malformed filter set totals zero and lists nothing, so a fail-closed
    // filter stays fail-closed all the way through the pager.
    $pageSize = min(self::PAGE_SIZE, DatabaseReservationManager::ADMIN_LIMIT);
    $total = $this->reservationManager->adminTotal($filters);
    $pager = $this->pagerManager->createPager($total, $pageSize);
    // The pager clamps the requested page into range, so ?page=9999 and an
    // empty result set both resolve to a safe first-page offset.
    $offset = $pager->getCurrentPage() * $pageSize;
    $records = $this->reservationManager->adminList($filters, $pageSize, $offset);

    $rows = [];
    foreach ($records as $record) {
      $rows[] = [
        'data' => [
          $record->reservationId,
          $record->localDate(),
          $record->localTime() . '–' . $record->localEndTime(),
          $record->timezone,
          $record->webformId,
          $record->resourceId,
          $this->stateLabel($record->state),
          $record->expiresAt === NULL
            ? $this->t('—')
            : $this->formatTimestamp($record->expiresAt),
          $record->submissionId === NULL ? $this->t('None') : $record->submissionId,
          $this->formatTimestamp($record->created),
          $this->formatTimestamp($record->changed),
          ['data' => $this->operations($record)],
        ],
      ];
    }

    $build = [];

    $build['filters'] = $this->filterForm($filters);

    $build['summary'] = $this->summary($filters);
    $build['schedule'] = $this->scheduleSection($filters, $request);
    $build['availability'] = $this->availabilitySection($filters);

    $build['actions'] = [
      '#type' => 'container',
      'block' => Link::createFromRoute(
        $this->t('Block a slot'),
        'custom_webform_handlers.reservation_admin_block',
      )->toRenderable() + ['#attributes' => ['class' => ['button', 'button--action']]],
    ];

    $build['count'] = $this->recordCount($total, $offset, count($records));

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Date'),
        $this->t('Time'),
        $this->t('Timezone'),
        $this->t('Webform'),
        $this->t('Resource'),
        $this->t('State'),
        $this->t('Expires'),
        $this->t('Submission ID'),
        $this->t('Created'),
        $this->t('Changed'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No reservations match these filters.'),
    ];

    // The active filters travel with every pager link, so paging a filtered
    // list cannot silently widen it back to every reservation on the site.
    // Empty filters are dropped rather than published as empty query values.
    $build['pager'] = [
      '#type' => 'pager',
      '#quantity' => 5,
      '#parameters' => array_filter(
        $filters + [
          'from' => (string) $request->query->get('from', ''),
          'to' => (string) $request->query->get('to', ''),
        ],
        static fn (string $value): bool => trim($value) !== '',
      ),
    ];

    // Holds expire on a clock, so a cached page would show slots as taken after
    // they were already free again.
    $build['#cache']['max-age'] = 0;
    $build['#attached']['library'][] = 'custom_webform_handlers/reservation_admin';

    $build['reconciliation'] = $this->reconciliationSection($filters['webform_id']);

    return $build;
  }

  /**
   * States how many records match, and which of them this page shows.
   *
   * A bounded page is only honest if the administrator can see what it is
   * bounded out of; the previous screen said "showing the most recent 100" and
   * left the real size of the filtered set unstated.
   */
  private function recordCount(int $total, int $offset, int $shown): array {
    if ($total === 0) {
      return [
        '#markup' => '<p class="custom-reservation-count">'
        . $this->t('No reservation records match these filters.')
        . '</p>',
      ];
    }

    return [
      '#markup' => '<p class="custom-reservation-count">'
      . $this->t('Showing @first–@last of @total matching reservation records.', [
        '@first' => $offset + 1,
        '@last' => $offset + $shown,
        '@total' => $total,
      ])
      . '</p>',
    ];
  }

  /**
   * Summarises the reservation states under the active filters.
   */
  private function summary(array $filters): array {
    $counts = $this->reservationManager->adminCounts($filters);
    $items = [
      'confirmed' => [DatabaseReservationManager::STATE_CONFIRMED, $this->t('Booked')],
      'held' => [DatabaseReservationManager::STATE_HELD, $this->t('Held')],
      'blocked' => [DatabaseReservationManager::STATE_BLOCKED, $this->t('Blocked')],
      'released' => [DatabaseReservationManager::STATE_RELEASED, $this->t('Released')],
      'expired' => [DatabaseReservationManager::STATE_EXPIRED, $this->t('Expired')],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['custom-reservation-summary']],
    ];
    foreach ($items as $key => [$state, $label]) {
      $build[$key] = [
        '#markup' => '<div class="custom-reservation-summary__item"><span class="custom-reservation-summary__count">'
          . (int) ($counts[$state] ?? 0)
          . '</span><span>' . $label . '</span></div>',
      ];
    }
    return $build;
  }

  /**
   * Summarises capacity per day across a bounded range.
   *
   * This answers the question the record list cannot: not "what happened" but
   * "how much of the coming fortnight is still sellable". It reads through the
   * manager's calendar seam, so it counts an hour as unavailable under exactly
   * the rule the booking path applies, and it restates no SQL of its own.
   *
   * The range defaults to a short window and is clamped to the configured
   * booking horizon in both directions: a dashboard listing every day to the
   * horizon buries this week's problem in three months of empty rows.
   */
  private function scheduleSection(array $filters, Request $request): array {
    $webformId = $filters['webform_id'] !== '' ? $filters['webform_id'] : self::DEFAULT_WEBFORM;

    try {
      $definition = $this->definitionProvider->definition($webformId, $filters['resource_id']);
    }
    catch (ReservationException) {
      return [
        '#type' => 'details',
        '#title' => $this->t('Schedule capacity'),
        '#open' => TRUE,
        '#weight' => -5,
        'message' => [
          '#markup' => '<p>' . $this->t('No bookable schedule is configured for Webform %webform with resource %resource.', [
            '%webform' => $webformId,
            '%resource' => $filters['resource_id'] !== '' ? $filters['resource_id'] : $this->t('(default)'),
          ]) . '</p>',
        ],
      ];
    }

    $timezone = $definition->timezoneObject();
    $today = (new \DateTimeImmutable('now', $timezone))->setTime(0, 0, 0);
    $horizonEnd = $today->modify('+' . $definition->bookingWindowDays . ' days');

    $from = $this->scheduleDay((string) $request->query->get('from', ''), $today, $today, $horizonEnd);
    $ceiling = $from->modify('+' . (self::SCHEDULE_MAX_DAYS - 1) . ' days');
    if ($ceiling > $horizonEnd) {
      $ceiling = $horizonEnd;
    }
    $default = $from->modify('+' . (self::SCHEDULE_DAYS - 1) . ' days');
    if ($default > $ceiling) {
      $default = $ceiling;
    }
    $to = $this->scheduleDay((string) $request->query->get('to', ''), $default, $from, $ceiling);

    $days = $this->reservationManager->calendar($definition, $from, $to);

    $rows = [];
    $totals = ['held' => 0, 'confirmed' => 0, 'blocked' => 0, 'free' => 0];
    foreach ($days as $day) {
      if (!$day['weekday_open']) {
        $status = 'closed';
        $label = $this->t('Closed');
      }
      elseif ($day['available_hours'] === 0) {
        $status = 'full';
        $label = $this->t('Fully booked');
      }
      elseif ($day['unavailable_hours'] > 0) {
        $status = 'partial';
        $label = $this->t('Partially booked');
      }
      else {
        $status = 'open';
        $label = $this->t('Available');
      }

      $totals['held'] += $day['held_hours'];
      $totals['confirmed'] += $day['confirmed_hours'];
      $totals['blocked'] += $day['blocked_hours'];
      $totals['free'] += $day['available_hours'];

      $rows[] = [
        'class' => ['custom-reservation-schedule-row--' . $status],
        'data' => [
          // Links to this same screen filtered to the day, which is the existing
          // record listing and its per-reservation detail links.
          ['data' => $this->scheduleDateLink($day['date'], $filters)],
          $day['configured_hours'],
          $day['held_hours'],
          $day['confirmed_hours'],
          $day['blocked_hours'],
          $day['available_hours'],
          [
            'data' => [
              '#markup' => '<span class="custom-reservation-admin-status custom-reservation-admin-status--'
              . $status . '">' . $label . '</span>',
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Schedule capacity, @from to @to', [
        '@from' => $from->format('Y-m-d'),
        '@to' => $to->format('Y-m-d'),
      ]),
      '#open' => TRUE,
      '#weight' => -5,
      'help' => [
        '#markup' => '<p>' . $this->t('Webform %webform, resource %resource, times in @timezone. Bookable through @horizon. Across this range: @confirmed confirmed, @held held, @blocked blocked, @free hour(s) still free.', [
          '%webform' => $definition->webformId,
          '%resource' => $definition->resourceId,
          '@timezone' => $definition->timezone,
          '@horizon' => $horizonEnd->format('Y-m-d'),
          '@confirmed' => $totals['confirmed'],
          '@held' => $totals['held'],
          '@blocked' => $totals['blocked'],
          '@free' => $totals['free'],
        ]) . '</p>',
      ],
      'range' => $this->scheduleRangeForm($filters, $from, $to),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Date'),
          $this->t('Hours'),
          $this->t('Held'),
          $this->t('Confirmed'),
          $this->t('Blocked'),
          $this->t('Free'),
          $this->t('Status'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No days fall inside this range and the booking horizon.'),
      ],
    ];
  }

  /**
   * Links one schedule day to the record listing for that day.
   */
  private function scheduleDateLink(string $date, array $filters): array {
    $query = array_filter([
      'webform_id' => $filters['webform_id'],
      'resource_id' => $filters['resource_id'],
      'date' => $date,
    ], static fn (string $value): bool => $value !== '');

    return Link::createFromRoute(
      $date,
      'custom_webform_handlers.reservation_admin',
      [],
      ['query' => $query],
    )->toRenderable();
  }

  /**
   * Resolves one requested schedule bound into the allowed window.
   */
  private function scheduleDay(string $requested, \DateTimeImmutable $fallback, \DateTimeImmutable $earliest, \DateTimeImmutable $latest): \DateTimeImmutable {
    $requested = trim($requested);
    if ($requested === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
      return $fallback;
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested, $earliest->getTimezone());
    if (!$parsed || $parsed->format('Y-m-d') !== $requested) {
      return $fallback;
    }
    if ($parsed < $earliest) {
      return $earliest;
    }
    if ($parsed > $latest) {
      return $latest;
    }
    return $parsed;
  }

  /**
   * Builds the GET range form, carrying the active filters with it.
   */
  private function scheduleRangeForm(array $filters, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
    $hidden = '';
    foreach (['state', 'webform_id', 'resource_id', 'date'] as $name) {
      if ($filters[$name] !== '') {
        $hidden .= '<input type="hidden" name="' . $name . '" value="'
          . htmlspecialchars($filters[$name], ENT_QUOTES) . '" />';
      }
    }

    $action = Url::fromRoute('custom_webform_handlers.reservation_admin')->toString();

    return [
      '#markup' => '<form method="get" class="custom-reservation-schedule-range" action="'
      . htmlspecialchars($action, ENT_QUOTES) . '">'
      . $hidden
      . '<div class="form-item"><label for="reservation-schedule-from">' . $this->t('Schedule from') . '</label>'
      . '<input id="reservation-schedule-from" type="date" name="from" value="' . $from->format('Y-m-d') . '" /></div>'
      . '<div class="form-item"><label for="reservation-schedule-to">' . $this->t('Schedule to') . '</label>'
      . '<input id="reservation-schedule-to" type="date" name="to" value="' . $to->format('Y-m-d') . '" /></div>'
      . '<input type="submit" class="button" value="' . $this->t('Update range') . '" />'
      . '</form>',
      '#allowed_tags' => ['form', 'div', 'label', 'input'],
    ];
  }

  /**
   * Shows administrators the state of every offered time for one local day.
   *
   * The reservation table lists what has happened, newest first. This is the
   * complementary operational question — for this day, which of the configured
   * times can a visitor still take, and for the rest, what exactly is holding
   * them. Every configured time is listed whether or not a row exists for it, so
   * a quiet day reads as a full grid of Available rather than an empty table.
   *
   * Two seams answer it together, and their roles are not interchangeable:
   * availability() is authoritative for whether a Slot is takeable, because it
   * is the same read the booking path uses; the filtered record list only
   * explains why. Where they disagree, availability() wins and the explanation
   * is reported as unknown, so this screen can never invite an administrator to
   * hand out a slot the server would refuse.
   */
  private function availabilitySection(array $filters): array {
    $date = trim($filters['date']);
    if ($date === '') {
      return [
        '#type' => 'details',
        '#title' => $this->t('Daily slot availability'),
        '#open' => TRUE,
        'message' => [
          '#markup' => '<p>' . $this->t('Choose a local date above to see every configured time for that day as Available, Held, Confirmed, Blocked, Expired, or Released.') . '</p>',
        ],
      ];
    }

    $webformId = $filters['webform_id'] !== '' ? $filters['webform_id'] : self::DEFAULT_WEBFORM;
    try {
      $definition = $this->definitionProvider->definition($webformId, $filters['resource_id']);
      $day = $this->definitionProvider->day($definition, $date);
      $booked = array_flip($this->reservationManager->availability($definition, $day));
    }
    catch (ReservationException) {
      return [
        '#type' => 'details',
        '#title' => $this->t('Daily slot availability'),
        '#open' => TRUE,
        'message' => [
          '#markup' => '<p>' . $this->t('Availability cannot be displayed for the selected Webform, resource, and date. The date may be outside the booking window, on a closed weekday, or the Webform may not have a usable reservation configuration.') . '</p>',
        ],
      ];
    }

    // Scoped to this day, this Webform, and this Resource through the same
    // filtered-list seam the table pages over. The state filter is deliberately
    // NOT inherited: the day's picture needs every state, or filtering the list
    // to "confirmed" would make every other slot look Available.
    $records = $this->reservationManager->adminList([
      'webform_id' => $definition->webformId,
      'resource_id' => $definition->resourceId,
      'date' => $date,
    ], DatabaseReservationManager::ADMIN_LIMIT);

    $governing = [];
    foreach ($records as $record) {
      $time = $record->localTime();
      $incumbent = $governing[$time] ?? NULL;
      if ($incumbent === NULL || $this->outranks($record, $incumbent)) {
        $governing[$time] = $record;
      }
    }

    $rows = [];
    $availableCount = 0;
    foreach ($definition->timeOptions as $time) {
      $record = $governing[$time] ?? NULL;
      $isTaken = isset($booked[$time]);
      if (!$isTaken) {
        $availableCount++;
      }

      [$state, $label] = $this->slotStatus($record, $isTaken);

      $rows[] = [
        'class' => ['custom-reservation-slot-row--' . $state],
        'data' => [
          $time,
          [
            'data' => [
              '#markup' => '<span class="custom-reservation-admin-status custom-reservation-admin-status--'
              . $state . '">' . $label . '</span>',
            ],
          ],
          $isTaken ? $this->t('No') : $this->t('Yes'),
          // Only a live Hold has a meaningful expiry; a confirmed, blocked, or
          // historical row carries none.
          $record !== NULL && $record->expiresAt !== NULL && $isTaken
            ? $this->formatTimestamp($record->expiresAt)
            : $this->t('—'),
          $record === NULL
            ? $this->t('—')
            : ['data' => $this->recordReference($record)],
          $record === NULL || $record->submissionId === NULL
            ? $this->t('—')
            : $record->submissionId,
        ],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Daily slot availability for @date (@available of @total times open)', [
        '@date' => $date,
        '@available' => $availableCount,
        '@total' => count($definition->timeOptions),
      ]),
      '#open' => TRUE,
      'help' => [
        '#markup' => '<p>' . $this->t('Times shown in @timezone for Webform %webform, resource %resource. Confirmed reservations, administrator blocks, and unexpired visitor holds are the three states that stop a visitor booking. Expired and Released describe the last thing that happened to a time that is open again.', [
          '@timezone' => $definition->timezone,
          '%webform' => $definition->webformId,
          '%resource' => $definition->resourceId,
        ]) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Local time'),
          $this->t('State'),
          $this->t('Visitor can book'),
          $this->t('Hold expires'),
          $this->t('Reservation'),
          $this->t('Submission ID'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No bookable times are configured for this date.'),
      ],
    ];
  }

  /**
   * Resolves one Slot's displayed state from its governing record.
   *
   * The authoritative takeable flag leads, so the label can never contradict
   * what the booking path would do:
   * - taken, with a record: the record's own state is the reason.
   * - taken, with no record: something holds the key that this filtered day view
   *   did not return, so it is reported as booked without inventing a cause.
   * - open, with a held record: the Hold's window has closed and it has simply
   *   not been swept yet, which is Expired in everything but the stored label.
   * - open, with a historical record: the last thing that happened to the Slot.
   * - open, with no record: never touched today.
   *
   * @return array
   *   A machine state key for CSS, and the human label to print.
   */
  private function slotStatus(?ReservationRecord $record, bool $isTaken): array {
    if ($isTaken) {
      if ($record === NULL) {
        return ['booked', $this->t('Booked')];
      }
      return [$record->state, $this->stateLabel($record->state)];
    }

    if ($record === NULL) {
      return ['available', $this->t('Available')];
    }

    if ($record->state === DatabaseReservationManager::STATE_HELD) {
      return [
        DatabaseReservationManager::STATE_EXPIRED,
        $this->t('Expired (hold lapsed)'),
      ];
    }

    return [$record->state, $this->stateLabel($record->state)];
  }

  /**
   * Links one Slot's governing record to its detail screen.
   *
   * The reservation ID is a safe reference: it names a row without disclosing
   * the active slot key or either secret hash, none of which a ReservationRecord
   * can carry in the first place.
   */
  private function recordReference(ReservationRecord $record): array {
    return Link::createFromRoute(
      $this->t('#@id', ['@id' => $record->reservationId]),
      'custom_webform_handlers.reservation_admin_detail',
      ['reservation_id' => $record->reservationId],
    )->toRenderable();
  }

  /**
   * Tells whether one record describes a Slot's state better than another.
   *
   * A Slot accumulates history: an expired Hold, a release, then a confirmation
   * can all name the same time. The row that decides the display is the one
   * still holding the Slot, and among equals the most recent.
   */
  private function outranks(ReservationRecord $candidate, ReservationRecord $incumbent): bool {
    $rank = $this->stateRank($candidate->state) <=> $this->stateRank($incumbent->state);
    if ($rank !== 0) {
      return $rank > 0;
    }
    if ($candidate->changed !== $incumbent->changed) {
      return $candidate->changed > $incumbent->changed;
    }
    return $candidate->reservationId > $incumbent->reservationId;
  }

  /**
   * Orders states by how strongly they describe a Slot right now.
   */
  private function stateRank(string $state): int {
    return match ($state) {
      DatabaseReservationManager::STATE_CONFIRMED => 5,
      DatabaseReservationManager::STATE_BLOCKED => 4,
      DatabaseReservationManager::STATE_HELD => 3,
      DatabaseReservationManager::STATE_RELEASED => 2,
      DatabaseReservationManager::STATE_EXPIRED => 1,
      default => 0,
    };
  }

  /**
   * Lists completed submissions whose chosen Slot no reservation protects.
   *
   * These are submissions from before the reservation workflow was active. They
   * are reported, never converted: no Hold is invented, no confirmation is
   * fabricated, and where several submissions claim one Slot no winner is
   * chosen. The only offered action is the ordinary administrative block.
   */
  private function reconciliationSection(string $webformFilter): array {
    $webformId = $webformFilter !== '' ? $webformFilter : self::DEFAULT_WEBFORM;
    $candidates = $this->reconciliation->candidates($webformId);

    $rows = [];
    foreach ($candidates as $candidate) {
      $status = $candidate['status'] === ReservationReconciliation::STATUS_PROTECTED
        ? $this->t('Protected (@state)', ['@state' => $candidate['state']])
        : ($candidate['conflict_sids']
          ? $this->t('Unprotected — conflicts with @sids', ['@sids' => implode(', ', $candidate['conflict_sids'])])
          : $this->t('Unprotected'));

      $links = [];
      if ($candidate['status'] === ReservationReconciliation::STATUS_UNPROTECTED) {
        $links['protect'] = [
          'title' => $this->t('Protect this historical booking'),
          'url' => Url::fromRoute('custom_webform_handlers.reservation_admin_protect', [
            'webform' => $webformId,
            'sid' => $candidate['sid'],
          ]),
        ];
      }

      $rows[] = [
        'data' => [
          $candidate['sid'],
          $this->formatTimestamp($candidate['changed']),
          $candidate['date'],
          $candidate['time'],
          $candidate['timezone'],
          $candidate['resource'],
          $status,
          ['data' => ['#type' => 'operations', '#links' => $links]],
        ],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Unreserved completed submissions (@count)', ['@count' => count($candidates)]),
      '#open' => FALSE,
      '#weight' => 10,
      'help' => [
        '#markup' => '<p>' . $this->t('These completed submissions chose a date and time before reservations were enforced, so no reservation covers their slot and a new visitor can still book it. Blocking a slot stops future bookings; it does not create a reservation for the submission, link the block to it, or change it in any way. Slots that are already past, on a closed weekday, or no longer offered are omitted because no visitor can book them either.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Submission ID'),
          $this->t('Changed'),
          $this->t('Local date'),
          $this->t('Local time'),
          $this->t('Timezone'),
          $this->t('Resource'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No completed submission is claiming an unprotected slot.'),
      ],
    ];
  }

  /**
   * Shows one reservation's non-secret fields.
   */
  public function detail(int $reservation_id): array {
    $record = $this->reservationManager->adminRecord($reservation_id);
    if (!$record) {
      throw new NotFoundHttpException();
    }

    $rows = [
      [$this->t('Reservation ID'), $record->reservationId],
      [$this->t('Webform'), $record->webformId],
      [$this->t('Resource'), $record->resourceId],
      [$this->t('Local date'), $record->localDate()],
      [$this->t('Local time'), $record->localTime() . '–' . $record->localEndTime()],
      [$this->t('Timezone'), $record->timezone],
      [$this->t('State'), $this->stateLabel($record->state)],
      [
        $this->t('Hold expires'),
        $record->expiresAt === NULL
          ? $this->t('No expiry (confirmed, blocked, or already released)')
          : $this->formatTimestamp($record->expiresAt),
      ],
      [
        $this->t('Submission ID'),
        $record->submissionId === NULL ? $this->t('None') : $record->submissionId,
      ],
      [$this->t('Created'), $this->formatTimestamp($record->created)],
      [$this->t('Changed'), $this->formatTimestamp($record->changed)],
    ];

    $build = [];

    $build['record'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
    ];

    $links = [];
    if ($record->isActive()) {
      $links['release'] = Link::createFromRoute(
        $this->t('Release this slot'),
        'custom_webform_handlers.reservation_admin_release',
        ['reservation_id' => $record->reservationId],
      )->toRenderable() + ['#attributes' => ['class' => ['button']]];
    }
    $links['back'] = Link::createFromRoute(
      $this->t('Back to reservations'),
      'custom_webform_handlers.reservation_admin',
    )->toRenderable();

    $build['actions'] = ['#type' => 'container'] + $links;
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * Builds the plain GET filter form.
   *
   * It is a GET form on purpose: filtering is a read, so the resulting screen
   * stays linkable and bookmarkable.
   */
  private function filterForm(array $filters): array {
    $states = [
      '' => $this->t('- Any -'),
      DatabaseReservationManager::STATE_HELD => $this->t('Held'),
      DatabaseReservationManager::STATE_CONFIRMED => $this->t('Confirmed'),
      DatabaseReservationManager::STATE_BLOCKED => $this->t('Blocked'),
      DatabaseReservationManager::STATE_RELEASED => $this->t('Released'),
      DatabaseReservationManager::STATE_EXPIRED => $this->t('Expired'),
    ];

    $options = '';
    foreach ($states as $value => $label) {
      $options .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'
        . ($filters['state'] === (string) $value ? ' selected' : '') . '>'
        . htmlspecialchars((string) $label, ENT_QUOTES) . '</option>';
    }

    $text = function (string $name, string $label, string $value, string $type = 'text'): string {
      return '<div class="form-item">'
        . '<label for="reservation-filter-' . $name . '">' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
        . '<input id="reservation-filter-' . $name . '" type="' . $type . '" name="' . $name . '"'
        . ' value="' . htmlspecialchars($value, ENT_QUOTES) . '" />'
        . '</div>';
    };

    $action = Url::fromRoute('custom_webform_handlers.reservation_admin')->toString();

    return [
      '#markup' => '<form method="get" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'
      . '<div class="form-item">'
      . '<label for="reservation-filter-state">' . $this->t('State') . '</label>'
      . '<select id="reservation-filter-state" name="state">' . $options . '</select>'
      . '</div>'
      . $text('webform_id', (string) $this->t('Webform machine name'), $filters['webform_id'])
      . $text('resource_id', (string) $this->t('Resource'), $filters['resource_id'])
      . $text('date', (string) $this->t('Local date'), $filters['date'], 'date')
      . '<input type="submit" value="' . $this->t('Filter') . '" class="button" />'
      . '</form>',
      '#allowed_tags' => ['form', 'div', 'label', 'select', 'option', 'input'],
    ];
  }

  /**
   * Builds the operations for one record.
   */
  private function operations(ReservationRecord $record): array {
    $links = [
      'detail' => [
        'title' => $this->t('Details'),
        'url' => Url::fromRoute('custom_webform_handlers.reservation_admin_detail', [
          'reservation_id' => $record->reservationId,
        ]),
      ],
    ];

    // An expired or released row owns no active key, so there is nothing left to
    // give back and the operation would be a no-op that reads as an action.
    if ($record->isActive()) {
      $links['release'] = [
        'title' => $this->t('Release'),
        'url' => Url::fromRoute('custom_webform_handlers.reservation_admin_release', [
          'reservation_id' => $record->reservationId,
        ]),
      ];
    }

    return [
      '#type' => 'operations',
      '#links' => $links,
    ];
  }

  /**
   * Returns the human label for one stored state.
   */
  private function stateLabel(string $state): string {
    return match ($state) {
      DatabaseReservationManager::STATE_HELD => (string) $this->t('Held'),
      DatabaseReservationManager::STATE_CONFIRMED => (string) $this->t('Confirmed'),
      DatabaseReservationManager::STATE_BLOCKED => (string) $this->t('Blocked'),
      DatabaseReservationManager::STATE_RELEASED => (string) $this->t('Released'),
      DatabaseReservationManager::STATE_EXPIRED => (string) $this->t('Expired'),
      default => $state,
    };
  }

  private function formatTimestamp(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'short');
  }

}
