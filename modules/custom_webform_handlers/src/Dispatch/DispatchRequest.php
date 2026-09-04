<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The server-normalized operational facts for one potential job.
 *
 * Per CONTEXT.md a Dispatch Request can be quoted, offered Slots, manually
 * reviewed, held, or confirmed, but it is not itself a Reservation. It is a
 * value object and carries no behaviour that decides any of those outcomes.
 *
 * Two things are deliberately absent. There is no threshold, hour, duration, or
 * depot on this object: those belong to the Resource Profile and to module
 * configuration, so a request cannot carry its own idea of what is bookable.
 * And there is no price: quoting stays on the existing Google Routes v2 path
 * and is not part of dispatch planning.
 *
 * Machine values for $serviceType, $vehicleHelperNeeds, and $addOns are the ones
 * already persisted by the Webform. They are stored verbatim and never
 * reinterpreted here, so existing submissions stay readable.
 */
final readonly class DispatchRequest {

  /**
   * @param string $webformId
   *   The Webform the request originates from.
   * @param string $serviceType
   *   A persisted service_type machine value, for example on_demand_delivery.
   * @param string $pickupAddress
   *   Where the Resource must arrive to begin pickup work.
   * @param string $deliveryAddress
   *   Where the Resource must arrive to begin delivery work.
   * @param \Drupal\custom_webform_handlers\Dispatch\DeliveryMode|null $deliveryMode
   *   The Delivery Mode when one applies, otherwise NULL.
   * @param float|null $declaredWeightLbs
   *   The weight the customer declared, or NULL when none was declared. NULL is
   *   not zero: an undeclared weight is unknown, which the planner may treat as
   *   ineligible for self-service rather than as light.
   * @param bool $singleDriverHandling
   *   Whether the customer asserts one driver can safely handle the package
   *   unaided. An assertion only; the planner still applies its own limits.
   * @param string|null $vehicleHelperNeeds
   *   A persisted vehicle_helper_needs machine value when one was chosen.
   * @param string[] $addOns
   *   Persisted add-on machine values that apply to this request.
   * @param string[] $handlingFlags
   *   Normalised handling markers such as oversized or restricted, used to route
   *   work to Manual Review. The vocabulary lives in configuration.
   * @param string|null $moveSize
   *   A persisted move_size_residential machine value, or NULL when unknown.
   * @param int|null $originStairsFlights
   *   Flights of stairs at pickup, or NULL when not stated.
   * @param int|null $destinationStairsFlights
   *   Flights of stairs at delivery, or NULL when not stated.
   * @param string|null $originAccess
   *   A persisted origin access-condition machine value, or NULL when unknown.
   * @param string|null $destinationAccess
   *   A persisted destination access-condition machine value, or NULL when
   *   unknown.
   * @param string|null $parkingSituation
   *   A persisted parking_situation machine value, or NULL when unknown.
   * @param int|null $specialtyItemCount
   *   How many specialty items were declared. NULL means the question was not
   *   answered, which is not the same as answering zero.
   * @param bool|null $storePickupPaidAndReady
   *   For Store Pickup, whether the customer confirms the item is already paid
   *   for and waiting. NULL means unconfirmed, which is not a yes.
   * @param bool|null $contentsAttested
   *   Whether the customer has affirmed the package carries nothing prohibited.
   *   NULL means the question was not answered and FALSE means it was answered
   *   no; neither is an affirmation, and the eligibility rules treat both the
   *   same way. Separate from $handlingFlags on purpose: a flag refuses when a
   *   known-bad marker is present, while this refuses when an affirmation is
   *   absent, and silence cannot be expressed as a marker.
   */
  public function __construct(
    public string $webformId,
    public string $serviceType,
    public string $pickupAddress,
    public string $deliveryAddress,
    public ?DeliveryMode $deliveryMode = NULL,
    public ?float $declaredWeightLbs = NULL,
    public bool $singleDriverHandling = FALSE,
    public ?string $vehicleHelperNeeds = NULL,
    public array $addOns = [],
    public array $handlingFlags = [],
    public ?string $moveSize = NULL,
    public ?int $originStairsFlights = NULL,
    public ?int $destinationStairsFlights = NULL,
    public ?string $originAccess = NULL,
    public ?string $destinationAccess = NULL,
    public ?string $parkingSituation = NULL,
    public ?int $specialtyItemCount = NULL,
    public ?bool $storePickupPaidAndReady = NULL,
    public ?bool $contentsAttested = NULL,
  ) {}

  /**
   * Tells whether both endpoints are present.
   *
   * A Dispatch Window spans pickup work, the route, and delivery work, so a
   * request missing either endpoint cannot produce one.
   */
  public function hasBothEndpoints(): bool {
    return trim($this->pickupAddress) !== '' && trim($this->deliveryAddress) !== '';
  }

  /**
   * Tells whether a weight was declared at all.
   */
  public function hasDeclaredWeight(): bool {
    return $this->declaredWeightLbs !== NULL;
  }

}
