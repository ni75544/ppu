#!/usr/bin/env bash
# Task 24H true multi-process race orchestrator.
#
# Spawns independent `drush php:script` worker PROCESSES that share the DB-backed
# ledger and the Redis single-flight lock, and asserts the real cross-process
# provider-call count via a shared DB-backed keyvalue.expirable collection (each
# genuine calculate() writes one unique key). This is the real race the earlier
# report could only model in one process; a Mutagen-synced counter file would not
# reflect container-side writes on the host, which is why the count lives in the DB.
#
# Run from repo root:
#   bash modules/custom_webform_handlers/docs/execution/system-integrity-audit/\
#     task-24h-route-cost-boundary/route-race-orchestrator.sh
#
# development_fixed mode, no paid request, no config change, no Dispatch write.

set -u
DIR="modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24h-route-cost-boundary"
WORKER="$DIR/route-race-worker.php"
RDR="$DIR/.race-count-reader.php"
SPANRDR="$DIR/.race-span-reader.php"
RUN="race$(date +%s)$$"
TMP="$(mktemp -d)"
PASS=0
FAIL=0

check() { # label expected actual
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  PASS  $1 (=$3)";
  else FAIL=$((FAIL+1)); echo "  FAIL  $1 (expected $2, got $3)"; fi
}

# A tiny reader script: prints the key count of a collection, then clears it.
cat > "$RDR" <<'PHP'
<?php
$c = (string) getenv('RC_COLLECTION');
$kv = \Drupal::service('keyvalue.expirable')->get($c);
echo count($kv->getAll());
$kv->deleteAll();
PHP

read_and_clear() { # collection
  ddev exec env "RC_COLLECTION=$1" vendor/bin/drush php:script "$RDR" 2>/dev/null | tr -d ' \n'
}

# Reads the workers' recorded spans for a run and prints "1" when they genuinely
# overlapped — i.e. max(start) < min(end), an instant when all were inside preview()
# at once — else "0". This is what distinguishes single-flight collapse of
# concurrent work from mere sequential cache reuse. Clears the spans after reading.
cat > "$SPANRDR" <<'PHP'
<?php
$c = (string) getenv('RC_COLLECTION') . ':spans';
$kv = \Drupal::service('keyvalue.expirable')->get($c);
$spans = array_values($kv->getAll());
$kv->deleteAll();
if (count($spans) < 2) { echo '0'; return; }
$maxStart = max(array_map(static fn($s) => (int) $s['start'], $spans));
$minEnd = min(array_map(static fn($s) => (int) $s['end'], $spans));
echo ($maxStart < $minEnd) ? '1' : '0';
PHP

overlapped() { # collection
  ddev exec env "RC_COLLECTION=$1" vendor/bin/drush php:script "$SPANRDR" 2>/dev/null | tr -d ' \n'
}

spawn() { # collection owner op origin dest tag
  ddev exec env "RACE_OP=$3" "RACE_ORIGIN=$4" "RACE_DEST=$5" \
    "RACE_COUNTER=$1" "RACE_OWNERKEY=$2" \
    vendor/bin/drush php:script "$WORKER" >"$TMP/$6.out" 2>"$TMP/$6.err" &
}

echo "== Scenario 1: same identity + SAME operation id, 4 concurrent workers =="
echo "   Expect exactly ONE provider call (single-flight collapses the duplicates)."
COL="t24h_race_s1_$RUN"
read_and_clear "$COL" >/dev/null
pids=()
for i in 1 2 3 4; do
  spawn "$COL" "ownerA-$RUN" "op-shared-$RUN" "10 Race St, Town" "20 Race Ave, City" "s1_$i"
  pids+=("$!")
done
for p in "${pids[@]}"; do wait "$p"; done
cat "$TMP"/s1_*.out 2>/dev/null | sed 's/^/    /'
check "same-id 4-way workers genuinely overlapped in preview()" 1 "$(overlapped "$COL")"
check "same-id 4-way race bills exactly one provider call" 1 "$(read_and_clear "$COL")"

echo "== Scenario 2: same identity + DIFFERENT operation ids, 4 concurrent workers =="
echo "   Expect exactly ONE provider call (identity-level reuse collapses distinct ids)."
COL="t24h_race_s2_$RUN"
read_and_clear "$COL" >/dev/null
pids=()
for i in 1 2 3 4; do
  spawn "$COL" "ownerB-$RUN" "op-uniq-$RUN-$i" "30 Race Rd, Town" "40 Race Blvd, City" "s2_$i"
  pids+=("$!")
done
for p in "${pids[@]}"; do wait "$p"; done
cat "$TMP"/s2_*.out 2>/dev/null | sed 's/^/    /'
check "diff-id same-journey 4-way workers genuinely overlapped in preview()" 1 "$(overlapped "$COL")"
check "diff-id same-journey 4-way race bills exactly one provider call" 1 "$(read_and_clear "$COL")"

echo "== Scenario 3: DIFFERENT journeys, 3 concurrent workers, unique ids =="
echo "   Expect THREE provider calls (distinct identities never share)."
COL="t24h_race_s3_$RUN"
read_and_clear "$COL" >/dev/null
pids=()
k=0
for pair in "50 A St, Town|60 A Ave, City" "51 B St, Town|61 B Ave, City" "52 C St, Town|62 C Ave, City"; do
  k=$((k+1))
  o="${pair%%|*}"; d="${pair##*|}"
  spawn "$COL" "ownerC-$RUN" "op-distinct-$RUN-$k" "$o" "$d" "s3_$k"
  pids+=("$!")
done
for p in "${pids[@]}"; do wait "$p"; done
cat "$TMP"/s3_*.out 2>/dev/null | sed 's/^/    /'
check "three distinct journeys 3-way workers genuinely overlapped in preview()" 1 "$(overlapped "$COL")"
check "three distinct journeys bill three provider calls" 3 "$(read_and_clear "$COL")"

rm -f "$RDR" "$SPANRDR"
rm -rf "$TMP"
echo ""
echo "== RACE RESULT: $PASS passed, $FAIL failed =="
