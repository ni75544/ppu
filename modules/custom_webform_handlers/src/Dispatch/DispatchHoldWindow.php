<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * How long a Dispatch Hold withholds capacity from everyone else.
 *
 * This exists as its own seam for the same reason ServiceDurationPolicyInterface
 * does: it answers an operational question, two callers need the same answer, and
 * the two must not be able to disagree. The planner reads it when it claims a Slot,
 * and the booking endpoints read it when they extend a Hold the visitor is still
 * working through. If those two read the figure independently, a configuration
 * change could leave renewals running on a lifetime claims no longer use.
 *
 * It is configuration rather than a PHP literal because held capacity is capacity
 * nobody else can book: the number trades one visitor's time to finish a form
 * against how long a Slot is off the market for everybody. An operator must be able
 * to retune that without a deployment.
 *
 * Unconfigured is NULL, never a plausible default. A planner that invents a hold
 * lifetime is deciding an operational policy on the operator's behalf, and the
 * failure is silent: capacity would be withheld for a duration nobody chose. So an
 * absent or non-positive figure refuses the claim outright and says NotConfigured.
 */
final class DispatchHoldWindow {

  /**
   * The dispatch operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the Hold lifetime in seconds, or NULL when it is not configured.
   *
   * @return int|null
   *   A positive number of seconds, or NULL — which every caller must read as "no
   *   Hold may be claimed or renewed", never as a cue to substitute a number.
   */
  public function seconds(): ?int {
    $seconds = $this->configFactory->get(self::SETTINGS)->get('holds.hold_seconds');
    if (!is_numeric($seconds)) {
      return NULL;
    }
    $seconds = (int) $seconds;

    return $seconds > 0 ? $seconds : NULL;
  }

}
