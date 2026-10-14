# Open Dental Two-Way Sync — Phase 2 Design + Field Mapping Proposal

Status: **infrastructure implemented, writes disabled everywhere.** Nothing
here pushes data to Open Dental until (a) the `PMS_OUTBOUND_SYNC` feature
flag is enabled server-side, (b) the connection's
`config_json.outbound.enabled` is set, and (c) a `field_map` is configured.
This document is the approval gate for those settings.

## Architecture

| Piece | Where | Notes |
|---|---|---|
| Outbound queue | `integration_outbound_events` table | mirrors `integration_external_events`: durable rows, atomic claims, bounded linear backoff (max 10 attempts, 300s cap, honors `Retry-After`), 10-minute stale-claim recovery |
| Enqueue | `IntegrationOutbound::enqueueStatusTransition()` | called after successful status writes in `update-case-status.php`, `update-case.php`, and the column-archive bulk move in `workflow-columns.php` |
| Worker | `IntegrationOutbound::processDue()` | drained by the **same** Cloud Scheduler endpoint (`api/integration-event-worker.php`) and CLI (`process-events.php`) as inbound — no new infra |
| Adapter writes | `OpenDentalAdapter::updateLabCase()` / `createLabCase()` | `PUT /labcases/{num}` / `POST /labcases`; both refuse (`writes_disabled`) unless the adapter was built with `writesEnabled` from `config_json.outbound.enabled` |
| Capability probe | `check_outbound_capability` action → `GET /securityperms` | read-only; reports whether the office key has `ApiAllOthers` |

### Dedup / coalescing
- `dedup_key = sha256(operation | external_id | canonical payload)` with `UNIQUE(connection_id, dedup_key)` — re-enqueueing the identical change is a no-op; an identical re-assertion against a terminal row resurrects it as `queued`.
- A newer queued event for the same (connection, case, operation) **supersedes** older queued events, merging their payload fields forward — at most one queued write per case per operation.

### Loop prevention
- Inbound events only ever write Open Dental–owned fields (due date, appointment, instructions, provider) — outbound never writes those, so an echo physically cannot produce a local diff.
- After each delivered write, the case mapping's `metadata_json.outbound_sync` records `{fields, delivered_at, last_tstamp}`; when an inbound event's `DateTStamp` matches `last_tstamp` it is finalized as `Echo of DentaTrak-originated write; suppressed.` The normal field diff still runs first, so a real remote change riding the same delivery is never dropped.

### Conflict policy (all-or-nothing per event)
Before writing, the worker GETs the remote LabCase and compares each payload field against the `expected_remote` snapshot captured at enqueue (mapping provenance):

| Remote now | Result |
|---|---|
| == desired | field already in target state — skipped |
| == expected prior | unchanged since last sync — written |
| expected unknown + remote empty | first-write rule — written |
| expected unknown + remote set | **conflict** (never overwrite unobserved data) |
| remote ≠ expected prior | **conflict** — human reconciles |

`conflict` is terminal: no partial write, no retry, surfaced via the
connection's `outbound.conflicts` count.

## Proposed lifecycle mapping (REQUIRES APPROVAL — not enabled)

DentaTrak has **six fixed internal statuses** (`getWorkflowStageDefinitions()`),
with `cases_cache.status_changed_at` stamped on every transition:

| DentaTrak transition INTO | Open Dental field | Proposed? |
|---|---|---|
| `Sent To External Lab` | `DateTimeSent` = status_changed_at | ✅ proposed |
| `Received From External Lab` | `DateTimeRecd` = status_changed_at | ✅ proposed |
| `Delivered` | `DateTimeChecked` = status_changed_at | ⚠️ proposed — see open question 1 |
| `Originated`, `Designed`, `Manufactured`, custom columns | — none | not mappable |

Never written outbound (Open Dental–authoritative): PatNum, ProvNum,
LaboratoryNum, AptNum/PlannedAptNum, DateTimeDue, Instructions.
Never written (DentaTrak-only): comments, attachments, tooth shade,
material, carrier/tracking, assignee, custom workflow columns.

## Open questions needing a decision

1. **`Delivered` → `DateTimeChecked`**: OD's `DateTimeChecked` is "date/time
   that quality was checked" — arguably "case verified after return," which
   may precede delivery-to-patient. Alternatives: leave unmapped, or map
   `Received From External Lab` → `DateTimeChecked` instead of `DateTimeRecd`.
2. **Backward moves (regressions)**: a case returning to an earlier stage
   re-stamps the mapped OD date on re-entry. Clearing dates remotely (sentinel
   `0001-01-01`) is *not* implemented — OD's semantics for clearing via PUT
   are unverified. Proposed: never auto-clear; regression re-entry overwrites
   forward timestamps only.
3. **Timestamp semantics**: `status_changed_at` is server-local time; OD
   interprets dates in the office's timezone. Offsets are likely small but
   not guaranteed. Option: store/send UTC and document, or add a per-office
   TZ offset later.
4. **Instructions on create**: the queue's PHI contract currently rejects
   instruction text in outbound payloads (key screening). Enabling notes on
   created OD cases needs an explicit contract amendment.
5. **Create enablement UI/scope**: `enqueueCaseCreate` + worker
   `create_labcase` are implemented and tested but nothing calls the enqueue
   path yet — creating an OD LabCase needs a patient/lab/provider mapping
   story (PatNum source: patient mapping exists; LaboratoryNum/ProvNum
   selection is undecided) before wiring into `create-case.php`.

## Permissions / version requirements

- `LabCases POST/PUT` require **OD ≥ 24.4.19** and the **`ApiAllOthers`**
  permission group (a paid tier above the free `ApiReadAll` used today).
  Missing permission returns 401 → the event fails terminally.
  `check_outbound_capability` (Settings API action) probes this read-only.
- OD < required version returns a documented 400 → classified
  `unsupported_version`, terminal.

## Failure model (mirrors inbound)

Transient (`network`, `timeout`, `server_error`, `rate_limited`,
`econnector_offline`) → back to `queued` with backoff. Terminal: auth,
permission, bad data, unsupported version, missing mapping → `failed`;
disabled opt-in, unmapped/deleted records → `skipped`. Stale `processing`
rows are reclaimed after 10 minutes.

## Security contract

- `payload_json`/`expected_remote_json` pass `SyncEngine::assertDetailSafe`
  — PHI-/credential-named keys throw at enqueue.
- Credentials only decrypted in memory; never in payloads, URLs, logs, or
  error messages.
- Every write re-verifies: connection ↔ practice ↔ event ↔ mapping ↔ case
  all agree on `practice_id` before any remote call.
