<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * What a booking endpoint may tell a browser, and nothing more.
 *
 * The dispatch value objects are deliberately unsafe to publish. A Hold carries its
 * bearer token, a SlotOffer carries the Dispatch Window that describes how the
 * Resource's day is shaped, and PlanningNeighbour carries other customers'
 * addresses. Handing any of them to a controller would make "do not expose secrets"
 * a matter of the controller remembering which fields to skip, on every endpoint,
 * forever.
 *
 * So the conversion happens once, in DispatchBooking, and this is what comes out:
 * a flag, a stable machine reason, and an array that is already safe to serialise.
 * A controller cannot leak a token here because it is never given one.
 *
 * The reason is a DispatchReason rather than a message, because the caller
 * translating "slot_unavailable" into words is a display decision and the planner
 * has no business making it.
 */
final readonly class BookingOutcome {

  /**
   * @param bool $ok
   *   Whether the operation did what was asked.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise the stable refusal reason.
   * @param array $data
   *   Publishable payload. Every value here has been chosen for publication; it
   *   is never a filtered view of an internal object.
   */
  private function __construct(
    public bool $ok,
    public DispatchReason $reason,
    public array $data,
  ) {}

  /**
   * The operation succeeded, and this is what the browser may know about it.
   */
  public static function success(array $data = []): self {
    return new self(TRUE, DispatchReason::Available, $data);
  }

  /**
   * The operation was refused, and this is the stable reason.
   */
  public static function refused(DispatchReason $reason): self {
    return new self(FALSE, $reason, []);
  }

}
