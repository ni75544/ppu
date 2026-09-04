<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * The mutual exclusion that stops one announcement being sent twice.
 *
 * A separate seam from NotificationLogInterface, and separate for a reason worth
 * stating. The log is a history: it answers "what did the system try, and when", it
 * is insert-only, and every row in it describes something that already happened.
 * This answers a different question, asked before anything happens — "am I the first
 * to attempt this?" — and the answer has to be authoritative even when two workers
 * ask at the same instant.
 *
 * That is a uniqueness invariant, so it is a unique index and not a check in PHP.
 * The same reasoning the Checkout Attempt table is built on: two payment
 * notifications arriving together make a read-then-write in application code wrong,
 * and no amount of care in the calling method fixes it.
 *
 * Why this exists at all. Advanced Queue's fingerprint already collapses a duplicate
 * *enqueue*, but it cannot help with a *replay*: a job that was leased, sent its
 * mail, and then died before reporting success is released back to the queue by
 * cleanupQueue() and processed again. Without a claim the customer receives a second
 * confirmation. With one, the second attempt finds the first attempt's claim and
 * records that it sent nothing.
 *
 * Granularity is (key, audience) rather than key alone, because DispatchAudience is a
 * privacy boundary: a customer copy that was accepted and a staff copy that was
 * refused are two independent facts, and retrying the staff copy must not be blocked
 * by the customer copy having succeeded.
 *
 * A claim is taken immediately before the transport call and released again when the
 * transport provably did not take the message. Holding it through a refusal would be
 * simpler to describe but wrong in practice: it would turn every transient mail outage
 * into a permanent loss, because the retry Advanced Queue is configured to perform
 * would find the failed attempt's own claim and send nothing. Releasing is therefore
 * not a weakening of the guard — the guard covers messages that were handed over, and
 * a refused message was not.
 *
 * Nothing here decides capacity, price, payment, or availability. It decides whether
 * a message has already been attempted, and that is the whole of its authority.
 */
interface NotificationClaimInterface {

  /**
   * Takes the exclusive right to attempt one message, if it is still available.
   *
   * @param string $key
   *   The payload-derived key. See DispatchNotification::idempotencyKey().
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Which copy is being attempted.
   *
   * @return bool
   *   TRUE when the caller now owns the attempt and should proceed. FALSE when the
   *   attempt was already claimed, which means the message was already handed to a
   *   mail server and must not be handed to it again.
   *
   * @throws \Throwable
   *   Thrown when the claim could not be established either way — a lost
   *   connection, a missing table, a deadlock. A unique-key violation is not this
   *   case: that is a decisive FALSE. This method deliberately does throw, unlike
   *   NotificationLogInterface::record() — the two have opposite failure duties. A
   *   history write that failed must not stop a delivered message being marked
   *   delivered, so it is swallowed. A claim that failed leaves the caller unable to
   *   tell whether it is first, and guessing in either direction is wrong: guessing
   *   "first" duplicates the message, guessing "not first" loses it. The correct
   *   response is to let the job fail and be retried, because on the retry the claim
   *   is decisive again.
   */
  public function claim(string $key, DispatchAudience $audience): bool;

  /**
   * Gives back a claim whose message the transport did not take.
   *
   * The only legitimate caller is the send path itself, on the branch where the mail
   * transport threw or refused, and it must be called before the failure is recorded
   * so that a crash in between leaves no claim rather than an unreleasable one. Do not
   * call this to "reset" a message an operator wants sent again: the claim is what
   * proves the first copy went out, and discarding it re-enables a duplicate.
   *
   * Silent about whether anything was removed. A release that finds no row is not an
   * error — a concurrent worker may have pruned it — and the caller has no decision to
   * make either way, because it has already established that the message was not sent.
   *
   * @param string $key
   *   The payload-derived key the matching self::claim() call used.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Which copy was refused.
   */
  public function release(string $key, DispatchAudience $audience): void;

  /**
   * Whether an attempt has already been claimed, without claiming it.
   *
   * A read for operators and for the runbook's inspection steps. Never use it to
   * decide whether to send: between the read and the send another worker can claim,
   * which is precisely the race self::claim() exists to lose safely.
   *
   * Its real value is forensic. A worker that hands a message to the transport and
   * dies before writing history leaves a claim and no record, so this is the only
   * evidence that the copy went out — which is why the runbook consults it before
   * anyone considers replaying a notification that appears to be missing.
   */
  public function claimed(string $key, DispatchAudience $audience): bool;

  /**
   * Discards claims recorded before one instant, and reports how many.
   *
   * Retention only, and never automatic. Nothing calls this on cron: forgetting a
   * claim re-enables the send it was suppressing, so the decision to forget belongs
   * to an operator who knows the queue no longer holds a job that would act on it.
   * The runbook documents the safe window.
   *
   * @param int $before
   *   Unix timestamp. Claims recorded strictly before this are discarded.
   *
   * @return int
   *   How many rows were discarded.
   */
  public function prune(int $before): int;

}
