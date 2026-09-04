<?php

namespace Drupal\custom_webform_handlers\Dispatch\Profile;

/**
 * Answers what one service type decides about being booked.
 *
 * One method, deliberately. Every caller in the booking path needs the same
 * thing — the rules for the service in front of it — and none of them needs to
 * know how those rules are stored, which configuration keys back them, or which
 * services exist. Adding a second method would let a caller reach past the
 * profile for a fact the profile is supposed to own, which is exactly the
 * duplication this seam removes.
 *
 * Total: resolve() always returns a profile. There is no NULL and no exception
 * for an unknown service type, because every caller would then have to invent
 * its own answer for that case, and their answers would differ. The
 * implementation is responsible for defaulting closed instead: an unrecognised
 * service resolves to Manual Review, which costs a booking channel rather than
 * publishing Slots for a job nobody can time.
 */
interface BookingProfileResolverInterface {

  /**
   * Resolves the booking profile for a service type.
   *
   * @param string $serviceType
   *   The service type element's value. May be '' when the visitor has not
   *   chosen one yet; the returned profile then carries the shared shell's
   *   canonical field names and a Manual Review mode.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfile
   *   Never NULL.
   */
  public function resolve(string $serviceType): BookingProfile;

}
