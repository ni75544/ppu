<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * What one delivery attempt achieved, in counts.
 *
 * Exists because the queue runtime has to make a decision the dispatcher cannot make
 * for it: whether to retry. Advanced Queue asks a job type for success or failure, and
 * "some audiences were written to and one refused" is neither of those until somebody
 * decides what it means. This carries the facts up to the job type, which decides.
 *
 * Counts and one short code, deliberately. This value is turned into the message
 * stored on the job row, which is visible on an administrative queue listing and in
 * logs — a wider audience than the mail itself had. So there is no recipient here, no
 * subject, no body, and no operator-entered detail text: an address that appeared in a
 * job message would outlive the item that carried it.
 *
 * "Suppressed" is counted apart from "skipped" because they mean opposite things about
 * the system's health. Skipped is a decision made about this event now — no recipient
 * is configured for that audience, or the submission has been deleted. Suppressed is
 * this event having already been announced, which is the idempotency guard working
 * exactly as intended, and a job whose every audience was suppressed is a clean replay
 * rather than a problem.
 */
final readonly class DeliveryReport {

  /**
   * @param int $sent
   *   Copies the mail transport accepted.
   * @param int $skipped
   *   Copies deliberately not sent, with a reason recorded in the history.
   * @param int $suppressed
   *   Copies not sent because this announcement had already been attempted.
   * @param int $failed
   *   Copies the transport refused, or that threw on the way to it.
   * @param string $failureDetail
   *   The first failure's operator-safe code, or an empty string. Never free text.
   */
  public function __construct(
    public int $sent = 0,
    public int $skipped = 0,
    public int $suppressed = 0,
    public int $failed = 0,
    public string $failureDetail = '',
  ) {}

  /**
   * How many audiences were considered.
   */
  public function attempted(): int {
    return $this->sent + $this->skipped + $this->suppressed + $this->failed;
  }

  /**
   * Whether every audience reached a settled outcome, with none failing.
   *
   * A settled outcome includes skipped and suppressed. Neither needs retrying: the
   * first was a decision and the second already happened.
   */
  public function isComplete(): bool {
    return $this->failed === 0;
  }

  /**
   * Whether this attempt did nothing because the work was already done.
   *
   * The signature of a replay. Used by the job type to report a no-op honestly rather
   * than claiming it sent something.
   */
  public function isReplay(): bool {
    return $this->suppressed > 0 && $this->sent === 0 && $this->failed === 0;
  }

  /**
   * One line for the job row, containing counts and no content.
   */
  public function summary(): string {
    return sprintf(
      'sent %d, skipped %d, suppressed %d, failed %d%s',
      $this->sent,
      $this->skipped,
      $this->suppressed,
      $this->failed,
      $this->failureDetail === '' ? '' : ' (' . $this->failureDetail . ')',
    );
  }

}
