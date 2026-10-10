<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Quote\QuoteResult;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\custom_webform_handlers\Review\ReviewState;

/**
 * The one amount a Checkout Attempt was started against, locked.
 *
 * Per CONTEXT.md the browser may display a price but never sets one, and this
 * class is how that is made structural rather than remembered. There is no public
 * constructor: an amount can only enter this object from the server's own
 * QuoteResult, from a Review Request a member of staff approved, or back out of a
 * row this module wrote earlier. Every one of those is a value object this module
 * built server-side. A controller holding a number a visitor posted has nothing it
 * can call.
 *
 * Locked means locked in both directions. The figure is copied into the Commerce
 * order item as an overridden unit price, so Commerce's own order refresh leaves
 * it alone, and it is copied into the attempt row, so what the customer agreed to
 * is still legible after a rate change. A Replan already copies the Dispatch
 * row's price snapshot forward verbatim for the same reason: moving the van does
 * not requote the job.
 *
 * Minor units are the stored form because that is what the existing quote path
 * produces and what an integer column can hold exactly. The decimal string is
 * derived on demand for Commerce, which prices in decimal strings.
 *
 * What is locked is the whole priced job, not only its total (Task 24B / E08).
 * The snapshot carries the complete integer-cent line-item breakdown, the raw
 * job-leg route meters and seconds the price was computed from, and the normalized
 * endpoint-pair fingerprint — everything a reader needs to see what was bought.
 * Four identities travel with that payload, and each answers a different question:
 *
 *  - snapshotId is a legacy name derived from six pricing-output fields. The
 *    attempt's idempotency key is built from it — and, since Task 24B, from the
 *    accepted-job identity beside it — so it is unchanged here: an in-flight
 *    attempt keeps the key it was opened under.
 *
 *  - payloadDigest is a schema-versioned SHA-256 over the whole stored payload,
 *    including both accepted-job fields below. It is the integrity seal: a row read
 *    back whose figures no longer hash to the digest they were written under has
 *    been corrupted or tampered with, and fromStored() refuses it rather than
 *    settling a booking against altered facts (E08).
 *
 *  - jobFactsDigest is the accepted-job identity the confirm path binds to (E09),
 *    and it is NOT computed here. It is the digest AcceptedJob derives from the
 *    material inputs — the normalized endpoints and the pricing/resource answers —
 *    supplied by the caller that has the submission. Recomputing it from the saved
 *    submission at confirmation and comparing proves the purchased job is unchanged;
 *    an edited address, package, handling, service, or timing input produces a
 *    different identity even when the price and the reserved duration are identical.
 *    It hashes inputs, never outputs, so a rate change alone never invalidates an
 *    unchanged job.
 *
 *  - jobFactsJson is the canonical accepted facts themselves — what the customer
 *    accepted, not merely a fingerprint of it (Task 24B / E09). A digest alone
 *    cannot reconstruct the job; these are the very facts jobFactsDigest was
 *    computed over, so a reader can both see the accepted job and re-derive its
 *    identity through AcceptedJob::digestForFactsJson() and prove the stored
 *    identity is the identity of these stored facts. fromStored() performs exactly
 *    that re-derivation and refuses a row whose facts and digest do not correspond.
 *
 * completeProvenance records whether this snapshot carries the recomputable priced
 * job — route facts and a summing breakdown — for the lifecycle to display (E08).
 * It is deliberately NOT what gates the confirm-time identity check: a
 * staff-approved figure has no route facts yet still carries an accepted-job
 * identity, so it is bound at confirmation exactly as an automatic quote is. Only a
 * genuine legacy row, written before this payload existed, carries no identity at
 * all, and only that row skips the check — never by a blanket provenance bypass,
 * and never by a newly built snapshot, which cannot be created without one.
 */
final readonly class QuoteSnapshot {

  /**
   * The quote status that means staff must price the job, not the server.
   *
   * A Manual Review job consumes no capacity and takes no payment, so it may not
   * reach an order at all. Named here rather than compared inline because the
   * refusal below is the only thing standing between a reviewed job and a charge.
   */
  const MANUAL_REVIEW_STATUS = 'manual_review';

  /**
   * The version of the accepted-snapshot payload this class writes and validates.
   *
   * Bumped when the set of fields the payload digest covers, or the way they are
   * canonicalized, changes. Raised to 3 at Task 24B when the canonical accepted
   * facts (jobFactsJson) joined the sealed payload beside the accepted-job identity.
   * It is stored beside the digest so a row written under one layout is read back
   * under the same one: a row whose stored version matches this constant has its
   * digest validated, a genuine legacy row with no version is read compatibly with
   * incomplete provenance, and any other version is refused rather than validated
   * against a layout it was never written under.
   */
  const SCHEMA_VERSION = 3;

  /**
   * The version stamped on a snapshot rebuilt from a genuine pre-24B legacy row.
   *
   * Zero is not a layout this class ever writes to storage — every snapshot it
   * builds today carries SCHEMA_VERSION. It exists only so a legacy object read back
   * through the legacy path is self-describing in memory: its schemaVersion reports
   * "written before the sealed payload existed", it carries no accepted-job identity
   * to bind, and its integrity digest is folded under this sentinel so it can never
   * be mistaken for one computed under a current layout.
   */
  const LEGACY_SCHEMA_VERSION = 0;

  /**
   * Versions the ENCODING of the reservation binding identifier, standalone.
   *
   * bindingIdentity() stamps this on a current-format snapshot's persisted binding
   * so the way that binding string is assembled is versioned independently of the
   * sealed payload's SCHEMA_VERSION. The two answer different questions: SCHEMA_VERSION
   * governs the layout the payload digest is computed under; this prefix governs how a
   * reservation-binding string is composed. A later payload-layout change therefore
   * bumps SCHEMA_VERSION without touching this, so a reservation persisted under one
   * binding encoding is still compared byte-for-byte under the same encoding — do not
   * re-tie this to the schema version. A genuine legacy snapshot carries no identity
   * and so no prefix: its binding stays the bare snapshotId exactly as pre-24B
   * reservations persisted it.
   */
  const BINDING_IDENTITY_PREFIX = 'v3:';

  /**
   * Constructs one immutable server-authoritative price snapshot.
   *
   * @param string $snapshotId
   *   A stable identifier for the priced figure, derived from the figure itself.
   *   It names what was quoted, not when: two attempts quoted identically share
   *   it, and the attempt's own idempotency key is what distinguishes them.
   * @param int $amountCents
   *   The authoritative amount in minor units. Always positive.
   * @param string $currency
   *   An upper-case ISO 4217 alphabetic code.
   * @param string $rateVersion
   *   Which rate table produced the figure, carried for audit.
   * @param string $routeSource
   *   The normalized provider that supplied the priced route, when automatic.
   * @param string $routeFingerprint
   *   A one-way identity for the exact normalized route result.
   * @param int $routeCalculatedAt
   *   When that result was originally calculated, in UTC.
   * @param int|null $routeMeters
   *   The raw job-leg distance in meters the price was computed from, when the
   *   figure was priced from a route. NULL for a staff-approved or legacy figure.
   * @param int|null $routeSeconds
   *   The raw job-leg duration in seconds, on the same basis as $routeMeters.
   * @param array<int, array<string, mixed>> $lineItems
   *   The complete integer-cent line-item breakdown, in calculation order, whose
   *   amounts sum to $amountCents. Empty for a staff-approved or legacy figure.
   * @param string $pairFingerprint
   *   The normalized origin/destination endpoint-pair fingerprint the price was
   *   computed for, or '' when the endpoints are not part of this figure.
   * @param string $jobFactsDigest
   *   The accepted-job identity (Task 24B / E09), a SHA-256 hex string AcceptedJob
   *   derived from the material inputs, or '' for a genuine legacy figure that
   *   carries none and is therefore skipped by the confirm-time identity check.
   * @param string $jobFactsJson
   *   The canonical accepted facts jobFactsDigest was computed over, verbatim as
   *   AcceptedJob::factsJson() produced them, or '' for a legacy figure. Retained so
   *   the accepted job can be reconstructed, not only compared.
   * @param int $schemaVersion
   *   The payload layout this snapshot's digest was computed under.
   * @param string $payloadDigest
   *   The schema-versioned integrity digest over the whole payload.
   * @param bool $completeProvenance
   *   Whether this snapshot carries the recomputable priced job — route facts and a
   *   summing breakdown — for lifecycle display. Not the confirm-time gate.
   */
  private function __construct(
    public string $snapshotId,
    public int $amountCents,
    public string $currency,
    public string $rateVersion,
    public string $routeSource,
    public string $routeFingerprint,
    public int $routeCalculatedAt,
    public ?int $routeMeters,
    public ?int $routeSeconds,
    public array $lineItems,
    public string $pairFingerprint,
    public string $jobFactsDigest,
    public string $jobFactsJson,
    public int $schemaVersion,
    public string $payloadDigest,
    public bool $completeProvenance,
  ) {}

  /**
   * Locks the server's own quote, refusing anything that is not chargeable.
   *
   * Returns NULL rather than throwing, because every refusal here is a normal
   * operational outcome the caller must convert into a refused checkout: a
   * reviewed job, a job priced at nothing, a currency the site does not use, or a
   * job whose accepted identity could not be derived. None of them is a programming
   * error, and none of them may become an order.
   *
   * The accepted job is supplied by the caller rather than derived here as an
   * AcceptedJob value object: this class holds a price and its provenance, and the
   * material inputs the identity is derived from — the full submission — belong to
   * the checkout starter, which builds the one shared AcceptedJob canonicalization
   * and passes it in whole. Passing the object rather than a bare digest is what
   * guarantees the stored identity and the stored facts correspond: they come from
   * the same canonicalization of the same job, so build() can seal both and a reader
   * can re-derive one from the other.
   *
   * A newly accepted automatic quote MUST carry a valid identity. There is no
   * legacy tolerance on this door — the legacy path exists only for reading rows
   * written before the identity existed, never for minting a new identity-less
   * snapshot. If the accepted job carries no usable identity, build() refuses, and
   * the checkout is refused with it.
   */
  public static function fromQuoteResult(QuoteResult $quote, string $origin, string $destination, AcceptedJob $acceptedJob): ?self {
    if ($quote->status() === self::MANUAL_REVIEW_STATUS) {
      return NULL;
    }

    $distance = $quote->distance();

    return self::build(
      $quote->totalCents(),
      $quote->currency(),
      $quote->rateVersion(),
      $distance->provider(),
      $distance->routeFingerprint($origin, $destination),
      $distance->calculatedAt(),
      $distance->distanceMeters(),
      $distance->durationSeconds(),
      $quote->lineItems(),
      DistanceResult::pairFingerprint($origin, $destination),
      $acceptedJob->digest,
      $acceptedJob->factsJson(),
    );
  }

  /**
   * Locks the figure a member of staff approved on a Review Request.
   *
   * The other door into this class, and it is the same shape of door: an amount
   * still cannot arrive from a request. fromQuoteResult() takes the server's own
   * QuoteResult; this takes a ReviewRequest, which only ReviewRequestStore builds
   * and only from a row a staff form wrote. A controller holding a number a
   * visitor posted has nothing here it can call either — passing ints was the
   * obvious signature and is exactly the one that would have given it something.
   *
   * A reviewed job is refused by fromQuoteResult() because at quote time nobody
   * had priced it. Once somebody has, the refusal has served its purpose, and the
   * approved figure is as authoritative as a rate table's — more so, since a human
   * looked at the job. So rateVersion names the review rather than a rate table:
   * an auditor reading the order item learns which decision produced the price.
   *
   * The approved figure is still bound to the job it was approved for (Task 24B /
   * E09), and it is bound the same way an automatic quote is: the caller derives an
   * AcceptedJob from the same submission the review priced and passes it in whole.
   * A reviewed booking then confirms only while the job on the submission is the one
   * the reviewer saw — the reviewed price is retained, and the identity is what an
   * intervening edit would change. A review whose job facts could not be derived —
   * unreadable endpoints, an unrepresentable value — has no AcceptedJob to pass and
   * is refused by the caller before it reaches here; it is NOT quietly recorded as a
   * legacy figure, because a newly accepted review is not a legacy row.
   *
   * Only an approved request qualifies. Under review is unpriced, declined and
   * cancelled were refused, expired lapsed, and scheduled is already paid — none
   * may open a second payment. Whether an approved offer is still within its
   * deadline is the caller's to ask, because only the caller has a clock; see
   * ReviewRequest::hasLapsed().
   */
  public static function fromApprovedReview(ReviewRequest $request, AcceptedJob $acceptedJob): ?self {
    if ($request->state !== ReviewState::Approved || $request->quoteAmountCents === NULL || $request->quoteCurrency === NULL) {
      return NULL;
    }

    return self::build(
      $request->quoteAmountCents,
      $request->quoteCurrency,
      'review:' . $request->reviewId,
      '',
      '',
      0,
      NULL,
      NULL,
      [],
      '',
      $acceptedJob->digest,
      $acceptedJob->factsJson(),
    );
  }

  /**
   * Rebuilds a snapshot this module stored earlier, or refuses it.
   *
   * The same validation as a fresh quote, applied to our own row, and then the
   * integrity check the row's own schema version selects. There are exactly four
   * outcomes, and none of them is "sanitize and accept" (Task 24B / E08):
   *
   *  - A row with no schema version AND no new-format field predates this payload.
   *    It is genuine legacy: its historical figure is honoured through the legacy
   *    assembler, its provenance is flagged incomplete, and its accepted-job
   *    identity is empty, so the confirm gate skips it rather than false-refusing a
   *    job it cannot recompute. This is the ONLY skip path.
   *
   *  - A row with no schema version but SOME new-format field populated — a payload
   *    digest, an accepted-job identity, accepted facts, or a line-item breakdown —
   *    is a mixed record: a current-format write that lost its version, or a forged
   *    row assembled to look legacy so its populated fields escape validation. It is
   *    refused. A partial current write is never reinterpreted as legacy to slip past
   *    the digest, which is precisely the NULL-schema downgrade this closes.
   *
   *  - A row whose schema version matches this class is validated strictly. It must
   *    carry a payload digest, an accepted-job identity, and the accepted facts, all
   *    non-empty; the facts must re-derive to the identity; and its figures must
   *    still hash to the stored digest. Any of those failing refuses the row.
   *
   *  - A row with any other version is refused. A higher one is a layout this code
   *    cannot validate; a lower one is a superseded layout with different rules.
   *    Neither is read as legacy and neither is validated against today's rules.
   *
   *  - A figure that no longer reads as chargeable — an emptied currency, a lost
   *    amount, a blank identifier — fails the mandatory checks first, whatever its
   *    version, because a snapshot that cannot name a price must not resolve to one.
   */
  public static function fromStored(
    ?string $snapshotId,
    ?int $amountCents,
    ?string $currency,
    ?string $rateVersion,
    ?string $routeSource = NULL,
    ?string $routeFingerprint = NULL,
    ?int $routeCalculatedAt = NULL,
    ?int $routeMeters = NULL,
    ?int $routeSeconds = NULL,
    ?string $lineItemsJson = NULL,
    ?string $pairFingerprint = NULL,
    ?int $schemaVersion = NULL,
    ?string $payloadDigest = NULL,
    ?string $jobFactsDigest = NULL,
    ?string $jobFactsJson = NULL,
  ): ?self {
    // Mandatory in every case. A figure with no identifier, amount, or currency is
    // not a chargeable snapshot whatever schema wrote it, so it fails before the
    // version is even consulted.
    if ($snapshotId === NULL || trim($snapshotId) === '' || $amountCents === NULL || $currency === NULL) {
      return NULL;
    }

    $storedPayloadDigest = is_string($payloadDigest) ? trim($payloadDigest) : '';
    $storedJobFacts = is_string($jobFactsDigest) ? strtolower(trim($jobFactsDigest)) : '';
    // The accepted facts are compared and re-hashed byte-for-byte as they were
    // written, so they are NOT trimmed or otherwise reshaped — only "absent" is
    // normalized to the empty string. A stray surrounding byte would break the
    // digest correspondence below, which is the correct fail-closed outcome, not
    // something to paper over here.
    $storedJobFactsJson = is_string($jobFactsJson) ? $jobFactsJson : '';
    $storedLineItemsJson = is_string($lineItemsJson) ? $lineItemsJson : '';

    // Whether the row carries any field that only a Task 24B (or later) write
    // produces. A genuine pre-24B row has none of these. The pre-24B route
    // provenance fields (routeSource/routeFingerprint/routeCalculatedAt) are NOT on
    // this list because a legacy row legitimately carried them; the raw route
    // metrics (routeMeters/routeSeconds), the endpoint-pair fingerprint, and the
    // three sealed-payload fields ARE, because buildLegacy() leaves every one of them
    // absent. The metrics and fingerprint were omitted from an earlier version of
    // this check, which let a versionless row smuggle them in below the mixed-record
    // gate. Presence is tested without collapsing zero into absent: a zero-meter or
    // zero-second route is a real priced write, so NULL — not falsiness — is what
    // marks a route metric absent.
    $hasNewFormatField = $storedPayloadDigest !== ''
      || $storedJobFacts !== ''
      || trim($storedJobFactsJson) !== ''
      || trim($storedLineItemsJson) !== ''
      || $routeMeters !== NULL
      || $routeSeconds !== NULL
      || (is_string($pairFingerprint) && trim($pairFingerprint) !== '');

    // Which of the outcomes this row takes is decided by its schema version and the
    // new-format fields together, before any figure is trusted.
    if ($schemaVersion === NULL) {
      // A versionless row is legacy ONLY if it is versionless in full. A versionless
      // row carrying a new-format field is a mixed record — a current write that lost
      // its version, or one forged to look legacy — and is refused rather than read
      // down to the unvalidated legacy path. This is the NULL-schema downgrade close.
      if ($hasNewFormatField) {
        return NULL;
      }

      return self::buildLegacy(
        $snapshotId,
        $amountCents,
        $currency,
        (string) $rateVersion,
        (string) $routeSource,
        (string) $routeFingerprint,
        (int) $routeCalculatedAt,
      );
    }

    if ($schemaVersion !== self::SCHEMA_VERSION) {
      // A higher version is a layout this code cannot validate; a lower one is a
      // superseded layout with different rules. Neither is read as legacy.
      return NULL;
    }

    // A current-schema row must carry all three seals, non-empty. A row missing any
    // was written partially or has been corrupted, and is refused here rather than
    // read as legacy — a current-schema row never skips validation.
    if ($storedPayloadDigest === '' || $storedJobFacts === '' || $storedJobFactsJson === '') {
      return NULL;
    }

    // The stored line items, decoded before the rebuild so the same figures that
    // were written are the ones re-hashed. Decoding is fail-closed and does NOT
    // silently substitute an empty breakdown for an unreadable one: a non-empty blob
    // that is not valid JSON, or valid JSON that is not an array, refuses the row.
    // The earlier "a corrupt breakdown makes the digest disagree" reasoning held only
    // for a priced row — a staff-approved snapshot legitimately seals an EMPTY
    // breakdown, so a garbled blob silently read as [] would re-hash to the very
    // digest that empty breakdown was sealed under and pass corruption off as a
    // priceless figure. Only an absent blob ('') is a legitimate empty breakdown and
    // stays [].
    $lineItems = [];
    if ($storedLineItemsJson !== '') {
      try {
        $decoded = json_decode($storedLineItemsJson, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        return NULL;
      }
      if (!is_array($decoded)) {
        return NULL;
      }
      $lineItems = $decoded;
    }

    // Rebuilt through the same strict door a fresh quote takes: build() re-derives
    // the accepted-job identity from the stored facts and refuses the row unless the
    // stored digest matches it, so a facts blob and an identity that do not
    // correspond — tampering, corruption, or facts written under a superseded
    // canonicalization — fail closed before any figure is trusted.
    $rebuilt = self::build(
      $amountCents,
      $currency,
      (string) $rateVersion,
      (string) $routeSource,
      (string) $routeFingerprint,
      (int) $routeCalculatedAt,
      $routeMeters,
      $routeSeconds,
      $lineItems,
      (string) $pairFingerprint,
      $storedJobFacts,
      $storedJobFactsJson,
    );
    if ($rebuilt === NULL) {
      return NULL;
    }

    // A current-schema row's figures must still hash to the digest they were
    // written under. A row that no longer does has been altered since — corruption
    // or tampering — and is refused rather than used to settle a booking against
    // changed facts. Because the payload digest covers both accepted-job fields, a
    // tampered identity or facts blob is caught here as well as by the
    // correspondence check inside build().
    if (!hash_equals($rebuilt->payloadDigest, $storedPayloadDigest)) {
      return NULL;
    }

    // The stored identifier wins over a freshly derived one, so a snapshot keeps
    // the name it was written under even if the derivation is ever changed.
    return new self(
      substr(trim($snapshotId), 0, 128),
      $rebuilt->amountCents,
      $rebuilt->currency,
      $rebuilt->rateVersion,
      $rebuilt->routeSource,
      $rebuilt->routeFingerprint,
      $rebuilt->routeCalculatedAt,
      $rebuilt->routeMeters,
      $rebuilt->routeSeconds,
      $rebuilt->lineItems,
      $rebuilt->pairFingerprint,
      $rebuilt->jobFactsDigest,
      $rebuilt->jobFactsJson,
      $rebuilt->schemaVersion,
      $rebuilt->payloadDigest,
      $rebuilt->completeProvenance,
    );
  }

  /**
   * Returns the amount as the decimal string Commerce prices in.
   */
  public function amountDecimal(): string {
    return number_format($this->amountCents / 100, 2, '.', '');
  }

  /**
   * Whether this snapshot carries an accepted-job identity to bind at confirmation.
   *
   * True for every snapshot this class writes today — an automatic quote and a
   * staff-approved figure both carry one, and build() cannot produce one without.
   * False only for a genuine legacy row rebuilt through buildLegacy(), which the
   * confirm path must skip rather than refuse. It is the presence of the identity,
   * not the completeness of the priced job, that decides whether the confirm-time
   * check runs.
   */
  public function hasJobFactsIdentity(): bool {
    return $this->jobFactsDigest !== '';
  }

  /**
   * The identifier a reservation persists to bind the accepted quote to itself.
   *
   * Task 24B / E09 residual. snapshotId names only the priced FIGURE — it is a
   * truncated hash of amount, currency, rate, and route — so two materially
   * different jobs that price identically (same endpoints, same cached route, same
   * duration, same total) share it. A reservation bound on snapshotId alone
   * therefore cannot tell those jobs apart at confirmation: the accepted quote it
   * settles is provably the same PRICE, but not provably the same JOB. A
   * current-format snapshot folds its accepted-job identity into the binding so the
   * persisted value distinguishes the job, not just the figure — two jobs that price
   * alike but differ in any material input carry different bindings and cannot be
   * confirmed against one another's reservation.
   *
   * The composition is versioned by BINDING_IDENTITY_PREFIX, independently of the
   * sealed payload's SCHEMA_VERSION, so a future payload-layout bump does not change
   * a comparison a reservation already persisted. It stays within the reservation
   * column's budget by construction: the 3-character prefix, the 32-hex snapshotId, a
   * one-character separator, and the 64-hex jobFactsDigest total 100 ASCII
   * characters, and price_snapshot_id is varchar_ascii(128).
   *
   * A genuine legacy snapshot carries no accepted-job identity, so its binding is the
   * bare snapshotId, byte-identical to what pre-24B reservations persisted. The fold
   * is append-only: nothing already written changes meaning, and legacy idempotency
   * is preserved.
   */
  public function bindingIdentity(): string {
    if (!$this->hasJobFactsIdentity()) {
      return $this->snapshotId;
    }
    return self::BINDING_IDENTITY_PREFIX . $this->snapshotId . ':' . $this->jobFactsDigest;
  }

  /**
   * Applies the shared validation and derives the identifier for a NEW snapshot.
   *
   * This is the one door a freshly accepted job takes, and it is strict: a snapshot
   * built here always carries a valid accepted-job identity and the facts that
   * identity was computed over, or it is not built at all. There is no path from
   * here to an identity-less current-schema object — that closes the bypass where a
   * blanked digest still produced a schema-current snapshot that fromStored() would
   * then reject as un-round-trippable. Legacy rows do not come through here; they go
   * through buildLegacy(), which is the only assembler that tolerates absent
   * identity.
   *
   * The identifier is a truncated SHA-256 of the figures rather than a random
   * value, so the same quote is always named the same thing and an auditor can
   * recompute it from the row. It is not a secret and proves nothing: it names a
   * price, and the store still re-proves feasibility before capacity moves.
   */
  private static function build(
    int $amountCents,
    string $currency,
    string $rateVersion,
    string $routeSource = '',
    string $routeFingerprint = '',
    int $routeCalculatedAt = 0,
    ?int $routeMeters = NULL,
    ?int $routeSeconds = NULL,
    array $lineItems = [],
    string $pairFingerprint = '',
    string $jobFactsDigest = '',
    string $jobFactsJson = '',
  ): ?self {
    $currency = strtoupper(trim($currency));
    $rateVersion = trim($rateVersion);
    $routeSource = trim($routeSource);
    $routeFingerprint = strtolower(trim($routeFingerprint));
    $pairFingerprint = strtolower(trim($pairFingerprint));
    $jobFactsDigest = strtolower(trim($jobFactsDigest));

    // A new snapshot carries a real accepted-job identity or it is not a snapshot.
    // A missing or malformed identity is refused here, not blanked-and-kept: blanking
    // was the bypass that let an identity-less object still be stamped schema-current
    // and then fail to round-trip through fromStored(). The caller converts this NULL
    // into a refused checkout.
    if ($jobFactsDigest === '' || preg_match('/^[a-f0-9]{64}$/D', $jobFactsDigest) !== 1) {
      return NULL;
    }
    // The accepted facts must be present and must re-derive to exactly this identity.
    // This is what makes the two stored E09 fields a matched pair rather than two
    // independent columns: a facts blob that does not hash to the digest — tampering,
    // corruption, or facts canonicalized under a superseded AcceptedJob version — is
    // refused, so the digest a confirm binds to is always the identity of the facts
    // stored beside it.
    if ($jobFactsJson === '' || !hash_equals(AcceptedJob::digestForFactsJson($jobFactsJson), $jobFactsDigest)) {
      return NULL;
    }

    if ($routeSource !== '' && preg_match('/^[a-z0-9_]{1,64}$/D', $routeSource) !== 1) {
      $routeSource = '';
    }
    if ($routeFingerprint !== '' && preg_match('/^[a-f0-9]{64}$/D', $routeFingerprint) !== 1) {
      $routeFingerprint = '';
    }
    if ($pairFingerprint !== '' && preg_match('/^[a-f0-9]{64}$/D', $pairFingerprint) !== 1) {
      $pairFingerprint = '';
    }
    $routeCalculatedAt = max(0, $routeCalculatedAt);
    // A non-positive meter or second count is not a job leg. Normalized to NULL so
    // "no route was priced" reads the same whether the figure never had one or a
    // bad write left a zero, and so completeProvenance below cannot be fooled into
    // treating a zeroed leg as a recomputable route.
    $routeMeters = ($routeMeters !== NULL && $routeMeters > 0) ? $routeMeters : NULL;
    $routeSeconds = ($routeSeconds !== NULL && $routeSeconds >= 0) ? $routeSeconds : NULL;

    if ($amountCents < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
      return NULL;
    }

    $rateVersion = substr($rateVersion, 0, 64);

    // The snapshot identifier is unchanged from its original six-field derivation
    // (Task 24B compatibility): the attempt's idempotency key is built from it, so
    // an attempt opened before this payload existed must still derive the same key.
    // The accepted-job identity is folded into the attempt's key by CheckoutAttempt,
    // not into this name, so two materially different jobs that price identically get
    // distinct intent keys without disturbing this identifier's derivation. The
    // richer payload is sealed by the separate digest below.
    $snapshotId = substr(hash('sha256', implode('|', [
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
    ])), 0, 32);

    // The complete integer-cent breakdown, canonicalized for storage and for the
    // payload digest, so the priced job is recorded whole and its amounts are the
    // ones the total is proved to be the sum of.
    $canonicalLineItems = self::canonicalLineItems($lineItems);

    $completeProvenance = self::isComplete(
      $routeSource,
      $routeMeters,
      $routeSeconds,
      $canonicalLineItems,
      $pairFingerprint,
      $amountCents,
    );

    $payloadDigest = self::computePayloadDigest(
      self::SCHEMA_VERSION,
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
      $routeMeters,
      $routeSeconds,
      $canonicalLineItems,
      $pairFingerprint,
      $jobFactsDigest,
      $jobFactsJson,
    );

    return new self(
      $snapshotId,
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
      $routeMeters,
      $routeSeconds,
      $canonicalLineItems,
      $pairFingerprint,
      $jobFactsDigest,
      $jobFactsJson,
      self::SCHEMA_VERSION,
      $payloadDigest,
      $completeProvenance,
    );
  }

  /**
   * Rebuilds a genuine pre-24B legacy row, which carries no accepted-job identity.
   *
   * The ONLY assembler that tolerates absent identity, and it is reachable only from
   * fromStored()'s versionless-and-new-format-free branch — never from a factory, so
   * a newly accepted job can never acquire the legacy skip. It honours the historical
   * figure and its pre-24B route provenance, records no identity and no facts (so the
   * confirm gate skips it rather than false-refuse a job it cannot recompute), flags
   * provenance incomplete, and stamps LEGACY_SCHEMA_VERSION so the object is
   * self-describing as pre-payload. Its integrity digest is folded under that
   * sentinel, so it can never be confused with a current-layout digest.
   */
  private static function buildLegacy(
    string $snapshotId,
    int $amountCents,
    string $currency,
    string $rateVersion,
    string $routeSource,
    string $routeFingerprint,
    int $routeCalculatedAt,
  ): ?self {
    $currency = strtoupper(trim($currency));
    $rateVersion = substr(trim($rateVersion), 0, 64);
    $routeSource = trim($routeSource);
    $routeFingerprint = strtolower(trim($routeFingerprint));
    if ($routeSource !== '' && preg_match('/^[a-z0-9_]{1,64}$/D', $routeSource) !== 1) {
      $routeSource = '';
    }
    if ($routeFingerprint !== '' && preg_match('/^[a-f0-9]{64}$/D', $routeFingerprint) !== 1) {
      $routeFingerprint = '';
    }
    $routeCalculatedAt = max(0, $routeCalculatedAt);

    if ($amountCents < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
      return NULL;
    }

    // A legacy row carries neither a breakdown, a route leg, nor an endpoint pair —
    // those are Task 24B payload fields it predates — so its provenance is always
    // incomplete and its digest is folded over the empty payload under the legacy
    // sentinel version.
    $payloadDigest = self::computePayloadDigest(
      self::LEGACY_SCHEMA_VERSION,
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
      NULL,
      NULL,
      [],
      '',
      '',
      '',
    );

    return new self(
      substr(trim($snapshotId), 0, 128),
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
      NULL,
      NULL,
      [],
      '',
      '',
      '',
      self::LEGACY_SCHEMA_VERSION,
      $payloadDigest,
      FALSE,
    );
  }

  /**
   * The line-item breakdown as JSON for the one column that stores it.
   *
   * The already-canonical list this snapshot holds, so what is written is what the
   * digest was computed over. Never re-normalized here — a second normalization
   * with a different rule would be a second answer about what the priced job was.
   */
  public function lineItemsJson(): string {
    return json_encode($this->lineItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
  }

  /**
   * Reduces a raw line-item list to the ordered, minimal breakdown the digest uses.
   *
   * Each surviving entry is exactly a machine-name code and its integer amount in
   * minor units — the two facts that are the priced job, stripped of the label and
   * the metadata, which are presentation and audit colour that must never move a
   * digest. Sorted by code so the record of a job does not depend on the order the
   * calculator happened to add its lines in, and any entry without a non-empty code
   * or a positive amount is dropped, because a zero line changes no price and a
   * nameless one names nothing.
   *
   * @param array<int, mixed> $lineItems
   *   The raw breakdown, in the shape QuoteCalculator::addLine() writes.
   *
   * @return array<int, array{code: string, amount_cents: int}>
   *   The canonical breakdown, ascending by code.
   */
  private static function canonicalLineItems(array $lineItems): array {
    $canonical = [];
    foreach ($lineItems as $item) {
      if (!is_array($item)) {
        continue;
      }
      $code = isset($item['code']) && is_string($item['code']) ? trim($item['code']) : '';
      $amount = isset($item['amount_cents']) && is_numeric($item['amount_cents']) ? (int) $item['amount_cents'] : 0;
      if ($code === '' || $amount <= 0) {
        continue;
      }
      $canonical[] = ['code' => substr($code, 0, 64), 'amount_cents' => $amount];
    }

    usort($canonical, static fn (array $a, array $b): int => $a['code'] <=> $b['code'] ?: $a['amount_cents'] <=> $b['amount_cents']);

    return $canonical;
  }

  /**
   * Encodes the canonical breakdown as one deterministic string for hashing.
   *
   * @param array<int, array{code: string, amount_cents: int}> $canonicalLineItems
   *   The already-canonical breakdown.
   */
  private static function encodeLineItems(array $canonicalLineItems): string {
    $parts = [];
    foreach ($canonicalLineItems as $item) {
      $parts[] = $item['code'] . ':' . $item['amount_cents'];
    }

    return implode(',', $parts);
  }

  /**
   * Whether a figure carries the recomputable priced job the lifecycle can display.
   *
   * True only for a server quote: it names its route provider, carries positive
   * job-leg meters, a defined duration, at least one priced line, and the endpoint
   * pair the price was computed for, and its line items sum to the stored total. A
   * staff-approved figure has none of the route facts, and a legacy row has neither
   * the breakdown nor the fingerprint, so both read as incomplete.
   *
   * This is an E08 display fact — "was the whole priced job recorded" — and no
   * longer gates the confirm-time identity check, which binds on jobFactsDigest
   * instead. A staff-approved figure is therefore incomplete here yet still bound at
   * confirmation, which is the reviewer's requirement that an approved figure retain
   * its reviewed price and still be tied to the job it was approved for.
   *
   * The sum check is what makes the breakdown trustworthy as a record: a breakdown
   * that does not add up to the total is not the priced job the total came from, so
   * it is treated as incomplete rather than shown as the job's whole cost.
   *
   * @param array<int, array{code: string, amount_cents: int}> $canonicalLineItems
   *   The canonical breakdown.
   */
  private static function isComplete(
    string $routeSource,
    ?int $routeMeters,
    ?int $routeSeconds,
    array $canonicalLineItems,
    string $pairFingerprint,
    int $amountCents,
  ): bool {
    if ($routeSource === '' || $routeMeters === NULL || $routeSeconds === NULL) {
      return FALSE;
    }
    if ($canonicalLineItems === [] || $pairFingerprint === '') {
      return FALSE;
    }

    $sum = 0;
    foreach ($canonicalLineItems as $item) {
      $sum += $item['amount_cents'];
    }

    return $sum === $amountCents;
  }

  /**
   * The schema-versioned integrity digest over the whole stored payload.
   *
   * Covers every stored field, so any change to what was written — the total, the
   * currency, the rate version, the route provenance, the breakdown, the endpoint
   * pair, the accepted-job identity, or the accepted facts — changes the digest and
   * is caught when the row is read back. The schema version is the digest's first
   * component and is passed in rather than read from the constant, so a legacy
   * object folded under LEGACY_SCHEMA_VERSION and a current object folded under
   * SCHEMA_VERSION can never collide, and a digest can never be mistaken for one
   * computed under a different layout.
   *
   * @param array<int, array{code: string, amount_cents: int}> $canonicalLineItems
   *   The canonical breakdown.
   */
  private static function computePayloadDigest(
    int $schemaVersion,
    int $amountCents,
    string $currency,
    string $rateVersion,
    string $routeSource,
    string $routeFingerprint,
    int $routeCalculatedAt,
    ?int $routeMeters,
    ?int $routeSeconds,
    array $canonicalLineItems,
    string $pairFingerprint,
    string $jobFactsDigest,
    string $jobFactsJson,
  ): string {
    return hash('sha256', implode('|', [
      $schemaVersion,
      $amountCents,
      $currency,
      $rateVersion,
      $routeSource,
      $routeFingerprint,
      $routeCalculatedAt,
      $routeMeters ?? -1,
      $routeSeconds ?? -1,
      self::encodeLineItems($canonicalLineItems),
      $pairFingerprint,
      $jobFactsDigest,
      // Length-prefixed so the facts JSON cannot collide with the field after it by
      // moving a delimiter — the facts are attacker-adjacent free-form text, unlike
      // the fixed-shape fields above, so its boundary is made explicit.
      strlen($jobFactsJson) . ':' . $jobFactsJson,
    ]));
  }

}
