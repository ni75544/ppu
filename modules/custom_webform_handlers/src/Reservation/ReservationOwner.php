<?php

namespace Drupal\custom_webform_handlers\Reservation;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The single source of the session identity that a Hold belongs to.
 *
 * A Hold is owned by a session, so the identity has to mean the same thing on
 * the request that takes the Hold and on the later request that releases or
 * submits it. Drupal keeps an anonymous session lazy on purpose: with no
 * session cookie it never starts one, so the PHP session identifier is still
 * empty while the request runs and nothing would carry the visitor's identity
 * forward. Reading the identifier is therefore not sufficient on its own.
 *
 * So the identity is seeded from the live PHP session identifier when the
 * platform has established one, and otherwise from fresh random bytes, and then
 * kept in the session itself. Writing it is also what makes Drupal persist the
 * session and issue its cookie, which is what makes the identity survive to the
 * next request. Once seeded it never changes, so a Hold taken by a visitor who
 * had no session yet is still recognised as theirs afterwards.
 *
 * The value never leaves the session: callers pass it to the reservation
 * manager, which stores only its hash.
 */
final class ReservationOwner {

  /**
   * The session key that carries this visitor's reservation identity.
   */
  const SESSION_KEY = 'custom_webform_handlers_reservation_owner';

  /**
   * The session key that carries this visitor's outstanding Hold receipts.
   */
  const HOLDS_SESSION_KEY = 'custom_webform_handlers_reservation_holds';

  /**
   * The largest number of Hold receipts one session retains.
   *
   * A receipt is dropped when its Hold is released or refused, but a Hold that
   * is simply abandoned leaves its receipt behind until the session ends. The
   * cap keeps a visitor who cycles through many times from growing their session
   * without bound; the oldest receipts go first, and losing one only means
   * falling back to the browser's own copy.
   */
  const MAX_REMEMBERED_HOLDS = 20;

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Returns this visitor's stable session identity.
   *
   * @return string
   *   The identifier, or an empty string when no session can carry one, which
   *   callers must treat as "no Hold may be taken, released, or verified".
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
   * Records that this session holds one Slot, so the server keeps the receipt.
   *
   * The browser is given the token too, but a hidden form element is not a
   * reliable place to keep it: a Webform AJAX rebuild can replace that element
   * before the Hold response reaches it, and the token is then gone while the
   * Hold itself is alive and owned. Keeping a copy here means final validation
   * can still find the receipt for the Slot the visitor actually chose.
   *
   * This is a receipt, not an authority. It only reproduces a token this same
   * session was already given, and every caller still proves the Hold through
   * the reservation manager, which checks the Slot, the token, and the owner.
   */
  public function rememberHold(ReservationSlot $slot, string $token): void {
    $token = trim($token);
    if ($token === '') {
      return;
    }

    $session = $this->session();
    if (!$session) {
      return;
    }

    $key = $slot->activeSlotKey();
    $holds = $this->holds($session);
    // Re-holding the same Slot replaces its receipt instead of keeping both, and
    // re-inserting moves it to the newest position for the cap below.
    unset($holds[$key]);
    $holds[$key] = $token;

    if (count($holds) > self::MAX_REMEMBERED_HOLDS) {
      $holds = array_slice($holds, -self::MAX_REMEMBERED_HOLDS, NULL, TRUE);
    }

    $session->set(self::HOLDS_SESSION_KEY, $holds);
  }

  /**
   * Returns the token this session recorded for one Slot, or an empty string.
   */
  public function holdToken(ReservationSlot $slot): string {
    $session = $this->session();
    if (!$session) {
      return '';
    }

    return $this->holds($session)[$slot->activeSlotKey()] ?? '';
  }

  /**
   * Drops the receipt for one token, whichever Slot it was recorded against.
   *
   * Called when the server has said the Hold is gone — released, or refused on
   * renewal. Keeping the receipt would let a later submission present a token
   * for a Hold that no longer exists, which the manager would refuse anyway, but
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
    foreach ($holds as $key => $stored) {
      // Compared with hash_equals, so a receipt is never dropped on a partial
      // match and the comparison is not timing-sensitive.
      if (!hash_equals($stored, $token)) {
        $kept[$key] = $stored;
      }
    }

    if ($kept === $holds) {
      return;
    }

    $session->set(self::HOLDS_SESSION_KEY, $kept);
  }

  /**
   * Returns the recorded receipts, keeping only well-formed entries.
   *
   * Session data is durable and this map is read on the submission path, so
   * anything that is not a non-empty string keyed by a non-empty string is
   * discarded rather than trusted.
   *
   * @return string[]
   *   Active slot key => raw Hold token.
   */
  private function holds(SessionInterface $session): array {
    $stored = $session->get(self::HOLDS_SESSION_KEY, []);
    if (!is_array($stored)) {
      return [];
    }

    $holds = [];
    foreach ($stored as $key => $token) {
      if (!is_string($key) || $key === '' || !is_string($token)) {
        continue;
      }
      $token = trim($token);
      if ($token !== '') {
        $holds[$key] = $token;
      }
    }
    return $holds;
  }

  /**
   * Returns the session every reservation identity and receipt lives in.
   *
   * One accessor, so a Hold receipt is always written to and read from exactly
   * the session that produced the owner identity it belongs to.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return NULL;
    }
    return $request->getSession();
  }

}
