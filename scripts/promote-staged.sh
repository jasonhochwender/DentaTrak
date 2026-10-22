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

echo "Resolved tag '$TAG' -> revision '$REV'; routing 100% traffic"
gcloud run services update-traffic "$SERVICE" \
  --region "$REGION" \
  --to-revisions "$REV=100" \
  --quiet
echo "Promoted $REV"
