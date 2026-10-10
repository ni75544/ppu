<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Thrown when no owner can be established for a route request.
 *
 * The route fact store and the operation ledger both scope what they keep to a
 * single requester, so a request that cannot be attributed to one — no request
 * context, no session to carry an anonymous identity — must be refused before
 * any ledger lookup, fact write, or provider call, never served under a shared
 * or empty-session placeholder. The controller turns this into a typed busy
 * refusal; it is not a client error and carries no owner value.
 */
final class RouteRequestOwnerException extends \RuntimeException {
}
