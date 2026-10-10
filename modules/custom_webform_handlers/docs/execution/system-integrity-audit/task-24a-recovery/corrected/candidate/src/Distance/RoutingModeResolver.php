<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Decides which routing mode is in force for this environment.
 *
 * One question, asked in one place: may the customer-route entry provider reach
 * the live Google adapter, or must it serve a deterministic recorded figure that
 * costs nothing? A ~40,000-request incident came from local development and
 * automated verification billing the live provider, so the safe answer in DDEV
 * is not a preference an operator can forget to set — it is read from the
 * environment itself.
 *
 * Two signals, in order:
 *
 * - The DDEV signal. `IS_DDEV_PROJECT=true` is present in every DDEV web
 *   container and nowhere else, and it is the same signal settings.php already
 *   trusts. When it is set, the mode is development_fixed unconditionally, so no
 *   configuration value and no stray API key can talk local development into
 *   dialling out.
 * - Otherwise configuration. `custom_webform_handlers.settings:routing_mode`
 *   selects the mode on a server that is not DDEV, defaulting to live_cached.
 *   Production can still be pinned to development_fixed through a settings.php
 *   config override without editing exported configuration.
 *
 * The environment flag is injectable so a non-billing probe can force either
 * mode with a literal — `new RoutingModeResolver($configFactory, 'false')` asks
 * the live branch even inside DDEV — without a real key and without a network.
 * Nothing here reads or stores a credential.
 */
final class RoutingModeResolver {

  /**
   * Deterministic, no-HTTP recorded routes. The mode DDEV always resolves to.
   */
  public const MODE_DEVELOPMENT_FIXED = 'development_fixed';

  /**
   * Cache-first live Google routing. The production default.
   */
  public const MODE_LIVE_CACHED = 'live_cached';

  /**
   * The resolved DDEV signal: 'true' inside a DDEV container, '' otherwise.
   */
  private readonly string $environmentFlag;

  /**
   * Constructs the routing mode resolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads the routing_mode setting when the environment does not force a mode.
   * @param string|null $environmentFlag
   *   The DDEV signal, for tests and probes. NULL — the service default — reads
   *   getenv('IS_DDEV_PROJECT') at construction. A literal ('true' / 'false')
   *   forces the branch without touching the environment, so a probe can prove
   *   either mode without a key or a request.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    ?string $environmentFlag = NULL,
  ) {
    $this->environmentFlag = $environmentFlag ?? (getenv('IS_DDEV_PROJECT') ?: '');
  }

  /**
   * Returns the routing mode in force, one of this class's MODE_* constants.
   */
  public function effectiveMode(): string {
    if ($this->environmentFlag === 'true') {
      return self::MODE_DEVELOPMENT_FIXED;
    }

    $configured = (string) $this->configFactory
      ->get('custom_webform_handlers.settings')
      ->get('routing_mode');

    return $configured === self::MODE_DEVELOPMENT_FIXED
      ? self::MODE_DEVELOPMENT_FIXED
      : self::MODE_LIVE_CACHED;
  }

  /**
   * Returns whether no outbound routing request may be made.
   *
   * The single predicate the entry facade and the live adapter's fail-closed
   * guard both read: TRUE means serve a recorded figure and refuse the network.
   */
  public function isDevelopmentFixed(): bool {
    return $this->effectiveMode() === self::MODE_DEVELOPMENT_FIXED;
  }

}
