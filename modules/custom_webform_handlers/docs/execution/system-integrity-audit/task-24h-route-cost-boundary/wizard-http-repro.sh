#!/usr/bin/env bash
# Task 24H — bounded HTTP/AJAX reproduction of the full page-1 -> page-2 -> page-3
# wizard advance on QA, driving the REAL multistep webform and the REAL dispatch
# offer/hold endpoints (no reflection). Confirms:
#   - the acceptance gate (page-2 -> page-3, once a slot is present) no longer returns
#     "Your saved route estimate is no longer available";
#   - the price-acceptance handshake completes in ONE submit because the accepted
#     figure rides the dedicated per-instance carrier (custom_accepted_quote), present
#     at the gate on the page-2 advance before the breakdown page renders;
#   - the form ACTUALLY reaches page 3 (a page-3-only field is present in the response),
#     not merely the absence of the previous warning;
#   - the dedicated route reference (custom_route_reference) carries from page 1.
#
# Reports the two SEPARATED [T24H-DIAG] counters by their full state keys:
#   custom_webform_handlers.t24h_customer_route_calls   (customer-route calculations)
#   custom_webform_handlers.t24h_scheduling_leg_calls   (offer scheduling-leg calculations)
# Counters are NOT reset here (acceptance window must not reset counters); deltas only.
#
# One cookie jar (one anonymous session), real CSRF tokens throughout:
#   1. POST /distance-preview route_preview (the page-1 JS commit) -> mints fact +
#      reference + the quote the customer sees. customer-route += 1.
#   2. GET /form/hg (page 1), submit page 1 carrying custom_route_reference AND
#      custom_accepted_quote (op="Next >") -> page 2.
#   3. POST /dispatch/offer/hg for the requested date -> real offerable arrivals.
#   4. POST /dispatch/hold/hg for the first arrival -> a real advisory session Hold.
#   5. Submit page 2 (date + real arrival + both carriers, op="Next >") -> the gate
#      runs. Expect page 3, no warning, customer-route +0; scheduling-leg recorded
#      separately.
#   6. POST /dispatch/release/hg -> give the probe's Hold back (leave QA as found).
set -u
HOST="https://ppu-qa.ddev.site"; URI="$HOST"
JAR="$(mktemp)"; W1="$(mktemp)"; W2="$(mktemp)"; W3="$(mktemp)"; PASS=0; FAIL=0
chk(){ if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  PASS  $1 (=$3)"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 (expected $2, got $3)"; fi; }
cust(){ rtk proxy ddev exec vendor/bin/drush --uri="$URI" state:get custom_webform_handlers.t24h_customer_route_calls 2>/dev/null | tr -d '[:space:]'; }
sched(){ rtk proxy ddev exec vendor/bin/drush --uri="$URI" state:get custom_webform_handlers.t24h_scheduling_leg_calls 2>/dev/null | tr -d '[:space:]'; }
hid(){ grep -oE "name=\"$2\"[^>]*value=\"[^\"]*\"" "$1" | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'; }

O="350 5th Avenue, New York, NY 10118"; D="30 Rockefeller Plaza, New York, NY 10112"
DATE="2026-09-21"   # Monday: an open dispatch day on ppu-qa (Mon-Wed).

CU0="$(cust)"; SC0="$(sched)"
# The [T24H-DIAG] counters are removed at acceptance closeout. When their QA state keys
# are gone the delta assertions degrade to INFO (the recorded passing run is the
# evidence: wizard-http-acceptance-*.log in this directory); the substantive HTTP-flow
# assertions below still run and gate the verdict.
COUNTERS=0
case "$CU0" in ''|*[!0-9]*) CU0=0; SC0=0;; *) COUNTERS=1;; esac
TOK="$(curl -s -k -c "$JAR" -b "$JAR" "$HOST/session/token")"

echo "== 1. page-1 committed preview (route_preview) =="
PV=$(curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/custom-webform-handlers/distance-preview" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOK" \
  --data "{\"origin\":\"$O\",\"destination\":\"$D\",\"operation\":\"route_preview\",\"operation_id\":\"op-wiz-$(date +%s)\",\"quote_input\":{\"service_type\":\"courier\",\"delivery_mode\":\"courier_send\",\"total_weight_lbs\":\"5\",\"total_weight_unit\":\"lb\",\"courier_prohibited_ack\":\"1\"}}")
PVSTATUS=$(printf '%s' "$PV" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("status"))' 2>/dev/null)
REF=$(printf '%s' "$PV" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("route_reference","") if d.get("status")=="success" else "")' 2>/dev/null)
# The accepted-quote carrier value the browser writes on page 1: the preview quote
# plus the route reference, compact JSON — exactly what the gate compares.
AQ=$(printf '%s' "$PV" | python3 -c 'import sys,json;d=json.load(sys.stdin);q=dict(d.get("quote",{}));q["route_reference"]=d.get("route_reference","");print(json.dumps(q,separators=(",",":")))' 2>/dev/null)
CU1="$(cust)"; SC1="$(sched)"
case "$CU1" in ''|*[!0-9]*) CU1=0;; esac; case "$SC1" in ''|*[!0-9]*) SC1=0;; esac
chk "preview success" "success" "$PVSTATUS"
chk "preview minted a route_reference" "yes" "$([ -n "$REF" ] && echo yes || echo no)"
chk "preview produced an accepted-quote carrier value" "yes" "$([ -n "$AQ" ] && echo yes || echo no)"
if [ "$COUNTERS" = "1" ]; then
  chk "cold preview adds exactly 1 customer-route calc" "1" "$((CU1 - CU0))"
  echo "    customer-route $CU0->$CU1 ; scheduling-leg $SC0->$SC1"
else
  echo "  INFO  counter deltas: [T24H-DIAG] keys removed at acceptance; see wizard-http-acceptance-*.log"
fi

echo "== 2. GET page 1, submit page 1 (with both carriers) -> page 2 =="
# NOTE: capture with `> file`, not `curl -o file`. On this Windows/Git-Bash + DDEV
# stack, `curl -o` writes 0 bytes for chunked (Transfer-Encoding: chunked) HTML
# responses while stdout redirection captures the full body — an output-capture bug,
# not an empty page. All three page fetches below use redirection for that reason.
curl -s -k -c "$JAR" -b "$JAR" "$HOST/form/hg" > "$W1"
BID1="$(hid "$W1" form_build_id)"; FTOK1="$(hid "$W1" form_token)"
curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/form/hg" \
  --data-urlencode "service_type=courier" \
  --data-urlencode "delivery_mode=courier_send" \
  --data-urlencode "origin_address=$O" \
  --data-urlencode "destination_address=$D" \
  --data-urlencode "total_weight_lbs=5" \
  --data-urlencode "total_weight_unit=lb" \
  --data-urlencode "courier_prohibited_ack=1" \
  --data-urlencode "custom_route_reference=$REF" \
  --data-urlencode "custom_accepted_quote=$AQ" \
  --data-urlencode "form_build_id=$BID1" \
  --data-urlencode "form_token=$FTOK1" \
  --data-urlencode "form_id=webform_submission_hg_add_form" \
  --data-urlencode "op=Next >" > "$W2"
HAS_DATE=$(grep -oE 'name="preferred_service_date"' "$W2" | head -1)
P2REF="$(hid "$W2" custom_route_reference)"
P2AQ=$(grep -oE 'name="custom_accepted_quote"[^>]*value="[^"]*"' "$W2" | head -1)
chk "advanced to page 2 (date field present)" "yes" "$([ -n "$HAS_DATE" ] && echo yes || echo no)"
chk "page 2 re-renders the route reference populated" "yes" "$([ "$P2REF" = "$REF" ] && echo yes || echo no)"
chk "page 2 re-renders the accepted-quote carrier populated" "yes" "$([ -n "$P2AQ" ] && echo yes || echo no)"

echo "== 3. dispatch offer for the requested date -> real offerable arrivals =="
OFFER=$(curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/custom-webform-handlers/dispatch/offer/hg" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOK" \
  --data "{\"date\":\"$DATE\",\"values\":{\"service_type\":\"courier\",\"delivery_mode\":\"courier_send\",\"origin_address\":\"$O\",\"destination_address\":\"$D\",\"total_weight_lbs\":\"5\",\"total_weight_unit\":\"lb\",\"courier_prohibited_ack\":\"1\",\"preferred_service_date\":\"$DATE\"}}")
OFFERSTATUS=$(printf '%s' "$OFFER" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("status"))' 2>/dev/null)
AT=$(printf '%s' "$OFFER" | python3 -c 'import sys,json;s=json.load(sys.stdin).get("slots",[]);print(s[0]["at"] if s else "")' 2>/dev/null)
chk "offer success" "success" "$OFFERSTATUS"
chk "offer returned at least one arrival" "yes" "$([ -n "$AT" ] && echo yes || echo no)"
echo "    first offered arrival at=$AT"

echo "== 4. [PRECONDITION] attempt a real advisory Hold on that arrival =="
# The hg form is a DISPATCH workflow: the arrival is advisory checkout intent and no
# Hold is taken at form time (validateForm's dispatch branch claims nothing; the Hold
# is created when checkout starts). So this step does NOT gate the page-2 -> page-3
# advance below — it exercises the real hold endpoint as an environment precondition.
# On a QA database behind schema 10046 it surfaces the missing price_snapshot_version
# column: a Task-24A-family staleness gap, NOT the route-cost boundary, and NOT counted
# against the route-cost verdict. It is reported here in full, never suppressed.
HOLD=$(curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/custom-webform-handlers/dispatch/hold/hg" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOK" \
  --data "{\"at\":$AT,\"values\":{\"service_type\":\"courier\",\"delivery_mode\":\"courier_send\",\"origin_address\":\"$O\",\"destination_address\":\"$D\",\"total_weight_lbs\":\"5\",\"total_weight_unit\":\"lb\",\"courier_prohibited_ack\":\"1\",\"preferred_service_date\":\"$DATE\"}}")
HOLDSTATUS=$(printf '%s' "$HOLD" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("status"))' 2>/dev/null)
HELD=0
if [ "$HOLDSTATUS" = "success" ]; then
  HELD=1
  echo "  PRECONDITION MET: real advisory Hold taken (status=success)"
elif printf '%s' "$HOLD" | grep -q "price_snapshot_version"; then
  echo "  PRECONDITION BLOCKED (out of Task 24H scope): dispatch hold needs QA schema >= 10046."
  echo "    QA is at 10040; column price_snapshot_version is absent from"
  echo "    custom_webform_handlers_dispatch_reservation. Added by update_10046 (Task 24A);"
  echo "    default site ppu is at 10046 and has the column. Repair = an update-hook run this"
  echo "    directive forbids. This is a stale-QA-database gap, not a route-cost-boundary defect,"
  echo "    and the page-2 -> page-3 advance below is independent of it (dispatch Hold is advisory)."
else
  echo "  PRECONDITION BLOCKED: hold endpoint returned status='$HOLDSTATUS' (not a route-cost assertion)."
fi

echo "== 5. submit page 2 (date + real arrival + both carriers) -> reach page 3 =="
BID2="$(hid "$W2" form_build_id)"; FTOK2="$(hid "$W2" form_token)"
curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/form/hg" \
  --data-urlencode "preferred_service_date=$DATE" \
  --data-urlencode "dispatch_slot=$AT" \
  --data-urlencode "custom_route_reference=$REF" \
  --data-urlencode "custom_accepted_quote=$AQ" \
  --data-urlencode "form_build_id=$BID2" \
  --data-urlencode "form_token=$FTOK2" \
  --data-urlencode "form_id=webform_submission_hg_add_form" \
  --data-urlencode "op=Next >" > "$W3"
CU2="$(cust)"; SC2="$(sched)"
case "$CU2" in ''|*[!0-9]*) CU2=0;; esac; case "$SC2" in ''|*[!0-9]*) SC2=0;; esac
WARN=$(grep -c "saved route estimate is no longer available" "$W3")
UPDATED=$(grep -c "estimate has been updated to" "$W3")
# Page-3-SPECIFIC marker: estimated_quote_breakdown / final_calculated_quote are hidden
# elements rendered ONLY on page 3 (the progress-bar title "Contact" is on every page).
REACHED_P3=$(grep -oE 'name="estimated_quote_breakdown"|name="final_calculated_quote"' "$W3" | head -1)
chk "NO 'route estimate no longer available' warning on advance" "0" "$WARN"
chk "NO 'estimate has been updated, submit again' handshake stall" "0" "$UPDATED"
chk "actually reached page 3 (page-3-only field present)" "yes" "$([ -n "$REACHED_P3" ] && echo yes || echo no)"
if [ "$COUNTERS" = "1" ]; then
  chk "advancement adds ZERO customer-route calcs" "0" "$((CU2 - CU1))"
  echo "    customer-route $CU1->$CU2 (advance = +$((CU2 - CU1))) ; scheduling-leg $SC1->$SC2 (recorded separately: +$((SC2 - SC1)))"
else
  echo "  INFO  advance counter deltas: [T24H-DIAG] keys removed at acceptance; see wizard-http-acceptance-*.log"
fi

echo "== 6. release the probe's Hold only if one was created (leave QA as found) =="
if [ "$HELD" = "1" ]; then
  REL=$(curl -s -k -c "$JAR" -b "$JAR" -X POST "$HOST/custom-webform-handlers/dispatch/release/hg" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOK" \
    --data "{\"at\":$AT}")
  RELSTATUS=$(printf '%s' "$REL" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("status"))' 2>/dev/null)
  echo "    released probe Hold (status=$RELSTATUS)"
else
  echo "    no Hold was created (precondition blocked); nothing to release"
fi

rm -f "$JAR" "$W1" "$W2" "$W3"
echo ""
echo "== TASK 24H ROUTE-COST ACCEPTANCE (substantive): $PASS passed, $FAIL failed =="
echo "== customer-route $CU0 -> $CU2 (external routing is development_fixed, offline) ; scheduling-leg $SC0 -> $SC2 (separate keys, never conflated) =="
echo "== Hold precondition: $([ "$HELD" = "1" ] && echo MET || echo 'BLOCKED — stale QA schema, out of route-cost scope (see step 4)') =="
[ "$FAIL" -eq 0 ]
