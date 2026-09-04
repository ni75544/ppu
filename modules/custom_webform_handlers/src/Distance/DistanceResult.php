<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Immutable normalized route result.
 */
final class DistanceResult {

  private readonly int $calculatedAt;

  private readonly ?string $encodedPolyline;

  /**
   * @param bool $trafficAware
   *   Whether traffic conditions at a stated departure informed the duration.
   *   FALSE — the default, and what the quote path always receives — means the
   *   figure is time-independent, which is the fastest the drive is ever likely to
   *   be. A scheduling caller must not treat the two as interchangeable.
   */
  public function __construct(
    private readonly int $distanceMeters,
    private readonly int $durationSeconds,
    private readonly string $provider = 'google_routes',
    ?int $calculatedAt = NULL,
    ?string $encodedPolyline = NULL,
    private readonly bool $trafficAware = FALSE,
  ) {
    if ($distanceMeters <= 0) {
      throw new \InvalidArgumentException('Distance must be greater than zero.');
    }
    if ($durationSeconds < 0) {
      throw new \InvalidArgumentException('Duration cannot be negative.');
    }
    $this->calculatedAt = $calculatedAt ?? time();
    $this->encodedPolyline = $encodedPolyline === NULL || trim($encodedPolyline) === ''
      ? NULL
      : trim($encodedPolyline);
  }

  public function distanceMeters(): int {
    return $this->distanceMeters;
  }

  public function distanceMiles(): float {
    return $this->distanceMeters / 1609.344;
  }

  public function distanceKilometers(): float {
    return $this->distanceMeters / 1000;
  }

  public function durationSeconds(): int {
    return $this->durationSeconds;
  }

  public function provider(): string {
    return $this->provider;
  }

  public function calculatedAt(): int {
    return $this->calculatedAt;
  }

  /**
   * Returns the server-validated route geometry, when available.
   */
  public function encodedPolyline(): ?string {
    return $this->encodedPolyline;
  }

  /**
   * Returns whether traffic at a stated departure informed the duration.
   */
  public function trafficAware(): bool {
    return $this->trafficAware;
  }

  /**
   * Returns the public shape of this result.
   *
   * The traffic basis is deliberately absent. This array is serialized straight
   * into the browser's distance-preview response, and how the schedule prices a
   * drive is operational detail the customer's page has no use for.
   */
  public function toArray(): array {
    return [
      'distance_meters' => $this->distanceMeters(),
      'distance_miles' => round($this->distanceMiles(), 2),
      'distance_kilometers' => round($this->distanceKilometers(), 2),
      'duration_seconds' => $this->durationSeconds(),
      'provider' => $this->provider(),
      'calculated_at' => $this->calculatedAt(),
      'encoded_polyline' => $this->encodedPolyline(),
    ];
  }

}
