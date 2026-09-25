# Public-page maintenance

- Marketing copy is rendered through `t()` from `locales/en-US.json`. The homepage and About page also contain page-specific inline CSS and JSON-LD.
- `sitemap.xml` is manually maintained. Update only materially changed public pages; do not derive `lastmod` from deployment timestamps. Preserve canonical URLs and the sitemap reference in `robots.txt`.
- Subscription display amounts in `api/appConfig.php` are cents. Visible homepage prices are translated strings; verify that structured offers match both sources.
- Syntax checks: `php -l index.php`, `php -l about.php`. Existing responsive audit: `php tests/responsive-css-audit-test.php`.
- Local public-page verification: `node tests/public-seo-test.js` against the running MAMP site at `http://localhost/DentaTrak` (override with `BASE_URL`). Set `VERIFY_PUBLIC_URLS=1` to check live canonical sitemap URLs and the professional profile; set `SEO_SCREENSHOTS` to an existing directory to capture screenshots. No login or database writes are needed.
- New test scripts and screenshots are ignored by the existing repository rules unless explicitly allowlisted. The public SEO verifier is retained locally; do not assume it exists in a fresh checkout.

# Local case-view verification

- Before database-backed checks, verify `appConfig.current_environment` is `development` and its database port is `3308`. Without `.env_mode`, the application probes port `3307` first; never start a production proxy there for local testing.
- Filter/sort unit checks: `node tests/case-filter-sort-test.js` and `php tests/case-view-preferences-test.php` (no database).
- Local browser/API checks: `node tests/case-filter-sort-e2e-test.js` (`DT_BROWSER=chromium|firefox|webkit`, local test credentials via `DENTATRAK_TEST_EMAIL`/`DENTATRAK_TEST_PASSWORD`) and `node tests/case-filter-sort-isolation-e2e-test.js` (creates and cleans its own local user/practice fixtures). Run suites sharing the same account sequentially.
- Related regressions: `php tests/case-list-view-test.php`, `php tests/practice-security-authorization-test.php`, `php tests/responsive-css-audit-test.php`, and `node tests/case-actions-menu-e2e-test.js`.

# PMS integration event worker

- Queued Open Dental webhook events (`integration_external_events` -> fetch -> normalize -> exact-once case import) are drained by `IntegrationEvents::processDue()`.
- **Production**: Cloud Scheduler job `dtk-prod-integration-event-worker` POSTs `https://dtk-app-prod-1029275239454.us-east1.run.app/api/integration-event-worker.php` every minute with the `X-Queue-Worker-Token` header (Secret Manager `dtk-prod-queue-worker-token`, same mechanism as `notification-queue-worker.php`). Provisioning is idempotent via `scripts/provision-integration-worker.sh`, invoked at the end of `cloudbuild.yaml`. Overlapping invocations are safe (atomic claims).
- **Local development**: `php api/integrations/process-events.php [--limit=25] [--watch[=seconds]]` (CLI), or POST the endpoint with the dev `QUEUE_WORKER_TOKEN` header. Windows Task Scheduler alternative: run `php.exe C:\MAMP\htdocs\DentaTrak\api\integrations\process-events.php` every minute.
- Events are claimed atomically (conditional UPDATE with `claimed_at`), transient failures (network/timeout/5xx/429/eConnector offline) retry with bounded linear backoff (max 10 attempts, cap 300s, honors Retry-After), permanent failures park as `failed`, and stale `processing` rows are reclaimed 10 minutes after their last claim.

# Commit-message attribution hook

- `.githooks/commit-msg` strips AI coding-tool attribution lines/trailers (`Generated with/by Devin`, `Generated with Windsurf`, `Co-Authored-By:` entries naming Devin, Windsurf, Codeium, Cascade, Cursor, Claude, Copilot, ChatGPT, OpenAI, Gemini, or other AI coding agents). Human `Co-Authored-By` trailers and normal message content are preserved.
- Git hooks are not shared automatically. Activate once per clone:
  `git config core.hooksPath .githooks`
- The hook is a POSIX shell script (works in Git Bash on Windows, macOS, Linux). Do not add attribution lines to commit messages manually; the hook will remove them.
