<?php

namespace Drupal\custom_webform_handlers\Dispatch\Policy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\DeliveryMode;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequest;
use Drupal\custom_webform_handlers\Dispatch\EligibilityOutcome;
use Drupal\custom_webform_handlers\Dispatch\ServiceDuration;

/**
 * Derives on-site durations from configured rules.
 *
 * Two entirely separate rule sets, chosen by service class rather than by
 * anything about the addresses:
 *
 * - Courier is handling at each end. Small, predictable, and independent of what
 *   the building looks like.
 * - A move is built up from move size, stairs at each end, access conditions,
 *   parking, specialty items, and add-ons. It is never given Courier timings.
 *
 * Unmapped facts return NULL rather than contributing zero. The shipped
 * configuration leaves the access, parking, and add-on maps empty precisely
 * because their Webform machine values are not yet known: inventing keys here
 * would produce confident timings for facts nobody has priced, so instead those
 * requests go to staff until an operator maps them.
 */
final class ConfigServiceDurationPolicy implements ServiceDurationPolicyInterface {

  /**
   * The operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function durationFor(DispatchRequest $request, string $serviceClass): ?ServiceDuration {
    $config = $this->configFactory->get(self::SETTINGS);

    return match ($serviceClass) {
      EligibilityOutcome::CLASS_COURIER => $this->courierDuration($config, $request),
      EligibilityOutcome::CLASS_MOVE => $this->moveDuration($config, $request),
      // An unknown class has no rules, and guessing one would be worse than
      // sending the request to staff.
      default => NULL,
    };
  }

  /**
   * Express Courier: configured dwell at each end, by Delivery Mode.
   *
   * Store Pickup *replaces* the pickup dwell rather than adding to it, because it
   * is a different job at that end and not an extra step within the same one: the
   * driver queues at a counter and identifies a paid order instead of taking a
   * package from the customer's hands. Adding the two would bill both jobs for one
   * visit and quietly over-reserve the day.
   *
   * The buffer and promise are read here, courier-scoped, and carried on the
   * returned value rather than looked up again by the planner. Zero means "no
   * courier-specific figure", which is how an operator declines to override the
   * Resource Profile buffer or the global promise.
   */
  private function courierDuration($config, DispatchRequest $request): ?ServiceDuration {
    $pickup = $this->positiveInt($config->get('durations.courier.pickup_handling_minutes'));
    $delivery = $this->positiveInt($config->get('durations.courier.delivery_handling_minutes'));

    if ($pickup === NULL || $delivery === NULL) {
      return NULL;
    }

    if ($request->deliveryMode === DeliveryMode::StorePickup) {
      $storeDwell = $this->positiveInt($config->get('durations.courier.store_pickup_dwell_minutes'));
      if ($storeDwell !== NULL) {
        $pickup = $storeDwell;
      }
    }

    return new ServiceDuration(
      $pickup,
      $delivery,
      $this->positiveInt($config->get('durations.courier.operational_buffer_minutes')),
      $this->positiveInt($config->get('durations.courier.promise_minutes')),
    );
  }

  /**
   * A move: built from what is being moved and how it can be reached.
   */
  private function moveDuration($config, DispatchRequest $request): ?ServiceDuration {
    $sizeMap = $this->intMap($config->get('durations.move.move_size_minutes'));
    $moveSize = trim((string) $request->moveSize);
    if ($moveSize === '' || !array_key_exists($moveSize, $sizeMap)) {
      // The single largest determinant of a move's length. Without it there is
      // nothing to build on.
      return NULL;
    }

    $base = $sizeMap[$moveSize];
    $perFlight = $this->nonNegativeInt($config->get('durations.move.stairs_per_flight_minutes'));
    if ($perFlight === NULL) {
      return NULL;
    }

    $accessMap = $this->intMap($config->get('durations.move.access_minutes'));
    $parkingMap = $this->intMap($config->get('durations.move.parking_minutes'));
    $addOnMap = $this->intMap($config->get('durations.move.add_on_minutes'));
    $perSpecialty = $this->nonNegativeInt($config->get('durations.move.specialty_item_minutes'));
    if ($perSpecialty === NULL) {
      return NULL;
    }

    // Stairs. Absent is unknown, not a ground-floor property.
    $originFlights = $request->originStairsFlights;
    $destinationFlights = $request->destinationStairsFlights;
    if ($originFlights === NULL || $destinationFlights === NULL
      || $originFlights < 0 || $destinationFlights < 0) {
      return NULL;
    }

    // Access conditions at each end must both be mapped.
    $originAccess = $this->mappedMinutes($accessMap, $request->originAccess);
    $destinationAccess = $this->mappedMinutes($accessMap, $request->destinationAccess);
    if ($originAccess === NULL || $destinationAccess === NULL) {
      return NULL;
    }

    // Parking applies to the job as a whole and is split across both ends.
    $parking = $this->mappedMinutes($parkingMap, $request->parkingSituation);
    if ($parking === NULL) {
      return NULL;
    }

    // Specialty items. Unanswered is unknown; answered zero is fine.
    $specialtyCount = $request->specialtyItemCount;
    if ($specialtyCount === NULL || $specialtyCount < 0) {
      return NULL;
    }

    $addOnMinutes = 0;
    foreach ($request->addOns as $addOn) {
      $minutes = $this->mappedMinutes($addOnMap, (string) $addOn);
      if ($minutes === NULL) {
        // An add-on nobody has timed cannot be scheduled around.
        return NULL;
      }
      $addOnMinutes += $minutes;
    }

    // Pickup carries the load-out: the base move time, the origin's stairs and
    // access, half the parking overhead, and every specialty item, since the
    // difficult part of a specialty item is getting it out.
    $pickupMinutes = $base
      + ($originFlights * $perFlight)
      + $originAccess
      + (int) floor($parking / 2)
      + ($specialtyCount * $perSpecialty)
      + $addOnMinutes;

    // Delivery carries the load-in: the destination's stairs and access, and the
    // remainder of the parking overhead.
    $deliveryMinutes = ($destinationFlights * $perFlight)
      + $destinationAccess
      + (int) ceil($parking / 2);

    return new ServiceDuration($pickupMinutes, $deliveryMinutes);
  }

  /**
   * Returns configured minutes for one machine value, or NULL when unmapped.
   *
   * An empty value is unknown rather than zero: a question that was not answered
   * is not the same as an answer of "no time needed".
   */
  private function mappedMinutes(array $map, ?string $key): ?int {
    $key = trim((string) $key);
    if ($key === '' || !array_key_exists($key, $map)) {
      return NULL;
    }
    return $map[$key];
  }

  /**
   * Casts a configured map to integer minutes, dropping malformed entries.
   *
   * A malformed entry is dropped rather than coerced, so it reads as unmapped and
   * routes the request to staff.
   *
   * @return array<string, int>
   */
  private function intMap(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $map = [];
    foreach ($value as $key => $minutes) {
      $key = trim((string) $key);
      if ($key === '' || !is_numeric($minutes)) {
        continue;
      }
      $minutes = (int) $minutes;
      if ($minutes >= 0) {
        $map[$key] = $minutes;
      }
    }
    return $map;
  }

  /**
   * Returns a configured value that must be greater than zero, or NULL.
   */
  private function positiveInt(mixed $value): ?int {
    if (!is_numeric($value)) {
      return NULL;
    }
    $number = (int) $value;
    return $number > 0 ? $number : NULL;
  }

  /**
   * Returns a configured value that may be zero but not negative, or NULL.
   */
  private function nonNegativeInt(mixed $value): ?int {
    if (!is_numeric($value)) {
      return NULL;
    }
    $number = (int) $value;
    return $number >= 0 ? $number : NULL;
  }

}
