# Open Dental Integration — Deployment-Environment Verification

**Status:** audit complete; customer-environment verification pending.

This note records *which Open Dental environments the integration has
actually been exercised against*, so verification claims stay accurate.

## API mode used

DentaTrak uses Open Dental's **Remote API** only
(`https://api.opendental.com/api/v1`, `ODFHIR {DeveloperKey}/{CustomerKey}`).
There is no Local API (`localhost:30222`), no API Service (`:30223`), no
local agent, no direct database access, and no dependency on files installed
on the office's computers. `OPEN_DENTAL_BASE_URL` exists only to point tests
at a stub.

Per Open Dental's API Modes documentation, the Remote endpoint is the same
for locally installed Open Dental and for Open Dental Cloud — the hosted VM
provides the office-side service, and "a third party software does not even
need to distinguish between the customer utilizing Cloud or not."

## Verification matrix

| Function | OD-hosted demo (verified) | On-prem practice | Open Dental Cloud |
|---|---|---|---|
| Auth + reads (`/clinics`, `/labcases`, support resources) | ✅ `pms-open-dental-live-e2e-test.php`, `pms-open-dental-live-read-proof.php` | not verified | not verified |
| Subscriptions lifecycle (GET/POST/PUT) | ✅ `pms-open-dental-events-live-test.php` | not verified | not verified |
| Event delivery (WatchTable push to webhook) | ✅ verified to webhook.site | not verified | not verified |
| LabCase writeback (POST/PUT) | unit/stub only — needs paid tier | not verified | not verified |
| Toolbar bridge (Program Link → `od-launch.php`) | manual | not verified | not verified (see toolbar doc) |

**Nothing above has been verified end-to-end against a real on-premises
customer install or a real Open Dental Cloud account.** All live tests ran
against Open Dental's public hosted demo database with the published demo
credential pair. Do not describe local-install or Cloud customer support as
"tested" or "verified" in release notes, sales material, or support replies —
say "built on the official Remote API; available for locally installed Open
Dental and Open Dental Cloud, subject to required permissions and supported
versions."

## Customer-side prerequisites

- **Locally installed:** the office's eConnector service must run on the
  practice's server (usually already running when eServices are in use).
  The office enables the API and pastes the customer key under
  Setup → Advanced Setup → API.
- **Open Dental Cloud:** the equivalent service runs in Open Dental's
  hosted environment — nothing for the practice to install or check. If
  the connection cannot reach it, the office contacts Open Dental support.
- **Either environment:** LabCase WatchTable events need OD ≥ 25.4.14 and
  LabCaseDeleted ≥ 26.1.6; writeback needs the paid "All Others" permission
  on the customer key.

## Smallest test to confirm a real environment

1. Configure a connection with a real customer key.
2. `php tests/pms-open-dental-sandbox-diagnostic.php --connection-id=N`
   (PHI-safe) — proves auth + reads end-to-end.
3. Enable automatic updates; confirm `DateTimeStart` advances on the
   subscription and an event lands on our webhook.
4. Optionally enable writeback on a scratch connection and POST one
   LabCase — proves the paid-tier path.

For Open Dental Cloud specifically, Open Dental's signup flow can provide a
test environment with synthetic data; no code changes are expected to be
needed, only the verification above.
