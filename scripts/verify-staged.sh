#!/usr/bin/env bash
# Smoke-verify a staged (zero-traffic) Cloud Run revision before promotion.
# Usage: verify-staged.sh <revision-url>
#
# Fails the build (and leaves production traffic on the previous revision)
# unless the staged revision reports a PHP version patched for
# CVE-2026-109618 / CVE-2026-109619 and serves requests normally.
set -euo pipefail

BASE="${1:?usage: verify-staged.sh <revision-url>}"

retry_curl() {
  # Scale-to-zero revisions can take ~30s to cold-start; retry briefly.
  local args=("$@") out="" i
  for i in 1 2 3 4 5; do
    out="$(curl -sS --max-time 30 "${args[@]}" 2>/dev/null)" && { printf '%s' "$out"; return 0; }
    sleep 10
  done
  return 1
}

fail() { echo "VERIFY-STAGED FAIL: $1"; exit 1; }

echo "== Verifying staged revision: $BASE =="

# 1) PHP runtime is patched. Minimum fixed: 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
HDRS="$(retry_curl -sSI "$BASE/")" || fail "no response from $BASE/"
echo "$HDRS" | grep -i '^x-powered-by:' || true
PHPV="$(printf '%s' "$HDRS" | grep -io 'PHP/[0-9.]*' | head -1 | cut -d/ -f2)"
[ -n "$PHPV" ] || fail "X-Powered-By header missing; cannot confirm PHP version"
IFS=. read -r MAJ MIN PAT _ <<<"$PHPV"
patched=0
if [ "$MAJ" -eq 8 ]; then
  case "$MIN" in
    2) [ "$PAT" -ge 35 ] && patched=1 ;;
    3) [ "$PAT" -ge 36 ] && patched=1 ;;
    4) [ "$PAT" -ge 27 ] && patched=1 ;;
    *) [ "$MIN" -ge 5 ] && [ "$PAT" -ge 12 ] && patched=1 ;;
  esac
elif [ "$MAJ" -gt 8 ]; then
  patched=1
fi
[ "$patched" -eq 1 ] || fail "PHP $PHPV is vulnerable (need >= 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12)"
echo "PHP $PHPV patched OK"

# 2) Homepage renders (mod_php processing, not an error page).
HOME_BODY="$(retry_curl -sS "$BASE/")" || fail "homepage request failed"
printf '%s' "$HOME_BODY" | grep -q 'DentaTrak' || fail "homepage did not render expected markup"
echo "homepage OK"

# 3) Login page renders.
CODE="$(retry_curl -o /dev/null -w '%{http_code}' "$BASE/login.php")" || fail "login page request failed"
[ "$CODE" = "200" ] || fail "login.php returned HTTP $CODE"
echo "login.php OK ($CODE)"

# 4) API endpoint executes PHP and returns JSON (login endpoint, no creds).
API_BODY="$(retry_curl -sS -X POST "$BASE/api/auth-email.php" -H 'Content-Type: application/json' -d '{}')" \
  || fail "auth API request failed"
printf '%s' "$API_BODY" | grep -q '"success"' || fail "auth-email.php did not return JSON: ${API_BODY:0:120}"
echo "auth API OK"

# 5) Static + localized assets served.
CODE="$(retry_curl -o /dev/null -w '%{http_code}' "$BASE/css/app.light.css")"
[ "$CODE" = "200" ] || fail "static asset returned HTTP $CODE"
CODE="$(retry_curl -o /dev/null -w '%{http_code}' "$BASE/locales/en-US.json")"
[ "$CODE" = "200" ] || fail "locale asset returned HTTP $CODE"
echo "static assets OK"

echo "== Staged revision verified; safe to route traffic =="
