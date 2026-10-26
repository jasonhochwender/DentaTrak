#!/usr/bin/env bash
# Authenticated smoke test for a staged (zero-traffic) Cloud Run revision.
# Usage: staged-auth-smoke.sh <revision-url>
#
# Exercises the real login -> main.php -> shared-permission-check -> logout
# path that an unauthenticated page check cannot catch (a 500 on main.php
# post-login once shipped to production). Runs BEFORE promotion; any failure
# fails the build and leaves traffic on the previous revision.
#
# Credentials come from Cloud Build secrets (SMOKE_TEST_EMAIL /
# SMOKE_TEST_PASSWORD) - a synthetic test account, never a customer.
# Never prints credentials, cookies, or response bodies containing PHI.
set -euo pipefail

BASE="${1:?usage: staged-auth-smoke.sh <revision-url>}"

fail() { echo "AUTH-SMOKE FAIL: $1"; exit 1; }

if [ -z "${SMOKE_TEST_EMAIL:-}" ] || [ -z "${SMOKE_TEST_PASSWORD:-}" ]; then
  fail "SMOKE_TEST_EMAIL/SMOKE_TEST_PASSWORD not provisioned (Cloud Build secrets)"
fi

# Candidate host: every redirect must stay on this exact revision URL so the
# session never escapes to the currently serving production revision.
BASE_HOST="$(printf '%s' "$BASE" | sed -E 's#^https?://([^/]+).*#\1#')"

JAR="$(mktemp)" && HDRS="$(mktemp)"

# Removing the cookie jar only discards the client-side session id; the
# server-side session would remain open until expiry. On ANY exit -
# success or failure - attempt a normal logout first so the synthetic
# account is not left with dangling sessions.
cleanup() {
  if [ -s "$JAR" ]; then
    curl -sS --max-time 15 -b "$JAR" -o /dev/null "$BASE/api/logout.php" >/dev/null 2>&1 || true
  fi
  rm -f "$JAR" "$HDRS"
}
trap cleanup EXIT

retry_curl() {
  local args=("$@") out="" i
  for i in 1 2 3 4 5; do
    out="$(curl -sS --max-time 30 "${args[@]}" 2>/dev/null)" && { printf '%s' "$out"; return 0; }
    sleep 10
  done
  return 1
}

echo "== Authenticated smoke test: $BASE =="

# 1) Normal login flow: POST credentials, keep cookies.
LOGIN_BODY="$(retry_curl -c "$JAR" -D "$HDRS" -X POST "$BASE/api/auth-email.php" \
  -H 'Content-Type: application/json' \
  -d "{\"action\":\"login\",\"email\":\"${SMOKE_TEST_EMAIL}\",\"password\":\"${SMOKE_TEST_PASSWORD}\"}")" \
  || fail "login request failed"
[[ "$LOGIN_BODY" == *'"success":true'* ]] || fail "login rejected: ${LOGIN_BODY:0:80}"
[[ "$LOGIN_BODY" == *'main.php'* ]] || fail "login did not redirect to main.php"
echo "login OK"

# Redirect target must stay on the candidate revision.
LOC="$(grep -i '^location:' "$HDRS" 2>/dev/null | tr -d '\r' | awk '{print $2}' || true)"
if [ -n "$LOC" ]; then
  case "$LOC" in
    "$BASE"*|/*) : ;;
    http*) fail "login redirect escaped candidate revision: $LOC" ;;
  esac
fi

# 2) Follow to main.php with the session - the authenticated page must
# render (a 500 here is exactly the failure this gate exists to catch).
CODE="$(retry_curl -b "$JAR" -o "$HDRS" -w '%{http_code}' "$BASE/main.php")" || fail "main.php request failed"
[ "$CODE" = "200" ] || fail "authenticated main.php returned HTTP $CODE (expected 200)"
MAIN_BODY="$(cat "$HDRS")"
[[ "$MAIN_BODY" == *'data-tab="cases"'* || "$MAIN_BODY" == *'main-tab'* ]] \
  || fail "main.php did not render authenticated application content"
[[ "$MAIN_BODY" != *'name="password"'* ]] || fail "main.php rendered a login page, not the app"
echo "main.php OK (200, authenticated content)"

# 3) Shared permission check path: the synthetic account is the allowlisted
# practice's admin, so the inbox check must succeed (not 403/500).
# EXPECTATION IS DELIBERATE: this assumes the test practice keeps scanner
# access (SCANNER_INBOX flag + allowlist + plan + admin role). If scanner
# access is ever intentionally disabled for it, update this step's expected
# result in the same change - do NOT silently weaken or skip the
# authenticated main.php validation above, which is flag-independent.
CODE="$(retry_curl -b "$JAR" -o "$HDRS" -w '%{http_code}' "$BASE/api/scan-inbox.php?action=list&view=attention")" \
  || fail "scan-inbox request failed"
[ "$CODE" = "200" ] || fail "scan-inbox list returned HTTP $CODE (expected 200 for allowed practice admin)"
INBOX_BODY="$(cat "$HDRS")"
[[ "$INBOX_BODY" == *'"success":true'* ]] || fail "scan-inbox list failed: ${INBOX_BODY:0:100}"
echo "shared permission check OK (inbox access granted as expected)"

# 4) Logout, then protected pages must re-require authentication.
retry_curl -b "$JAR" -c "$JAR" -o /dev/null "$BASE/api/logout.php" >/dev/null || fail "logout request failed"
CODE="$(curl -sS --max-time 30 -b "$JAR" -o "$HDRS" -w '%{http_code}' "$BASE/main.php" 2>/dev/null || echo 000)"
POST_BODY="$(cat "$HDRS" 2>/dev/null || true)"
if [ "$CODE" = "200" ]; then
  [[ "$POST_BODY" == *'name="password"'* ]] \
    || fail "main.php served authenticated content after logout (HTTP 200, no login form)"
else
  [ "$CODE" = "302" ] || [ "$CODE" = "303" ] || [ "$CODE" = "401" ] \
    || fail "post-logout main.php returned HTTP $CODE (expected redirect/401)"
fi
echo "logout OK (protected page re-gated)"

echo "== Authenticated smoke test passed =="
