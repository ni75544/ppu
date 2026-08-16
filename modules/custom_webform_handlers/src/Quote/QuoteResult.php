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

  public function currency(): string {
    return $this->currency;
  }

  public function totalCents(): int {
    return $this->totalCents;
  }

  public function totalDecimal(): string {
    return number_format($this->totalCents / 100, 2, '.', '');
  }

  public function status(): string {
    return $this->status;
  }

  public function rateVersion(): string {
    return $this->rateVersion;
  }

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
