<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * Who one notification is written for.
 *
 * The distinction is a privacy boundary, not a formatting preference. A customer
 * audience may be told only about their own booking; a staff audience may be told
 * operational facts about the Resource and the queue. Composing one message and
 * sending it to both would be the single easiest way to leak one customer's arrival
 * window to another, so the audience is decided before any text exists and the
 * composer is given no way to widen it.
 *
 * There is no "everybody". A notification with two audiences is composed twice,
 * once per audience, and each copy is addressed from that audience's own recipient
 * setting.
 */
enum DispatchAudience: string {

  /**
   * The person who made the booking, about their own booking only.
   *
   * Never told which vehicle, never told about another job, never told a Hold token
   * or a submission of somebody else's. What they may learn is what they were
   * already shown at confirmation: when the vehicle is promised, the window it is
   * promised inside, and how the delivery runs.
   */
  case Customer = 'customer';

  /**
   * The operators who run the board.
   *
   * May be told the Resource, the reason a request needs a human, and the fact that
   * a Reservation moved or was released. Still not told a secret: staff mail is mail
   * and leaves the site, so a Hold token or an owner hash has no business in it even
   * for an audience already permitted to see the board.
   */
  case Staff = 'staff';

}
