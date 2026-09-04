<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * One Review Request: the staff lifecycle of a job that could not be auto-priced.
 *
 * Immutable, and rebuilt from the row rather than mutated, for the same reason
 * every other value object in this module is: a request that is read, changed in
 * memory, and written back is a request two requests can disagree about. Every
 * move is a compare-and-set in the store, and what comes back is a new object.
 *
 * What it deliberately does not carry:
 *
 * - The lookup credential, raw or hashed. The raw code is shown to its customer
 *   once, at issue, and never stored; the hash is a column the store matches on and
 *   is never lifted into an object that a controller, a form, or a template could
 *   render. So there is no object in this module from which a tracking code can be
 *   recovered or replayed.
 * - Any contact detail, address, or route. Those live on the Webform submission,
 *   behind Webform's own access control and behind this module's separate contact
 *   and address permissions. Copying them here would create a second store of
 *   personal data with no access control of its own.
 * - Any capacity fact. It names the Dispatch row only once the paid flow has
 *   already confirmed one, and that identifier is a pointer written after the
 *   guard transaction committed — never a claim this object makes about a driver's
 *   day.
 *
 * The staff message is the one piece of free text, and it is the one piece the
 * customer sees. It is written by staff on the decision forms, is plain text, and
 * is rendered through #plain_text everywhere it appears, so it is safe to show
 * without being trusted.
 */
final class ReviewRequest {

  /**
   * The longest staff message that will be stored, in characters.
   *
   * Bounded because it is shown to a customer on a page with no other content of
   * theirs, and an unbounded field on a customer-facing page is an invitation to
   * paste something that does not belong there.
   */
  const MESSAGE_MAX = 1000;

  private function __construct(
    public readonly int $reviewId,
    public readonly string $webformId,
    public readonly int $submissionId,
    public readonly ReviewState $state,
    public readonly ?int $orderId,
    public readonly ?int $dispatchReservationId,
    public readonly ?int $quoteAmountCents,
    public readonly ?string $quoteCurrency,
    public readonly string $staffMessage,
    public readonly int $actorUid,
    public readonly string $actorName,
    public readonly int $created,
    public readonly int $changed,
    public readonly ?int $decidedAt,
    public readonly ?int $expiresAt,
  ) {}

  /**
   * Builds the request a newly received job opens with.
   *
   * Always Submitted, always unpriced, always unlinked to an order. There is no
   * argument by which a caller can open a request in some other state: a request
   * that arrived already approved would be an offer nobody made.
   *
   * @throws \InvalidArgumentException
   *   When the job it names is not a job — no Webform, or no saved submission.
   *   Thrown rather than returning NULL because the caller is the manager, which
   *   has the submission entity in hand and cannot be holding a malformed one by
   *   accident.
   */
  public static function open(string $webformId, int $submissionId, int $now, ?int $expiresAt): self {
    $webformId = trim($webformId);
    if ($webformId === '' || $submissionId < 1) {
      throw new \InvalidArgumentException('A Review Request must name a Webform and a saved submission.');
    }

    return new self(
      0,
      $webformId,
      $submissionId,
      ReviewState::Submitted,
      NULL,
      NULL,
      NULL,
      NULL,
      '',
      0,
      '',
      $now,
      $now,
      NULL,
      $expiresAt,
    );
  }

  /**
   * Rebuilds one stored row.
   *
   * The state has already been resolved by the store, which refuses a row whose
   * state string nothing recognises rather than passing a guess in here.
   */
  public static function stored(
    int $reviewId,
    string $webformId,
    int $submissionId,
    ReviewState $state,
    ?int $orderId,
    ?int $dispatchReservationId,
    ?int $quoteAmountCents,
    ?string $quoteCurrency,
    string $staffMessage,
    int $actorUid,
    string $actorName,
    int $created,
    int $changed,
    ?int $decidedAt,
    ?int $expiresAt,
  ): self {
    return new self(
      $reviewId,
      $webformId,
      $submissionId,
      $state,
      $orderId !== NULL && $orderId > 0 ? $orderId : NULL,
      $dispatchReservationId !== NULL && $dispatchReservationId > 0 ? $dispatchReservationId : NULL,
      $quoteAmountCents !== NULL && $quoteAmountCents >= 0 ? $quoteAmountCents : NULL,
      self::normalizeCurrency($quoteCurrency),
      self::normalizeMessage($staffMessage),
      max(0, $actorUid),
      trim($actorName),
      $created,
      $changed,
      $decidedAt !== NULL && $decidedAt > 0 ? $decidedAt : NULL,
      $expiresAt !== NULL && $expiresAt > 0 ? $expiresAt : NULL,
    );
  }

  /**
   * Whether an authoritative quote has been recorded against this request.
   *
   * Both halves are required. An amount with no currency is not a price, and a
   * currency with no amount is not one either, so a partially written offer answers
   * FALSE and is never shown to a customer as something payable.
   */
  public function hasQuote(): bool {
    return $this->quoteAmountCents !== NULL && $this->quoteCurrency !== NULL;
  }

  /**
   * Whether this request has an offer a customer could act on now.
   *
   * Approved and priced. This is the single question the tracker asks before it
   * offers a payment action, so a request that is approved but somehow unpriced
   * shows its status and no button, rather than a link to pay an unknown amount.
   */
  public function isPayable(): bool {
    return $this->state === ReviewState::Approved && $this->hasQuote();
  }

  /**
   * Whether this request's deadline has passed as at the given instant.
   *
   * FALSE when no deadline was set, which is how a site that does not want requests
   * to lapse configures that: a request with no expiry is never swept.
   */
  public function hasLapsed(int $now): bool {
    return $this->expiresAt !== NULL && $this->expiresAt <= $now;
  }

  /**
   * Bounds a staff message to what will be stored and shown.
   *
   * Control characters are stripped rather than escaped, because this text is
   * rendered as plain text into a customer-facing page and a stray newline run or
   * an embedded NUL has no meaning there. Tabs and newlines survive as spaces so a
   * message typed as a short paragraph still reads as one.
   */
  public static function normalizeMessage(?string $message): string {
    if ($message === NULL) {
      return '';
    }

    $message = preg_replace('/[\r\n\t]+/u', ' ', $message) ?? '';
    $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message) ?? '';
    $message = trim(preg_replace('/\s{2,}/u', ' ', $message) ?? '');

    return mb_substr($message, 0, self::MESSAGE_MAX);
  }

  /**
   * Bounds a currency code, or NULL when it is not one.
   *
   * Three ASCII letters, upper-cased. Anything else is not a currency this module
   * will quote in, and NULL keeps hasQuote() honest about it.
   */
  public static function normalizeCurrency(?string $currency): ?string {
    if ($currency === NULL) {
      return NULL;
    }

    $currency = strtoupper(trim($currency));

    return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : NULL;
  }

}
