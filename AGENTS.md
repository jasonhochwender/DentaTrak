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
