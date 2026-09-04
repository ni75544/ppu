<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The session identity a Dispatch Hold belongs to, and the receipts for it.
 *
 * A Hold is owned by a session, so the identity has to mean the same thing on the
 * request that claims capacity and on the later request that completes the form.
 * Drupal keeps an anonymous session lazy on purpose: with no session cookie it
 * starts no session, so the PHP session identifier is still empty while the request
 * runs. The identity is therefore seeded from the live session identifier when the
 * platform has established one and from fresh random bytes when it has not, and
 * then kept in the session — writing it is also what makes Drupal persist the
 * session and issue its cookie, which is what carries the identity to the next
 * request. Once seeded it never changes.
 *
 * Alongside it, this keeps the raw Hold token the server handed back, against the
 * exact Slot it was issued for. That copy exists because a hidden form element is
 * not a reliable place to keep a token: a Webform AJAX rebuild can replace the
 * element before the Hold response has reached it, and the token would then be
 * gone while the Hold is alive and owned.
 *
 * The receipt is not an authority and grants nothing. It reproduces a token this
 * same session was already given, and every use of it still goes through the
 * storage seam, which proves the token hash, the owner hash, the state, and the
 * expiry inside a transaction. Losing a receipt costs the visitor a re-hold; having
 * one wins nothing that the database has not already agreed to.
 *
 * Neither value is ever logged, rendered, cached, or attached to drupalSettings.
 * Both leave this class only as arguments to the store, which persists SHA-256
 * hashes and never the values themselves.
 */
final class DispatchOwner {

  /**
   * The session key carrying this visitor's dispatch identity.
   *
   * Deliberately distinct from the older fixed-slot reservation identity. The two
   * workflows own separate tables and separate tokens, and one identity serving
   * both would make a Hold in one of them evidence of ownership in the other.
   */
  const SESSION_KEY = 'custom_webform_handlers_dispatch_owner';

  /**
   * The session key carrying this visitor's outstanding Hold receipts.
   */
  const HOLDS_SESSION_KEY = 'custom_webform_handlers_dispatch_holds';

  /**
   * The largest number of Hold receipts one session retains.
   *
   * An abandoned Hold leaves its receipt behind until it is released or the
   * session ends, so the cap keeps a visitor who re-picks many times from growing
   * their session without bound. The oldest go first, and losing one only costs a
   * re-hold.
   */
  const MAX_REMEMBERED_HOLDS = 20;

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Returns this visitor's stable session identity.
   *
   * @return string
   *   The identifier, or an empty string when no session can carry one — which
   *   callers must read as "no Hold may be claimed, renewed, released, or
   *   confirmed", never as a reason to skip the ownership check.
   */
  public function ownerId(): string {
    $session = $this->session();
    if (!$session) {
      return '';
    }

    $owner = trim((string) $session->get(self::SESSION_KEY, ''));
    if ($owner !== '') {
      return $owner;
    }

    $owner = trim((string) $session->getId());
    if ($owner === '') {
      $owner = bin2hex(random_bytes(32));
    }
    $session->set(self::SESSION_KEY, $owner);

    return $owner;
  }

  /**
   * Records the token this session was given for one Slot.
   *
   * The expiry is kept alongside it so a later request can rebuild an honest Hold
   * object instead of inventing a lifetime for one. It is a copy of what the
   * server decided, not a claim about it: the store re-reads the real expiry under
   * its own locks before anything is confirmed.
   */
  public function rememberHold(Slot $slot, string $token, int $expiresAt): void {
    $token = trim($token);
    if ($token === '' || $expiresAt < 1) {
      return;
    }

    $session = $this->session();
    if (!$session) {
      return;
    }

    $key = $this->slotKey($slot);
    $holds = $this->holds($session);
    // Re-holding the same Slot replaces its receipt rather than keeping both, and
    // re-inserting moves it to the newest position for the cap below.
    unset($holds[$key]);
    $holds[$key] = ['token' => $token, 'expires_at' => $expiresAt];

    if (count($holds) > self::MAX_REMEMBERED_HOLDS) {
      $holds = array_slice($holds, -self::MAX_REMEMBERED_HOLDS, NULL, TRUE);
    }

    $session->set(self::HOLDS_SESSION_KEY, $holds);
  }

  /**
   * Returns the Hold this session recorded for one Slot, or NULL.
   *
   * The returned Hold is a receipt reconstructed from session data. It is only
   * ever passed back to the planner, which proves it against the database; nothing
   * downstream may treat its presence as proof that capacity is held.
   */
  public function heldSlot(Slot $slot): ?Hold {
    $session = $this->session();
    if (!$session) {
      return NULL;
    }

    $receipt = $this->holds($session)[$this->slotKey($slot)] ?? NULL;
    if ($receipt === NULL) {
      return NULL;
    }

    return new Hold($slot, $receipt['token'], $receipt['expires_at']);
  }

  /**
   * Drops the receipt for one token, whichever Slot it was recorded against.
   *
   * Called once the server has said the Hold is gone — released, refused, or
   * consumed by a successful confirmation. Keeping it would let a later submission
   * present a token for a Hold that no longer exists, which would be refused, but
   * only after the visitor had finished the form.
   */
  public function forgetHold(string $token): void {
    $token = trim($token);
    if ($token === '') {
      return;
    }

    $session = $this->session();
    if (!$session) {
      return;
    }

    $holds = $this->holds($session);
    $kept = [];
    foreach ($holds as $key => $receipt) {
      // hash_equals, so a receipt is never dropped on a partial match and the
      // comparison is not timing-sensitive.
      if (!hash_equals($receipt['token'], $token)) {
        $kept[$key] = $receipt;
      }
    }

    if ($kept === $holds) {
      return;
    }

    $session->set(self::HOLDS_SESSION_KEY, $kept);
  }

  /**
   * Identifies one Slot for the receipt map.
   *
   * The Resource, the Webform, and the exact pickup arrival together, because a
   * receipt must not be found for a neighbouring time, a different Resource, or a
   * different form. The timezone is absent deliberately: the timestamp is already
   * absolute, and including a zone would make the same instant key differently
   * after an operator renamed the Resource's zone to an equivalent one.
   */
  private function slotKey(Slot $slot): string {
    return $slot->resourceId . '|' . $slot->webformId . '|' . $slot->startTimestamp;
  }

  /**
   * Returns the recorded receipts, keeping only well-formed entries.
   *
   * Session data is durable and this map is read on the submission path, so
   * anything that is not a non-empty token with a positive expiry, under a
   * non-empty string key, is discarded rather than trusted.
   *
   * @return array<string, array{token: string, expires_at: int}>
   */
  private function holds(SessionInterface $session): array {
    $stored = $session->get(self::HOLDS_SESSION_KEY, []);
    if (!is_array($stored)) {
      return [];
    }

    $holds = [];
    foreach ($stored as $key => $receipt) {
      if (!is_string($key) || $key === '' || !is_array($receipt)) {
        continue;
      }
      $token = $receipt['token'] ?? NULL;
      $expiresAt = $receipt['expires_at'] ?? NULL;
      if (!is_string($token) || !is_int($expiresAt) || $expiresAt < 1) {
        continue;
      }
      $token = trim($token);
      if ($token !== '') {
        $holds[$key] = ['token' => $token, 'expires_at' => $expiresAt];
      }
    }

    return $holds;
  }

  /**
   * Returns the session both the identity and the receipts live in.
   *
   * One accessor, so a receipt is always written to and read from exactly the
   * session that produced the owner identity it belongs to.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return NULL;
    }

    return $request->getSession();
  }

}
