# Data Export v2 — Controlled Production Test Plan

Scope: verify the async export pipeline (case-data CSV/JSON + Complete
Practice Archive) end-to-end on production using ONE synthetic test
practice. No customer PHI may be used. `DATA_EXPORT_V2` stays disabled
for everyone else throughout.

## Preconditions

- Cloud Build deploy completed: app revision promoted AND
  `scripts/provision-export-worker.sh` verified (job, custom IAM role
  `dtkExportJobRunner`, scheduler `dtk-prod-export-worker-sweep`, GCS
  lifecycle rule) — all before traffic promotion.
- `DATA_EXPORT_V2=true` and `DATA_EXPORT_V2_PRACTICES=<test practice id>`
  set as env vars on `dtk-app-prod` (job inherits the same env via the
  provision script's env copy).
- Synthetic practice contains ONLY fabricated data: cases with clearly
  fake patient names, image/PDF attachments, an STL scanner file, a
  comment image, an archived case, and a duplicate filename pair.

## Test sequence

1. **Request** — Settings > Data & Privacy > v2 section (visible only
   for the allowlisted practice). Request case-data CSV, then JSON,
   then Complete Archive. Confirm each returns `queued`/`completed`
   and appears in the export list with type, date, status.
2. **Background execution** — Cloud Run Job `dtk-export-worker`
   execution appears in Logs/Executions within ~1 min (jobs:run kick)
   or ≤15 min (scheduler sweep). `EXPORT_ID` override targets one
   export; `attempts` increments per claim.
3. **Data collection** — `data_exports.sections_done`/`manifest_json`
   populate; archive `manifest.json` row counts match fixture counts.
4. **GCS retrieval** — attachments/scanner files appear under
   `files/cases/...` and `files/scanner/...` in the ZIP; per-file
   status `included`, checksums `match`/`computed`.
5. **Archive integrity** — download ZIP; verify `SHA256SUMS` (all
   entries), `manifest.json` (`complete:true`, `missingOrFailedCount:0`
   unless a fixture is intentionally missing), attachment bytes
   byte-identical to uploads, duplicate filenames deduplicated
   (`_2` suffix), archived cases present, other-practice data absent.
6. **Signed download** — `download_url` returns a V4 signed URL
   (~15 min). Confirm `url_issued_at`/`url_issued_count` increment and
   a `phi_access_log` row with the URL-issuance action exists —
   distinct from any actual-download evidence.
7. **Expiry** — confirm `expires_at` ≈ created+7d; after expiry (or by
   manually expiring a test row) `download_url` returns 410 and the
   sweeper deletes the GCS object + marks `expired`. The 8-day
   lifecycle rule is the backstop.
8. **Allowlist enforcement** — from a NON-allowlisted practice account:
   v2 UI section absent, `action=request|status|download_url` → 404.
   From the allowlisted practice, attempt `download_url` for an export
   row whose `practice_id` is not allowlisted (if any exist) → 404.
9. **Failure/retry** — temporarily revoke the job SA's bucket access
   (or use a fixture pointing at a missing object) and confirm the
   export retries ≤3 attempts then `failed`; no partial GCS object
   remains (resumable session cancelled, nothing committed).
10. **Cleanup** — remove `DATA_EXPORT_V2_PRACTICES` value (or set to a
    nonexistent id) once testing completes; `DATA_EXPORT_V2` may remain
    on only if a follow-up test window is planned — default is OFF.

## Rollback

- Feature: unset `DATA_EXPORT_V2` (or empty `DATA_EXPORT_V2_PRACTICES`
  in production → denies everyone). UI + API go dark; legacy
  `api/data-export.php` untouched.
- Infra: `gcloud run jobs delete dtk-export-worker`,
  `gcloud scheduler jobs delete dtk-prod-export-worker-sweep`,
  `gcloud run services update dtk-app-prod --remove-env-vars
  EXPORT_RUN_JOB`. The custom role can remain (inert) or be deleted.
- Data: expired artifacts self-delete; `data_exports` rows are
  metadata only.
