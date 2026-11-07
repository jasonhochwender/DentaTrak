# Cloud Build promotion step intermittently fails after successful staged deploy

## Symptom

`dentatrak-prod-deploy` builds regularly report `FAILURE` even though the
container image built cleanly, the staged (`--no-traffic`) revision deployed
and passed both verification gates (`verify-staged.sh`,
`staged-auth-smoke.sh`), and the revision is healthy. Observed on builds for
commits `e6af13c`, `fb8dd11`, `85811c4` (and earlier) — at least five
occurrences. Each time the fix has been a manual
`gcloud run services update-traffic --to-revisions <rev>=100` after
independently verifying the staged revision's image digest and health.

## Where it fails

`scripts/promote-staged.sh` resolves the revision bound to the
build-unique traffic tag:

```bash
REV="$(gcloud run services describe "$SERVICE" --region "$REGION" \
        --format='json(status.traffic)' \
      | python3 -c '... find entry where tag == TAG ...' "$TAG")" \
  || { echo "PROMOTE FAIL: describe failed for tag '$TAG'"; exit 1; }
```

Observed build logs show `PROMOTE FAIL: describe failed for tag
'staged-<sha>'`. The `||` branch fires when the `gcloud | python3`
pipeline exits non-zero — i.e. either `gcloud run services describe`
itself failed, `python3` was unavailable/errored in the build step
container, or the JSON parse found no match path that raised.

## Root cause — CONFIRMED 2026-11-07

Build log for `5dace5b` shows the exact failure:

```
Step #5: scripts/promote-staged.sh: line 23: python3: command not found
Step #5: PROMOTE FAIL: describe failed for tag 'staged-5dace5b'
```

The promote step runs in `gcr.io/google.com/cloudsdktool/cloud-sdk`,
which does **not** ship `python3`. The `gcloud | python3` pipeline
exited non-zero on every build — deterministic, not intermittent.
Earlier "glitches" were the same failure masked by manual promotion.

## Fix — implemented in `scripts/promote-staged.sh`

- Tag→revision resolution now uses `gcloud --format='yaml(status.traffic)'`
  parsed with `awk` (both guaranteed in the image); verified against the
  live service traffic config.
- Bounded retry (5 × 10s) absorbs traffic propagation lag after
  `gcloud run deploy --tag`.
- Unchanged safety properties: build-unique tags, verify-before-promote
  gates, newer-serving-revision regression guard.

## Verification

After the fix, the next `dentatrak-prod-deploy` build should promote
automatically; confirm via `gcloud run services describe dtk-app-prod
--format='value(status.traffic)'` showing 100% on the new revision
without manual `update-traffic`.
