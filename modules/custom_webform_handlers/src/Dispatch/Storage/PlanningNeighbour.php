<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

/**
 * One live neighbouring claim, with the addresses the planner must route from.
 *
 * This is the planner's private view of another customer's claim, and it exists
 * for exactly one reason: repositioning cannot be proved without knowing where
 * the driver is coming from and where they must be next. OccupiedInterval
 * deliberately carries no address, because it is the availability read model that
 * reaches controllers, JSON, and JavaScript. This object never does.
 *
 * @internal
 *   Produced only by ReservationStoreInterface::planningNeighbours() and consumed
 *   only inside DispatchPlanner. It must not be returned from a controller,
 *   serialized into a response, attached to drupalSettings, or merged into
 *   OccupiedInterval. It contains another customer's pickup and delivery
 *   addresses, so any path that leaks it leaks their locations.
 */
final readonly class PlanningNeighbour {

  /**
   * @param int $windowStart
   *   Pickup arrival of the neighbouring claim, in UTC.
   * @param int $windowEnd
   *   The instant the neighbouring claim releases the Resource, in UTC.
   * @param string $pickupLocation
   *   Where the driver must be when this neighbour begins. Empty when the stored
   *   snapshot is missing, which the planner must treat as unroutable.
   * @param string $deliveryLocation
   *   Where the driver is when this neighbour ends. Empty when the stored
   *   snapshot is missing.
   */
  public function __construct(
    public int $windowStart,
    public int $windowEnd,
    public string $pickupLocation,
    public string $deliveryLocation,
  ) {}

}
