<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Quote\QuoteResult;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\custom_webform_handlers\Review\ReviewState;

/**
 * The one amount a Checkout Attempt was started against, locked.
 *
 * Per CONTEXT.md the browser may display a price but never sets one, and this
 * class is how that is made structural rather than remembered. There is no public
 * constructor: an amount can only enter this object from the server's own
 * QuoteResult, from a Review Request a member of staff approved, or back out of a
 * row this module wrote earlier. Every one of those is a value object this module
 * built server-side. A controller holding a number a visitor posted has nothing it
 * can call.
 *
 * Locked means locked in both directions. The figure is copied into the Commerce
 * order item as an overridden unit price, so Commerce's own order refresh leaves
 * it alone, and it is copied into the attempt row, so what the customer agreed to
 * is still legible after a rate change. A Replan already copies the Dispatch
 * row's price snapshot forward verbatim for the same reason: moving the van does
 * not requote the job.
 *
 * Minor units are the stored form because that is what the existing quote path
 * produces and what an integer column can hold exactly. The decimal string is
 * derived on demand for Commerce, which prices in decimal strings.
 */
final readonly class QuoteSnapshot {

  /**
   * The quote status that means staff must price the job, not the server.
   *
   * A Manual Review job consumes no capacity and takes no payment, so it may not
   * reach an order at all. Named here rather than compared inline because the
   * refusal below is the only thing standing between a reviewed job and a charge.
   */
  const MANUAL_REVIEW_STATUS = 'manual_review';

  /**
   * @param string $snapshotId
   *   A stable identifier for the priced figure, derived from the figure itself.
   *   It names what was quoted, not when: two attempts quoted identically share
   *   it, and the attempt's own idempotency key is what distinguishes them.
   * @param int $amountCents
   *   The authoritative amount in minor units. Always positive.
   * @param string $currency
   *   An upper-case ISO 4217 alphabetic code.
   * @param string $rateVersion
   *   Which rate table produced the figure, carried for audit.
   */
  private function __construct(
    public string $snapshotId,
    public int $amountCents,
    public string $currency,
    public string $rateVersion,
  ) {}

  /**
   * Locks the server's own quote, refusing anything that is not chargeable.
   *
   * Returns NULL rather than throwing, because every refusal here is a normal
   * operational outcome the caller must convert into a refused checkout: a
   * reviewed job, a job priced at nothing, or a currency the site does not use.
   * None of them is a programming error, and none of them may become an order.
   */
  public static function fromQuoteResult(QuoteResult $quote): ?self {
    if ($quote->status() === self::MANUAL_REVIEW_STATUS) {
      return NULL;
    }

    return self::build($quote->totalCents(), $quote->currency(), $quote->rateVersion());
  }

  /**
   * Locks the figure a member of staff approved on a Review Request.
   *
   * The other door into this class, and it is the same shape of door: an amount
   * still cannot arrive from a request. fromQuoteResult() takes the server's own
   * QuoteResult; this takes a ReviewRequest, which only ReviewRequestStore builds
   * and only from a row a staff form wrote. A controller holding a number a
   * visitor posted has nothing here it can call either — passing ints was the
   * obvious signature and is exactly the one that would have given it something.
   *
   * A reviewed job is refused by fromQuoteResult() because at quote time nobody
   * had priced it. Once somebody has, the refusal has served its purpose, and the
   * approved figure is as authoritative as a rate table's — more so, since a human
   * looked at the job. So rateVersion names the review rather than a rate table:
   * an auditor reading the order item learns which decision produced the price.
   *
   * Only an approved request qualifies. Under review is unpriced, declined and
   * cancelled were refused, expired lapsed, and scheduled is already paid — none
   * may open a second payment. Whether an approved offer is still within its
   * deadline is the caller's to ask, because only the caller has a clock; see
   * ReviewRequest::hasLapsed().
   */
  public static function fromApprovedReview(ReviewRequest $request): ?self {
    if ($request->state !== ReviewState::Approved || $request->quoteAmountCents === NULL || $request->quoteCurrency === NULL) {
      return NULL;
    }

    return self::build($request->quoteAmountCents, $request->quoteCurrency, 'review:' . $request->reviewId);
  }

  /**
   * Rebuilds a snapshot this module stored earlier.
   *
   * The same validation as a fresh quote, applied to our own row. A stored figure
   * that no longer reads as chargeable — a currency emptied by a bad write, an
   * amount lost — must fail to reconstruct rather than resolve to zero and settle
   * a booking for nothing.
   */
  public static function fromStored(?string $snapshotId, ?int $amountCents, ?string $currency, ?string $rateVersion): ?self {
    if ($snapshotId === NULL || trim($snapshotId) === '' || $amountCents === NULL || $currency === NULL) {
      return NULL;
    }

    $rebuilt = self::build($amountCents, $currency, (string) $rateVersion);

    // The stored identifier wins over a freshly derived one, so a snapshot keeps
    // the name it was written under even if the derivation is ever changed.
    return $rebuilt === NULL
      ? NULL
      : new self(substr(trim($snapshotId), 0, 128), $rebuilt->amountCents, $rebuilt->currency, $rebuilt->rateVersion);
  }

  /**
   * Returns the amount as the decimal string Commerce prices in.
   */
  public function amountDecimal(): string {
    return number_format($this->amountCents / 100, 2, '.', '');
  }

  /**
   * Applies the shared validation and derives the identifier.
   *
   * The identifier is a truncated SHA-256 of the figures rather than a random
   * value, so the same quote is always named the same thing and an auditor can
   * recompute it from the row. It is not a secret and proves nothing: it names a
   * price, and the store still re-proves feasibility before capacity moves.
   */
  private static function build(int $amountCents, string $currency, string $rateVersion): ?self {
    $currency = strtoupper(trim($currency));
    $rateVersion = trim($rateVersion);

    if ($amountCents < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
      return NULL;
    }

    $snapshotId = substr(hash('sha256', $amountCents . '|' . $currency . '|' . $rateVersion), 0, 32);

    return new self($snapshotId, $amountCents, $currency, substr($rateVersion, 0, 64));
  }

}
