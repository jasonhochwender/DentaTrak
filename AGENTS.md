# Public-page maintenance

- Marketing copy is rendered through `t()` from `locales/en-US.json`. The homepage and About page also contain page-specific inline CSS and JSON-LD.
- `sitemap.xml` is manually maintained. Update only materially changed public pages; do not derive `lastmod` from deployment timestamps. Preserve canonical URLs and the sitemap reference in `robots.txt`.
- Subscription display amounts in `api/appConfig.php` are cents. Visible homepage prices are translated strings; verify that structured offers match both sources.
- Syntax checks: `php -l index.php`, `php -l about.php`. Existing responsive audit: `php tests/responsive-css-audit-test.php`.
- Local public-page verification: `node tests/public-seo-test.js` against the running MAMP site at `http://localhost/DentaTrak` (override with `BASE_URL`). Set `VERIFY_PUBLIC_URLS=1` to check live canonical sitemap URLs and the professional profile; set `SEO_SCREENSHOTS` to an existing directory to capture screenshots. No login or database writes are needed.
- New test scripts and screenshots are ignored by the existing repository rules unless explicitly allowlisted. The public SEO verifier is retained locally; do not assume it exists in a fresh checkout.
