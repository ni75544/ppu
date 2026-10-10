<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves the effective routing mode for distance and offer calculations.
 *
 * Enforces development_fixed mode when running in DDEV environments.
 */
final class RoutingModeResolver {

  public const MODE_DEVELOPMENT_FIXED = 'development_fixed';
  public const MODE_LIVE_CACHED = 'live_cached';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the effective routing mode.
   *
   * Checks IS_DDEV_PROJECT environment variable first before reading config.
   */
  public function effectiveMode(): string {
    $isDdev = getenv('IS_DDEV_PROJECT');
    if ($isDdev === 'true' || $isDdev === '1') {
      return self::MODE_DEVELOPMENT_FIXED;
    }

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    return $config->get('routing_mode') ?? self::MODE_LIVE_CACHED;
  }

  /**
   * Checks whether the effective routing mode is development_fixed.
   */
  public function isDevelopmentFixed(): bool {
    return $this->effectiveMode() === self::MODE_DEVELOPMENT_FIXED;
  }

}
