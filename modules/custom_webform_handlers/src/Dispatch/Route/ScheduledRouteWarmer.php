<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks the route provider, ahead of time, about the journeys one day is made of.
 *
 * A cache primer and nothing more. Every question this class asks, the planner would
 * ask for itself the moment someone opened that day — a Replan, a revalidation, an
 * administrative board that shows travel figures. Asking early moves the provider's
 * latency off a request a human is waiting on and onto a queue worker that nobody is,
 * and asking early is all it does: no answer is stored anywhere except the route
 * cache the provider already maintains, and no decision is taken on any answer.
 *
 * What it can warm, precisely. The legs of a day that are already fixed: depot to the
 * first pickup, each claim's own pickup to its delivery, the repositioning between one
 * claim's delivery and the next claim's pickup, and the return to depot when the
 * profile requires one. It cannot warm a future visitor's own journey, because that
 * journey's addresses do not exist until they type them. So this makes an existing
 * day cheaper to re-examine; it does not make a new booking cheaper to offer, and it
 * would be dishonest to describe it as speeding up availability.
 *
 * On reading other customers' addresses. Warming needs them, which is why this reads
 * planningNeighbours() — the planner's private seam, documented @internal because what
 * it returns is where other people live. This class is held to the same rule the
 * planner is: the addresses are used to form provider requests and are never returned,
 * never logged, never put in a report, and never written anywhere. RouteWarmReport
 * carries three integers for exactly that reason.
 *
 * Not an authority. It takes no lock, writes no planner table, and touches no
 * Reservation, Hold, attempt, order or submission. If this class never ran, every
 * booking decision on the site would be identical and slower.
 */
final class ScheduledRouteWarmer {

  public function __construct(
    private readonly ReservationStoreInterface $store,
    private readonly ResourceProfileFactory $profiles,
    private readonly DispatchRouteEstimatorInterface $estimator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Warms every fixed leg of one local calendar day for one Resource.
   *
   * @param string $resourceId
   *   The Resource whose day is warmed.
   * @param string $date
   *   The local Y-m-d date, read in the Resource's own timezone. A day is a local
   *   calendar day and not a fixed number of seconds, so the range is built by
   *   advancing one day from local midnight rather than by adding 86400 — on a
   *   transition night those differ by an hour, and the hour they differ by is one
   *   the Resource may well be working.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Route\RouteWarmReport|null
   *   The counts, or NULL when the request itself cannot be honoured: an unknown
   *   Resource, an unconfigured profile, or a date that is not a date. NULL means
   *   "this will never work", which a caller with retries should treat as permanent.
   *   A day with nothing scheduled is a report of zero legs, not NULL.
   */
  public function warm(string $resourceId, string $date): ?RouteWarmReport {
    $profile = $this->profiles->createForResource($resourceId);

    if ($profile === NULL) {
      // Not logged as an error. A Resource can be retired between the enqueue and the
      // run, and a warming pass that discovers the day belongs to nobody has found a
      // stale job rather than a broken site.
      return NULL;
    }

    $timezone = $profile->timezoneObject();
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

    // Rejected rather than corrected. createFromFormat is lenient enough to read
    // '2026-02-31' as the third of March, and silently warming a different day than
    // the one the payload named would make the counts unexplainable.
    if ($start === FALSE || $start->format('Y-m-d') !== $date) {
      return NULL;
    }

    $from = $start->getTimestamp();
    $to = $start->modify('+1 day')->getTimestamp();

    $legs = $this->legs($resourceId, $profile->depotAddress, $profile->returnToBase, $from, $to);

    $warmed = 0;
    $failed = 0;

    foreach ($legs as $leg) {
      [$origin, $destination, $departureAt] = $leg;

      try {
        $estimate = $this->estimator->estimate($origin, $destination, $departureAt);
      }
      catch (\Throwable $exception) {
        // The class only. An exception message from a routing provider can contain the
        // addresses that were sent to it, and this is the one place in the module where
        // three of another customer's locations are in scope at once.
        $this->logger->warning('A route warming leg for @resource on @date could not be measured: @type', [
          '@resource' => $resourceId,
          '@date' => $date,
          '@type' => get_class($exception),
        ]);

        $failed++;
        continue;
      }

      $estimate === NULL ? $failed++ : $warmed++;
    }

    return new RouteWarmReport(count($legs), $warmed, $failed);
  }

  /**
   * The distinct journeys a day's committed work consists of.
   *
   * Duplicates are collapsed on origin, destination and departure together, because a
   * pair asked about twice at the same hour is one provider request and would
   * otherwise be counted as two legs warmed. The departure instants are the ones the
   * planner itself would use — a claim's own window start for its outbound leg, the
   * previous claim's window end for the repositioning that precedes the next — so the
   * entries written here are the entries a later read finds.
   *
   * A leg with an empty address is skipped. planningNeighbours() returns a claim whose
   * stored address snapshot is missing with empty strings precisely so the planner
   * refuses it rather than routing around a gap; warming such a leg would send the
   * provider an empty origin and record a failure that means nothing.
   *
   * @return array<int, array{0: string, 1: string, 2: int}>
   *   Each entry is origin, destination, departure timestamp.
   */
  private function legs(string $resourceId, string $depot, bool $returnToBase, int $from, int $to): array {
    $neighbours = $this->store->planningNeighbours($resourceId, $from, $to);

    if ($neighbours === []) {
      return [];
    }

    $legs = [];
    $previous = NULL;

    foreach ($neighbours as $neighbour) {
      if ($previous === NULL) {
        // Leaving the depot for the first job of the day. Departure is that job's
        // window start rather than a computed departure, because the estimator
        // quantizes to a bucket and the bucket the planner will ask for is the one
        // derived from the arrival it is testing.
        $this->addLeg($legs, $depot, $neighbour->pickupLocation, $neighbour->windowStart);
      }
      else {
        $this->addLeg($legs, $previous->deliveryLocation, $neighbour->pickupLocation, $previous->windowEnd);
      }

      $this->addLeg($legs, $neighbour->pickupLocation, $neighbour->deliveryLocation, $neighbour->windowStart);

      $previous = $neighbour;
    }

    if ($returnToBase && $previous !== NULL) {
      $this->addLeg($legs, $previous->deliveryLocation, $depot, $previous->windowEnd);
    }

    return array_values($legs);
  }

  /**
   * Records one leg, unless it is unroutable or already recorded.
   */
  private function addLeg(array &$legs, string $origin, string $destination, int $departureAt): void {
    $origin = trim($origin);
    $destination = trim($destination);

    // An origin equal to its destination is a real occurrence — a claim collected and
    // delivered at the same address — and it is not a journey. Asking the provider
    // about it would spend a request to be told zero.
    if ($origin === '' || $destination === '' || $origin === $destination) {
      return;
    }

    $key = hash('sha256', $origin . "\0" . $destination . "\0" . $departureAt);
    $legs[$key] ??= [$origin, $destination, $departureAt];
  }

}
