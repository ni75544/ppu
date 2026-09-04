<?php

namespace Drupal\custom_webform_handlers\Checkout;

/**
 * Who Commerce should address about one order.
 *
 * Deliberately thin, and deliberately not stored by this module. Commerce already
 * owns customer identity on an order — the mail base field, the customer
 * reference, and the billing profile — and duplicating any of it into a dispatch
 * table would create a second copy that can disagree with the first. So this
 * value object exists only to carry a contact from the caller to Commerce's own
 * fields, and the Checkout Attempt row it passes through records none of it.
 *
 * The Webform submission remains the operational record of who the customer is
 * and where the driver is going. This is the commerce recipient, nothing more.
 */
final readonly class CheckoutContact {

  /**
   * @param string $email
   *   A syntactically valid address, lower-cased and trimmed.
   * @param string $name
   *   An optional display name, or the empty string when none was given.
   */
  private function __construct(
    public string $email,
    public string $name,
  ) {}

  /**
   * Builds a contact, or NULL when the address is not one.
   *
   * NULL rather than an exception, and rather than an order with no recipient:
   * Commerce sends a receipt to this address and a payment gateway may need it,
   * so an unusable address is a refused checkout, not a checkout that quietly
   * cannot be replied to.
   */
  public static function create(string $email, string $name = ''): ?self {
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
      return NULL;
    }

    // Collapsed and bounded rather than sanitised for output: this reaches a
    // Commerce field, and escaping is the renderer's job at the point of display.
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

    return new self($email, mb_substr($name, 0, 255));
  }

}
