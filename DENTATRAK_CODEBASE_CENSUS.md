# DentaTrak Codebase Census

**Generated:** 2026-09-25 · **Scope:** full repository at `C:\MAMP\htdocs\DentaTrak`
**Method:** scripted file classification and line counting, regex-based structural extraction (CREATE TABLE, function declarations, env vars), plus manual inspection of `main.php`, `api/`, `js/`, `css/`, `migrations/`, `tests/`, `.env.example`, `composer.json`, `.htaccess`, and `cloudbuild.yaml`.

**Exclusions everywhere unless noted:** `node_modules/`, `vendor/`, `composer.lock`, `.package-lock.json`, generated/minified assets, runtime folders (`logs/`, `sessions/`, `uploads/`, `screenshots/`, `test-results/`), binary documents (`documentation/DentaTrak User Guide.docx` — ~23K "lines" of binary content, not source), and temporary census scripts.

---

## 1. Executive Summary

| Metric | Value | Measured / Estimated |
|---|---|---|
| Total application code | **~154,700 LOC** (~103,600 excluding localization JSON) | Measured — scripted LOC count; locales counted separately |
| Total files (application source) | **330** (482 incl. tests) | Measured — file walk, exclusions applied |
| Primary languages | PHP, JavaScript, CSS, JSON (i18n), SQL | Measured — extension-based classification |
| Primary frameworks / libraries | Vanilla PHP + JS; Composer: google/apiclient, google/cloud-storage, guzzle, stripe-php, dompdf, phppresentation, phpdotenv, endroid/qr-code, zipstream | Measured — `composer.json` (9 direct packages) |
| Major application modules | **~17** | Estimated — curated grouping of subsystems |
| Identifiable user-facing features | **~95** | Estimated — feature inventory in §4, each item individually listed |
| Database tables | **44 persistent** (35 created in-repo + 9 provisioned outside repo but referenced) + 1 temp table | Measured — `CREATE TABLE` scan + query-reference scan |
| API endpoints / routes | **~120 HTTP entry points** (~105–110 session-authenticated, ~10 public, ~6 worker/token/CLI) | Estimated — file classification of 170 `api/*.php` |
| Background jobs / workers | **9** | Measured — enumerated files |
| External integrations | **~14 services** | Measured — enumerated integration code/config |
| Major security controls | **18 control categories** | Estimated — control categories, not helper-file count |
| Distinct screens / views | **~45** | Estimated — page files + SPA tabs/subviews |
| Modals / dialogs | **~24** | Estimated — `id="…Modal/Panel"` count in page files |
| Reusable UI components | **~30** | Estimated — JS modules + partials + shared modal structures |
| Test files / test LOC | **152 files, ~28,100 LOC** | Measured |
| Test coverage | Not measured — no coverage tooling configured | — |

Every quantitative figure below is labeled **(measured)** or **(est.)** with its method stated at first use.

---

## 2. Codebase Size

### By area (measured; per-line script separating code / comments / blanks)

| Area | Files | Code LOC | % of app code* | Notes |
|---|---|---|---|---|
| `api/` (backend incl. integrations) | 182 | 39,639 | 25.6% | includes `api/integrations/` (12 files, 2,137) |
| `js/` (frontend) | 32 | 24,256 | 15.7% | |
| `css/` (styling) | 36 | 18,991 | 12.3% | |
| Root `*.php` pages (marketing, auth, admin, app shell) | ~39 | ~18,500 | 12.0% | incl. `main.php` 3,551, `index.php` 2,675, `admin-practices.php` 2,455, `login.php` 1,269 |
| `locales/` (9 JSON files) | 9 | 51,102 | 33.0% | content strings, not logic |
| `migrations/` | 21 | 1,588 | 1.0% | SQL embedded in PHP |
| `partials/` | 3 | 206 | 0.1% | |
| `scripts/` (shell) | 5 | 253 | 0.2% | |
| **Application total** | **~330** | **~154,700** | 100% | **~103,600 LOC excluding locales** |
| `tests/` | 152 | 28,073 | — | test code, reported separately |

\* percentages are of ~154,700 including locale JSON; excluding locales, `api/` ≈ 38%, `js/` ≈ 23%, `css/` ≈ 18%, root pages ≈ 18%.

### Functional-area split (est. — keyword bucket assignment of each file; excludes tests)

| Functional area | Files | Code LOC |
|---|---|---|
| Internationalization (9 locales) | 13 | 51,967 |
| Cases core (create/update/list/board/kanban) | 40 | 10,614 |
| Auth & identity (login, OAuth, 2FA, sessions, password reset) | 40 | 9,706 |
| Insights / analytics / at-risk | 15 | 8,649 |
| Admin & dev tooling | 12 | 8,562 |
| Billing / subscriptions (Stripe) | 22 | 6,457 |
| Files & storage (GCS, signed URLs, ZIP, viewer) | 21 | 4,641 |
| Notifications & email | 21 | 3,631 |
| Public marketing pages | 9 | 3,854 |
| PMS integration (Open Dental) | 20 | 3,370 |
| Workflow columns/stages | 9 | 3,253 |
| Comments & activity | 9 | 2,993 |
| Security & compliance (authz, PHI, CSRF, headers) | 11 | 2,819 |
| AI & Ask DentaTrak | 11 | 2,249 |
| Users & practices management | 20 | 2,086 |
| Settings / config | 3 | 1,934 |
| Remakes | 5 | 1,410 |
| Remaining unbucketed | ~25 | ~2,589 |

### Totals

- **Application code:** ~154,700 LOC (measured); ~103,600 LOC excluding localization content.
- **Test code:** ~28,100 LOC in 152 files (measured).
- **Comments/documentation in source:** ~17,700 comment lines across non-test source (measured — `//`, `/* */`, `#`, `<!--` counting).
- **SQL/database code:** ~1,600 LOC in `migrations/` (measured) plus SQL embedded throughout `api/*.php` (not separately measurable without a SQL-aware parser).
- **PHP:** ~964 named functions across 245 PHP files (measured). **JS:** ~896 named functions in 32 files (measured); ~1,600 additional anonymous/arrow functions (est. — combined function-expression count minus named count).

---

## 3. Application Module Inventory

Approximate LOC is the file-level sum from §2's bucket mapping (est., ±15%).

| Module | Purpose | Primary files | Type | ~LOC |
|---|---|---|---|---|
| **Case management** | CRUD, board/list views, archive/restore, search, filter/sort, assignment | `cases-cache.php`, `case_updates`, `CaseService.php`, `list/get/create/update/delete/restore-case.php`, `update-case-status.php`, `js/app.js`, `js/case-list.js`, `case-filter-sort.js`, `mobile-kanban.js` | Mixed | ~10,600 + large share of `app.js` (11,342) |
| **Workflow stages** | Customizable columns/stages, reorder, archive, per-practice config | `workflow-columns.php`, `workflow-columns-service.php`, `workflow-stages.php`, `workflow-draft*.js`, `kanban-dragdrop.css` | Mixed | ~3,300 |
| **Comments & activity** | Case comments, @mentions, comment images, activity log, revision history | `case-comments.php`, `case-activity-log.php`, `js/case-comments.js`, `activity-timeline.js` | Mixed | ~3,000 |
| **Files & storage** | GCS object storage, signed upload/download URLs, attachment viewer, ZIP export, print | `gcs-storage.php`, `gcs-attachments.php`, `upload/download-signed-url.php`, `download-case-attachments-zip.php`, `attachment-content/display.php`, `attachment-viewer.js`, `gcs-upload.js`, `print-case.php` | Mixed | ~4,600 |
| **Remakes** | Structured remake tracking, cost events | `remakes.php`, `case-remakes.js`, `case_remake_events` | Mixed | ~1,400 |
| **Assignments & lab history** | Per-case assignment, lab assignment periods, labels | `update-case-assignment.php`, `lab-assignment-history.php`, `assignments.js`, `case_lab_assignment_periods`, `practice_assignment_labels` | Mixed | folded into Cases core |
| **Notifications** | In-app + email notifications, preferences, queue, bell UI | `notifications.php`, `notification-service.php`, `notification-email-renderer.php`, `notification-preferences.php`, `notifications.js`, `notification_email_queue`, `user_notifications` | Mixed | ~3,600 |
| **Practice Insights** | Dashboards: status/type distribution, volume, team performance, durations, lifecycle | `get-analytics.php`, `js/analytics.js`, `analytics-pro.js`, `insights.js` | Mixed | ~8,600 |
| **Lab Insights** | Per-lab performance, turnaround, remakes, comparison | `get-lab-insights.php`, `lab-performance-metrics.php`, `lab-recommendations.php`, `js/lab-insights.js` | Mixed | incl. above |
| **At-risk engine** | Appointment-risk / late / due-soon heuristics | `at-risk-calculator.php` | Backend | incl. above |
| **Smart Recommendations (AI)** | Practice-level AI recommendations over analytics data | `ai-recommendations.php`, `ai-client.php` | Backend | ~2,200 |
| **Ask DentaTrak** | Natural-language assistant: product help + allowlisted case queries, feedback, telemetry | `ask-dentatrak*.php` (5 files), `ask-dentatrak.js`, `ask_dentatrak_usage` | Mixed | incl. above |
| **PMS / Open Dental integration** | Connections, credentials, canonical model, adapter, sync engine, event processing | `api/integrations/` (IntegrationManager, IntegrationEvents, SyncEngine, OpenDental*, adapters), `open-dental-events.php` | Backend | ~3,400 |
| **Authentication & identity** | Password login, Google OAuth, unified identity, remember-me, email verification, password reset/setup, 2FA (TOTP), sessions | `unified-identity.php`, `google-auth*.php`, `2fa-*.php`, `password-*.php`, `email-verification.php`, `session*.php`, `TOTP`, `PdoSessionHandler` | Mixed | ~9,700 |
| **Authorization & practice isolation** | Role checks, practice membership, per-case authorization, lab collaborator limits, terms/BAA gating | `practice-security.php` (30+ functions), `billing-gate.php`, `subscription-access.php`, `plan-entitlements.php` | Backend | ~2,800 |
| **Billing & subscriptions** | Stripe checkout/portal/webhooks, plans (Operate/Control/Scale), entitlements | `billing*.php`, `stripe-*.php`, `create-checkout-session.php`, `subscriptions`, `js/billing-portal.js` | Mixed | ~6,500 |
| **Administration** | Practice admin console, audit log, email log, hidden practices, compliance views | `admin-practices.php` (2,455 + 1,872 api), `dev-tools`, `demo_generation_runs` | Mixed | ~8,600 |
| **Localization** | 9 locales, runtime `t()`, locale detection | `locales/*.json`, `i18n.php`, `i18n.js`, `set-session-locale` | Mixed | ~52,000 content + ~600 logic |
| **Settings & preferences** | Practice settings, notification prefs, display prefs, integrations config | `get/save-settings.php`, settings modal in `main.php`, `settings-billing.css` | Mixed | ~1,900 |
| **Security & compliance** | CSRF, security headers, encryption, PHI access log, HIPAA helpers, audit logging | `csrf.php`, `security-headers.php`, `encryption.php`/`PIIEncryption`, `phi-access-log.php`, `hipaa-compliance.php` | Backend | ~2,800 |
| **Public site & resources** | Marketing homepage, about, resources library, 11 articles, remake calculator, legal pages, SEO | root `*.php` pages, `marketing.css`, `sitemap.xml`, `indexnow.php` | Frontend | ~18,500 (root pages, incl. app shell) |
| **Background processing** | Queue workers, maintenance, cleanup, event processing | see §10 | Backend | ~2,000 |

---

## 4. Complete Feature Inventory

Status: **active** = wired into UI/routes today; **flagged** = behind a feature flag (`isFeatureEnabled`); **legacy/dev** = dev-tool or superseded path.

### Case operations
| Feature | Description | Users | Code | Status |
|---|---|---|---|---|
| Case create | New case with patient ref, type, lab, due/appointment dates | Practice staff | `create-case.php`, `createCaseModal` | Active |
| Case edit/update | Update fields, clinical details | Practice staff | `update-case.php`, `clinical-details.js` | Active |
| Case delete/restore | Soft-delete + restore | Practice staff | `delete-case.php`, `restore-case.php` | Active |
| Archive & archived view | Archive filtering, archived cases modal | Practice staff | `get-archived-cases.php`, `archivedCasesModal` | Active |
| Board (Kanban) view | Drag-drop columns by workflow stage | Practice staff | `app.js`, `kanban-dragdrop.css` | Active |
| List view | Sortable/filterable table | Practice staff | `case-list.js`, `case-filter-sort.js` | Active |
| Filtering | By case type, assignee, carrier, attention flags | Practice staff | `case-filter-sort.js`, filter bar in `main.php` | Active |
| Sorting | Multi-key sort with guidance UI | Practice staff | `case-filter-sort.js` | Active |
| Search | Case search, patient search | Practice staff | `patient-search.js`, list search | Active |
| Case status updates | Move through workflow stages | Practice staff | `update-case-status.php` | Active |
| Case types | Categorized case types (localized vocabulary) | All | `case_types` locale keys | Active |
| Assignment | Assign cases to team members | Practice staff | `update-case-assignment.php`, `assignments.js` | Active |
| Assignment labels | Named role labels + per-label notification recipients | Practice admins | `practice_assignment_labels`, `case_label_assignments` | Active |
| Attention signals | Due Soon, Late, Appointment Risk, Needs Review | Practice staff | `at-risk-calculator.php`, filter flags | Active (`SHOW_AT_RISK` flag off globally for the at-risk surface) |
| Case detail modal | Details/comments/history tabs | Practice staff | `caseViewTabs` in `main.php` | Active |
| Print case | Printable case summary | Practice staff | `print-case.php` | Active |
| Comments | Threaded comments per case | Practice staff | `case-comments.php` | Active (`SHOW_COMMENTS`) |
| @mentions | Notify mentioned users | Practice staff | `case-comments.php` + notification service | Active |
| Comment images | Images attached to comments | Practice staff | `case_comments` image columns (migration `2026_09_30`) | Active |
| Activity log | Per-case activity history | Practice staff | `case_activity_log`, `activity-timeline.js` | Active (`SHOW_ACTIVITY_TIMELINE` flag off) |
| Revision history | Field-change history | Practice staff | revision UI, `SHOW_REVISION_HISTORY` | Flagged off |
| Remake tracking | Structured remake events/reasons | Practice staff | `remakes.php`, `case_remake_events` | Active |
| Lab assignment history | Per-case lab assignment periods | Practice staff | `case_lab_assignment_periods` | Active |
| Bulk ZIP download | Download all case attachments | Practice staff | `download-case-attachments-zip.php`, `case-zip-helpers.php` | Active (`SHOW_CASE_DOWNLOAD_ALL`) |
| Signed file URLs | GCS signed upload/download | Practice staff | `upload-signed-url.php`, `download-signed-url.php` | Active |
| Attachment viewer | In-app viewer (PDF.js etc.) | Practice staff | `attachment-viewer.js`, `attachment-content.php` | Active |

### Workflow administration
- Custom workflow columns/stages — add/rename/reorder/archive/restore, draft UI (`workflow-draft*.js`). Active.
- Per-practice workflow configuration persisted in practice settings. Active.

### Notifications
- In-app notification center (bell + panel). `SHOW_NOTIFICATIONS` flag.
- Email notifications via queue (Resend). Active.
- Notification preferences per user (`notification-preferences.*`).
- Notification destination configuration (`notification-destination.php`).
- Event types: mention, comment, assignment_changed/assignment_set, status_changed, due_date_changed/removed (measured in `notification-service.php`).
- Dismissal/read state (`user_notifications`).

### Insights & analytics
- Practice Insights dashboard: status distribution, case-type distribution, monthly volume, team performance, status duration, lifecycle distribution, cases-created-by-user. Active.
- Lab Insights: per-lab performance table, turnaround, remake metrics, lab comparison. Active (`SHOW_LAB_INSIGHTS`).
- At-risk calculation: approaching-due-unassigned, late-and-unscheduled, excessive time-in-stage, multiple regressions, multiple reassignments (measured in `at-risk-calculator.php`).
- Insights usage tracking (`recordInsightsVisit`, migration `2026_09_16`).
- Plan gating for advanced analytics (`analytics-pro.*`).

### AI & natural language
- **Smart Recommendations** — AI-generated practice recommendations over aggregated analytics (`ai-recommendations.php` → `ai-client.php`; providers: Gemini default, OpenAI).
- **Ask DentaTrak** — in-app assistant: product-help answers grounded in current content + six allowlisted read-only case tools: `count_cases`, `list_cases`, `aggregate_cases_by_status`, `count_remakes`, `get_case_summary`, `get_reference_values` (measured in `ask-dentatrak.php`). Permission-scoped via `authorized_case_ids` temp table; redirects analytics to Insights; refuses out-of-scope topics. Flagged by `SHOW_AI_CHAT`.
- Ask telemetry + user feedback + usage retention (`ask_dentatrak_usage`, `ask-dentatrak-telemetry.php`, `ask-dentatrak-feedback.php`, `ask-dentatrak-maintenance.php`).

### PMS integration (Open Dental)
- Integration connections & encrypted credentials (`integration_connections`, `integration_credentials`, `IntegrationCredentials`).
- Entity mappings (`integration_entity_mappings`), canonical model (`CanonicalCase`), adapter pattern (`PmsAdapterInterface`, `OpenDentalAdapter`).
- Portal client (`OpenDentalPortalClient`), config (`OpenDentalConfig`).
- Webhook/external event intake → `integration_external_events` → `IntegrationEvents::processDue()` exact-once import.
- Sync runs/events tracking (`integration_sync_runs`, `integration_sync_events`).
- Guided setup UI (`integrationConfigModal`, `integrationGuidedSetup` in settings).
- Flagged by `SHOW_PMS_INTEGRATIONS` (off by default).

### Accounts, identity, access
- Email/password login with throttling (`login_attempts`).
- Google sign-in / unified identity linking (`user_auth_methods`).
- Email verification (`email_verification_tokens`).
- Password reset & first-time setup (`password_reset_tokens`, `password_setup_tokens`).
- Remember-me tokens (`remember_me_tokens`).
- TOTP 2FA: setup, challenge, recovery, reset tokens; practice-level 2FA requirement (`practice_require_2fa` migration); Google-2FA verify.
- User invitations to practices (`practice-invite-email.php`, `get-practice-users.php`).
- Practice switching & selection (`select-practice.php`, `switch-practice.php`, `get-user-practices.php`).
- Practice setup wizard (`practice-setup.php`).
- Terms & BAA acceptance flows (`accept-terms.php`, `baa-acceptance.php`, `accept-baa.php`).
- Language selection across 9 locales; locale persistence.
- Notification preferences; display preferences (`user_preferences`).

### Roles & permissions
- Practice roles (owner/admin/member), `isPracticeAdmin/Owner`, `getUserPracticeRole`.
- Limited-visibility users (`hasLimitedVisibility`), lab-collaborator restriction (`isLabCollaborator`, `requireNotLabCollaborator`).
- Case-edit permission (`canEditCases`), analytics permission (`canViewAnalytics`), assignment-label management (`canManageAssignmentLabels`).
- Per-case authorization materialized via `authorized_case_ids` temp table.

### Billing & plans
- Plans: Operate / Control / Scale (monthly+annual price IDs per plan; Scale additional-seat pricing).
- Stripe Checkout (`create-checkout-session.php`), Billing Portal (`create-portal-session.php`, `billing-portal.php`), webhooks (`stripe-webhook.php` + `stripe_webhook_events`).
- Subscription records (`subscriptions`), subscription access gate (`subscription-access.php`), plan entitlements (`plan-entitlements.php`), billing gate (`billing-gate.php`).
- Trial extension (admin `extendTrialModal`).

### Administration (super-user)
- `admin-practices.php` console: practice list/detail panel, compliance modal, PHI log modal, deactivate modal, email modal, extend-trial modal.
- `admin_audit_log`, `admin_email_log`, `admin_hidden_practices`.
- Dev tools panel (demo data generate/reset/delete; `demo_generation_runs`), `check-dev-tools.php`, `dev-ask-usage.php`, `dev-enable-notifications.php` — gated by `SUPER_USERS`/`SHOW_DEV_TOOLS`.

### Security & compliance (features)
- PHI access logging (`phi_access_log`, `phi-access-log.php`, PHI audit modal).
- HIPAA helpers (`hipaa-compliance.php`).
- PII encryption (`PIIEncryption`, `encryption.php`, `ENCRYPTION_KEY`).
- Session revocation (`revoke-sessions.php`), session status, DB session handler w/ rotation (`php_sessions`, `php_session_rotations`), inactivity timeout, remember-me.
- CSRF tokens (`csrf.php`), security headers (`security-headers.php`).
- Data export (`data-export.php`, `data_exports`), account/data deletion paths.
- Google Drive backup (`google-drive.php`, `SHOW_GOOGLE_DRIVE_BACKUP` flag).
- Security event logging (`logSecurityEvent`).

### Public site & resources
- Marketing homepage, About, Resources library (12 resource pages: 11 articles + Dental Remake Cost Calculator), HIPAA & Security page, Privacy, Terms, User Guide (`api/user-guide.php` via rewrite).
- SEO: canonical URLs, OG/Twitter meta, JSON-LD (Article/Breadcrumb/FAQPage/Product), `sitemap.xml`, `robots.txt`, IndexNow submission.
- Demo/practice sign-up path (`practice-setup.php`).
- Tour (`SHOW_TOUR`, `js/tour.js`).

---

## 5. Screen / UI Inventory

### Public pages (measured — root `*.php` files)
index (marketing home) · about · resources (library index) · 11 articles (`dental-case-tracking`, `dental-case-tracking-checklist`, `dental-case-tracking-software`, `dental-case-tracking-software-vs-pms`, `dental-case-tracking-vs-spreadsheets`, `dental-lab-case-tracking`, `dental-case-management-external-labs`, `crown-and-bridge-case-tracking`, `how-to-track-dental-cases`, `implant-case-tracking`, `visual-dental-case-workflow`) · `dental-remake-cost` (calculator) · `hipaa-security` · `privacy` · `terms` · user-guide (`api/user-guide.php` via `.htaccess`) · `404` → **~20 public views**

### Auth / onboarding (measured)
login · forgot-password · reset-password · set-password · verify-email · 2fa-required · 2fa-recovery · 2fa-reset · accept-terms · baa-acceptance · practice-setup · clear-session · clear-google-tokens → **~13 views**

### Application (SPA, `main.php`)
- **Cases tab** — board view, list view, filter bar, sort UI, mobile kanban (`mobile-kanban.js`), mobile case modal (`mobile-case-modal.js`).
- **Practice Insights tab** — subtabs (`insightsSubtabs`), metrics grid, operational overview, Smart Recommendations, status/type/volume/team/duration/lifecycle charts, cases-by-user section.
- **Lab Insights tab** — subtabs (`labInsightsSubtabs`), lab performance table, lab metrics.
- **Settings modal** — 6 nav panels: Practice, Display, Users (authorized), Security, Data & Privacy, Integrations (flagged).
- **Notification bell + panel** with preferences link.
- **User menu** — settings, billing, Ask DentaTrak, logout, keyboard shortcuts (`Ctrl/Cmd+,`, `Ctrl/Cmd+/`, `Esc`).
- **Ask DentaTrak panel** (`askDentatrakPanel`).
- **Dev tools panel** (`devToolsPanel`) — super-user only.
- **Admin console** (`admin-practices.php`) — practice list, detail panel, modals (compliance, PHI log, deactivate, email, extend trial).
- **Billing page** (`billing.php`).

### Modals/dialogs (est. ~24 — counted `id="…Modal/Panel"` occurrences in `main.php` + `admin-practices.php` + `billing.php`)
createCase · caseModal (details/comments/history tabs) · caseCommentsPanel · caseRevisionHistoryPanel · deleteConfirm · remake · feedback + feedbackSuccess · archivedCases · phiAudit · billingPortal · renameAssignmentLabel · settingsBilling (6-panel) · extendTrial · email · compliance · phiLog · deactivate · detailPanel · integrationConfig (+ guided setup, test) · googleDriveBackup · cardDelete · devToolsPanel · askDentatrakPanel · confirm (generic) · attachmentViewer · notificationPreferences · pageLoading/error overlays · control-plan upgrade overlays.

### Reusable UI components (est. ~30)
Method: 32 JS feature modules + 3 partials + recurring markup structures (modals, toast, tabs, user-menu, notification bell, kanban card, attachment chips, charts, settings twisties, guided-setup wizard). Distinct reusable units ≈ 30; not a formal component library — vanilla JS modules render into server-rendered markup.

### Totals (est.)
- **Distinct screens/views: ~45** (20 public + 13 auth + ~9 app views incl. subtabs + admin console + billing + dev surfaces).
- **Modals/dialogs: ~24.**
- **Reusable components: ~30.**

---

## 6. Database Inventory

**Method (measured):** scanned `migrations/` and all `api/*.php` for `CREATE TABLE` blocks (35 found); scanned all SQL `FROM/JOIN/INTO/UPDATE` references for tables used but not created in-repo (9 found). Column/index counts are per-statement approximations from the same scan. Local DB at port 3308 was unavailable during census; figures are source-derived, not live-DB-verified. An earlier broad regex produced ~279 false positives and was discarded.

### Confirmed — created in-repo (35)

| Table | ~Cols | Purpose | Key relationships |
|---|---|---|---|
| `cases_cache` | 32 | Primary case store (denormalized case record) | practice_id, assigned users; hub table |
| `case_updates` | 8 | Case update events | case_id |
| `case_activity_log` | 9 | Per-case activity history | case_id |
| `case_comments` | 13 | Comments incl. images, mentions | case_id, user_id |
| `case_remake_events` | 13 | Structured remake tracking | case_id |
| `case_lab_assignment_periods` | 16 | Lab assignment history per case | case_id, lab |
| `notifications`→`user_notifications` | 16 | In-app notifications + dismissal | user_id, case_id, event_id |
| `notification_events` | 8 | Notification event log | event_id |
| `notification_email_queue` | 15 | Queued email notifications | event_id, user_id, practice_id |
| `user_notification_preferences` | 7 | Per-user prefs | user_id |
| `practice_assignment_label_recipients` | 5 | Label→notification recipients | label_id |
| `users` (ext.) | — | Accounts | FK target of 4 constraints |
| `practices` (ext.) | — | Tenants | practice_id everywhere |
| `practice_users` (ext.) | — | Membership/roles | user+practice |
| `user_preferences` (ext.) | — | Per-user settings | user_id |
| `case_assignments` (ext.) | — | Case→assignee | case_id |
| `practice_assignment_labels` (ext.) | — | Custom assignment labels | practice_id |
| `case_label_assignments` (ext.) | — | Case→label links | case_id |
| `sessions` (ext.) | — | Legacy session records | user_id |
| `user_activity_log` (ext.) | — | Account activity (BAA acceptance etc.) | user_id |
| `php_sessions` | 5 | DB session handler storage | — |
| `php_session_rotations` | 4 | Session rotation hashes | — |
| `remember_me_tokens` | 9 | Persistent login tokens | FK→users |
| `user_auth_methods` | 8 | Auth method linking (password/Google) | FK→users |
| `login_attempts` | 4 | Login throttling | — |
| `email_verification_tokens` | 6 | Email verification | FK→users |
| `password_reset_tokens` | 6 | Password resets | — |
| `password_setup_tokens` | 6 | First-time password setup | FK→users |
| `two_factor_reset_tokens` | 7 | 2FA recovery | user_id |
| `subscriptions` | 19 | Stripe subscription state | FK→users (owner) |
| `stripe_webhook_events` | 13 | Webhook dedup/processing | event id |
| `integration_connections` | 13 | PMS connections | practice_id |
| `integration_credentials` | 6 | Encrypted integration credentials | connection_id |
| `integration_entity_mappings` | 10 | External↔internal ID map | connection_id |
| `integration_external_events` | 12 | Inbound webhook event queue | connection_id |
| `integration_sync_runs` | 14 | Sync job runs | connection_id |
| `integration_sync_events` | 10 | Per-entity sync events | run_id |
| `phi_access_log` | 9 | HIPAA PHI access audit | user_id, resource |
| `admin_audit_log` | 7 | Admin action audit | admin user |
| `admin_email_log` | 12 | Admin-sent emails | practice_id |
| `admin_hidden_practices` | 4 | Hidden practice records | — |
| `data_exports` | 11 | User data export requests | user_id |
| `ask_dentatrak_usage` | 12 | Ask usage/telemetry | user_id, practice_id |
| `demo_generation_runs` | 9 | Dev demo-data runs | — |

Plus `authorized_case_ids` — a per-request **temporary** table materializing the calling user's authorized case IDs (created by `ensureAuthorizedCaseIdsTempTable` in `practice-security.php`).

### Totals
- **Tables: ~44 persistent** (35 in-repo DDL + 9 externally provisioned but heavily referenced) + 1 temp. Core tables (`users`, `practices`, `practice_users`, `user_preferences`, `case_assignments`, `practice_assignment_labels`, `case_label_assignments`, `sessions`, `user_activity_log`) have **no DDL in the repo** — base schema is provisioned outside this codebase (historical setup; `setup-db.php` references a `setup-practice-tables.php` that no longer exists).
- **Views: 0** found.
- **Indexes: ~200 index clauses** measured across CREATE/ALTER statements (includes inline `KEY`/`UNIQUE KEY` definitions; approximate).
- **Foreign keys: ~5 declared** in CREATE TABLE bodies (users ×4, subscriptions→users); most integrity is enforced in application code rather than FK constraints.

### Central tables
`cases_cache` (167 query references), `practices` (119), `practice_users` (116), `users` (197 across all refs), `practice_assignment_labels` (35), `subscriptions` (35), `case_lab_assignment_periods` (27).

---

## 7. API / Route Inventory

**Method (est.):** `api/` contains 170 `.php` files. Classification by content: ~50 shared libraries/services (no direct request input), ~6 worker/CLI/token-authenticated entry points, ~11 public request handlers (account flows, webhooks), remainder session/CSRF-authenticated endpoints. Effective HTTP endpoints ≈ **115–120**. Grouped:

### Cases (~25 endpoints)
`list-cases`, `get-case`, `create-case`, `update-case`, `delete-case`, `restore-case`, `get-archived-cases`, `update-case-status`, `update-case-assignment`, `get-all-case-assignments`, `get-case-activity`, `case-comments`, `case-remakes`/`remakes`, `case-activity-log`, `print-case`, `preflight-*`, patient search, etc.

### Files/attachments (~10)
`upload-signed-url`, `download-signed-url`, `delete-file`, `download-case-attachments-zip`, `attachment-content`, `attachment-display`, `gcs-attachments`, etc.

### Workflow (~4)
`workflow-columns`, `workflow-columns-service`, `workflow-stages`, status endpoints.

### Notifications (~6)
`notifications`, `notification-preferences`, `save-notification-preferences`, `notification-destination`, queue worker, maintenance.

### Insights/analytics (~6)
`get-analytics`, `get-lab-insights`, `lab-performance-metrics`, `lab-recommendations`, `ai-recommendations`, at-risk calculations.

### Ask DentaTrak / AI (~6)
`ask-dentatrak`, `ask-dentatrak-tools`, `ask-dentatrak-help`, `ask-dentatrak-telemetry`, `ask-dentatrak-feedback`, `ask-dentatrak-maintenance` (worker).

### Users/practices/settings (~15)
`get-settings`, `save-settings`, `user-manager`, `get-practice-users`, `get-current-user`, `get-user-practices`, `select-practice`, `switch-practice`, `update-practice`, `practice-security`, `practice-2fa-policy`, invites, preferences.

### Billing (~9)
`billing`, `billing-portal`, `create-checkout-session`, `create-portal-session`, `stripe-webhook`, `subscription-access`, `plan-entitlements`, `billing-gate`, migrate-* helpers.

### Auth/identity (~15)
`unified-identity`, `google-auth`, `google-auth-callback`, `oauth-start`, `password-reset`, `request-password-setup`, `email-verification`, `2fa-*` (setup/challenge/recovery), `verify-google-2fa`, `change-password`, `logout`, `check-email`, remember-me.

### Sessions/security (~7)
`session-status`, `revoke-sessions`, `phi-access-log`, `data-export`, `check-dev-tools`, `dev-tools-access`, security event endpoints.

### Integrations (~6)
`open-dental-events`, `integration-event-worker`, `integrations/process-events` (CLI), `google-drive`, `google-drive-callback`, `google-drive-backup`.

### Admin (~5)
`admin-practices` API, `admin-subscription-helpers`, admin email/audit endpoints, demo-data generator.

### Public endpoints (~10)
login/unified-identity, `check-email`, `password-reset`, `request-password-setup`, `email-verification`, `stripe-webhook` (signature-authed, not session), `indexnow-submit` (key), `cleanup-orphan-uploads` (secret key), demo request. Workers authenticate via `X-Queue-Worker-Token`.

### Page routes
Root `*.php` pages (39) + `.htaccess` clean-URL rewrites (article slugs → `*.php`, `resources/user-guide` → `api/user-guide.php`).

---

## 8. External Integrations

| Integration | Purpose | Direction | Files | Required? |
|---|---|---|---|---|
| **Stripe** | Subscriptions, checkout, customer portal, webhooks | Bidirectional | `stripe-webhook.php`, `create-checkout-session.php`, `create-portal-session.php`, `billing-portal.php`, `stripe-price-map.php`, `stripe-webhook-guard.php` | Required for billing; gated by `BILLING_ENABLED`/price config |
| **Google OAuth / Identity** | Sign-in, account linking | Inbound auth | `google-auth.php`, `google-auth-callback.php`, `oauth-start.php`, `unified-identity.php` | Optional (email/password still works) |
| **Google Drive** | Per-practice backup/export to user's Drive | Outbound | `google-drive.php`, `google-drive-callback.php`, `google-drive-backup.php` | Optional (`SHOW_GOOGLE_DRIVE_BACKUP` flag, off by default) |
| **Google Cloud Storage** | Attachment object storage, signed URLs | Bidirectional | `gcs-storage.php`, `gcs-attachments.php`, signed-url endpoints | Required for file features (`GCS_BUCKET_NAME`) |
| **Open Dental (PMS)** | Lab-case import/sync via portal + webhook events | Inbound (webhook→import) | `api/integrations/OpenDental*`, `adapters/OpenDentalAdapter`, `IntegrationEvents`, `open-dental-events.php` | Optional (`SHOW_PMS_INTEGRATIONS`; dev/portal keys) |
| **Resend** | Transactional email (verification, reset, invites, notifications, billing) | Outbound | `email-sender.php`, `auth-email.php`, `notification-email-renderer.php`, `welcome-email.php`, `practice-invite-email.php` | Required for email delivery (`RESEND_API_KEY`) |
| **OpenAI API** | AI provider option for recommendations/Ask | Outbound | `ai-client.php` (`callOpenAIAPI`) | Optional (`AI_PROVIDER`) |
| **Google Gemini API** | Default AI provider | Outbound | `ai-client.php` (`callGeminiAPI`) | Optional (AI features degrade w/o key) |
| **Google Analytics** | Marketing-page analytics | Outbound (client) | inline tags on public pages | Optional |
| **Microsoft Clarity** | Behavior analytics | Outbound (client) | `partials/clarity.php` | Optional |
| **Google Fonts** | Typography | Outbound (client) | public pages | Optional |
| **IndexNow** | Search-engine URL submission | Outbound | `indexnow.php`, `indexnow-submit.php` | Optional (`INDEXNOW_KEY`) |
| **Google Cloud Scheduler + Cloud Run** | Worker invocation + app hosting | Inbound trigger | `scripts/provision-*.sh`, `cloudbuild.yaml` | Prod infra |
| **MySQL (PDO)** | Primary datastore | — | `appConfig.php` | Required |
| **PDF.js** | Client-side attachment viewing | n/a (bundled lib) | `js/pdfjs-worker.js` | Optional enhancement |
| **dompdf / PhpPresentation / QR-code / ZipStream** | Server-side PDF, slides, QR (2FA), ZIP streaming | libraries | composer | Feature-scoped |

Distinct external **services**: ~14 (Stripe, Google OAuth, Google Drive, GCS, Open Dental, Resend, OpenAI, Gemini, GA, Clarity, Fonts, IndexNow, Cloud Scheduler/Run).

---

## 9. Security Architecture Inventory

Inventory of implemented controls only (not a security assessment).

| Control | Implementation |
|---|---|
| Password authentication + throttling | `unified-identity.php`, `login_attempts` |
| Unified identity / multi-method linking | `unified-identity.php`, `user_auth_methods` |
| Google OAuth | `google-auth*.php`, `oauth-start.php` |
| Email verification | `email-verification.php`, `email_verification_tokens` |
| Password reset/setup | `password-reset*.php`, token tables, `request-password-setup.php` |
| TOTP 2FA (setup/challenge/recovery/reset) | `2fa-*.php`, `TOTP` class, `verify-google-2fa.php`, `two_factor_reset_tokens`, QR via endroid |
| Practice-level 2FA requirement | `practice-2fa-policy.php`, migration `2026_10_02` |
| Session management (DB handler, rotation, inactivity timeout, revocation) | `session.php`, `session-db-handler.php` (`PdoSessionHandler`, `SessionLockException`), `php_sessions`, `php_session_rotations`, `session-status.php`, `revoke-sessions.php`, `user_session_version` migration |
| Remember-me tokens | `remember_me_tokens`, `attemptRememberMeLogin()` |
| CSRF protection | `csrf.php` (`generateCsrfToken`, `validateCsrfToken`, `requireCsrfToken`); used by ~8+ mutating endpoints |
| Authorization / practice isolation | `practice-security.php` — `verifyPracticeAccess`, `requirePracticeAccess/Admin`, `getPracticeFilter`, `verifyCaseBelongsToPractice`, `requireValidPracticeContext`, `authorized_case_ids` temp-table scoping |
| Role-based access | `getUserPracticeRole`, `isPracticeAdmin/Owner`, lab-collaborator & limited-visibility restrictions, `canEditCases`, `canViewAnalytics`, `canManageAssignmentLabels` |
| Terms/BAA gating | `hasAcceptedCurrentTerms`, `requireCurrentTermsAccepted*`, `baa-acceptance.php` |
| PHI access logging (HIPAA audit) | `phi-access-log.php`, `hipaa-compliance.php`, `phi_access_log` |
| Security/audit event logging | `logSecurityEvent`, `admin_audit_log`, `user_activity_log` |
| PII encryption at rest | `encryption.php` / `PIIEncryption`, `ENCRYPTION_KEY` |
| Secure headers | `security-headers.php` |
| Webhook signature verification | `stripe-webhook-guard.php`, `STRIPE_WEBHOOK_SECRET*` |
| Worker token auth | `queue-worker-auth.php`, `X-Queue-Worker-Token` |
| File-access authorization | signed URLs (`upload/download-signed-url.php`), `attachment-content.php` practice checks, `DENTATRAK_BULK_ZIP_MAX_BYTES` cap |
| Dev-tools gating | `dev-tools-access.php`, `check-dev-tools.php`, `SUPER_USERS`, `SHOW_DEV_TOOLS` env defaults |
| Billing/subscription gates | `billing-gate.php`, `subscription-access.php`, `plan-entitlements.php` |
| Prepared statements | PDO placeholders throughout `api/` (SQL injection control) |
| Cleanup endpoint protection | `CLEANUP_SECRET_KEY` on `cleanup-orphan-uploads.php` |

**Control categories: 18–20** (est. — grouping the above into control families: authn, MFA, session mgmt, authz/isolation, RBAC, CSRF, headers, audit×3, encryption, webhook auth, worker auth, file authz, throttling, gating×2, input handling).

---

## 10. Background Processing & Automation

| Job | Purpose | Auth/trigger | File |
|---|---|---|---|
| Notification queue worker | Drain `notification_email_queue` → Resend; batch 25 (env-tunable) | `X-Queue-Worker-Token`; Cloud Scheduler 1/min | `notification-queue-worker.php` |
| Notification maintenance | Retention cleanup (~90 days, env-tunable) | worker token | `notification-maintenance.php` |
| Integration event worker | Drain `integration_external_events` → fetch→normalize→exact-once import | worker token; Cloud Scheduler `dtk-prod-integration-event-worker` | `integration-event-worker.php` |
| Integration CLI processor | Local equivalent (`--limit`, `--watch`) | CLI | `integrations/process-events.php` |
| Ask DentaTrak maintenance | Usage retention (~180 days) | worker token | `ask-dentatrak-maintenance.php` |
| Orphan-upload cleanup | Remove unreferenced uploads | `CLEANUP_SECRET_KEY` | `cleanup-orphan-uploads.php` |
| Stripe webhook | Deduped event processing | Stripe signature | `stripe-webhook.php`, `stripe_webhook_events` |
| Session GC / rotation | DB session expiry & rotation | runtime | `session-db-handler.php` |
| IndexNow submit | Bulk URL push | `INDEXNOW_KEY` | `indexnow-submit.php` |
| Demo data generator | Dev fixture generation | super-user | `generate-dental-practice-demo-data.php` |

**Retry semantics (measured, `IntegrationEvents`):** atomic claims via conditional `UPDATE` + `claimed_at`; transient failures (network/timeout/5xx/429/eConnector offline) retry with bounded linear backoff — max 10 attempts, 300s cap, honors `Retry-After`; permanent failures park as `failed`; stale `processing` rows reclaimed after 10 min.

**Workers/jobs total: 9** distinct scheduled/background entry points (excluding libraries).

---

## 11. Reporting, Analytics & AI

- **Practice Insights** (`get-analytics.php`, `analytics.js`, `analytics-pro.js`, `insights.js`): status distribution, case-type distribution, monthly volume, team performance, status-duration, lifecycle distribution, cases-created-by-user; time-period WHERE builder; calendar-day diffing. Pro/plan-gated layer in `analytics-pro.*`.
- **Lab Insights** (`get-lab-insights.php`, `lab-performance-metrics.php`, `lab-recommendations.php`, `lab-insights.js`): per-lab lateness (`labIsLate`), due-date-change analysis, period-overlap logic, turnaround/remake/volume, lab comparison.
- **At-risk engine** (`at-risk-calculator.php`): 5 heuristics (approaching-due-unassigned, late-and-unscheduled, excessive time-in-stage, multiple regressions, multiple reassignments) + batch calculation.
- **Smart Recommendations** (`ai-recommendations.php`): gathers practice analytics → AI provider → recommendations; plan/feature gated.
- **Ask DentaTrak** (`ask-dentatrak*.php`, `ask-dentatrak.js`): Gemini/OpenAI via `ai-client.php`; product-help answers; 6 allowlisted read-only case tools executed server-side against `authorized_case_ids`; redirects analytics/trends/comparisons to Insights; usage telemetry, thumbs feedback, retention worker. Global flag `SHOW_AI_CHAT` (default off).
- **Usage tracking**: `recordInsightsVisit` (migration `2026_09_16`), `ask_dentatrak_usage` (migration `2026_10_04`).

---

## 12. Complexity Indicators

**Largest source files (measured, code LOC):**
`js/app.js` 11,342 · `main.php` 3,551 · `css/app.light.css` 3,125 · `index.php` 2,675 · `css/settings-billing.css` 2,692 · `admin-practices.php` 2,455 · `api/test-helpers.php` 2,041 · `api/admin-practices.php` 1,872 · `css/mobile.css` 1,868 · `login.php` 1,269 · `api/unified-identity.php` 1,065 · `api/cases-cache.php` 1,066 · `api/print-case.php` 1,071.

**Widest modules (file count):** case management (~40 files), auth/identity (~40), billing (~22), notifications (~21), files (~21), integrations (~20), insights (~15).

**Most-dependent modules:** `cases_cache` touched by nearly every subsystem; `practice-security.php` required by ~100 endpoints; `cases-cache.php`/`CaseService.php` underpin all case reads/writes.

**Async-heavy areas:** notification pipeline (event→queue→worker→provider), integration pipeline (webhook→event row→worker→adapter→import), Stripe webhook dedup.

**Permission-logic concentration:** `practice-security.php` alone exposes 30+ authorization functions and is included by most endpoints.

**Integration-logic concentration:** `api/integrations/` (12 files) + event workers + settings UI + 6 integration tables + migrations + tests.

---

## 13. Repository Statistics

| Metric | Value | Method |
|---|---|---|
| Named functions — PHP | 964 | regex `function name(` across 245 PHP files |
| Named functions — JS | 896 | same, 32 files |
| Anonymous/arrow functions | ~1,600 | est. — total function-expression count minus named |
| Classes | 14 | `CanonicalCase`, `CaseService`, `IntegrationCredentials`, `IntegrationEvents`, `IntegrationManager`, `OpenDentalAdapter`, `OpenDentalApiException`, `OpenDentalConfig`, `OpenDentalPortalClient`, `PIIEncryption`, `PdoSessionHandler`, `SessionLockException`, `SyncEngine`, `TOTP` |
| Interfaces | 1 | `PmsAdapterInterface` |
| Custom exceptions | 2 | `OpenDentalApiException`, `SessionLockException` |
| Migrations | 21 files | `migrations/` |
| Env/config vars | ~44 declared in `.env.example`; ~50 `getEnvVar()` call sites | measured |
| Feature flags | 12 (`SHOW_*`) | `feature-flags.php` |
| Locales | 9 | `locales/` |
| Test files | 152 (PHP + JS + TS specs) | measured |
| Test cases | not reliably countable (mixed PHP assert scripts, Playwright specs, ad-hoc verifiers) | — |
| Direct dependencies | 9 Composer packages; ~4 npm packages (Playwright-family, no root `package.json`) | measured |
| Reusable UI components | ~30 | est., §5 |

---

## 14. Product Capability Map

```text
DentaTrak
  Case Management
    Case records
      Create / edit / delete / restore / archive
      Case types, carriers, patient references
      Clinical details, print view
    Workflow
      Customizable stages/columns (add/rename/reorder/archive/restore)
      Board (Kanban) view, drag-drop
      List view, multi-key sorting
      Filtering (type, assignee, carrier, attention flags)
      Search
    Attention signals
      Due Soon / Late / Appointment Risk / Needs Review
      At-risk heuristics (5 rules)
    Collaboration
      Comments, @mentions, comment images
      Activity log, revision history (flagged)
      Assignments, assignment labels, label recipients
    Files
      GCS attachments, signed upload/download URLs
      Attachment viewer (PDF.js), bulk ZIP export
    Remakes
      Structured remake events, cost tracking
    Lab history
      Per-case lab assignment periods
  Notifications
    In-app bell + panel, dismissal
    Email notifications (Resend, queued, retried)
    Per-user preferences, destinations
  Insights & Analytics
    Practice Insights (status/type/volume/team/duration/lifecycle charts)
    Lab Insights (turnaround, remakes, comparison)
    Usage tracking
  AI
    Smart Recommendations (Gemini/OpenAI)
    Ask DentaTrak (product help + 6 allowlisted read-only case tools)
      Permission-scoped via authorized_case_ids
      Telemetry, feedback, retention worker
  PMS Integration
    Open Dental (connections, credentials, entity mappings)
    Webhook events → exact-once case import
    Sync runs/events, guided setup UI
    (flagged: SHOW_PMS_INTEGRATIONS)
  Accounts & Access
    Email/password, Google sign-in, unified identity
    Email verification, password reset/setup, remember-me
    TOTP 2FA (+ recovery, practice-enforced)
    Practice switching, multi-practice membership
    Invitations, setup wizard
    Terms/BAA acceptance
  Security & Compliance
    Session mgmt (DB handler, rotation, revocation, timeout)
    CSRF, secure headers, PHI access log, audit logs
    PII encryption, webhook signatures, worker tokens
    RBAC: owner/admin/member, limited visibility, lab collaborator
    Per-case authorization (authorized_case_ids)
  Billing
    Plans (Operate/Control/Scale), checkout, portal
    Subscriptions, entitlements, trials, webhooks
  Administration
    Practice console (detail, compliance, PHI log, email, deactivate, trial)
    Audit/email logs, hidden practices
    Dev tools (demo data), super-user gating
  Data Management
    Data export, retention workers, orphan cleanup
    Google Drive backup (flagged)
  Public Site
    Marketing home, About, Resources (11 articles + calculator)
    HIPAA/Security, Privacy, Terms, User Guide
    SEO (canonical, OG, JSON-LD, sitemap, IndexNow)
    9-language localization
  Infrastructure
    Cloud Run hosting, Cloud Scheduler workers
    MySQL/PDO, GCS, provisioning scripts, cloudbuild
```

---

## 15. Final Metrics Table

| Metric | Value | M/E |
|---|---|---|
| Application LOC | ~154,700 (incl. locales) / ~103,600 (excl.) | M |
| Test LOC | ~28,100 | M |
| Application files | 330 (482 incl. tests) | M |
| Major modules | ~17 | E |
| User-facing features | ~95 individually listed | E |
| Screens/views | ~45 | E |
| Modals/dialogs | ~24 | E |
| UI components | ~30 | E |
| Database tables | 44 (+1 temp) | M |
| API endpoints/routes | ~115–120 HTTP; ~10 public, ~6 worker/CLI | E |
| External integrations | ~14 services | M |
| Background workers/jobs | 9 | M |
| Security controls identified | ~18–20 categories | E |
| Test files | 152 | M |
| Third-party dependencies | 9 Composer + ~4 npm | M |
| Named functions | ~1,860 | M |
| Classes / interfaces | 14 / 1 | M |
| Migrations | 21 | M |
| Env vars / feature flags | ~44 / 12 | M |
| Locales | 9 | M |

### Caveats
- Schema counts are source-derived; the local dev DB (port 3308) was unreachable during the census, and ~9 core tables are provisioned outside the repo.
- Endpoint count is a classification estimate — `api/` mixes endpoints, libraries, workers, and CLI scripts in one flat directory.
- Feature/screen/component/module counts are curated estimates; methodology stated per section.
- No test-coverage tooling exists in the repo; coverage is unmeasurable.
