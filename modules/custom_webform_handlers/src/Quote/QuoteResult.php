<?php

namespace Drupal\custom_webform_handlers\Quote;

use Drupal\custom_webform_handlers\Distance\DistanceResult;

/**
 * Immutable authoritative quote result.
 */
final class QuoteResult {

  public function __construct(
    private readonly string $currency,
    private readonly int $totalCents,
    private readonly array $lineItems,
    private readonly string $status,
    private readonly array $manualReviewReasons,
    private readonly string $rateVersion,
    private readonly DistanceResult $distance,
    private readonly ?float $weightLbs = NULL,
    private readonly string $weightSource = 'not_applicable',
  ) {}

  /**
   * Returns the quote currency.
   */
  public function currency(): string {
    return $this->currency;
  }

  /**
   * Returns the authoritative total in minor currency units.
   */
  public function totalCents(): int {
    return $this->totalCents;
  }

  /**
   * Returns the authoritative total as a decimal currency string.
   */
  public function totalDecimal(): string {
    return number_format($this->totalCents / 100, 2, '.', '');
  }

  /**
   * Returns the quote disposition.
   */
  public function status(): string {
    return $this->status;
  }

  /**
   * Returns the pricing configuration version.
   */
  public function rateVersion(): string {
    return $this->rateVersion;
  }

  /**
   * Returns the normalized route fact this quote was calculated from.
   */
  public function distance(): DistanceResult {
    return $this->distance;
  }

  /**
   * Returns the priced line-item breakdown, in calculation order.
   *
   * Each entry is the shape QuoteCalculator::addLine() writes — code, label,
   * integer amount_cents, decimal amount, and metadata. The list is the complete
   * breakdown the total is the sum of, which is what lets a snapshot record the
   * whole priced job rather than only its total.
   *
   * @return array<int, array<string, mixed>>
   *   The line items, each with at least a 'code' and an 'amount_cents'.
   */
  public function lineItems(): array {
    return $this->lineItems;
  }

  /**
   * Returns the public quote representation.
   *
   * @return array<string, mixed>
   *   The normalized quote fields.
   */
  public function toArray(): array {
    return [
      'currency' => $this->currency,
      'total_cents' => $this->totalCents,
      'total' => $this->totalDecimal(),
      'line_items' => $this->lineItems,
      'status' => $this->status,
      'manual_review_reasons' => $this->manualReviewReasons,
      'rate_version' => $this->rateVersion,
      'distance' => $this->distance->toArray(),
      'weight_lbs' => $this->weightLbs === NULL ? NULL : round($this->weightLbs, 2),
      'weight_source' => $this->weightSource,
      'preliminary_non_binding' => TRUE,
      'calculated_at' => time(),
    ];
  }

}
