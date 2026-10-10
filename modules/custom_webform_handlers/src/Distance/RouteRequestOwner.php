<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one answer to "whose route request is this", for every routing seam.
 *
 * The route fact store and the operation ledger both have to scope what they
 * keep to a single requester, and they must agree on who that is or a fact
 * stored under one identity would be invisible to the reprice that reads it
 * back. Before this class each derived its own owner, which is exactly the kind
 * of duplicated decision that drifts: PrivateTempStore partitions by owner
 * internally and never exposed the token it used, while the ledger lives in a
 * single shared table and has to carry the owner in its own keys. So the owner
 * is resolved once, here, and both are handed the same value.
 *
 * The scope is deliberately two-tier and stated rather than assumed:
 *
 * - An authenticated request is owned by its user. `uid:<n>` is the token, so
 *   the same person is the same owner across sessions, devices and logins — a
 *   route they priced on their phone can be repriced on their laptop, and no
 *   other account can name it. This is a stronger guarantee than a session, and
 *   the tests must show it holds by using two different authenticated users, not
 *   two sessions of one.
 *
 * - An anonymous request is owned by its session. `sess:<hash>` is the token,
 *   the hash keeping the minted session secret out of ledger keys and log lines.
 *   Two independent anonymous visitors are two owners and cannot see each
 *   other's facts; the same visitor across a cookie loss is a new owner, which
 *   is correct — an anonymous fact should not outlive the session cookie that
 *   named it. The tests must show isolation by driving two real sessions, not by
 *   asserting the guarantee in prose.
 *
 * The anonymous identity is a random secret minted here and kept in the session,
 * not a hash of the PHP session id. Drupal keeps an anonymous session lazy on
 * purpose: with no session cookie yet, `SessionManager::start()` does not start a
 * real session and `getId()` stays empty for the whole first request — so a token
 * derived from the session id would be `sess:sha256('')` for every cookieless
 * client, collapsing them all onto one shared owner. Writing the minted secret
 * into the session is instead what makes Drupal treat the session as non-obsolete,
 * persist it, and issue the cookie that carries the identity to the next request.
 * This mirrors the proven DispatchOwner seam, which owns a Hold the same way.
 *
 * If no owner can be established at all — no request context, or a request that
 * carries no session, as on the CLI or a queue worker — this refuses with a
 * RouteRequestOwnerException rather than inventing a shared or empty placeholder.
 * There is no anonymous visitor to own anything in those contexts, and a routing
 * operation must never proceed under an owner that isolates nothing.
 */
final class RouteRequestOwner {

  /**
   * The session key carrying this anonymous visitor's routing identity.
   *
   * Deliberately distinct from the Dispatch Hold identity: a routing owner and a
   * Hold owner name separate concerns, and one value serving both would make a
   * fact in one workflow evidence of ownership in the other.
   */
  private const SESSION_KEY = 'custom_webform_handlers_route_owner';

  /**
   * Constructs the route request owner resolver.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Returns the stable owner token for the current request.
   *
   * An authenticated request resolves to its uid; an anonymous one to a hash of
   * a per-session secret minted and persisted here on first use. A caller can key
   * per-owner storage on the returned value without a second branch for "no owner
   * yet".
   *
   * @return string
   *   Either "uid:<n>" for an authenticated user or "sess:<64-hex>" for an
   *   anonymous session.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\RouteRequestOwnerException
   *   When no session can carry an anonymous identity, so no isolating owner
   *   exists. The caller must refuse before any ledger, fact-store, or provider
   *   operation rather than fall back to a shared owner.
   */
  public function token(): string {
    $uid = (int) $this->currentUser->id();
    if ($uid > 0) {
      return 'uid:' . $uid;
    }

    return 'sess:' . hash('sha256', $this->anonymousSecret());
  }

  /**
   * Whether the current request is owned by an authenticated user.
   *
   * The ledger records this beside each operation so a completed operation can
   * refuse to be replayed across the authenticated/anonymous boundary even in
   * the pathological case of a token collision, and so the scope of a stored
   * fact is an asserted, checkable property rather than a comment.
   */
  public function isAuthenticated(): bool {
    return (int) $this->currentUser->id() > 0;
  }

  /**
   * Returns this anonymous visitor's stable per-session secret, minting one once.
   *
   * The secret is 32 random bytes, kept in the session and never returned raw to
   * a caller (the token hashes it). Reading a request's session and writing to it
   * is what makes Drupal persist the session and set its cookie — the cookie is
   * what carries this identity to the next request, so the value read back on a
   * later request with that cookie is the same, and two independent visitors mint
   * two independent secrets. It is deliberately not derived from the PHP session
   * id, which is empty on a cookieless request's first pass and would collapse
   * every such client onto one shared owner.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\RouteRequestOwnerException
   *   When there is no request, or the request carries no session, so no
   *   anonymous identity can be established.
   */
  private function anonymousSecret(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      // No request context, or a request with no session (CLI, a queue worker).
      // There is no anonymous visitor to own anything, and no session to carry an
      // identity, so refuse rather than serve under a shared or empty placeholder.
      throw new RouteRequestOwnerException('No session is available to own an anonymous route request.');
    }

    $session = $request->getSession();
    $secret = trim((string) $session->get(self::SESSION_KEY, ''));
    if ($secret !== '') {
      return $secret;
    }

    $secret = bin2hex(random_bytes(32));
    // Writing the secret is what persists the session and issues its cookie, so
    // the same secret is read back on the next request that carries the cookie.
    $session->set(self::SESSION_KEY, $secret);

    return $secret;
  }

}
