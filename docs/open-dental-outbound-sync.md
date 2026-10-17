# Open Dental Two-Way Sync — Design + Field Mapping (GA)

> **Environment scope:** the Remote-API paths described here were verified
> against Open Dental's hosted demo and local stubs only. Writeback has
> **not** been verified against a real on-premises practice or an official
> Open Dental Cloud account — see
> `docs/open-dental-environment-verification.md` before claiming
> customer-environment support.

Status: **generally available.** `PMS_OUTBOUND_SYNC` defaults on (env remains
the global kill switch) and every subscription plan may use the integration.
A connection still pushes nothing until an administrator explicitly enables
two-way sync in Settings → Integrations → Open Dental — which verifies office
health, this customer key's `ApiAllOthers` permission, and a configured IANA
timezone, then applies the approved field map automatically. Write access is
never assumed across keys or practices.

## Architecture

| Piece | Where | Notes |
|---|---|---|
| Outbound queue | `integration_outbound_events` table | mirrors `integration_external_events`: durable rows, atomic claims, bounded linear backoff (max 10 attempts, 300s cap, honors `Retry-After`), 10-minute stale-claim recovery |
| Enqueue | `IntegrationOutbound::enqueueStatusTransition()` | called after successful status writes in `update-case-status.php`, `update-case.php`, and the column-archive bulk move in `workflow-columns.php` |
| Worker | `IntegrationOutbound::processDue()` | drained by the **same** Cloud Scheduler endpoint (`api/integration-event-worker.php`) and CLI (`process-events.php`) as inbound — no new infra |
| Adapter writes | `OpenDentalAdapter::updateLabCase()` / `createLabCase()` | `PUT /labcases/{num}` / `POST /labcases`; both refuse (`writes_disabled`) unless the adapter was built with `writesEnabled` from `config_json.outbound.enabled` |
| Capability probe | `GET /securityperms` inside verify-on-enable | records `capability` (checked_at, has_permission, permission, min_od_version) for the settings UI; `ApiAllOthers` required before `enabled` honors |
| Config action | `update_outbound_config` action | admin-only; sets `enabled` / `create_enabled` / `timezone` / `laboratory_num` / `field_map` on `config_json.outbound`; enable runs health + permission probes and seeds the default map; disable needs no probes and never touches inbound |
| Retry action | `retry_outbound_failed` action | admin-only; requeues `failed` rows only — `conflict` rows stay parked for human reconciliation |
| Lookup action | `list_od_lookups` action | admin-only read of the office's provider/laboratory directories for the default-lab picker |

### Dedup / coalescing
- `dedup_key = sha256(operation | external_id | canonical payload)` with `UNIQUE(connection_id, dedup_key)` — re-enqueueing the identical change is a no-op; an identical re-assertion against a terminal row resurrects it as `queued`.
- A newer queued event for the same (connection, case, operation) **supersedes** older queued events, merging their payload fields forward — at most one queued write per case per operation.

### Loop prevention
- Inbound events only ever write Open Dental–owned fields (due date, appointment, instructions, provider) — outbound never writes those, so an echo physically cannot produce a local diff.
- After each delivered write, the case mapping's `metadata_json.outbound_sync` records `{fields, delivered_at, last_tstamp}`; when an inbound event's `DateTStamp` matches `last_tstamp` it is finalized as `Echo of DentaTrak-originated write; suppressed.` The normal field diff still runs first, so a real remote change riding the same delivery is never dropped.

### Conflict policy (all-or-nothing per event) — APPROVED
Lifecycle dates are **write-once**. Before writing, the worker GETs the
remote LabCase and compares each payload field:

| Remote now | Result |
|---|---|
| == desired | already in target state — skipped (no PUT) |
| populated, ≠ desired | **conflict** — remote preserved, human reconciles |
| empty (sentinel `0001-01-01`/`2000-01-01`, `T` or space separator, or null) | written |

A nonempty remote lifecycle date is NEVER overwritten, whatever provenance
said — so backward moves and stage re-entries are safe by construction:
re-entering a mapped stage finds its earlier timestamp still populated and
parks as `conflict` instead of rewriting history. `expected_remote_json`
is retained on the event for human inspection only; it no longer drives
the decision. `conflict` is terminal: no partial write, no retry.

Enqueue-side short-circuits (no queue row at all): provenance equal to the
desired value → `already_synced`; transition unchanged → `no_change`;
status unmapped → `status_unmapped`; office timezone unconfigured →
`timezone_unconfigured`.

## Approved lifecycle mapping

DentaTrak has **six fixed internal statuses** (`getWorkflowStageDefinitions()`),
with `cases_cache.status_changed_at` stamped (UTC) on every transition.
`field_map` keys are these **stable internal status identifiers** — the
literal `cases_cache.status` values — never display labels (a practice may
rename labels freely) and never localized text. Practice-specific custom
columns get stable `Custom-*` ids which may also be mapped deliberately.

| DentaTrak transition INTO | Open Dental field | Status |
|---|---|---|
| `Sent To External Lab` | `DateTimeSent` = status_changed_at (office-local) | ✅ APPROVED — means "prepared and sent / out for pickup" |
| `Received From External Lab` | `DateTimeRecd` = status_changed_at (office-local) | ✅ APPROVED — means "practice received the case" |
| `Delivered` | — NOT mapped | ❌ `DateTimeChecked` is OD's post-return quality check, not patient delivery |
| `Originated`, `Designed`, `Manufactured` | — none | not mappable |
| Custom (`Custom-*`) columns | configurable, opt-in only | unmapped by default |

Never written outbound (Open Dental–authoritative): PatNum, ProvNum,
LaboratoryNum, AptNum/PlannedAptNum, DateTimeDue, Instructions.
Never written (DentaTrak-only): comments, attachments, tooth shade,
material, carrier/tracking, assignee, custom workflow data.

**Timestamps:** `cases_cache.status_changed_at` is UTC (server default).
Open Dental interprets LabCase datetimes as office-local wall time, so
every outbound timestamp is converted using `config_json.outbound.timezone`
(IANA name). A connection **cannot enqueue writes until a timezone is
configured** — writing a UTC instant as office-local would silently shift
every lifecycle date by the office's UTC offset. The payload records the
transition instant, never worker processing time.

## Open questions needing a decision

1. ~~`Delivered` → `DateTimeChecked`~~ — RESOLVED: not mapped (user decision).
   `DateTimeChecked` stays unmapped until a fitting DentaTrak event exists.
2. **Clearing remote dates**: deliberately unsupported — OD sentinel-clear
   semantics via PUT are unverified, and regressions preserve history rather
   than rewriting it (conflict path above).
3. **Instructions on create**: the queue's PHI contract currently rejects
   instruction text in outbound payloads (key screening). Enabling notes on
   created OD cases needs an explicit contract amendment.
4. ~~**Create enablement UI/scope**~~ — RESOLVED (Phase 3): the case
   actions menu exposes "Send to Open Dental" (`od_send.menu_item`) for
   eligible cases when `window.pmsSync.odCreateEnabled` (both global flags
   + a create-enabled connection on the practice). `api/od-send-case.php`
   (`check` / `search_patients` / `send`) resolves PatNum/ProvNum/
   LaboratoryNum — via local-key identity mappings learned from inbound
   sync (`recordIdentityMappings`) or the dialog's pickers — persists picks
   through `persistCreateSelections`, verifies every remote id against the
   office's own API, then enqueues `create_labcase`. The worker POSTs,
   attaches the LabCaseNum mapping, and fingerprint-recovers timed-out
   POSTs; remote-id collisions park as `conflict`. Never auto-creates
   remote patients/providers/labs.

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
