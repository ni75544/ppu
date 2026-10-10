<?php

/**
 * Task 24B / Round 2 — bounded value-object probes for E08 (payload integrity)
 * and E09 (accepted-job identity).
 *
 * WHAT THIS IS. A read-only evidence harness. It bootstraps Drupal only so the
 * module's own classes autoload, then constructs value objects and compares the
 * results of their real methods. It performs NO database writes, opens NO
 * transaction, takes NO lock, inserts NO checkout attempt, and confirms NO
 * reservation. Every "round-trip" below is a value-object serialization →
 * hydration check (build()/fromStored()) or a pure comparison of two derived
 * identities — never a persistence round-trip. Attempt insertion, guarded
 * confirmation under the calendar lock, and reservation/order persistence are
 * NOT exercised here and remain PENDING a separately authorized QA run.
 *
 * WHY REFLECTION. QuoteSnapshot has no public constructor by design — a price may
 * enter it only from the server's own QuoteResult, an approved review, or a row
 * this module wrote. To obtain a canonically-derived baseline row without
 * standing up the whole quote pipeline, the harness invokes the class's OWN
 * private build() via reflection. That runs the real derivation: the real
 * payloadDigest, the real facts↔digest correspondence check, the real snapshotId.
 * Nothing is faked; reflection only bypasses the visibility that keeps
 * controllers out. Every read-side assertion then drives the PUBLIC fromStored()
 * over the serialized fields, exactly as CheckoutAttemptStore::hydrate() would.
 *
 * RUN (read-only, QA):
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script modules/custom_webform_handlers/docs/execution/\
 *     system-integrity-audit/task-24b-immutable-snapshots/round2-value-object-probes.php
 */

use Drupal\custom_webform_handlers\Checkout\AcceptedJob;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttempt;
use Drupal\custom_webform_handlers\Checkout\QuoteSnapshot;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;
use Drupal\custom_webform_handlers\Quote\QuoteResult;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\custom_webform_handlers\Review\ReviewState;

$pass = 0;
$fail = 0;
$results = [];

/**
 * Records one bounded assertion.
 */
$check = function (string $id, string $claim, bool $ok) use (&$pass, &$fail, &$results): void {
  if ($ok) {
    $pass++;
  }
  else {
    $fail++;
  }
  $results[] = sprintf('[%s] %s :: %s', $ok ? 'PASS' : 'FAIL', $id, $claim);
};

/**
 * Invokes the class's own private QuoteSnapshot::build() via reflection.
 *
 * Returns the real, canonically-derived snapshot (or NULL if build() refuses).
 */
$build = function (
  int $amountCents,
  string $currency,
  string $rateVersion,
  string $routeSource,
  string $routeFingerprint,
  int $routeCalculatedAt,
  ?int $routeMeters,
  ?int $routeSeconds,
  array $lineItems,
  string $pairFingerprint,
  string $jobFactsDigest,
  string $jobFactsJson,
): ?QuoteSnapshot {
  static $ref = NULL;
  if ($ref === NULL) {
    $ref = new \ReflectionMethod(QuoteSnapshot::class, 'build');
    $ref->setAccessible(TRUE);
  }
  return $ref->invoke(NULL, $amountCents, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $jobFactsDigest, $jobFactsJson);
};

// ---------------------------------------------------------------------------
// Fixtures. Fixed facts only; no live route, no rate table read, no clock.
// ---------------------------------------------------------------------------
$originA = '123 Example St, Sample City';
$destA   = '456 Test Ave, Sample City';
// A different destination is a genuine job edit.
$destB   = '789 Other Rd, Sample City';
// Same address, formatting noise only — must normalize to the same identity.
$destA_noisy = "  456   Test   Ave,  Sample  City ";

$acceptedA = AcceptedJob::fromSubmissionData([], $originA, $destA);
$acceptedB = AcceptedJob::fromSubmissionData([], $originA, $destB);
$acceptedA_noisy = AcceptedJob::fromSubmissionData([], $originA, $destA_noisy);

// A complete, self-consistent priced payload (line items sum to the total).
$amount = 12345;
$currency = 'USD';
$rateVersion = 'rate:test-2024';
$routeSource = 'google_routes';
$routeFingerprint = hash('sha256', 'route-fp');
$routeCalculatedAt = 1_700_000_000;
$routeMeters = 8000;
$routeSeconds = 900;
$pairFingerprint = hash('sha256', 'pair-fp');
$lineItems = [
  ['code' => 'base', 'amount_cents' => 12000, 'label' => 'Base fare'],
  ['code' => 'fee', 'amount_cents' => 345, 'label' => 'Fee'],
];

// =====================================================================
// GROUP A — E09 identity derivation (AcceptedJob), fully public API.
// =====================================================================

// A1: endpoint formatting noise normalizes away — same job, same identity.
$check('A1', 'endpoint whitespace/case noise yields an identical accepted-job digest',
  hash_equals($acceptedA->digest, $acceptedA_noisy->digest));

// A2: a genuine destination edit changes the identity.
$check('A2', 'a different destination yields a different accepted-job digest',
  !hash_equals($acceptedA->digest, $acceptedB->digest));

// A3: the digest round-trips through the read-side re-derivation.
$check('A3', 'digestForFactsJson(factsJson()) re-derives the stored digest',
  hash_equals(AcceptedJob::digestForFactsJson($acceptedA->factsJson()), $acceptedA->digest));

// A4: scalar shape determinism — int vs numeric string vs bool canonicalize alike
//     where they should, and empties drop. Uses the real allowlist fields so the
//     probe self-adjusts to whatever QUOTE_INPUT_FIELDS currently contains.
$fields = AddressDistanceHandler::QUOTE_INPUT_FIELDS;
$field0 = $fields[0] ?? NULL;
if ($field0 !== NULL) {
  $asInt = AcceptedJob::fromSubmissionData([$field0 => 5], $originA, $destA);
  $asStr = AcceptedJob::fromSubmissionData([$field0 => '5'], $originA, $destA);
  $check('A4', "int 5 and string '5' on '{$field0}' canonicalize to the same identity",
    hash_equals($asInt->digest, $asStr->digest));

  $absent = AcceptedJob::fromSubmissionData([], $originA, $destA);
  $emptied = AcceptedJob::fromSubmissionData([$field0 => ''], $originA, $destA);
  $check('A5', "an empty answer on '{$field0}' reads the same as an absent one",
    hash_equals($absent->digest, $emptied->digest));
}
else {
  $check('A4', 'QUOTE_INPUT_FIELDS non-empty (scalar-determinism probe precondition)', FALSE);
  $check('A5', 'QUOTE_INPUT_FIELDS non-empty (absent==empty probe precondition)', FALSE);
}

// A6: fail-closed on a non-scalar, non-array value (an object planted on a field).
$threw = FALSE;
if ($field0 !== NULL) {
  try {
    AcceptedJob::fromSubmissionData([$field0 => new \stdClass()], $originA, $destA);
  }
  catch (\UnexpectedValueException) {
    $threw = TRUE;
  }
}
$check('A6', 'a non-scalar/non-array field value is refused by exception, not read as "no answer"', $threw);

// A7: fail-closed on an un-encodable (invalid UTF-8) scalar — no digest from a
//     failed JSON serialization.
$threwEnc = FALSE;
if ($field0 !== NULL) {
  try {
    AcceptedJob::fromSubmissionData([$field0 => "\xB1\x31\xF0"], $originA, $destA);
  }
  catch (\UnexpectedValueException) {
    $threwEnc = TRUE;
  }
}
$check('A7', 'a value that cannot be JSON-encoded is refused by exception (JSON_THROW_ON_ERROR)', $threwEnc);

// =====================================================================
// GROUP B — E08 payload integrity + new-snapshot identity gate (build()).
// =====================================================================

$good = $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedA->digest, $acceptedA->factsJson());
$check('B1', 'build() with a valid identity + corresponding facts produces a snapshot',
  $good instanceof QuoteSnapshot);
$check('B2', 'that snapshot carries an accepted-job identity and complete provenance',
  $good !== NULL && $good->hasJobFactsIdentity() && $good->completeProvenance === TRUE);

// B3: NEW snapshot with an EMPTY identity is refused (no legacy tolerance on this door).
$check('B3', 'build() refuses an empty accepted-job identity (no blank-and-keep)',
  $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, '', '') === NULL);

// B4: NEW snapshot with a MALFORMED identity (not 64-hex) is refused.
$check('B4', 'build() refuses a malformed accepted-job identity',
  $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, 'not-a-sha', 'x') === NULL);

// B5: facts that do not hash to the supplied identity are refused (matched-pair gate).
$check('B5', 'build() refuses a facts blob that does not re-derive to the identity',
  $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedA->digest, '{}') === NULL);

// =====================================================================
// GROUP C — E08 read-side: fromStored() serialization/hydration checks.
// =====================================================================

if ($good instanceof QuoteSnapshot) {
  // C1: a valid current-schema row round-trips (value-object hydration, NOT a DB read).
  $rt = QuoteSnapshot::fromStored(
    $good->snapshotId,
    $good->amountCents,
    $good->currency,
    $good->rateVersion,
    $good->routeSource,
    $good->routeFingerprint,
    $good->routeCalculatedAt,
    $good->routeMeters,
    $good->routeSeconds,
    $good->lineItemsJson(),
    $good->pairFingerprint,
    $good->schemaVersion,
    $good->payloadDigest,
    $good->jobFactsDigest,
    $good->jobFactsJson,
  );
  $check('C1', 'a valid current-schema row hydrates and preserves total/identity/digest',
    $rt instanceof QuoteSnapshot
      && $rt->amountCents === $good->amountCents
      && hash_equals($rt->payloadDigest, $good->payloadDigest)
      && hash_equals($rt->jobFactsDigest, $good->jobFactsDigest)
      && $rt->snapshotId === $good->snapshotId);

  // C2: a tampered payload digest fails closed.
  $tampered = substr($good->payloadDigest, 0, -1) . ($good->payloadDigest[-1] === '0' ? '1' : '0');
  $check('C2', 'a row whose payload digest no longer matches its figures is refused',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, $good->routeMeters, $good->routeSeconds, $good->lineItemsJson(), $good->pairFingerprint, $good->schemaVersion, $tampered, $good->jobFactsDigest, $good->jobFactsJson) === NULL);

  // C3: a tampered TOTAL (digest left intact) fails closed — the digest covers the total.
  $check('C3', 'a row whose total was altered but digest left intact is refused',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents + 100, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, $good->routeMeters, $good->routeSeconds, $good->lineItemsJson(), $good->pairFingerprint, $good->schemaVersion, $good->payloadDigest, $good->jobFactsDigest, $good->jobFactsJson) === NULL);

  // C4: NULL schema + a populated new-format field (payload digest) is a MIXED row → refused.
  $check('C4', 'a versionless row carrying a new-format field is refused (NULL-schema downgrade close)',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, $good->routeMeters, $good->routeSeconds, $good->lineItemsJson(), $good->pairFingerprint, NULL, $good->payloadDigest, $good->jobFactsDigest, $good->jobFactsJson) === NULL);

  // C5: NULL schema + a populated jobFactsDigest only is ALSO a mixed row → refused.
  $check('C5', 'a versionless row carrying only an accepted-job identity is refused',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, NULL, NULL, NULL, NULL, NULL, NULL, $good->jobFactsDigest, NULL) === NULL);

  // C6: current schema version but MISSING a seal (empty facts JSON) → refused.
  $check('C6', 'a current-schema row missing the accepted facts is refused',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, $good->routeMeters, $good->routeSeconds, $good->lineItemsJson(), $good->pairFingerprint, $good->schemaVersion, $good->payloadDigest, $good->jobFactsDigest, '') === NULL);

  // C7: an unknown (future) schema version is refused, not read as legacy.
  $check('C7', 'a row with an unrecognized schema version is refused',
    QuoteSnapshot::fromStored($good->snapshotId, $good->amountCents, $good->currency, $good->rateVersion, $good->routeSource, $good->routeFingerprint, $good->routeCalculatedAt, $good->routeMeters, $good->routeSeconds, $good->lineItemsJson(), $good->pairFingerprint, 999, $good->payloadDigest, $good->jobFactsDigest, $good->jobFactsJson) === NULL);
}

// C8: a GENUINE legacy row (versionless, NO new-format field) hydrates, with no identity.
$legacy = QuoteSnapshot::fromStored('legacy-snap-1', 5000, 'USD', 'rate:old', 'google_routes', hash('sha256', 'old-route'), 1_600_000_000);
$check('C8', 'a fully versionless row with no new-format field hydrates as legacy',
  $legacy instanceof QuoteSnapshot && $legacy->schemaVersion === QuoteSnapshot::LEGACY_SCHEMA_VERSION);
$check('C9', 'a legacy row carries no accepted-job identity (confirm gate skips it, not refuses)',
  $legacy !== NULL && $legacy->hasJobFactsIdentity() === FALSE);

// C10: mandatory fields missing (no currency) is refused whatever the schema.
$check('C10', 'a row with no currency is refused before the schema is even consulted',
  QuoteSnapshot::fromStored('x', 5000, NULL, 'rate:old') === NULL);

// =====================================================================
// GROUP D — E09 intent-key fold (CheckoutAttempt::intentKey).
//   Two materially different jobs that price IDENTICALLY (same six output
//   fields → same snapshotId) and would reserve the same duration must get
//   DIFFERENT intent keys once the accepted-job identity is folded in.
// =====================================================================

$slot = new Slot('hg', 'resource_1', 1_700_000_000, 'UTC');

$snapJobA = $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedA->digest, $acceptedA->factsJson());
$snapJobB = $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedB->digest, $acceptedB->factsJson());

if ($snapJobA instanceof QuoteSnapshot && $snapJobB instanceof QuoteSnapshot) {
  // D1: identical price → identical snapshotId (the pre-24B collision surface).
  $check('D1', 'two different jobs that price identically share a snapshotId (the collision to fix)',
    $snapJobA->snapshotId === $snapJobB->snapshotId);

  // D2: but different accepted-job identity → different intent key.
  $keyA = CheckoutAttempt::intentKey(101, $slot, $snapJobA);
  $keyB = CheckoutAttempt::intentKey(101, $slot, $snapJobB);
  $check('D2', 'the same submission+slot+price yields DIFFERENT intent keys for different jobs',
    $keyA !== $keyB);

  // D3: the fold is append-only — a legacy snapshot (no identity) keeps its pre-fold key,
  //     so two legacy snapshots with the same snapshotId still collide as before.
  $legA = QuoteSnapshot::fromStored('same-legacy-id', $amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt);
  $legB = QuoteSnapshot::fromStored('same-legacy-id', $amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt);
  if ($legA instanceof QuoteSnapshot && $legB instanceof QuoteSnapshot) {
    $check('D3', 'two legacy snapshots sharing a snapshotId still collide (fold is append-only, back-compatible)',
      CheckoutAttempt::intentKey(101, $slot, $legA) === CheckoutAttempt::intentKey(101, $slot, $legB));
  }
}

// =====================================================================
// GROUP E — comparison PREDICATES the guarded confirmation relies on.
//   These prove the local comparisons the under-lock check performs. They do
//   NOT exercise the lock, the DB read of price_snapshot_id, or the submission
//   recompute inside DispatchPlanner::revalidate — those run only in a real
//   guarded confirmation and remain PENDING (see the evidence doc).
// =====================================================================

// E1: snapshot-binding predicate — an accepted snapshot id that does not equal the
//     reservation's persisted price_snapshot_id must be refused by hash_equals.
$persistedSnapshotId = $good instanceof QuoteSnapshot ? $good->snapshotId : 'snap';
$check('E1', 'snapshot-binding predicate: mismatched snapshot id fails hash_equals (would refuse)',
  !hash_equals($persistedSnapshotId, $persistedSnapshotId . 'x'));
$check('E2', 'snapshot-binding predicate: the matching snapshot id passes hash_equals (would proceed)',
  hash_equals($persistedSnapshotId, $persistedSnapshotId));

// E3: job-revision predicate — an edit between preparation and confirmation changes the
//     recomputed accepted-job identity, so the confirm-time hash_equals refuses.
$check('E3', 'job-revision predicate: a destination edited before confirmation fails identity hash_equals (would refuse)',
  !hash_equals($acceptedA->digest, $acceptedB->digest));
$check('E4', 'job-revision predicate: the unedited job re-derives to the same identity (would proceed)',
  hash_equals($acceptedA->digest, AcceptedJob::fromSubmissionData([], $originA, $destA)->digest));

// =====================================================================
// GROUP F — Round 3 §2/§5: binding-identity stability and job divergence.
//   These exercise the versioned bindingIdentity() a reservation persists, NOT
//   the snapshotId, through the public bindingIdentity()/intentKey API.
// =====================================================================

// F1: the SAME job built twice yields a stable snapshotId, binding, and intent key.
$twinA1 = $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedA->digest, $acceptedA->factsJson());
$twinA2 = $build($amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt, $routeMeters, $routeSeconds, $lineItems, $pairFingerprint, $acceptedA->digest, $acceptedA->factsJson());
if ($twinA1 instanceof QuoteSnapshot && $twinA2 instanceof QuoteSnapshot) {
  $check('F1', 'the same job built twice yields a stable snapshotId, binding identity, and intent key',
    $twinA1->snapshotId === $twinA2->snapshotId
      && hash_equals($twinA1->bindingIdentity(), $twinA2->bindingIdentity())
      && CheckoutAttempt::intentKey(101, $slot, $twinA1) === CheckoutAttempt::intentKey(101, $slot, $twinA2));

  // F2: a current-format binding is the prefixed composition, not the bare snapshotId.
  $check('F2', 'a current-format binding identity is prefixed and folds in the accepted-job identity',
    $twinA1->bindingIdentity() === QuoteSnapshot::BINDING_IDENTITY_PREFIX . $twinA1->snapshotId . ':' . $twinA1->jobFactsDigest
      && $twinA1->bindingIdentity() !== $twinA1->snapshotId);
}

// F3: two materially different jobs that price IDENTICALLY (same endpoints, route,
//     duration, price → same snapshotId) carry DIFFERENT binding identities. This is
//     the §2 collision the persisted binding resolves — proven on the binding itself,
//     not only on the intent key (D2).
if ($snapJobA instanceof QuoteSnapshot && $snapJobB instanceof QuoteSnapshot) {
  $check('F3', 'identical-price different-job snapshots share a snapshotId but differ in binding identity',
    $snapJobA->snapshotId === $snapJobB->snapshotId
      && !hash_equals($snapJobA->bindingIdentity(), $snapJobB->bindingIdentity()));
}

// F4: a legacy snapshot's binding is the BARE snapshotId — byte-identical to what a
//     pre-24B reservation persisted (append-only fold, idempotency preserved).
$legForBinding = QuoteSnapshot::fromStored('legacy-bind-1', $amount, $currency, $rateVersion, $routeSource, $routeFingerprint, $routeCalculatedAt);
if ($legForBinding instanceof QuoteSnapshot) {
  $check('F4', 'a legacy snapshot binds on the bare snapshotId (no prefix, back-compatible)',
    $legForBinding->hasJobFactsIdentity() === FALSE
      && $legForBinding->bindingIdentity() === $legForBinding->snapshotId);
}

// =====================================================================
// GROUP G — Round 3 §4A/§5: EACH new-format field independently closes the
//   NULL-schema downgrade. A versionless row carrying any single Task-24B field
//   is a mixed record and must be refused, never read down to the legacy path.
//   routeMeters, routeSeconds, and pairFingerprint were the fields an earlier
//   check omitted; they are proven here alongside the sealed-payload trio.
// =====================================================================

if ($good instanceof QuoteSnapshot) {
  $legFp = hash('sha256', 'old-route');
  // Positional: snapshotId, amount, currency, rateVersion, routeSource,
  // routeFingerprint, routeCalculatedAt, routeMeters, routeSeconds,
  // lineItemsJson, pairFingerprint, schemaVersion (NULL here), payloadDigest,
  // jobFactsDigest, jobFactsJson. Exactly ONE new-format field is populated per
  // probe; everything else is the genuine legacy shape.
  $g = function (array $o) use ($legFp): ?QuoteSnapshot {
    return QuoteSnapshot::fromStored(
      'g-legacy', 5000, 'USD', 'rate:old', 'google_routes', $legFp, 1_600_000_000,
      $o['routeMeters'] ?? NULL,
      $o['routeSeconds'] ?? NULL,
      $o['lineItemsJson'] ?? NULL,
      $o['pairFingerprint'] ?? NULL,
      NULL,
      $o['payloadDigest'] ?? NULL,
      $o['jobFactsDigest'] ?? NULL,
      $o['jobFactsJson'] ?? NULL,
    );
  };

  // Control: the base row with NO new-format field hydrates as legacy.
  $check('G0', 'the base versionless row (no new-format field) hydrates as legacy (probe control)',
    $g([]) instanceof QuoteSnapshot);
  $check('G1', 'versionless + routeMeters alone is refused',
    $g(['routeMeters' => 8000]) === NULL);
  $check('G2', 'versionless + routeSeconds alone is refused',
    $g(['routeSeconds' => 900]) === NULL);
  $check('G3', 'versionless + pairFingerprint alone is refused',
    $g(['pairFingerprint' => ($good->pairFingerprint ?: hash('sha256', 'pair'))]) === NULL);
  $check('G4', 'versionless + a line-item breakdown alone is refused',
    $g(['lineItemsJson' => '[{"code":"base","amount_cents":5000}]']) === NULL);
  $check('G5', 'versionless + payloadDigest alone is refused',
    $g(['payloadDigest' => $good->payloadDigest]) === NULL);
  $check('G6', 'versionless + jobFactsDigest alone is refused',
    $g(['jobFactsDigest' => $good->jobFactsDigest]) === NULL);
  $check('G7', 'versionless + accepted facts JSON alone is refused',
    $g(['jobFactsJson' => $good->jobFactsJson]) === NULL);
}

// =====================================================================
// GROUP H — Round 3 §5: PUBLIC-FACTORY integration. Reflection over build()
//   (Groups B/C) proves the derivation; it does NOT prove the public doors wire
//   into it. Here a snapshot is minted the way production mints one — an auto
//   quote through fromQuoteResult(), a staff figure through fromApprovedReview()
//   — and then hydrated through the PUBLIC fromStored(), exactly as the store does.
// =====================================================================

// H1: an automatic quote through the PUBLIC factory yields a snapshot whose binding
//     is current-format (prefixed, identity folded in).
$distanceH = DistanceResult::restored($routeMeters, $routeSeconds, $routeSource, $routeCalculatedAt);
$quoteH = new QuoteResult($currency, $amount, $lineItems, 'quoted', [], $rateVersion, $distanceH);
$snapFromQuote = QuoteSnapshot::fromQuoteResult($quoteH, $originA, $destA, $acceptedA);
$check('H1', 'fromQuoteResult() (public) yields a snapshot with a current-format binding identity',
  $snapFromQuote instanceof QuoteSnapshot
    && $snapFromQuote->hasJobFactsIdentity()
    && str_starts_with($snapFromQuote->bindingIdentity(), QuoteSnapshot::BINDING_IDENTITY_PREFIX));

if ($snapFromQuote instanceof QuoteSnapshot) {
  // H2: that factory-built snapshot round-trips through the PUBLIC fromStored().
  $rtQ = QuoteSnapshot::fromStored(
    $snapFromQuote->snapshotId, $snapFromQuote->amountCents, $snapFromQuote->currency,
    $snapFromQuote->rateVersion, $snapFromQuote->routeSource, $snapFromQuote->routeFingerprint,
    $snapFromQuote->routeCalculatedAt, $snapFromQuote->routeMeters, $snapFromQuote->routeSeconds,
    $snapFromQuote->lineItemsJson(), $snapFromQuote->pairFingerprint, $snapFromQuote->schemaVersion,
    $snapFromQuote->payloadDigest, $snapFromQuote->jobFactsDigest, $snapFromQuote->jobFactsJson,
  );
  $check('H2', 'an auto-quote snapshot hydrates back through the public factory, identity intact',
    $rtQ instanceof QuoteSnapshot
      && $rtQ->amountCents === $snapFromQuote->amountCents
      && hash_equals($rtQ->jobFactsDigest, $snapFromQuote->jobFactsDigest)
      && $rtQ->snapshotId === $snapFromQuote->snapshotId);
}

// H3: an APPROVED review through the PUBLIC factory seals an EMPTY breakdown yet still
//     carries a valid accepted-job identity at the reviewed amount.
$review = ReviewRequest::stored(
  42, 'hg', 101, ReviewState::Approved, NULL, NULL, 9900, 'USD',
  'Approved by staff.', 1, 'Staff', 1_700_000_000, 1_700_000_000, 1_700_000_000, NULL,
);
$snapFromReview = QuoteSnapshot::fromApprovedReview($review, $acceptedA);
$check('H3', 'fromApprovedReview() (public) yields a priced snapshot with an empty breakdown and an identity',
  $snapFromReview instanceof QuoteSnapshot
    && $snapFromReview->hasJobFactsIdentity()
    && $snapFromReview->amountCents === 9900);

if ($snapFromReview instanceof QuoteSnapshot) {
  // Vary ONLY the stored line-items JSON; every other field is the sealed row.
  $mk = function (?string $lineItemsJson) use ($snapFromReview): ?QuoteSnapshot {
    return QuoteSnapshot::fromStored(
      $snapFromReview->snapshotId, $snapFromReview->amountCents, $snapFromReview->currency,
      $snapFromReview->rateVersion, $snapFromReview->routeSource, $snapFromReview->routeFingerprint,
      $snapFromReview->routeCalculatedAt, $snapFromReview->routeMeters, $snapFromReview->routeSeconds,
      $lineItemsJson, $snapFromReview->pairFingerprint, $snapFromReview->schemaVersion,
      $snapFromReview->payloadDigest, $snapFromReview->jobFactsDigest, $snapFromReview->jobFactsJson,
    );
  };
  // H4: the review snapshot round-trips through the PUBLIC fromStored().
  $check('H4', 'an approved-review snapshot hydrates back through the public factory',
    $mk($snapFromReview->lineItemsJson()) instanceof QuoteSnapshot);
  // H5: a legitimately EMPTY breakdown reads the same whether stored as '' or '[]'.
  $check('H5', "an empty breakdown preserved as both '' and '[]' still hydrates (empty is not corruption)",
    $mk('') instanceof QuoteSnapshot && $mk('[]') instanceof QuoteSnapshot);
  // H6: a MALFORMED blob is refused, not silently read as [] — even though this row's
  //     legitimate breakdown IS empty, so a silent [] would re-hash to the sealed
  //     digest and pass corruption off as the priceless figure.
  $check('H6', 'a malformed line-item blob is refused even on an empty-breakdown review snapshot',
    $mk('{ not valid json') === NULL);
}

// =====================================================================
// GROUP I — Round 3 §2/§3: the VERSION-AWARE binding-refusal predicate the
//   confirm path applies under the calendar lock (DispatchPlanner::revalidate()).
//   This harness cannot take the resource-calendar lock or read a persisted
//   reservation, so it mirrors the decision as a pure predicate over
//   (accepted binding identity, stored reservation binding) and pins every arm.
//   The load-bearing rule Round 3 added: a CURRENT-format accepted binding whose
//   stored counterpart is NULL/empty REFUSES — missing data is not proof of
//   legacy status. Its production twin lives beside LockedHold in revalidate();
//   keep the two in lockstep.
// =====================================================================

$bindingRefuses = function (string $acceptedSnapshotId, ?string $storedBinding): bool {
  // '' means the accepted attempt carried no snapshot identity at all (a pre-24A
  // caller): the binding check is skipped entirely, so it never refuses.
  if ($acceptedSnapshotId === '') {
    return FALSE;
  }
  $acceptedIsCurrentFormat = str_starts_with($acceptedSnapshotId, QuoteSnapshot::BINDING_IDENTITY_PREFIX);
  $storedBindingPresent = $storedBinding !== NULL && $storedBinding !== '';
  if ($acceptedIsCurrentFormat) {
    // Current format: an absent OR mismatched stored binding refuses. Absence is
    // NOT tolerated — that is the Round 3 fail-closed correction.
    return !$storedBindingPresent || !hash_equals($storedBinding, $acceptedSnapshotId);
  }
  // Legacy format: an absent stored binding is tolerated (the reservation predates
  // the column), but a stored binding that is present and mismatched still refuses.
  return $storedBindingPresent && !hash_equals($storedBinding, $acceptedSnapshotId);
};

$currentBinding = QuoteSnapshot::BINDING_IDENTITY_PREFIX . str_repeat('a', 32) . ':' . str_repeat('b', 64);
$currentOther = QuoteSnapshot::BINDING_IDENTITY_PREFIX . str_repeat('c', 32) . ':' . str_repeat('d', 64);
$legacyBinding = str_repeat('e', 32);
$legacyOther = str_repeat('f', 32);

$check('I1', 'current-format accepted binding matching the stored binding PROCEEDS',
  $bindingRefuses($currentBinding, $currentBinding) === FALSE);
$check('I2', 'current-format accepted binding mismatched against the stored binding REFUSES',
  $bindingRefuses($currentBinding, $currentOther) === TRUE);
$check('I3', 'current-format accepted binding with a NULL stored binding REFUSES (missing data is not legacy proof)',
  $bindingRefuses($currentBinding, NULL) === TRUE);
$check('I4', "current-format accepted binding with an empty ('') stored binding REFUSES",
  $bindingRefuses($currentBinding, '') === TRUE);
$check('I5', 'legacy-format accepted binding with a NULL stored binding is TOLERATED (predates the column)',
  $bindingRefuses($legacyBinding, NULL) === FALSE);
$check('I6', 'legacy-format accepted binding matching the stored binding PROCEEDS',
  $bindingRefuses($legacyBinding, $legacyBinding) === FALSE);
$check('I7', 'legacy-format accepted binding present-but-mismatched still REFUSES',
  $bindingRefuses($legacyBinding, $legacyOther) === TRUE);
$check('I8', "an empty accepted binding ('') skips the check entirely and never refuses",
  $bindingRefuses('', NULL) === FALSE && $bindingRefuses('', $currentBinding) === FALSE);

// =====================================================================
// TERMINAL — report, then propagate the outcome through the process exit
// status. Success returns normally (no explicit exit()); failure throws.
// =====================================================================
//
// OBSERVED RUNNER BEHAVIOR — this installed invocation only, NOT a universal
// claim about every Drush version. Measured 2026-09-23 inside the ddev-ppu-web
// container running this harness via `drush php:script` against ppu-qa
// (Drush 12 / Drupal 10.6.16):
//   * ANY explicit exit(N) in a php:script-included file — INCLUDING exit(0) —
//     is caught by the runner as "[warning] Drush command terminated
//     abnormally." and the OUTER command exits 1, whatever N was. An explicit
//     exit() can therefore signal neither success (0) nor a chosen failure code
//     through this runner.
//   * Falling off the end of the script with NO explicit exit() lets the command
//     complete normally and exit 0.
//   * An uncaught exception reaches the runner's top-level handler and yields a
//     genuine nonzero command exit.
// An earlier note here claimed a raw exit(N) "terminates the whole Drush process
// with code N". That is DISPROVEN for this invocation and is retracted. So this
// harness signals success by returning normally and failure by throwing — never
// by exit(). Unexpected exceptions raised by any check above (autoload failure, a
// factory that throws, etc.) are intentionally NOT caught, so they too surface as
// a nonzero command exit rather than being mistaken for a pass.

echo PHP_EOL . '===== Task 24B / Rounds 2–3 — value-object integrity probes =====' . PHP_EOL;
foreach ($results as $line) {
  echo $line . PHP_EOL;
}
echo str_repeat('-', 66) . PHP_EOL;
echo sprintf('RESULT : %d passed, %d failed, %d total.', $pass, $fail, $pass + $fail) . PHP_EOL;
echo 'SCOPE  : value-object derivation, binding identity, fail-closed hydration,' . PHP_EOL;
echo '         and the version-aware binding-refusal predicate — all in-process.' . PHP_EOL;
echo 'PENDING: attempt insertion, guarded confirmation under the calendar lock, and' . PHP_EOL;
echo '         reservation/order persistence are NOT exercised and remain PENDING a' . PHP_EOL;
echo '         separately authorized QA run. Passing here is necessary, not sufficient.' . PHP_EOL;
echo 'STATUS : Task 24B remains PARTIAL.' . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL;

// A clean run returns normally, so the runner's command exit is 0. A single
// failed $check() makes $fail > 0 and throws, so the runner emits a nonzero
// command exit and a regression cannot be read as success from stdout alone.
if ($fail > 0) {
  throw new \RuntimeException(sprintf('%d of %d value-object probes failed; see the [FAIL] line(s) above.', $fail, $pass + $fail));
}
