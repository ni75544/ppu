<?php

namespace Drupal\custom_webform_handlers\Ranking;

/**
 * Reads the order in which Service Types should be shown, and nothing else.
 *
 * One method, taking the caller's own list and returning that same list rearranged.
 * The shape of the contract is the safety property: an implementation is handed the
 * services the form already offers and can only permute them, so it cannot hide a
 * service, invent one, or make one ineligible. Eligibility is settled by
 * self_bookable / manual_review in custom_webform_handlers.dispatch and by the planner
 * that reads them; popularity is a presentation concern and is deliberately given no
 * vocabulary for anything else.
 *
 * Never an authority. Nothing downstream of this may consult the returned order to
 * decide what a customer is allowed to book, what capacity exists, or what anything
 * costs. If every implementation returned its input unchanged for a year, the only
 * observable difference would be the order of cards on a page.
 *
 * An implementation must never throw and must never return a shorter list. Popularity
 * is derived from aggregate history that may be stale, missing, or unreachable, and
 * every one of those is an ordinary condition rather than a fault: the honest answer to
 * "which of these is most popular" when the data cannot be reached is "I cannot
 * improve on the order you gave me", which is the input, returned. That is why the
 * argument is the configured order rather than a flag the caller has to interpret —
 * the fallback is already in the caller's hands and needs no second list to be kept in
 * step with the form.
 */
interface ServicePopularityInterface {

  /**
   * Returns the given Service Type keys in the order they should be displayed.
   *
   * @param string[] $serviceIds
   *   Service Type machine values in their configured order, exactly as the form
   *   offers them. Order is meaningful: it is the deterministic fallback, and it is
   *   also the tie-breaker between services of equal popularity.
   *
   * @return string[]
   *   The same values, reordered. A permutation, always: same members, same count.
   */
  public function order(array $serviceIds): array;

}
