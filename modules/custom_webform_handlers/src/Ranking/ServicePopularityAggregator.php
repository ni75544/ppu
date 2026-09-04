<?php

namespace Drupal\custom_webform_handlers\Ranking;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\custom_webform_handlers\Checkout\CheckoutState;
use Psr\Log\LoggerInterface;

/**
 * Counts how often each Service Type was actually bought, once per night.
 *
 * One grouped query, and it returns counts. That is not an optimisation: the query is
 * written so that no row of customer data can leave the database in the first place.
 * There is no path here that loads a submission, a profile, or an order entity, so
 * there is nothing to accidentally log, cache, or carry into a queue payload — the
 * aggregate is produced by the database and the aggregate is all that exists in PHP.
 *
 * What counts as a booking is the Checkout Attempt reaching a state that carries a
 * Reservation. That is the module's own record of a paid Commerce order: settlePaid()
 * writes it only after the Resource Calendar Guard transaction has committed a
 * Reservation and the gateway has been asked for the money, so a counted row is a
 * booking that exists and was charged for. It is also the only place the two facts meet
 * — the order knows the money and the submission knows the service, and the attempt row
 * is what links them.
 *
 * ReconciliationRequired is counted alongside Paid. The customer chose that service and
 * holds a confirmed Reservation for it; what is unresolved is the capture, which is a
 * money question an operator settles on the order. Excluding it would make a
 * presentation ranking quietly sensitive to gateway reliability, which is not something
 * popularity should be able to express.
 *
 * Nothing here is an authority. It reads history that other transactions committed and
 * writes one derived value; it takes no lock, decides no capacity, and never writes to
 * a planner table, a Reservation, an attempt, an order, or a submission.
 */
final class ServicePopularityAggregator {

  /**
   * The dispatch operating profile, which names the element holding the service.
   */
  const DISPATCH_SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * The ranking settings, which hold the rolling window.
   */
  const SETTINGS = 'custom_webform_handlers.ranking';

  /**
   * The element key used when the operating profile names none.
   */
  const DEFAULT_ELEMENT = 'service_type';

  /**
   * The default rolling window in days.
   *
   * A quarter. Long enough that one slow fortnight cannot rearrange the page, short
   * enough that last year's mix does not outvote this season's.
   */
  const DEFAULT_WINDOW_DAYS = 90;

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly StateServicePopularityStore $store,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Recomputes the snapshot from settled bookings and stores it.
   *
   * @return \Drupal\custom_webform_handlers\Ranking\ServicePopularitySnapshot
   *   The snapshot that was stored. Empty counts are a real and expected result — a
   *   site with no paid dispatch bookings yet produces one every night, and it is
   *   written rather than skipped so that "the job ran and found nothing" is
   *   distinguishable from "the job has never run".
   *
   * @throws \Exception
   *   Thrown when the database cannot be read or the snapshot cannot be stored. Left to
   *   propagate deliberately: the caller is a queue job type, and a database that is
   *   briefly unreachable is exactly the condition a bounded retry exists for. Writing
   *   an empty snapshot instead would be worse than failing, because it would overwrite
   *   good counts with a fabricated zero and read as ordinary output.
   */
  public function recompute(): ServicePopularitySnapshot {
    $windowDays = $this->windowDays();
    $computedAt = $this->time->getRequestTime();
    $counts = $this->countBookings($computedAt - ($windowDays * 86400));

    $snapshot = new ServicePopularitySnapshot($counts, $computedAt, $windowDays);
    $this->store->write($snapshot);

    // Counts only, and they are already aggregates of at least one booking each. Logged
    // because the nightly run is otherwise invisible, and an operator investigating a
    // page whose cards did not move needs to know whether the job ran and found too
    // little, or did not run at all.
    $this->logger->info('Service popularity recomputed over @days days: @bookings bookings across @services services.', [
      '@days' => $windowDays,
      '@bookings' => $snapshot->totalBookings(),
      '@services' => count($counts),
    ]);

    return $snapshot;
  }

  /**
   * Returns bookings per Service Type since the given timestamp.
   *
   * Joined on the Checkout Attempt's own webform, so each answer is read from the form
   * that produced that booking rather than from whichever form happens to use the same
   * element key. Restricted to the scalar row a radio group writes — property empty,
   * delta zero — so a composite element could never contribute two rows for one
   * booking. COUNT(DISTINCT) makes that structural rather than a matter of trust.
   *
   * An attempt with no settlement timestamp is excluded by the comparison itself, which
   * is correct: an attempt that has not settled has not been bought.
   */
  private function countBookings(int $since): array {
    $settled = [
      CheckoutState::Paid->value,
      CheckoutState::ReconciliationRequired->value,
    ];

    $select = $this->database->select('custom_webform_handlers_checkout_attempt', 'a');
    $select->innerJoin('webform_submission_data', 'd', 'd.sid = a.submission_id AND d.webform_id = a.webform_id');
    $select->condition('a.state', $settled, 'IN');
    $select->condition('a.settled', $since, '>=');
    $select->condition('d.name', $this->serviceElement());
    $select->condition('d.property', '');
    $select->condition('d.delta', 0);
    $select->addField('d', 'value', 'service');
    $select->addExpression('COUNT(DISTINCT a.attempt_id)', 'bookings');
    $select->groupBy('d.value');

    $counts = [];
    foreach ($select->execute() as $row) {
      $service = trim((string) $row->service);
      $bookings = (int) $row->bookings;

      // A blank answer is not a service. It can only arise from a booking whose service
      // element was cleared after the fact, and counting it would put an unnamed rank
      // into a list of machine values that no card matches.
      if ($service !== '' && $bookings > 0) {
        $counts[$service] = $bookings;
      }
    }

    return $counts;
  }

  /**
   * The Webform element key that holds the chosen Service Type.
   *
   * Read from the operating profile rather than hardcoded, because that file is
   * documented as the only place the element map lives. The fallback matches the
   * shipped value, so a profile with the key removed still counts the right column
   * instead of counting nothing silently.
   */
  private function serviceElement(): string {
    $configured = $this->configFactory
      ->get(self::DISPATCH_SETTINGS)
      ->get('request_fields.service_type');

    return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_ELEMENT;
  }

  /**
   * The rolling window in days, clamped to something a ranking can mean.
   *
   * Clamped rather than trusted. A window of zero would count nothing and quietly
   * disable the feature while appearing configured; a window of a decade would rank
   * this year's page by services the business may no longer run.
   */
  private function windowDays(): int {
    $configured = (int) ($this->configFactory
      ->get(self::SETTINGS)
      ->get('window_days') ?: self::DEFAULT_WINDOW_DAYS);

    return max(7, min(730, $configured));
  }

}
