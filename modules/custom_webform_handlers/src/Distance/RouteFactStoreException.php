<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Thrown when the route fact store cannot safely take custody of a fact.
 *
 * The one case it signals is a lock it could not acquire. The per-owner map is a
 * read-modify-write, so a write without the lock could lose a concurrent request's
 * fact — and the store must never do that silently. Rather than proceed unprotected,
 * it raises this, and the caller turns it into a typed busy response. The shared map
 * is left exactly as it was: a busy result is a refusal to write, not a partial one.
 *
 * It is deliberately separate from DistanceProviderException, which classifies why a
 * provider could not answer. This is not a routing failure; the route may already be
 * calculated. It is a coordination failure, and the caller handles it as one — never
 * as a reason to call the provider again.
 */
final class RouteFactStoreException extends \RuntimeException {

}
