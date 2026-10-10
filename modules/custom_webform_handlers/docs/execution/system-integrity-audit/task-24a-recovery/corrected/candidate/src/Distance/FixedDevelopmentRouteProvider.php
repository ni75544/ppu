<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Serves deterministic recorded routes for local development, without HTTP.
 *
 * The development half of the routing contract. It implements the same
 * DistanceProviderInterface the live Google adapter does, returns a valid
 * DistanceResult for any usable From/To pair, and never opens a socket — so a
 * developer can price a quote, save a submission, start a checkout and look up
 * dispatch slots with the network unplugged and no provider ever billed.
 *
 * It answers in two ways, in order:
 *
 * - An exact recorded fixture, when the normalized address pair's fingerprint
 *   matches one that has been pinned in FIXTURES. This is where a real
 *   cached/recorded route fact is reused — a figure actually observed and
 *   written down by an operator, keyed exactly as the live cache keys it, so a
 *   fixture reproduces a known journey to the metre. It ships empty on purpose:
 *   a fixture is added deliberately, not scraped, and the module must work
 *   before any exist.
 * - Otherwise one documented fixed fallback. Every unpinned pair resolves to the
 *   same local NYC-metro figure, which is the point — it is deterministic, it
 *   exercises courier excess-distance pricing and slot travel time, and it does
 *   not depend on any historic record existing.
 *
 * The input contract mirrors the live adapter: an empty endpoint, or two
 * endpoints that are the same address, is unusable everywhere and is refused
 * with the same classified terminal failure rather than being priced as a
 * zero-length drive.
 *
 * The result names itself development_fixed and carries data_version
 * ddev-fixed-v1, so nothing downstream can mistake a development figure for a
 * live one — the quote endpoint's client-echo gate, which trusts only
 * 'google_routes', rejects it and recomputes server-side, which here is free.
 */
final class FixedDevelopmentRouteProvider implements DistanceProviderInterface {

  /**
   * The provider identifier this provider stamps on every result.
   */
  public const PROVIDER_ID = 'development_fixed';

  /**
   * The recorded-fixture dataset version, carried in result metadata.
   */
  public const DATA_VERSION = 'ddev-fixed-v1';

  /**
   * The documented fallback distance, in meters. 19312 m is 12.0 statute miles.
   *
   * Chosen to sit above the courier included-mileage threshold (5 mi) so excess
   * distance is exercised, and well below the commercial (50 mi) and
   * long-distance tiers, so a local form test reads as a local move.
   */
  private const FALLBACK_DISTANCE_METERS = 19312;

  /**
   * The documented fallback duration, in seconds. 1680 s is 28 minutes.
   */
  private const FALLBACK_DURATION_SECONDS = 1680;

  /**
   * Exact recorded routes, keyed by DistanceResult::pairFingerprint().
   *
   * Each key is the sha256 of the two normalized, lowercased endpoints joined by
   * a NUL, and each value is [distanceMeters, durationSeconds] observed for that
   * ordered pair. Add an entry to reproduce a known journey exactly; leave it
   * empty and every pair uses the documented fallback.
   *
   * @var array<string, array{0: int, 1: int}>
   */
  private const FIXTURES = [];

  /**
   * Constructs the fixed development route provider.
   *
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   Supplies the calculation timestamp. NULL falls back to time(), so direct
   *   construction in a probe needs no container.
   */
  public function __construct(
    private readonly ?TimeInterface $time = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    $origin = DistanceResult::normalizeAddressText($origin);
    $destination = DistanceResult::normalizeAddressText($destination);
    if ($origin === ''
      || $destination === ''
      || mb_strtolower($origin, 'UTF-8') === mb_strtolower($destination, 'UTF-8')) {
      throw DistanceProviderException::classified(DistanceProviderException::ADDRESS_UNUSABLE);
    }

    $fingerprint = DistanceResult::pairFingerprint($origin, $destination);
    [$meters, $seconds] = self::FIXTURES[$fingerprint]
      ?? [self::FALLBACK_DISTANCE_METERS, self::FALLBACK_DURATION_SECONDS];

    $now = $this->time?->getCurrentTime() ?? time();

    return new DistanceResult(
      $meters,
      $seconds,
      self::PROVIDER_ID,
      $now,
      NULL,
      FALSE,
      ['data_version' => self::DATA_VERSION],
    );
  }

}
