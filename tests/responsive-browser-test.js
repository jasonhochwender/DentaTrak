/**
 * Playwright-based responsive layout and modal scroll verification.
 *
 * This script logs in via the test-helpers API, navigates to main.php,
 * sets several representative viewports, and asserts that the page layout
 * does not overflow horizontally and that the major modals scroll correctly.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE_URL = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const SCREEN_DIR = path.join(__dirname, '..', 'screenshots');

const viewports = [
  { name: 'iPhone SE', width: 320, height: 568 },
  { name: 'iPhone 12/13 mini', width: 360, height: 780 },
  { name: 'iPhone 14 Pro', width: 390, height: 844 },
  { name: 'Pixel 7', width: 412, height: 915 },
  { name: 'iPhone 14 Pro Max / large phone', width: 480, height: 932 },
  { name: 'iPad Mini portrait', width: 768, height: 1024 },
  { name: 'Small tablet landscape', width: 880, height: 600 },
  { name: 'iPad landscape / laptop', width: 1024, height: 768 },
  { name: 'Desktop', width: 1280, height: 900 },
  { name: 'Large desktop', width: 1920, height: 1080 },
  { name: 'Ultrawide', width: 2048, height: 1080 },
];

async function run() {
  if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });

  // 1. Log in via an API request so the context cookie jar has the session
  // before the first main.php load. Using context.request avoids the race
  // between fetch() Set-Cookie and the next navigation.
  const loginRes = await context.request.post(`${BASE_URL}/api/auth-email.php`, {
    data: { action: 'login', email: EMAIL, password: PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const loginResult = await loginRes.json();
  if (!loginResult || loginResult.success === false) {
    throw new Error('Login failed: ' + JSON.stringify(loginResult));
  }

  const page = await context.newPage();
  await page.goto(`${BASE_URL}/main.php`);
  await page.waitForLoadState('networkidle');
  // The notification panel JS is deferred; wait for it before any viewport tests.
  await page.waitForFunction(() => typeof window.openNotificationDropdown === 'function', null, { timeout: 10000 });
  // Settings/Billing surfaces are guarded by window.isPracticeAdmin, which is
  // set after loadSettings() finishes. Make sure it is available before any
  // test attempts to open the Settings modal.
  await page.waitForFunction(() => window.isPracticeAdmin === true, null, { timeout: 10000 });

  const failures = [];

  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    // Give the browser a moment to reflow and for any lazy JS to settle.
    await page.waitForTimeout(300);

    // Ensure any previous modal/overlay is closed, but open filters so the
    // search input is in layout for the initial metrics snapshot.
    await page.evaluate(() => {
      const m = document.getElementById('createCaseModal');
      if (m) { m.style.display = 'none'; }
      const d = document.getElementById('notificationDropdown');
      if (d && typeof window.closeNotificationDropdown === 'function') window.closeNotificationDropdown();
      const a = document.getElementById('archivedCasesModal');
      if (a) a.style.display = 'none';
      const b = document.getElementById('kanbanFiltersBar');
      if (b) b.classList.add('filters-open');
      document.body.style.overflow = '';
    });

    const metrics = await page.evaluate(() => {
      const html = document.documentElement;
      const header = document.querySelector('.main-header');
      const input = document.querySelector('.dashboard-search-input, .kanban-search-field input, #kanbanSearchInput');
      const searchField = document.querySelector('.kanban-search-field');
      const board = document.querySelector('.kanban-board');
      const filtersBar = document.getElementById('kanbanFiltersBar');
      const filtersRow = document.querySelector('.kanban-filters-row');

      function rectInfo(el) {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { width: r.width, height: r.height, right: r.right, bottom: r.bottom };
      }

      return {
        scrollWidth: html.scrollWidth,
        clientWidth: html.clientWidth,
        innerWidth: window.innerWidth,
        innerHeight: window.innerHeight,
        header: rectInfo(header),
        search: input ? { ...rectInfo(input), placeholder: input.placeholder } : null,
        searchField: searchField ? { ...rectInfo(searchField), flex: getComputedStyle(searchField).flex } : null,
        filtersBar: filtersBar ? { ...rectInfo(filtersBar), overflowX: getComputedStyle(filtersBar).overflowX } : null,
        filtersRow: filtersRow ? { ...rectInfo(filtersRow) } : null,
        kanban: board ? { scrollWidth: board.scrollWidth, clientWidth: board.clientWidth } : null,
      };
    });

    // Document-level horizontal overflow check.
    if (metrics.scrollWidth > metrics.clientWidth) {
      failures.push(`${vp.name} (${vp.width}px): document scrollWidth (${metrics.scrollWidth}) > clientWidth (${metrics.clientWidth})`);
    }
    if (metrics.header && metrics.header.right > metrics.clientWidth + 0.5) {
      failures.push(`${vp.name} (${vp.width}px): .main-header overflows viewport (right=${metrics.header.right})`);
    }
    if (metrics.search && metrics.search.right > metrics.clientWidth + 0.5) {
      failures.push(`${vp.name} (${vp.width}px): search input overflows viewport (right=${metrics.search.right})`);
    }

    // Search field should not exceed 480px on desktop, nor overflow the
    // viewport on phones. The input itself should remain inside the field.
    if (metrics.searchField) {
      console.log(`${vp.name}: searchField=${JSON.stringify(metrics.searchField)}`);
      if (vp.width >= 641 && metrics.searchField.width > 480 + 0.5) {
        failures.push(`${vp.name} (${vp.width}px): search field width (${metrics.searchField.width.toFixed(1)}) exceeds 480px desktop cap`);
      }
      if (vp.width < 641 && metrics.searchField.width > metrics.clientWidth + 0.5) {
        failures.push(`${vp.name} (${vp.width}px): search field overflows viewport (right=${metrics.searchField.right.toFixed(1)})`);
      }
      if (metrics.search && metrics.search.width > metrics.searchField.width + 0.5) {
        failures.push(`${vp.name} (${vp.width}px): search input is wider than its field (input=${metrics.search.width.toFixed(1)}, field=${metrics.searchField.width.toFixed(1)})`);
      }
    }

    console.log(`${vp.name}: scrollWidth=${metrics.scrollWidth}, clientWidth=${metrics.clientWidth}, search=${JSON.stringify(metrics.search)}, kanban=${JSON.stringify(metrics.kanban)}`);

    // 2. Open the Create Case modal via JS and verify it fits and scrolls.
    try {
      await page.evaluate(() => {
        const m = document.getElementById('createCaseModal');
        if (m) {
          m.style.display = 'block';
          document.body.style.overflow = 'hidden';
        }
      });
      await page.waitForSelector('#createCaseModal', { state: 'visible', timeout: 3000 });
      await page.waitForTimeout(200);

      const createModal = await page.evaluate(() => {
        const modal = document.querySelector('#createCaseModal .modal-content');
        const body = document.querySelector('#createCaseModal .modal-body');
        const close = document.querySelector('#createCaseClose');
        const submit = document.querySelector('#createCaseSubmit');
        const cancel = document.querySelector('#createCaseCancel');

        const rectInfo = (el) => {
          if (!el) return null;
          const r = el.getBoundingClientRect();
          return { width: r.width, height: r.height, right: r.right, bottom: r.bottom };
        };
        const isInViewport = (el) => {
          const r = el.getBoundingClientRect();
          return r.top >= 0 && r.left >= 0 && r.bottom <= window.innerHeight && r.right <= window.innerWidth;
        };

        if (!modal) return null;
        return {
          modal: rectInfo(modal),
          body: body ? { scrollHeight: body.scrollHeight, clientHeight: body.clientHeight } : null,
          close: rectInfo(close),
          submitVisible: submit ? isInViewport(submit) : false,
          cancelVisible: cancel ? isInViewport(cancel) : false,
        };
      });

      if (createModal) {
        if (createModal.modal && createModal.modal.right > vp.width + 0.5) {
          failures.push(`${vp.name}: Create Case modal right edge (${createModal.modal.right}) > viewport width`);
        }
        if (createModal.modal && createModal.modal.bottom > vp.height + 0.5) {
          failures.push(`${vp.name}: Create Case modal bottom (${createModal.modal.bottom}) > viewport height`);
        }
        const closeMin = vp.width <= 480 ? 43.5 : 29.5;
        if (createModal.close && (createModal.close.width < closeMin || createModal.close.height < closeMin)) {
          failures.push(`${vp.name}: Create Case close button too small (${createModal.close.width.toFixed(1)}x${createModal.close.height.toFixed(1)}, expected >=${closeMin})`);
        }
        if (createModal.body && createModal.body.scrollHeight > createModal.body.clientHeight) {
          // long form: confirm the submit/cancel are not in viewport until scrolled
          if (!createModal.submitVisible && !createModal.cancelVisible) {
            console.log(`  ${vp.name} Create Case: long form, actions below fold (submitVisible=${createModal.submitVisible}, cancelVisible=${createModal.cancelVisible})`);
            // This is expected; we will verify they become visible after scrolling.
          }
        }
        console.log(`  ${vp.name} Create Case: ${JSON.stringify(createModal)}`);

        // Scroll modal body to the bottom and check that actions are visible.
        await page.evaluate(() => {
          const body = document.querySelector('#createCaseModal .modal-body');
          if (body) body.scrollTop = body.scrollHeight;
        });
        await page.waitForTimeout(200);

        const afterScroll = await page.evaluate(() => {
          const submit = document.querySelector('#createCaseSubmit');
          const cancel = document.querySelector('#createCaseCancel');
          const isInViewport = (el) => {
            const r = el.getBoundingClientRect();
            return r.top >= 0 && r.left >= 0 && r.bottom <= window.innerHeight && r.right <= window.innerWidth;
          };
          return { submitVisible: submit ? isInViewport(submit) : false, cancelVisible: cancel ? isInViewport(cancel) : false };
        });

        if (!afterScroll.submitVisible || !afterScroll.cancelVisible) {
          failures.push(`${vp.name}: Create Case Submit/Cancel not visible after scrolling (submit=${afterScroll.submitVisible}, cancel=${afterScroll.cancelVisible})`);
        }
      }

      // Close the modal.
      await page.evaluate(() => {
        const m = document.getElementById('createCaseModal');
        if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
      });
    } catch (e) {
      failures.push(`${vp.name}: Create Case modal interaction failed - ${e.message}`);
    }

    // 3. Cases/Insights tab switching should not introduce horizontal overflow.
    try {
      const insightsTab = await page.$('.main-tab[data-tab="insights"]');
      if (insightsTab) {
        await insightsTab.click();
        await page.waitForTimeout(300);
        const afterTab = await page.evaluate(() => {
          const html = document.documentElement;
          return { scrollWidth: html.scrollWidth, clientWidth: html.clientWidth, activeTab: document.querySelector('.main-tab.active')?.dataset.tab };
        });
        if (afterTab.scrollWidth > afterTab.clientWidth) {
          failures.push(`${vp.name}: horizontal overflow after switching to Insights tab`);
        }
        if (afterTab.activeTab !== 'insights') {
          failures.push(`${vp.name}: Insights tab did not become active`);
        }
        // Switch back to Cases for the next test.
        const casesTab = await page.$('.main-tab[data-tab="cases"]');
        if (casesTab) await casesTab.click();
        await page.waitForTimeout(200);
      }
    } catch (e) {
      failures.push(`${vp.name}: tab switch check failed - ${e.message}`);
    }

    // 4. Phone-only: filter panel must remove itself from layout when collapsed
    // and stack compactly when expanded.
    if (vp.width <= 480) {
      try {
        await page.evaluate(() => {
          const bar = document.getElementById('kanbanFiltersBar');
          const toggle = document.getElementById('kanbanFilterToggle');
          if (bar) bar.classList.remove('filters-open');
          if (toggle) toggle.classList.remove('active');
        });
        await page.waitForTimeout(100);

        const collapsed = await page.evaluate(() => {
          const bar = document.getElementById('kanbanFiltersBar');
          const archived = document.getElementById('viewArchivedBtn');
          const nav = document.getElementById('mobileKanbanNav');
          const firstCol = document.querySelector('.kanban-column');
          const archivedRect = archived ? archived.getBoundingClientRect() : null;
          const navRect = nav ? nav.getBoundingClientRect() : null;
          const firstColRect = firstCol ? firstCol.getBoundingClientRect() : null;
          return {
            bar: bar ? bar.getBoundingClientRect() : null,
            barDisplay: bar ? getComputedStyle(bar).display : null,
            archivedBottom: archivedRect ? archivedRect.bottom : null,
            navBottom: navRect ? navRect.bottom : null,
            firstColTop: firstColRect ? firstColRect.top : null,
            gap: firstColRect ? firstColRect.top - (navRect ? navRect.bottom : (archivedRect ? archivedRect.bottom : 0)) : null,
          };
        });

        await page.screenshot({ path: path.join(SCREEN_DIR, `filters-collapsed-${vp.width}.png`) });

        if (collapsed && collapsed.bar && collapsed.bar.height > 0.5 && collapsed.barDisplay !== 'none') {
          failures.push(`${vp.name}: collapsed filter bar still occupies layout (height=${collapsed.bar.height}, display=${collapsed.barDisplay})`);
        }
        if (collapsed && typeof collapsed.gap === 'number' && collapsed.gap > 60) {
          failures.push(`${vp.name}: large collapsed gap between mobile navigation and first column (${collapsed.gap.toFixed(1)}px)`);
        }

        // Open filters and inspect vertical layout.
        const filterToggle = await page.$('#kanbanFilterToggle');
        if (filterToggle) {
          await filterToggle.click();
          await page.waitForTimeout(300);

          await page.screenshot({ path: path.join(SCREEN_DIR, `filters-expanded-${vp.width}.png`) });

          const expanded = await page.evaluate(() => {
            const bar = document.getElementById('kanbanFiltersBar');
            const search = document.querySelector('.kanban-search-field');
            const searchInput = document.getElementById('patientSearch');
            const caseType = document.getElementById('filterCaseType');
            return {
              bar: bar ? bar.getBoundingClientRect() : null,
              search: search ? { rect: search.getBoundingClientRect(), flex: getComputedStyle(search).flex } : null,
              searchToCaseType: searchInput && caseType ? caseType.getBoundingClientRect().top - searchInput.getBoundingClientRect().bottom : null,
              searchHeight: search ? search.getBoundingClientRect().height : null,
            };
          });

          if (expanded) {
            if (expanded.search && (expanded.search.flex || '').includes('320px')) {
              failures.push(`${vp.name}: search field still has 320px flex basis in column layout (flex=${expanded.search.flex})`);
            }
            if (expanded.searchToCaseType > 100) {
              failures.push(`${vp.name}: excessive vertical gap between Search and Case Type (${expanded.searchToCaseType.toFixed(1)}px)`);
            }
            if (expanded.searchHeight > 100) {
              failures.push(`${vp.name}: search field itself is unreasonably tall (${expanded.searchHeight.toFixed(1)}px)`);
            }
          }

          // Close filters again.
          await filterToggle.click();
          await page.waitForTimeout(200);
        }
      } catch (e) {
        failures.push(`${vp.name}: filter panel check failed - ${e.message}`);
      }
    }

    // 5. Notification panel is the intended fixed full-height side sheet on phones.
    try {
      if (await page.evaluate(() => typeof window.openNotificationDropdown === 'function')) {
        await page.evaluate(() => { window.openNotificationDropdown(); });
        await page.waitForTimeout(300);

        if (vp.width <= 480) {
          await page.screenshot({ path: path.join(SCREEN_DIR, `notifications-open-${vp.width}.png`) });
        }

        const notify = await page.evaluate(() => {
          const d = document.getElementById('notificationDropdown');
          const list = document.getElementById('notificationList');
          const close = d ? d.querySelector('.notification-dropdown-close') : null;
          const style = d ? getComputedStyle(d) : null;
          if (!d) return null;
          return {
            rect: d.getBoundingClientRect(),
            position: style.position,
            widthStyle: style.width,
            heightStyle: style.height,
            list: list ? { rect: list.getBoundingClientRect(), maxHeight: getComputedStyle(list).maxHeight } : null,
            close: close ? { rect: close.getBoundingClientRect(), display: getComputedStyle(close).display } : null,
            bodyOverflow: document.body.style.overflow,
          };
        });

        if (notify) {
          if (vp.width <= 480) {
            if (notify.position !== 'fixed') {
              failures.push(`${vp.name}: notification panel is not position:fixed (got ${notify.position})`);
            }
            if (notify.rect.width < 300 || notify.rect.height < vp.height * 0.85) {
              failures.push(`${vp.name}: notification panel is not a full-height side sheet (width=${notify.rect.width}, height=${notify.rect.height})`);
            }
            if (notify.rect.right > vp.width + 0.5 || notify.rect.bottom > vp.height + 0.5) {
              failures.push(`${vp.name}: notification panel overflows viewport (right=${notify.rect.right}, bottom=${notify.rect.bottom})`);
            }
            if (!notify.close || notify.close.rect.width < 43.5 || notify.close.rect.height < 43.5) {
              failures.push(`${vp.name}: notification close button not 44x44 (close=${JSON.stringify(notify.close)})`);
            }
            if (!notify.list || notify.list.rect.height < 50) {
              failures.push(`${vp.name}: notification list has no usable height (list=${JSON.stringify(notify.list)})`);
            }
          } else {
            if (notify.rect.right > vp.width + 0.5 || notify.rect.bottom > vp.height + 0.5) {
              failures.push(`${vp.name}: notification dropdown overflows viewport (right=${notify.rect.right}, bottom=${notify.rect.bottom})`);
            }
          }
          if (vp.width <= 480 && notify.bodyOverflow !== 'hidden') {
            failures.push(`${vp.name}: body scroll not locked while notification panel is open`);
          }
        }

        // Dismiss with the close button on phones (where it is a 44x44 tappable
        // target) and through the API on larger viewports where it stays hidden.
        if (vp.width <= 480) {
          const closeBtn = await page.$('#notificationDropdownClose');
          if (closeBtn) await closeBtn.click();
        }
        await page.evaluate(() => { if (window.closeNotificationDropdown) window.closeNotificationDropdown(); });
        await page.waitForTimeout(100);
        const afterClose = await page.evaluate(() => document.body.style.overflow);
        if (afterClose === 'hidden') {
          failures.push(`${vp.name}: body scroll not restored after notification panel closed`);
        }
      }
    } catch (e) {
      failures.push(`${vp.name}: notification panel check failed - ${e.message}`);
    }

    // 6. Archived Cases modal fits and the table container owns horizontal scrolling.
    try {
      const archivedBtn = await page.$('#viewArchivedBtn');
      if (archivedBtn) {
        await page.evaluate(() => {
          const m = document.getElementById('archivedCasesModal');
          if (m) m.style.display = 'block';
          document.body.style.overflow = 'hidden';
        });
        await page.waitForSelector('#archivedCasesModal', { state: 'visible', timeout: 3000 });
        await page.waitForTimeout(200);
        const archived = await page.evaluate(() => {
          const modal = document.querySelector('#archivedCasesModal .modal-content');
          const tableContainer = document.querySelector('#archivedCasesModal .archived-table-container');
          const close = document.querySelector('#archivedCasesClose');
          if (!modal) return null;
          const mr = modal.getBoundingClientRect();
          const tc = tableContainer ? tableContainer.getBoundingClientRect() : null;
          return {
            modal: { width: mr.width, right: mr.right, bottom: mr.bottom },
            tableContainer: tc ? { width: tc.width, right: tc.right } : null,
            close: close ? { width: close.getBoundingClientRect().width, height: close.getBoundingClientRect().height } : null,
          };
        });
        if (archived) {
          if (archived.modal.right > vp.width + 0.5 || archived.modal.bottom > vp.height + 0.5) {
            failures.push(`${vp.name}: Archived Cases modal overflows viewport (right=${archived.modal.right}, bottom=${archived.modal.bottom})`);
          }
          if (archived.tableContainer && archived.tableContainer.right > vp.width + 0.5) {
            failures.push(`${vp.name}: Archived table container overflows viewport`);
          }
          if (vp.width <= 480 && archived.close && (archived.close.width < 43.5 || archived.close.height < 43.5)) {
            failures.push(`${vp.name}: Archived Cases close button too small (${archived.close.width}x${archived.close.height})`);
          }
        }
        await page.evaluate(() => { const m = document.getElementById('archivedCasesModal'); if (m) m.style.display = 'none'; document.body.style.overflow = ''; });
      }
    } catch (e) {
      failures.push(`${vp.name}: Archived Cases modal check failed - ${e.message}`);
    }

    // 7. Settings modal: no horizontal overflow and correct scroll behavior.
    try {
      if (vp.width > 720) {
        // Open via the user menu; the handler requires isPracticeAdmin and is
        // a desktop/tablet-only surface.
        await page.evaluate(() => {
          const o = document.getElementById('pageLoadingOverlay');
          if (o) { o.style.display = 'none'; o.style.opacity = '0'; }
        });
        const userToggle = await page.$('#userMenuToggle');
        if (userToggle) await userToggle.click();
        await page.waitForTimeout(200);
        const settingsItem = await page.$('#settingsMenuItem');
        if (settingsItem) await settingsItem.click();
        await page.waitForSelector('#settingsBillingModal', { state: 'visible', timeout: 5000 });

        for (const target of ['practice','display','authorized','security','data-privacy']) {
          const nav = await page.$(`.settings-nav-item[data-nav-target="${target}"]`);
          if (nav) await nav.click();
          await page.waitForTimeout(200);
          const sectionOk = await page.evaluate(() => {
            const panels = document.querySelector('.settings-panels');
            const content = document.querySelector('.settings-panels .settings-twisty.settings-panel-active .settings-twisty-content');
            const form = document.getElementById('settingsForm');
            const modal = document.querySelector('#settingsBillingModal .modal-content');
            const footer = document.querySelector('#settingsForm .button-container');
            return {
              panelsOk: panels ? panels.scrollWidth <= panels.clientWidth + 0.5 : false,
              contentOk: content ? content.scrollWidth <= content.clientWidth + 0.5 : false,
              formOk: form ? form.scrollWidth <= form.clientWidth + 0.5 : false,
              modalRight: modal ? modal.getBoundingClientRect().right : null,
              footerVisible: footer ? (footer.getBoundingClientRect().bottom <= window.innerHeight) : false,
            };
          });
          if (!sectionOk.panelsOk) failures.push(`${vp.name}: Settings "${target}" right pane horizontal overflow`);
          if (!sectionOk.contentOk) failures.push(`${vp.name}: Settings "${target}" content horizontal overflow`);
          if (!sectionOk.formOk) failures.push(`${vp.name}: Settings "${target}" form horizontal overflow`);
          if (sectionOk.modalRight > vp.width + 0.5) failures.push(`${vp.name}: Settings "${target}" modal overflows viewport (${sectionOk.modalRight})`);
          if (!sectionOk.footerVisible) failures.push(`${vp.name}: Settings "${target}" footer off-screen`);
        }

        const beforeNavTop = await page.evaluate(() => { const n = document.querySelector('.settings-nav'); return n ? n.getBoundingClientRect().top : null; });
        await page.evaluate(() => { const p = document.querySelector('.settings-panels'); if (p) p.scrollTop = p.scrollHeight; });
        await page.waitForTimeout(200);
        const afterNavTop = await page.evaluate(() => { const n = document.querySelector('.settings-nav'); return n ? n.getBoundingClientRect().top : null; });
        if (beforeNavTop !== null && afterNavTop !== null && Math.abs(beforeNavTop - afterNavTop) > 1) {
          failures.push(`${vp.name}: Settings left nav moved when right pane was scrolled`);
        }

        if (vp.width === 768) {
          await page.click('.settings-nav-item[data-nav-target="display"]');
          await page.waitForTimeout(200);
          await page.screenshot({ path: path.join(SCREEN_DIR, 'settings-display-768.png') });
          await page.click('.settings-nav-item[data-nav-target="authorized"]');
          await page.waitForTimeout(200);
          await page.screenshot({ path: path.join(SCREEN_DIR, 'settings-authorized-768.png') });
        }
        if (vp.width === 1280) {
          await page.click('.settings-nav-item[data-nav-target="display"]');
          await page.waitForTimeout(200);
          await page.screenshot({ path: path.join(SCREEN_DIR, 'settings-display-1280.png') });
        }

        await page.evaluate(() => { const m = document.getElementById('settingsBillingModal'); if (m) m.style.display = 'none'; document.body.style.overflow = ''; });
      } else {
        await page.evaluate(() => { if (typeof window.openSettingsBillingModal === 'function') window.openSettingsBillingModal(); });
        await page.waitForTimeout(300);
        const settingsVisible = await page.evaluate(() => { const m = document.getElementById('settingsBillingModal'); return m && getComputedStyle(m).display !== 'none'; });
        if (settingsVisible) {
          failures.push(`${vp.name}: Settings modal should not be visible on phone`);
        }
      }
    } catch (e) {
      failures.push(`${vp.name}: Settings modal check failed - ${e.message}`);
    }

    // 8. Cases filter screenshots at requested sizes.
    if ([412, 1280, 2048].includes(vp.width)) {
      try {
        const filterToggle = await page.$('#kanbanFilterToggle');
        if (filterToggle) {
          await filterToggle.click();
          await page.waitForTimeout(300);
          await page.screenshot({ path: path.join(SCREEN_DIR, `filters-expanded-${vp.width}.png`) });
          await filterToggle.click();
          await page.waitForTimeout(200);
        }
      } catch (e) {
        failures.push(`${vp.name}: filter screenshot failed - ${e.message}`);
      }
    }
  }

  // 7. Login page: HIPAA pill must sit above the white section with room to
  // breathe on phone viewports. Use a fresh incognito context so the existing
  // session cookie does not redirect /login.php to /main.php.
  const loginContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const loginPage = await loginContext.newPage();
  for (const vp of viewports.filter(v => v.width <= 480)) {
    await loginPage.setViewportSize({ width: vp.width, height: vp.height });
    await loginPage.goto(`${BASE_URL}/login.php`);
    await loginPage.waitForLoadState('networkidle');
    await loginPage.waitForTimeout(300);

    const loginMetrics = await loginPage.evaluate(() => {
      const html = document.documentElement;
      const hero = document.querySelector('.login-hero');
      const badge = document.querySelector('.trust-item');
      const container = document.querySelector('.login-container');
      const heroRect = hero ? hero.getBoundingClientRect() : null;
      const badgeRect = badge ? badge.getBoundingClientRect() : null;
      const containerRect = container ? container.getBoundingClientRect() : null;
      const form = document.querySelector('.login-card, .google-signin-btn, .email-signin-toggle');
      return {
        heroBottom: heroRect ? heroRect.bottom : null,
        badgeBottom: badgeRect ? badgeRect.bottom : null,
        containerTop: containerRect ? containerRect.top : null,
        gap: heroRect && badgeRect ? heroRect.bottom - badgeRect.bottom : null,
        docOverflowX: html.scrollWidth - html.clientWidth,
        formExists: !!form,
      };
    });

    console.log(`${vp.name} login: gap=${loginMetrics.gap ? loginMetrics.gap.toFixed(1) : null}, docOverflowX=${loginMetrics.docOverflowX}`);

    if (loginMetrics.docOverflowX > 0) {
      failures.push(`${vp.name} (${vp.width}px): login page horizontal overflow (${loginMetrics.docOverflowX}px)`);
    }
    if (!loginMetrics.formExists) {
      failures.push(`${vp.name} (${vp.width}px): login form not present`);
    }
    if (loginMetrics.gap === null) {
      failures.push(`${vp.name} (${vp.width}px): login page hero or badge not found`);
    } else if (loginMetrics.gap < 20) {
      failures.push(`${vp.name} (${vp.width}px): HIPAA pill gap too small (${loginMetrics.gap.toFixed(1)}px, expected >= 20px)`);
    } else if (loginMetrics.badgeBottom !== null && loginMetrics.containerTop !== null && loginMetrics.badgeBottom > loginMetrics.containerTop) {
      failures.push(`${vp.name} (${vp.width}px): HIPAA pill overlaps white sign-in section`);
    }
  }
  await loginContext.close();

  await browser.close();

  if (failures.length) {
    console.error('\nFAILURES:');
    failures.forEach(f => console.error('  ' + f));
    process.exit(1);
  }

  console.log('\nAll responsive checks passed across ' + viewports.length + ' viewports.');
}

run().catch(e => {
  console.error(e);
  process.exit(1);
});
