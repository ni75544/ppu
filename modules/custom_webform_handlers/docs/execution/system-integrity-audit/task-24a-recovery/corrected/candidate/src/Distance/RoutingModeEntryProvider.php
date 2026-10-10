<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * The one customer-route entry point every quote and booking seam calls.
 *
 * Before this facade, three seams — the quote preview controller, the
 * AddressDistanceHandler pre-save, and the dispatch checkout starter — each
 * injected the live Google adapter directly and called it whenever a server key
 * was present, with nothing between them and Guzzle. That is what let local
 * development bill the live provider. Now all three inject this, and the choice
 * of provider is made once, here, from the routing mode.
 *
 * It implements DistanceProviderInterface itself, so it is a drop-in replacement
 * at every seam and adds no method a caller has to learn. It owns no cache, no
 * budget and no provider chain: development_fixed delegates to the deterministic
 * recorded provider, live_cached delegates to the unchanged Google adapter,
 * whose own cache-first behaviour and one-request-per-committed-pair controls
 * are preserved exactly. There is one authority for each mode and this picks
 * between them; it does not duplicate either.
 */
final class RoutingModeEntryProvider implements DistanceProviderInterface {

  /**
   * Constructs the customer-route entry facade.
   *
   * @param \Drupal\custom_webform_handlers\Distance\RoutingModeResolver $resolver
   *   Decides which provider answers.
   * @param \Drupal\custom_webform_handlers\Distance\DistanceProviderInterface $liveProvider
   *   The live, cache-first provider used in live_cached mode.
   * @param \Drupal\custom_webform_handlers\Distance\DistanceProviderInterface $developmentProvider
   *   The deterministic no-HTTP provider used in development_fixed mode.
   */
  public function __construct(
    private readonly RoutingModeResolver $resolver,
    private readonly DistanceProviderInterface $liveProvider,
    private readonly DistanceProviderInterface $developmentProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    $provider = $this->resolver->isDevelopmentFixed()
      ? $this->developmentProvider
      : $this->liveProvider;

    return $provider->calculate($origin, $destination);
  }

}
