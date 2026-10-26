#!/usr/bin/env bash
# Promote the exact Cloud Run revision bound to a build-unique traffic tag.
# Usage: promote-staged.sh <service> <region> <tag>
#
# Resolves revisionName for the tag from the service's traffic config and
# routes 100% traffic to THAT revision. Never promotes by position
# (--to-latest), so a concurrent build's newer revision cannot be
# promoted without its own staged verification.
set -euo pipefail

SERVICE="${1:?usage: promote-staged.sh <service> <region> <tag>}"
REGION="${2:?usage: promote-staged.sh <service> <region> <tag>}"
TAG="${3:?usage: promote-staged.sh <service> <region> <tag>}"

REV="$(gcloud run services describe "$SERVICE" --region "$REGION" \
        --format='json(status.traffic)' \
      | python3 -c '
import json, sys
tag = sys.argv[1]
for entry in json.load(sys.stdin).get("status", {}).get("traffic", []):
    if entry.get("tag") == tag and entry.get("revisionName"):
        print(entry["revisionName"]); break
' "$TAG")" || { echo "PROMOTE FAIL: describe failed for tag '$TAG'"; exit 1; }
[ -n "$REV" ] || { echo "PROMOTE FAIL: no revision found for tag '$TAG'"; exit 1; }

echo "Resolved tag '$TAG' -> revision '$REV'"

# Promotion race safety: concurrent builds each verify and promote their
# own tagged revision. Unique tags stop the wrong REVISION from being
# promoted, but they don't stop an older build finishing last and
# replacing a newer release. Compare creation timestamps - if the
# currently serving revision is newer than the one this build verified,
# refuse to regress (the newer build's own verification already ran).
CUR="$(gcloud run services describe "$SERVICE" --region "$REGION" \
      --format='value(status.latestReadyRevisionName)' 2>/dev/null || echo '')"
if [ -n "$CUR" ] && [ "$CUR" != "$REV" ]; then
  CUR_TS="$(gcloud run revisions describe "$CUR" --region "$REGION" \
            --format='value(metadata.creationTimestamp)' 2>/dev/null || echo '')"
  NEW_TS="$(gcloud run revisions describe "$REV" --region "$REGION" \
            --format='value(metadata.creationTimestamp)' 2>/dev/null || echo '')"
  if [ -n "$CUR_TS" ] && [ -n "$NEW_TS" ] && [[ "$CUR_TS" > "$NEW_TS" ]]; then
    echo "PROMOTE SKIP: serving revision $CUR ($CUR_TS) is newer than $REV ($NEW_TS); not regressing"
    exit 0
  fi
fi

echo "Routing 100% traffic to $REV"
gcloud run services update-traffic "$SERVICE" \
  --region "$REGION" \
  --to-revisions "$REV=100" \
  --quiet
echo "Promoted $REV"
