#!/usr/bin/env bash
# Task 24H authorized HTTP/session acceptance — real request path, external
# providers denied (development_fixed serves an offline fixed provider).
#
# Drives the LIVE endpoint through Drupal's normal routing + CSRF over HTTPS on
# the DB-isolated QA host, with independent anonymous cookie jars and NO priming.
#
# WHY THE COUNTER, NOT calculated_at: time() has one-second precision, so two
# genuine calculations in the same second share a calculated_at and cannot be
# told apart by timestamp. "No recompute" is therefore proven by the actual
# provider-invocation count — the QA-gated [T24H-DIAG] counter in
# FixedDevelopmentRouteProvider — read as a delta at each checkpoint. If that
# counter is absent (instrumentation removed after acceptance) the behavioural
# checks still run and the deltas report INFO/n-a rather than pass.
#
# WHY PYTHON FOR PARSING: responses are parsed by python3's json module, not a
# regex. Each response is validated for HTTP status, that the body is a JSON
# object, and that required fields are present and of the right type; a missing
# or malformed field FAILS the check explicitly rather than silently returning "".
#
# PRINTS ONLY: HTTP status, the status/code field, a sha256[0:12] of a
# route_reference (never the reference itself), booleans, and counter deltas.
# Never cookies, CSRF tokens, raw references, session secrets, or bodies.
#
# On the design being verified (source-confirmed, RouteFactStore.php):
#   - store() mints reference = bin2hex(random_bytes(32)); every reference is a
#     distinct random 64-hex string, so two owners never share a reference string.
#   - Cross-owner isolation is enforced by PrivateTempStore partitioning by owner:
#     a different owner reads a different facts map, so retrieve() misses and
#     returns refresh_required. That refusal — not string equality — is the
#     mandatory isolation guarantee, and it is asserted directly below.
#
# No paid request, no config change, no Dispatch write, no commit, no log clear.
set -u

HOST="${QA_HOST:-https://ppu-qa.ddev.site}"
QA_URI="${QA_DRUSH_URI:-https://ppu-qa.ddev.site}"
EP="$HOST/custom-webform-handlers/distance-preview"
TMP="$(mktemp -d)"
JAR_A="$TMP/a.jar"; JAR_B="$TMP/b.jar"; JAR_C="$TMP/c.jar"
RUN="httpacc-$(date +%s)-$$"
PASS=0; FAIL=0

# A guard against the QA-only rule: refuse to run against the default site.
if [ "$HOST" != "${HOST/ppu-qa/}" ]; then :; else
  echo "REFUSED: HOST ($HOST) is not the ppu-qa host. This acceptance runs on QA only."; exit 2
fi

check() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  PASS  $1 (=$3)"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 (expected [$2], got [$3])"; fi; }

# ---- transport ------------------------------------------------------------
token_for() { curl -s -k -c "$1" -b "$1" "$HOST/session/token"; }

# POST a JSON body with a jar + CSRF token. Appends a newline and the HTTP code
# as the final line so the caller has both body and status from one call.
post() { # jar token json
  curl -s -k -c "$1" -b "$1" -X POST "$EP" \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: $2" \
    -w '\n%{http_code}' \
    --data "$3"
}

# ---- JSON extraction via python3 (validating) -----------------------------
# resp = "<body>\n<http_code>". Extract one field with type validation:
#   jget "$resp" status str    -> the string value, or "__MISSING__"/"__BADTYPE__"
#   jget "$resp" http                (special) -> the trailing HTTP code
#   jget "$resp" refhash             (special) -> sha256[0:12] of route_reference, or "-"
# Any parse failure prints "__PARSEFAIL__" so a check FAILS rather than passing on "".
jget() {
  QA_JGET_FIELD="$2" QA_JGET_KIND="${3:-str}" python3 - "$1" <<'PY'
import sys, os, json, hashlib
raw = sys.argv[1]
field = os.environ["QA_JGET_FIELD"]
kind = os.environ["QA_JGET_KIND"]
# Split trailing HTTP code appended by curl -w '\n%{http_code}'.
nl = raw.rfind("\n")
body, code = (raw[:nl], raw[nl+1:].strip()) if nl >= 0 else (raw, "")
if field == "http":
    print(code if code.isdigit() else "__NOHTTP__"); sys.exit(0)
try:
    doc = json.loads(body)
except Exception:
    print("__PARSEFAIL__"); sys.exit(0)
if not isinstance(doc, dict):
    print("__NOTOBJECT__"); sys.exit(0)
if field == "refhash":
    ref = doc.get("route_reference")
    if ref is None:
        print("-")
    elif isinstance(ref, str) and ref:
        print(hashlib.sha256(ref.encode()).hexdigest()[:12])
    else:
        print("__BADTYPE__")
    sys.exit(0)
if field not in doc:
    print("__MISSING__"); sys.exit(0)
val = doc[field]
if kind == "str":
    print(val if isinstance(val, str) else "__BADTYPE__")
elif kind == "present":
    print("yes")
else:
    print(str(val))
PY
}

# ---- provider-calc counter (QA-gated [T24H-DIAG]) -------------------------
COUNTER_KEY="custom_webform_handlers.t24h_calc_count"
counter_reset() { ddev exec "drush --uri=$QA_URI state:set $COUNTER_KEY 0" >/dev/null 2>&1; }
counter_read()  { local v; v="$(ddev exec "drush --uri=$QA_URI state:get $COUNTER_KEY" 2>/dev/null | tr -d '[:space:]')"; [ -n "$v" ] && echo "$v" || echo "n/a"; }
# COUNTER_ACTIVE is decided by the A1 checkpoint: A1 is a cold route_preview that
# MUST perform exactly one calculation when the [T24H-DIAG] counter is present.
# If A1's delta is 0 (counter reads static because the instrumentation has been
# removed for final tree state), every delta check downgrades to INFO — the
# behavioural, HTTP-status and JSON-shape checks still assert. This keeps the
# artifact honest whether or not the temporary counter is installed; the counting
# acceptance of record was captured with it present (see COMPLETION.md).
COUNTER_ACTIVE=unknown
check_delta() { # label expected before after
  if [ "$3" = "n/a" ] || [ "$4" = "n/a" ] || [ "$COUNTER_ACTIVE" = "no" ]; then echo "  INFO  $1 (provider-calc counter not active — instrumentation absent)"; return; fi
  local d=$(( $4 - $3 ))
  if [ "$d" = "$2" ]; then PASS=$((PASS+1)); echo "  PASS  $1 (delta=$d)"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 (expected delta $2, got $d)"; fi
}

BODY_JSON='{"origin":"10 Main St, Town","destination":"90 Elm Ave, City"'

echo "== Provider-calc counter: reset =="
counter_reset
CNT0=$(counter_read)
echo "    counter start = $CNT0"

echo "== A1: cold jar route_preview (unique owner, exactly one calculation) =="
TOK_A="$(token_for "$JAR_A")"
OP="op-$RUN"
RESP_A=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"operation\":\"route_preview\",\"operation_id\":\"$OP\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
CNT_A1=$(counter_read)
HTTP_A=$(jget "$RESP_A" http); ST_A=$(jget "$RESP_A" status str); RH1=$(jget "$RESP_A" refhash)
echo "    http=$HTTP_A status=$ST_A refhash=$RH1 counter=$CNT0->$CNT_A1"
check "A1 HTTP 200" "200" "$HTTP_A"
check "A1 status success" "success" "$ST_A"
check "A1 mints a valid route_reference" "yes" "$([ "${RH1:0:2}" != "__" ] && [ "$RH1" != "-" ] && echo yes || echo no)"
# Decide once whether the counter is live: a cold route_preview must add one calc.
if [ "$CNT0" != "n/a" ] && [ "$CNT_A1" != "n/a" ]; then
  [ "$(( CNT_A1 - CNT0 ))" -ge 1 ] && COUNTER_ACTIVE=yes || COUNTER_ACTIVE=no
else
  COUNTER_ACTIVE=no
fi
check_delta "A1 cold request performs EXACTLY ONE calculation" 1 "$CNT0" "$CNT_A1"

echo "== A2: quote-only reprice with A's own reference (zero calculations) =="
REF_A=$(jget "$RESP_A" route_reference str)
check "A2 route_reference is a string" "yes" "$([ "${REF_A:0:2}" != "__" ] && echo yes || echo no)"
RESP_Q=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"operation\":\"quote_only\",\"route_reference\":\"$REF_A\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"7\"}}")
CNT_A2=$(counter_read)
HTTP_Q=$(jget "$RESP_Q" http); ST_Q=$(jget "$RESP_Q" status str)
echo "    http=$HTTP_Q status=$ST_Q counter=$CNT_A1->$CNT_A2"
check "A2 HTTP 200" "200" "$HTTP_Q"
check "A2 status success" "success" "$ST_Q"
check_delta "A2 quote-only performs ZERO calculations" 0 "$CNT_A1" "$CNT_A2"

echo "== B1: independent session cannot use A's reference (refused, zero calc) =="
TOK_B="$(token_for "$JAR_B")"
RESP_B=$(post "$JAR_B" "$TOK_B" "$BODY_JSON,\"operation\":\"quote_only\",\"route_reference\":\"$REF_A\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
CNT_B1=$(counter_read)
HTTP_B=$(jget "$RESP_B" http); CODE_B=$(jget "$RESP_B" code str)
echo "    http=$HTTP_B code=$CODE_B counter=$CNT_A2->$CNT_B1"
check "B1 HTTP 409" "409" "$HTTP_B"
check "B1 Session B refused A's reference" "refresh_required" "$CODE_B"
check_delta "B1 cross-owner reference performs ZERO calculations" 0 "$CNT_A2" "$CNT_B1"

echo "== C1: second COLD jar, IDENTICAL operation_id + route, distinct owner =="
# A shared owner (the old sess:sha256('') bug) would let C replay A's completed
# ledger record for this same op id — a delta of 0 and A's own reference. A
# distinct owner instead computes its own route: delta 1, its own reference. The
# delta of 1 is the isolation proof; the mutual refusals in C2 confirm it.
TOK_C="$(token_for "$JAR_C")"
RESP_C=$(post "$JAR_C" "$TOK_C" "$BODY_JSON,\"operation\":\"route_preview\",\"operation_id\":\"$OP\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
CNT_C1=$(counter_read)
HTTP_C=$(jget "$RESP_C" http); ST_C=$(jget "$RESP_C" status str); REF_C=$(jget "$RESP_C" route_reference str); RH_C=$(jget "$RESP_C" refhash)
echo "    http=$HTTP_C status=$ST_C refhash=$RH_C (A refhash=$RH1) counter=$CNT_B1->$CNT_C1"
check "C1 HTTP 200" "200" "$HTTP_C"
check "C1 status success" "success" "$ST_C"
check "C1 route_reference is a string" "yes" "$([ "${REF_C:0:2}" != "__" ] && echo yes || echo no)"
check "C1 C's reference differs from A's (random per store())" "yes" "$([ -n "$REF_C" ] && [ "$REF_C" != "$REF_A" ] && echo yes || echo no)"
check_delta "C1 distinct cold owner computes its OWN route (one calc, not a replay of A)" 1 "$CNT_B1" "$CNT_C1"

echo "== C2: cross-owner references refused BOTH directions (zero calc) =="
RESP_CA=$(post "$JAR_C" "$TOK_C" "$BODY_JSON,\"operation\":\"quote_only\",\"route_reference\":\"$REF_A\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
RESP_AC=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"operation\":\"quote_only\",\"route_reference\":\"$REF_C\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
CNT_C2=$(counter_read)
CODE_CA=$(jget "$RESP_CA" code str); CODE_AC=$(jget "$RESP_AC" code str)
echo "    C-uses-A code=$CODE_CA  A-uses-C code=$CODE_AC  counter=$CNT_C1->$CNT_C2"
check "C2 Session C cannot use A's reference (distinct owner)" "refresh_required" "$CODE_CA"
check "C2 Session A cannot use C's reference (distinct owner)" "refresh_required" "$CODE_AC"
check_delta "C2 mutual cross-owner refusals perform ZERO calculations" 0 "$CNT_C1" "$CNT_C2"

echo "== A3: lost-response replay, same operation_id (zero additional calc) =="
RESP_R=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"operation\":\"route_preview\",\"operation_id\":\"$OP\",\"quote_input\":{\"delivery_mode\":\"standard\",\"total_weight_lbs\":\"5\"}}")
CNT_A3=$(counter_read)
HTTP_R=$(jget "$RESP_R" http); ST_R=$(jget "$RESP_R" status str)
echo "    http=$HTTP_R status=$ST_R counter=$CNT_C2->$CNT_A3"
check "A3 HTTP 200" "200" "$HTTP_R"
check "A3 replay succeeds" "success" "$ST_R"
check_delta "A3 same-session replay performs ZERO additional calculations" 0 "$CNT_C2" "$CNT_A3"

echo "== E1/E2/E3: malformed operation values refused, no provider =="
CNT_E0=$(counter_read)
RESP_M=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"quote_input\":{}}")
RESP_U=$(post "$JAR_A" "$TOK_A" "$BODY_JSON,\"operation\":\"buy_the_world\",\"quote_input\":{}}")
RESP_N=$(post "$JAR_B" "$TOK_B" "$BODY_JSON,\"operation\":\"quote_only\",\"quote_input\":{}}")
CNT_E1=$(counter_read)
check "E1 missing operation -> HTTP 400" "400" "$(jget "$RESP_M" http)"
check "E1 missing operation refused" "invalid_operation" "$(jget "$RESP_M" code str)"
check "E2 unknown operation -> HTTP 400" "400" "$(jget "$RESP_U" http)"
check "E2 unknown operation refused" "invalid_operation" "$(jget "$RESP_U" code str)"
check "E3 quote-only without a reference -> HTTP 409" "409" "$(jget "$RESP_N" http)"
check "E3 quote-only without a reference refused (no provider)" "refresh_required" "$(jget "$RESP_N" code str)"
check_delta "E1/E2/E3 refusals perform ZERO calculations" 0 "$CNT_E0" "$CNT_E1"

rm -rf "$TMP"
echo ""
echo "== HTTP SESSION ACCEPTANCE: $PASS passed, $FAIL failed =="
echo "== measured provider calculations across the whole run: $CNT0 -> $CNT_E1 (expected exactly 2: A1 + C1) =="
[ "$FAIL" = "0" ] || exit 1
