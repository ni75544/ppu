<?php

namespace Drupal\custom_webform_handlers\Dispatch\Policy;

use Drupal\custom_webform_handlers\Dispatch\DispatchRequest;
use Drupal\custom_webform_handlers\Dispatch\EligibilityOutcome;

/**
 * Decides whether a Dispatch Request may be self-booked at all.
 *
 * A seam because the rule varies: which services are open, what a courier may
 * weigh, which Delivery Modes are live, and what forces staff involvement are all
 * operator decisions that change without the planner changing.
 *
 * Internal to the planner. It is never exposed through DispatchPlannerInterface,
 * because a caller that could ask "am I eligible" separately from "what can I
 * book" would inevitably act on the first answer alone.
 */
interface DispatchEligibilityInterface {

  /**
   * Assesses one request against the configured self-service rules.
   */
  public function assess(DispatchRequest $request): EligibilityOutcome;

  /**
   * Assesses the same request on behalf of authorized staff.
   *
   * The staff answer differs from the self-service answer in one respect: work
   * that policy routes to Manual Review may be planned, because routing it there
   * means somebody should look at it and somebody has. Nothing else is relaxed.
   *
   * In particular a fact gap is never overridden. A request with no service type,
   * with only one endpoint, or arriving before any self-service policy has been
   * configured at all, has no service class — and without a class there is no
   * duration policy to consult, so there is no honest Dispatch Window to derive
   * for anybody. Those come back refused with the same reason staff would have
   * seen for a visitor, and the operator's remedy is to complete the facts or the
   * configuration, not to book around them.
   *
   * Implementations must derive this from the same configuration as assess(), and
   * must not consult permissions, the current user, or a request object: whether
   * this *particular* person may act is settled by route access before the
   * planner is reached, and duplicating that check here would create a second
   * place for it to disagree.
   */
  public function assessForStaff(DispatchRequest $request): EligibilityOutcome;

}
