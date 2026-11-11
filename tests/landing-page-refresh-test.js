// Landing page refresh verification — local dev.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.BASE_URL || 'http://localhost/DentaTrak').replace(/\/+$/, '');
const SHOTS = process.env.SHOT_DIR || path.join(process.env.TEMP || '/tmp', 'lp-shots');
fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const ok = (name, cond, extra = '') => {
  if (cond) { pass++; console.log('PASS', name); }
  else { fail++; console.log('FAIL', name, extra); }
};

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const jsErrors = [];
  page.on('pageerror', e => jsErrors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') jsErrors.push('console: ' + m.text()); });

  const resp = await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok('page loads 200', resp && resp.status() === 200);

  // --- Section order (DOM position of the actual sections) ---
  const orderIdx = await page.evaluate(() => {
    const pick = sel => { const el = document.querySelector(sel); return el ? el.compareDocumentPosition : null; };
    const els = {
      insights: [...document.querySelectorAll('section h2')].find(h => /insights/i.test(h.textContent))?.closest('section'),
      pms: [...document.querySelectorAll('section h2')].find(h => /practice software and digital scanners/i.test(h.textContent))?.closest('section'),
      langs: document.querySelector('section.langs'),
      compare: document.getElementById('already-tracking'),
      planFeatures: document.querySelector('.plan-features'),
      pricing: document.getElementById('pricing'),
    };
    const pos = {};
    for (const [k, el] of Object.entries(els)) {
      pos[k] = el ? [...document.querySelectorAll('section')].indexOf(el.closest('section')) : -1;
    }
    return pos;
  });
  console.log('section order:', JSON.stringify(orderIdx));
  ok('insights before integrations', orderIdx.insights > -1 && orderIdx.insights < orderIdx.pms, JSON.stringify(orderIdx));
  ok('integrations before languages', orderIdx.pms > -1 && orderIdx.pms < orderIdx.langs, JSON.stringify(orderIdx));
  ok('languages immediately before comparison', orderIdx.langs > -1 && orderIdx.compare === orderIdx.langs + 1, JSON.stringify(orderIdx));
  ok('plan-features immediately before pricing', orderIdx.planFeatures > -1 && orderIdx.pricing === orderIdx.planFeatures + 1, JSON.stringify(orderIdx));

  // --- Product preview interactions (role=tab based) ---
  async function clickTab(text) {
    const tabs = await page.$$('[role="tab"]');
    for (const t of tabs) {
      const txt = (await t.textContent()).trim();
      if (txt.toLowerCase().startsWith(text.toLowerCase())) { await t.click(); return true; }
    }
    return false;
  }
  const casesOk = await clickTab('Cases');
  const insightsOk = await clickTab('Insights');
  ok('Cases/Insights tabs found', casesOk && insightsOk);
  if (casesOk && insightsOk) {
    await page.waitForTimeout(600);
    const insState = await page.evaluate(() => {
      const t = document.getElementById('demo-tab-insights');
      const panel = document.getElementById('demo-insights');
      const r = panel && panel.getBoundingClientRect();
      return { selected: t && t.getAttribute('aria-selected'), hidden: panel && panel.hidden, visible: r ? r.height > 0 : false };
    });
    ok('Insights tab activates + panel shows', insState.selected === 'true' && insState.visible === true, JSON.stringify(insState));
    await clickTab('Cases');
    await page.waitForTimeout(300);
  }

  // Board/List toggle (aria-pressed buttons swapping hidden panels)
  const bl = await page.evaluate(() => {
    const board = document.getElementById('demo-view-board');
    const list = document.getElementById('demo-view-list');
    const bv = document.getElementById('demo-board-view');
    const lv = document.getElementById('demo-list-view');
    if (!board || !list) return { found: false };
    list.click();
    return new Promise(res => setTimeout(() => {
      const listOn = list.getAttribute('aria-pressed') === 'true' && board.getAttribute('aria-pressed') === 'false';
      const listVisible = lv && !lv.hidden && lv.getBoundingClientRect().height > 0;
      const boardHidden = bv && bv.hidden;
      board.click();
      setTimeout(() => res({
        found: true, listOn, listVisible, boardHidden,
        boardBack: board.getAttribute('aria-pressed') === 'true' && bv && !bv.hidden,
      }), 250);
    }, 250));
  });
  ok('Board/List toggle found', bl.found === true);
  ok('List view activates', bl.listOn === true && bl.listVisible === true && bl.boardHidden === true, JSON.stringify(bl));
  ok('Board view restores', bl.boardBack === true, JSON.stringify(bl));

  // --- Checkmarks visible without depending on reveal animation ---
  const ck = await page.evaluate(() => {
    const paths = [...document.querySelectorAll('.cmp-ck-path')];
    return paths.map(p => {
      const cs = getComputedStyle(p);
      const r = p.getBoundingClientRect();
      return { dash: cs.strokeDashoffset, stroke: cs.stroke, w: r.width, h: r.height };
    });
  });
  const ckVisible = ck.filter(c => parseFloat(c.dash) === 0 && c.stroke !== 'none' && c.w > 2 && c.h > 2);
  ok('all comparison checkmarks drawn & visible', ck.length === 6 && ckVisible.length === 6, JSON.stringify(ck.slice(0, 2)) + ` total=${ck.length} vis=${ckVisible.length}`);

  // --- Integrations copy ---
  const pmsText = await page.evaluate(() => {
    const s = [...document.querySelectorAll('section')].find(x => /Connect your practice software/.test(x.textContent));
    return s ? s.textContent : '';
  });
  ok('integrations heading updated', /digital scanners/i.test(pmsText));
  ok('Primescan / DS Core mentioned', /Primescan \/ DS Core/i.test(pmsText));
  ok('Open Dental mentioned', /Open Dental/i.test(pmsText));
  ok('no TRIOS/SHINING claims', !/TRIOS|SHINING 3D/i.test(pmsText));
  ok('Medit Link not claimed (staging-gated)', !/Medit Link/i.test(pmsText));
  ok('in-development note present', /development|coming/i.test(pmsText));

  // --- Languages flags ---
  const langInfo = await page.evaluate(() => {
    const s = [...document.querySelectorAll('section')].find(x => /speaks your team/i.test(x.textContent));
    if (!s) return null;
    const flags = s.querySelectorAll('.lang-flag, [class*="flag"], .lang-item svg, .lang-item img');
    const items = s.querySelectorAll('.lang-item, .lang-chip, li');
    return { flags: flags.length, items: items.length };
  });
  console.log('languages:', JSON.stringify(langInfo));
  ok('language flag indicators present', langInfo && langInfo.flags >= 8, JSON.stringify(langInfo));

  // --- Plan features before pricing ---
  const pfBeforePricing = await page.evaluate(() => {
    const secs = [...document.querySelectorAll('section')];
    const pf = secs.findIndex(s => /Plan features|Compare plans/i.test(s.textContent) && s.querySelector('.plan-compare, table'));
    const pr = secs.findIndex(s => s.id === 'pricing' || /Choose your plan|Simple pricing/i.test(s.textContent));
    return { pf, pr };
  });
  ok('plan-features section exists before pricing', pfBeforePricing.pf > -1 && pfBeforePricing.pf < pfBeforePricing.pr, JSON.stringify(pfBeforePricing));

  // --- No Recommendations in marketing feature lists ---
  const mktText = await page.evaluate(() => {
    const pf = document.querySelector('.plan-features, section:has(.plan-compare)');
    const pricing = document.getElementById('pricing');
    return { pf: pf ? pf.textContent : '', pricing: pricing ? pricing.textContent : '' };
  });
  ok('no Recommendations row in plan comparison', !/Recommendations/i.test(mktText.pf));
  ok('no Recommendations in pricing card list', !/Recommendations/i.test(mktText.pricing));

  // --- Insights preview content ---
  const insSection = await page.evaluate(() => {
    const s = [...document.querySelectorAll('section')].find(x => /Practice and Lab Insights|Insights that/.test(x.textContent) && x.querySelector('h2'));
    return s ? s.textContent.slice(0, 2000) : '';
  });
  ok('insights copy mentions lab comparisons/trends', /lab|trend|turnaround/i.test(insSection));

  // --- No JS errors so far ---
  ok('no JS errors on desktop', jsErrors.length === 0, jsErrors.slice(0, 3).join('; '));

  await page.screenshot({ path: path.join(SHOTS, 'desktop-top.png'), fullPage: false });
  await page.evaluate(() => document.querySelector('.plan-features')?.scrollIntoView());
  await page.waitForTimeout(600);
  await page.screenshot({ path: path.join(SHOTS, 'plan-features.png') });

  // --- Responsive widths ---
  for (const w of [320, 390, 768]) {
    await page.setViewportSize({ width: w, height: 844 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(`no horizontal overflow at ${w}px`, overflow <= 1, `overflow=${overflow}px`);
    // check comparison table scrolls intentionally
    const scrollable = await page.evaluate(() => {
      const sc = document.querySelector('.plan-compare-scroll');
      return sc ? sc.scrollWidth > sc.clientWidth || getComputedStyle(sc).overflowX === 'auto' : false;
    });
    if (w <= 768) ok(`plan table scrollable at ${w}px`, scrollable);
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => document.querySelector('.comparison, section:has(table)')?.scrollIntoView());
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'mobile-comparison.png') });
  await page.evaluate(() => window.scrollTo(0, 0));

  // --- Locales ---
  const locales = ['de-DE', 'es-ES', 'fr-FR', 'fr-CA', 'it-IT', 'ko-KR', 'pt-BR', 'ru-RU'];
  for (const loc of locales) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(BASE + '/?lang=' + loc, { waitUntil: 'networkidle' });
    const txt = await page.evaluate(() => document.body.textContent);
    const hasFlags = await page.evaluate(() => document.querySelectorAll('.lang-flag, [class*="flag"]').length);
    ok(`${loc}: page renders + flags`, txt.length > 5000 && hasFlags >= 8, `len=${txt.length} flags=${hasFlags}`);
    // no raw translation keys visible
    ok(`${loc}: no raw marketing.* keys`, !/marketing\.(pms|insights_section|plan_features|languages)\./.test(txt));
  }

  ok('no JS errors overall', jsErrors.length === 0, jsErrors.slice(0, 5).join('; '));
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
