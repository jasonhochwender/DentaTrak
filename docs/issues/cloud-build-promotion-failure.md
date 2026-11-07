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

## Likely root causes (ranked)

1. **Propagation lag**: `gcloud run deploy --tag` returns before the tag
   is visible in `services describe` traffic config. The promote step
   runs immediately after with no retry, so a not-yet-propagated tag
   yields an empty `REV` or a describe that races the traffic write.
2. **Missing `python3` in the promote step's build image**: the script
   depends on `python3` for JSON parsing; if the Cloud Build step image
   lacks it, the pipeline fails deterministically — but failures are
   intermittent, so this is less likely unless step images differ.
3. **Transient `gcloud`/API errors**: no retry or backoff around the
   describe call.

## Proposed permanent correction

Make `promote-staged.sh` resilient rather than rewriting the pipeline:

1. **Retry the describe** — wrap the `gcloud run services describe |
   python3` resolution in a bounded retry loop (e.g. 5 attempts, 10s
   apart) to absorb propagation lag and transient API errors.
2. **Replace `python3` dependency** — parse the traffic JSON with
   `gcloud --format` value extraction or `jq` (verify availability in
   the build image first), removing a fragile runtime dependency.
3. **Fallback resolution** — if the tag is absent from traffic config,
   fall back to `gcloud run revisions list --service=$SERVICE
   --filter="metadata.labels.'run.googleapis.com/traffic-tag'=$TAG"`
   (or the tag annotation) before failing.
4. **Keep the existing safety properties**: build-unique tags,
   verify-before-promote gates, and the newer-serving-revision
   regression guard must remain unchanged.

## Verification

After the fix, the next `dentatrak-prod-deploy` build should promote
automatically; confirm via `gcloud run services describe dtk-app-prod
--format='value(status.traffic)'` showing 100% on the new revision
without manual `update-traffic`.
