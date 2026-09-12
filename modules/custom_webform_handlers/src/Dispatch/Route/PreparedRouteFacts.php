<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\custom_webform_handlers\Dispatch\RouteEstimate;

/**
 * The bounded route facts prepared for one planner decision.
 *
 * This object is deliberately request-local. It is carried from the unlocked
 * preparation into the Resource Calendar Guard callback so that callback can
 * re-read the authoritative neighbours and ask only whether every required leg
 * was already prepared. It has no provider and therefore no way to perform I/O.
 *
 * Keys are one-way identities over an ordered endpoint pair and the requested
 * departure. Addresses never leave this private planner path and are never
 * logged or serialized.
 *
 * @internal
 */
final class PreparedRouteFacts {

  /**
   * Stores route estimates by their private directional identity.
   *
   * @var array<string, \Drupal\custom_webform_handlers\Dispatch\RouteEstimate>
   */
  private array $facts = [];

  /**
   * Records one estimate under the exact question that produced it.
   */
  public function remember(string $origin, string $destination, int $departureAt, RouteEstimate $estimate): void {
    $this->facts[$this->key($origin, $destination, $departureAt)] = $estimate;
  }

  /**
   * Returns the previously prepared answer to one exact route question.
   */
  public function find(string $origin, string $destination, int $departureAt): ?RouteEstimate {
    return $this->facts[$this->key($origin, $destination, $departureAt)] ?? NULL;
  }

  /**
   * Builds a directional identity without retaining either endpoint.
   */
  private function key(string $origin, string $destination, int $departureAt): string {
    return hash('sha256', implode("\0", [
      $this->normalize($origin),
      $this->normalize($destination),
      $departureAt,
    ]));
  }

  /**
   * Applies the same harmless text normalization to both fact operations.
   */
  private function normalize(string $value): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

    return mb_strtolower($value, 'UTF-8');
  }

}
