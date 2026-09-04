<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One feasible candidate, with the working figures the offer does not publish.
 *
 * SlotOffer is what a customer may see: a pickup arrival, an arrival promise, a
 * Dispatch Window, an estimated delivery. Claiming that same candidate needs more
 * than that — the exact inbound and outbound Repositioning the planner proved, and
 * the routed service leg it measured — because those are the intervals the storage
 * seam locks and protects. Recomputing them at claim time from the offer alone
 * would be guessing at figures that were already known.
 *
 * So this pairs the public offer with the private arithmetic behind it. The public
 * contract stays narrow because callers still receive SlotOffer; this object never
 * leaves the planner.
 *
 * @internal
 *   Produced by DispatchPlanner for its own claim and revalidation paths. The
 *   repositioning seconds describe how the Resource's day fits together, which is
 *   schedule detail about other customers' work, so this must not be returned from
 *   a controller, serialized into a response, or attached to drupalSettings.
 */
final readonly class SlotPlan {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\SlotOffer $offer
   *   The publishable part: the Slot, its Dispatch Window, and its promises.
   * @param int $inboundRepositionSeconds
   *   Protected space before the Dispatch Window opens. Either the routed leg from
   *   the previous delivery plus the configured repositioning buffer, or the routed
   *   depot leg plus that buffer when this candidate is the day's first job.
   * @param int $outboundRepositionSeconds
   *   Protected space after the Dispatch Window closes. Either the routed leg to
   *   the next pickup plus the configured buffer, or the buffer alone when nothing
   *   follows on the day.
   * @param int $routeSeconds
   *   The measured pickup-to-delivery driving time, stored on the claim so an
   *   administrator can later see what the plan was built from.
   * @param int $routeMeters
   *   The measured pickup-to-delivery distance, kept for the same reason.
   */
  public function __construct(
    public SlotOffer $offer,
    public int $inboundRepositionSeconds,
    public int $outboundRepositionSeconds,
    public int $routeSeconds,
    public int $routeMeters,
  ) {}

}
