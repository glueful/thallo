// Real-browser print gate for the invoice/receipt document (orders-invoices-receipts spec,
// Task 11). Vitest already owns Vue/query correctness for InvoiceDocument.vue (all three presets
// render, untoggleable core present, optional sections respond to toggles, footer escaping); a
// Node hand-stubbed DOM never runs real CSS computation, so THIS spec answers exactly one
// question per preset: what does a real browser do with `admin/src/assets/print.css` under
// `page.emulateMedia({ media: 'print' })`? See fixtures/invoice-print.html for the byte-cited
// production contract this fixture mirrors.
//
// Deliberately NOT asserted: the `@page` rule / paper size selection (print.css L15-33) —
// browsers hand paper selection to the print dialog, and `emulateMedia` never paginates a page
// the way an actual print/PDF pass would, so there is nothing meaningful to read back for it.
'use strict';

const { test, expect } = require('@playwright/test');

const MM_PER_PX = 25.4 / 96; // CSS px is defined as 1/96 inch; 1 inch = 25.4mm.
const mmToPx = (mm) => mm / MM_PER_PX;
const WIDTH_TOLERANCE_PX = 2; // sub-pixel layout rounding only — not a loose bound.

const PRESETS = [
  { preset: 'a4', className: 'invoice-a4', expectedWidthPx: mmToPx(210) },
  { preset: 'thermal_80', className: 'invoice-thermal-80', expectedWidthPx: mmToPx(80) },
  { preset: 'thermal_58', className: 'invoice-thermal-58', expectedWidthPx: mmToPx(58) },
];

for (const { preset, className, expectedWidthPx } of PRESETS) {
  test.describe(`preset=${preset}`, () => {
    test(`chrome hidden, document visible, untoggleable core present (${preset})`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);
      await page.emulateMedia({ media: 'print' });

      const doc = page.locator('[data-test="invoice-document"]');
      await expect(doc).toHaveClass(new RegExp(`(^|\\s)${className}(\\s|$)`));

      // Two REAL, independent [data-print-chrome] placements exist in production: the dashboard
      // sidebar (default.vue) and the in-content paper-preset toolbar rendered inside the shell,
      // next to the document (orders/[uuid]/invoice.vue). Both must hide under print.
      const chromeCount = await page.locator('[data-print-chrome]').count();
      expect(chromeCount).toBe(2);
      await expect(page.locator('[data-print-chrome]').first()).toBeHidden();
      await expect(page.locator('[data-print-chrome]').nth(1)).toBeHidden();
      await expect(page.locator('[data-test="invoice-toolbar"]')).toBeHidden();
      await expect(page.locator('[data-print-shell]')).toBeVisible();
      await expect(doc).toBeVisible();

      // Untoggleable core: order number, "Order status", a line name, grand total.
      await expect(page.locator('[data-test="invoice-order-number"]')).toBeVisible();
      await expect(page.locator('[data-test="invoice-order-number"]')).toContainText('ORD-1042');
      await expect(page.locator('[data-test="invoice-order-status"]')).toBeVisible();
      await expect(page.locator('[data-test="invoice-order-status"]')).toContainText('Order status');
      await expect(page.locator('[data-test="invoice-line-name"]').first()).toBeVisible();
      await expect(page.locator('[data-test="invoice-total-grand"]')).toBeVisible();
      await expect(page.locator('[data-test="invoice-total-grand"]')).toContainText('512.88');
    });

    test(`the dashboard-shell fixed/overflow-hidden reset actually applies, not just the chrome hide (${preset})`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);

      // Confirm the premise BEFORE print: the fixture's hostile baseline (mirroring
      // default.vue L116/L169-172 — see the fixture's header comment) genuinely clips the
      // document to one viewport, exactly like production's dashboard shell does on screen.
      // If this precondition ever stopped holding, the print-mode assertions below would pass
      // vacuously (nothing to un-clip), so it is checked and enforced here, not assumed.
      const viewport = page.viewportSize();
      const preRootStyle = await page
        .locator('[data-print-root]')
        .evaluate((el) => ({ position: getComputedStyle(el).position, overflow: getComputedStyle(el).overflowY }));
      expect(preRootStyle).toEqual({ position: 'fixed', overflow: 'hidden' });
      const preScrollHeight = await page.evaluate(() => document.documentElement.scrollHeight);
      expect(preScrollHeight).toBeLessThanOrEqual(viewport.height + 1);

      await page.emulateMedia({ media: 'print' });

      // print.css L104-110/L122-130: `[data-print-root]` un-fixes to `position: static; overflow:
      // visible; height: auto`, and `[data-print-shell]` drops its own `overflow: hidden`. Assert
      // both directly (not just "chrome is hidden", which the fixture-only baseline change above
      // cannot fail on its own).
      const postRootStyle = await page
        .locator('[data-print-root]')
        .evaluate((el) => ({ position: getComputedStyle(el).position, overflow: getComputedStyle(el).overflowY }));
      expect(postRootStyle).toEqual({ position: 'static', overflow: 'visible' });

      const shellOverflow = await page
        .locator('[data-print-shell]')
        .evaluate((el) => getComputedStyle(el).overflowY);
      expect(shellOverflow).toBe('visible');

      // The real, falsifiable proof: with the reset applied, the document's full content — 15
      // rows plus one long multi-line description — now lays out FAR beyond one viewport height,
      // and the last seeded row is genuinely reachable (its bottom edge exceeds the viewport).
      // Comment out print.css's [data-print-root]/[data-print-shell] blocks locally and this
      // assertion fails (content stays clipped to `viewport.height`) — that RED run is the proof
      // this isn't vacuous.
      const postScrollHeight = await page.evaluate(() => document.documentElement.scrollHeight);
      expect(postScrollHeight).toBeGreaterThan(viewport.height + 100);

      const lastRowBottom = await page
        .locator('[data-test="invoice-line"][data-row="15"]')
        .evaluate((el) => el.getBoundingClientRect().bottom);
      expect(lastRowBottom).toBeGreaterThan(viewport.height);
    });

    test(`thead repeats as a header group (${preset})`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);
      await page.emulateMedia({ media: 'print' });

      const theadDisplay = await page
        .locator('[data-test="invoice-lines"] thead')
        .evaluate((el) => getComputedStyle(el).display);
      expect(theadDisplay).toBe('table-header-group');
    });

    test(`rows are not clipped: visible overflow, no line-clamp, no max-height, real containment (${preset})`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);
      await page.emulateMedia({ media: 'print' });

      // Seed has 15 rows (>=15 required so multi-page/clipping behavior is real, not a
      // one-row toy case).
      const rowCount = await page.locator('[data-test="invoice-line"]').count();
      expect(rowCount).toBe(15);

      const cellComputed = await page.evaluate(() => {
        const cells = Array.from(document.querySelectorAll('[data-test="invoice-lines"] td, [data-test="invoice-lines"] th'));
        return cells.map((el) => {
          const cs = getComputedStyle(el);
          return {
            overflow: cs.overflowY,
            lineClamp: cs.webkitLineClamp,
            maxHeight: cs.maxHeight,
            display: cs.display,
          };
        });
      });
      expect(cellComputed.length).toBeGreaterThan(0);
      for (const cell of cellComputed) {
        expect(cell.overflow).toBe('visible');
        // Chromium reports an unset -webkit-line-clamp as 'none'; print.css forces it to the
        // `unset` keyword, which resolves back to the property's initial value ('none').
        expect(cell.lineClamp).toBe('none');
        expect(cell.maxHeight).toBe('none');
        expect(cell.display).toBe('table-cell');
      }

      // The invalid proxy this gate deliberately does NOT use is `scrollHeight <= clientHeight`
      // (a clipped-but-not-scrollable box reports scrollHeight === clientHeight and would pass
      // that check even while genuinely cropping content — it only detects clipping that also
      // happens to be scrollable). The real check: every row's bounding box must fully CONTAIN
      // every one of its descendants' bounding boxes.
      const containment = await page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('[data-test="invoice-line"]'));
        return rows.map((row) => {
          const rowBox = row.getBoundingClientRect();
          const descendants = Array.from(row.querySelectorAll('*'));
          const violations = descendants
            .map((el) => {
              const box = el.getBoundingClientRect();
              // Skip zero-area boxes (e.g. an empty <div> with no rendered content) — they carry
              // no containment signal either way.
              if (box.width === 0 && box.height === 0) return null;
              const contained =
                box.top >= rowBox.top - 0.5 &&
                box.left >= rowBox.left - 0.5 &&
                box.bottom <= rowBox.bottom + 0.5 &&
                box.right <= rowBox.right + 0.5;
              return contained ? null : { tag: el.tagName, box, rowBox };
            })
            .filter(Boolean);
          return { row: row.getAttribute('data-row'), violations };
        });
      });

      for (const { row, violations } of containment) {
        expect(violations, `row ${row} must contain all descendants; violations: ${JSON.stringify(violations)}`).toEqual([]);
      }
    });

    test(`the long multi-line description row is genuinely uncropped, not just non-scrolling (${preset})`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);
      await page.emulateMedia({ media: 'print' });

      const longRow = page.locator('[data-long-row="true"]');
      const longDesc = longRow.locator('[data-test-long="invoice-line-long-desc"]');
      await expect(longDesc).toBeVisible();

      const box = await page.evaluate(() => {
        const row = document.querySelector('[data-long-row="true"]');
        const desc = document.querySelector('[data-test-long="invoice-line-long-desc"]');
        const rowBox = row.getBoundingClientRect();
        const descBox = desc.getBoundingClientRect();
        return { rowBox, descBox, descText: desc.textContent || '' };
      });

      // Sanity: the seeded description is genuinely long (multi-line at any realistic width),
      // so a passing containment check here is meaningful, not vacuous.
      expect(box.descText.length).toBeGreaterThan(300);

      expect(box.descBox.top).toBeGreaterThanOrEqual(box.rowBox.top - 0.5);
      expect(box.descBox.left).toBeGreaterThanOrEqual(box.rowBox.left - 0.5);
      expect(box.descBox.bottom).toBeLessThanOrEqual(box.rowBox.bottom + 0.5);
      expect(box.descBox.right).toBeLessThanOrEqual(box.rowBox.right + 0.5);
    });
  });
}

// Width assertions live outside the shared preset loop body above only in the sense that they
// assert a DIFFERENT thing per preset family (A4 is a "document" width; thermal is a "content"
// width) — both are just the `.invoice-document` element's own rendered width, computed from mm
// at 96dpi (CSS's fixed 1px = 1/96in definition), independent of any physical print/PDF pass.
test.describe('paper-preset widths', () => {
  for (const { preset, expectedWidthPx } of PRESETS) {
    test(`${preset} renders at the expected width (±${WIDTH_TOLERANCE_PX}px)`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/invoice-print.html?preset=${preset}`);
      await page.emulateMedia({ media: 'print' });

      const width = await page
        .locator('[data-test="invoice-document"]')
        .evaluate((el) => el.getBoundingClientRect().width);

      expect(Math.abs(width - expectedWidthPx)).toBeLessThanOrEqual(WIDTH_TOLERANCE_PX);
    });
  }
});
