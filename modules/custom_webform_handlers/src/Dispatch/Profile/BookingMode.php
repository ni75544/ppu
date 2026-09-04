<?php

namespace Drupal\custom_webform_handlers\Dispatch\Profile;

/**
 * How a service may be booked: by the visitor, or only by a person.
 *
 * The two values are the whole vocabulary. There is deliberately no third
 * "maybe" case: a service the module cannot time and cannot cost against a
 * resource is Manual Review, and saying so is a truthful answer rather than a
 * missing one. Every branch that used to read
 * `self_bookable.service_types` / `manual_review.service_types` for itself now
 * reads this off a BookingProfile instead.
 */
enum BookingMode: string {

  /**
   * Duration and capacity are both deterministic, so Slots may be offered.
   */
  case SelfBookable = 'self_bookable';

  /**
   * Duration or capacity cannot be computed, so no Slot may ever be offered.
   */
  case ManualReview = 'manual_review';

  /**
   * Reads a configured value, defaulting closed.
   *
   * An unrecognised or absent mode resolves to Manual Review, never to
   * self-bookable. A typo in configuration must cost a booking channel, not
   * publish Slots for a job nobody can time.
   */
  public static function fromConfig(mixed $value): self {
    return self::tryFrom(trim((string) (is_scalar($value) ? $value : ''))) ?? self::ManualReview;
  }

}
