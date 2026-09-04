<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * One composed message, or an explicit decision not to send one.
 *
 * The two are the same class on purpose. Composing can legitimately end in "nothing
 * goes out" — no recipient configured for this audience, a submission that has since
 * been deleted, a booking with no contact address — and every one of those is a fact
 * the history should carry rather than a NULL for the worker to interpret. A skipped
 * message names its reason, so the board can say "not sent: no customer address" instead
 * of showing a gap.
 *
 * Plain text only, by design. There is no HTML body and no markup escaping to get
 * wrong, which removes the entire class of bug where a customer's own answer is
 * rendered into a message as though it were part of the template.
 *
 * The recipient lives on this object and nowhere else. It is used by the dispatcher,
 * handed to the mail system, and discarded when the object goes out of scope; it is
 * never written to the notification history. See NotificationRecord.
 */
final readonly class DispatchMessage {

  private function __construct(
    public DispatchAudience $audience,
    public string $recipient,
    public string $langcode,
    public string $subject,
    public string $body,
    public string $skipReason,
  ) {}

  /**
   * A message that should be sent.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Who it is written for.
   * @param string $recipient
   *   Where it goes. One address, or several separated by commas for a staff list.
   * @param string $langcode
   *   The language to render site strings in.
   * @param string $subject
   *   One line. Newlines are stripped: a subject containing a line break is how a
   *   header injection starts, and a template is editable by a site administrator.
   * @param string $body
   *   The message, already substituted.
   */
  public static function to(
    DispatchAudience $audience,
    string $recipient,
    string $langcode,
    string $subject,
    string $body,
  ): self {
    return new self(
      $audience,
      trim($recipient),
      trim($langcode),
      self::oneLine($subject),
      trim($body),
      '',
    );
  }

  /**
   * A deliberate decision not to send, carrying the reason for the history.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Who would have been written to.
   * @param string $reason
   *   A short operator-safe code, never a customer's data.
   */
  public static function skipped(DispatchAudience $audience, string $reason): self {
    return new self($audience, '', '', '', '', trim($reason));
  }

  /**
   * Tells whether this is a message rather than a decision not to send one.
   *
   * All three of recipient, subject and body are required. An empty template is a
   * misconfiguration, and mailing somebody a blank message is a worse answer to it than
   * recording that nothing was sent.
   */
  public function isSendable(): bool {
    return $this->recipient !== ''
      && $this->subject !== ''
      && $this->body !== '';
  }

  /**
   * Flattens a subject to a single line.
   *
   * Templates are administrator-editable text that ends up in a mail header, so the
   * newlines come out here rather than being trusted not to be there.
   */
  private static function oneLine(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

}
