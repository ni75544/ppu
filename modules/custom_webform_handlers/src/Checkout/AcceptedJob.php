<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;

/**
 * The material identity of one accepted job, derived the same way twice.
 *
 * A price alone cannot tell one job from another. Task 24B / E09: different
 * packages, handling, service or scheduling inputs can produce identical prices
 * and identical line-item codes, and the reserved duration can be identical too,
 * so a booking confirmed on price-and-timing alone would settle an edited job
 * under the figure the customer accepted for the old one. What distinguishes two
 * jobs is not what they cost but what was asked for, and this class is the one
 * place that reduces "what was asked for" to a single comparable identity.
 *
 * It is a canonicalization, not a price. It hashes the material inputs — the
 * normalized ordered endpoints and every pricing/resource answer on the shared
 * QUOTE_INPUT_FIELDS allowlist — never the pricing outputs. Two consequences
 * follow, and both are the point:
 *
 *  - A change that moves no price still changes the identity. An address edited
 *    to a nearby point that routes to the same distance, a package resized within
 *    the same tier, a handling flag toggled that happens not to add a line: each
 *    alters an input on the allowlist, so each produces a different digest and
 *    cannot confirm under the accepted snapshot.
 *
 *  - A change that moves a price but not an input cannot happen through this,
 *    because it reads inputs. A rate table edited between acceptance and
 *    confirmation re-prices the same job to a different total, and the identity is
 *    unchanged — which is correct: the customer accepted a figure for a job, and
 *    that job is still the one on the submission. Validating identity by
 *    recalculating the price against today's mutable tariffs is exactly the
 *    coupling the reviewer forbids, and reading inputs is how this avoids it.
 *
 * The allowlist is the identity set: AddressDistanceHandler::QUOTE_INPUT_FIELDS —
 * the single client/server pricing-input list the browser is served and preSave
 * canonicalizes against — plus the two endpoints. What it deliberately omits is
 * the revalidated set: fields whose current value is proved by another authority
 * at confirmation rather than frozen into identity. Contact and payment fields are
 * off it, so a changed email or a new payment method never re-prices a job. The
 * chosen arrival is off it, because it is already the attempt's own idempotency
 * fact and is re-proved against the locked calendar. And the Store-Pickup
 * order_is_paid_and_ready flag is off it by the same rule: it is a lifecycle
 * eligibility answer the BookingWorkflowGate revalidates at confirmation, not a
 * pricing input, so freezing it into identity would refuse a job merely because
 * its paid-and-ready state advanced — which is the state advancing as intended.
 *
 * The endpoints are the exception the field list cannot express. They are the
 * handler's own origin and destination settings, read as raw submission values at
 * acceptance and as the request factory's trimmed addresses at confirmation; both
 * converge here because normalizeEndpoint() trims, collapses inner whitespace, and
 * lowercases, so a formatting difference between the two read paths never forces a
 * re-acceptance while a genuine address edit always does.
 *
 * Determinism is the whole contract, and it is fail-closed. The same job
 * canonicalizes to the same bytes however its scalar values were stored — a number
 * as an int or a numeric string, a boolean as a stable '1' or '0'. Empty answers
 * are dropped, so an absent answer and a cleared one read alike. A multi-value
 * answer keeps its stored order rather than being sorted: Webform serializes a
 * multi-select in stable element-definition order, so preserving it never makes
 * one job read as two, while sorting could collapse two genuinely different
 * ordered sequences into one identity — the one failure this must not have, since
 * it is the fail-open direction. A value that is neither scalar nor array, or a
 * job whose canonical facts cannot be JSON-encoded, is rejected by exception
 * rather than reduced to a normal-looking digest over discarded material: a digest
 * is produced only from facts that were fully and losslessly canonicalized.
 * VERSION is folded into the hash, so a change to this canonicalization retires
 * every digest written under the old one rather than silently comparing across
 * incompatible rules.
 *
 * The canonical facts themselves are retained beside the digest, not only the
 * digest, because a digest alone cannot reconstruct what the customer accepted
 * (Task 24B / E09). The caller persists factsJson() alongside the digest so a
 * later reader can see the material job that was priced — and can re-derive the
 * digest from those very facts through digestForFactsJson(), proving the stored
 * identity is the identity of the stored facts and not an unrelated value.
 */
final readonly class AcceptedJob {

  /**
   * The canonicalization version folded into every digest.
   *
   * Bumped when the field set or the normalization rules below change. It is
   * hashed in, so a digest computed under one version can never hash-equal one
   * computed under another: a rule change refuses stale identities rather than
   * comparing them under a rule they were never written for. Raised to 2 at Task
   * 24B when list canonicalization changed from sorted to order-preserving and the
   * canonicalizer became fail-closed on unsupported value shapes.
   */
  const VERSION = 2;

  /**
   * The reserved fact keys the endpoints occupy in the canonical map.
   *
   * Colon-bearing, so they cannot collide with a Webform element machine name —
   * those are lower-case, digits, and underscores only — even if a form ever named
   * an element "origin" or "destination".
   */
  const ENDPOINT_ORIGIN_KEY = 'endpoint:origin';
  const ENDPOINT_DESTINATION_KEY = 'endpoint:destination';

  /**
   * @param string $digest
   *   The SHA-256 identity over the canonical facts, folded with VERSION.
   * @param array<string, string|array<int|string, mixed>> $facts
   *   The canonical facts the digest was computed over, key-ordered. Retained so a
   *   reader can reconstruct the accepted job, not only compare its identity.
   */
  private function __construct(
    public string $digest,
    public array $facts,
  ) {}

  /**
   * Derives the accepted-job identity from a submission's stored values.
   *
   * The one factory, called at acceptance to record the identity and at
   * confirmation to recompute it for comparison. Both callers pass the submission's
   * own data and the origin and destination the quote was priced for; nothing a
   * browser could set out of band reaches the digest, because every key read is on
   * the server-owned allowlist or is an endpoint the server resolved.
   *
   * Fail-closed: a value on the allowlist that is neither scalar nor array, or a
   * canonical fact set that cannot be JSON-encoded, throws rather than producing a
   * digest over silently discarded material. The caller converts that into a
   * refusal — no snapshot at acceptance, an InvalidRequest at confirmation — so a
   * job whose facts could not be fully canonicalized is never bound or confirmed.
   *
   * @param array<string, mixed> $data
   *   The submission's element values, as WebformSubmissionInterface::getData()
   *   returns them.
   * @param string $origin
   *   The pickup address the price was computed for.
   * @param string $destination
   *   The delivery address the price was computed for.
   *
   * @throws \UnexpectedValueException
   *   When a value cannot be canonicalized, or the facts cannot be encoded.
   */
  public static function fromSubmissionData(array $data, string $origin, string $destination): self {
    $facts = [
      self::ENDPOINT_ORIGIN_KEY => self::normalizeEndpoint($origin),
      self::ENDPOINT_DESTINATION_KEY => self::normalizeEndpoint($destination),
    ];

    foreach (AddressDistanceHandler::QUOTE_INPUT_FIELDS as $field) {
      if (!array_key_exists($field, $data)) {
        continue;
      }
      $canonical = self::canonicalize($data[$field], $field);
      // An empty answer and an absent one are the same unknown, so a cleared field
      // does not read as a distinct job from one that never carried the element.
      // This drops "no answer", never a material value: '0' and FALSE canonicalize
      // to the non-empty string '0' and survive.
      if ($canonical === '' || $canonical === []) {
        continue;
      }
      $facts[$field] = $canonical;
    }

    // Key order is not a job fact. Sorting the map away means the identity depends
    // on which answers were given, never on the order the allowlist or the storage
    // happened to present them in. The order WITHIN a multi-value answer is left
    // as stored (see canonicalize()); only the top-level key order is normalized.
    ksort($facts);

    return new self(self::digestForFactsJson(self::encodeFacts($facts)), $facts);
  }

  /**
   * The canonical facts as the one deterministic JSON string the digest covers.
   *
   * Persisted verbatim beside the digest so a reader can reconstruct the accepted
   * job and re-derive its identity. Never re-normalized on the way out — the facts
   * this object holds are already canonical, and a second normalization would be a
   * second answer about what the job was.
   */
  public function factsJson(): string {
    return self::encodeFacts($this->facts);
  }

  /**
   * Re-derives the identity a stored facts JSON string must hash to.
   *
   * The read-side counterpart to fromSubmissionData(): given the exact JSON that
   * was persisted, it recomputes the digest the same way, folding in VERSION. A
   * reader compares the result with the stored digest to prove the stored identity
   * is the identity of the stored facts — a facts blob and a digest that do not
   * correspond are a corrupted or mismatched record and are refused by the caller.
   *
   * @param string $factsJson
   *   The persisted canonical facts JSON, byte-for-byte as factsJson() wrote it.
   */
  public static function digestForFactsJson(string $factsJson): string {
    return hash('sha256', self::VERSION . '|' . $factsJson);
  }

  /**
   * Encodes a canonical facts map to one deterministic JSON string.
   *
   * @param array<string, string|array<int|string, mixed>> $facts
   *   The canonical, key-ordered facts.
   *
   * @throws \UnexpectedValueException
   *   When the facts cannot be JSON-encoded, so a digest is never produced from a
   *   failed serialization.
   */
  private static function encodeFacts(array $facts): string {
    try {
      return json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \UnexpectedValueException('Accepted-job facts could not be encoded: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Normalizes one endpoint to a stable comparable form.
   *
   * Trimmed, inner whitespace collapsed to single spaces, and lowercased, so the
   * raw submission value read at acceptance and the request factory's already-
   * trimmed address read at confirmation reduce to the same bytes. A genuine edit —
   * a different street, unit, or city — still changes the result; only formatting
   * noise is removed.
   */
  private static function normalizeEndpoint(string $address): string {
    $collapsed = preg_replace('/\s+/u', ' ', trim($address));

    return mb_strtolower($collapsed ?? '');
  }

  /**
   * Reduces one stored value to a deterministic canonical form.
   *
   * Scalars become their trimmed string, so a number stored as an int and the same
   * number stored as a numeric string canonicalize alike and a boolean is a stable
   * '1' or '0' rather than PHP's context-dependent string cast. Arrays are
   * canonicalized element-wise, and their order is PRESERVED: a multi-value answer
   * is kept in the order Webform stored it (stable element-definition order),
   * because the alternative — sorting — could reduce two genuinely different
   * ordered sequences to one identity, and collapsing two jobs into one is the
   * fail-open direction this must never take. A keyed array is key-ordered, since a
   * map's key order is not a fact, and empty members are dropped so a blank option
   * cannot shift an identity.
   *
   * Note this does NOT make a scalar '1' and a single-element list ['1'] identical:
   * they are different stored shapes and canonicalize to a string and a list
   * respectively. That is deliberate. A given Webform element has one fixed storage
   * shape — a single checkbox always stores a scalar, a multi-value element always
   * stores an array — so the same field never arrives as both; and if an element's
   * type were changed so its shape did change, that is a different form describing a
   * different job, which should not share an identity.
   *
   * @param mixed $value
   *   The raw stored value.
   * @param string $field
   *   The allowlist field the value came from, named only for the exception
   *   message so a rejected shape is traceable.
   *
   * @return string|array<int|string, mixed>
   *   The canonical scalar string, or the canonical array. An empty string or empty
   *   array means "no answer" to the caller.
   *
   * @throws \UnexpectedValueException
   *   When the value is neither scalar nor array — an object or resource that
   *   cannot appear in well-formed submission data — so material a browser or a
   *   corrupted store injected is never silently dropped from the identity.
   */
  private static function canonicalize(mixed $value, string $field): string|array {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
      return trim((string) $value);
    }
    if (!is_array($value)) {
      // NULL, an object, or a resource. None is a valid submission answer, so it is
      // refused rather than read as the empty string, which would drop it from the
      // identity and let an unrepresentable value confirm as "no answer".
      throw new \UnexpectedValueException(sprintf('Accepted-job field "%s" holds a %s, which is not a canonicalizable job fact.', $field, get_debug_type($value)));
    }

    $canonical = [];
    $isList = array_is_list($value);
    foreach ($value as $key => $item) {
      $inner = self::canonicalize($item, $field);
      if ($inner === '' || $inner === []) {
        continue;
      }
      if ($isList) {
        $canonical[] = $inner;
      }
      else {
        $canonical[$key] = $inner;
      }
    }

    // A list keeps its order (see the method docblock); only a map's key order is
    // normalized, because which keys answered is a fact and the order they were
    // stored in is not.
    if (!$isList) {
      ksort($canonical);
    }

    return $canonical;
  }

}
