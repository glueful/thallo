// The one measuring procedure (container-layout plan, Task 0.1). Capture and comparison must
// measure identically, so both go through `measurePage()` here: `scripts/capture-layout-references`
// calls this file's CLI to freeze an old rendering, and `tests/support/compare.js` calls the same
// function against a current rendering.
//
// What is measured, per named element, at each width, grouped into the assertion profiles the
// plan defines:
//
//   geometry    the bounding rect RELATIVE to the case root (absolute page coordinates would
//               move with unrelated chrome), rounded to 0.01px;
//   spacing     the computed margin-* and padding-*;
//   typography  font-size, font-weight, line-height, text-align, color;
//   surface     background-color;
//   semantics   tag, role, accessible name, heading level, and the element's index in the
//               case root's reading order (document order of the named elements).
//
// Layout-mechanism values (display, flex-direction, gaps, grid-template-columns, max-width,
// min-height…) are deliberately NOT measured: the old and new mechanisms differ by design, and
// the mechanism is proven separately by layout.spec.js.
//
// CLI: node scripts/measure.js <url-path> <case-name> <case-definition.json> <out.json>
'use strict';

const WIDTHS = [375, 700, 800, 1280];
const PROFILES = ['geometry', 'spacing', 'typography', 'surface', 'semantics'];

/**
 * Measure one rendering. `elements` maps a name to a CSS selector; `profiles` maps a name to the
 * profiles that apply to it (defaults to every profile).
 *
 * @returns {Promise<Record<string, Record<string, object>>>} width => name => profile => values
 */
async function measurePage(page, url, elements, profiles, rootSelector) {
  const out = {};
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url);
    // Fonts settle the metrics; without this the first width can measure fallback text.
    await page.evaluate(() => document.fonts && document.fonts.ready);
    out[String(width)] = await page.evaluate(
      ([elements, profiles, rootSelector]) => {
        const round = (n) => Math.round(n * 100) / 100;
        const root = document.querySelector(rootSelector);
        if (!root) throw new Error(`case root ${rootSelector} not found`);
        const rootRect = root.getBoundingClientRect();
        const names = Object.keys(elements);
        const nodes = new Map();
        for (const name of names) {
          const el = document.querySelector(elements[name]);
          if (el) nodes.set(name, el);
        }
        // Reading order: the named elements in document order.
        const order = names
          .filter((n) => nodes.has(n))
          .sort((a, b) => {
            const position = nodes.get(a).compareDocumentPosition(nodes.get(b));
            return position & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
          });
        const headingLevel = (el) => (/^H([1-6])$/.test(el.tagName) ? Number(el.tagName[1]) : null);
        const accessibleName = (el) =>
          (
            el.getAttribute('aria-label') ||
            (el.getAttribute('aria-labelledby')
              ? (document.getElementById(el.getAttribute('aria-labelledby'))?.textContent ?? '')
              : '') ||
            el.textContent ||
            ''
          )
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 200);
        const measured = {};
        for (const name of names) {
          const el = nodes.get(name);
          if (!el) {
            measured[name] = { missing: true };
            continue;
          }
          const style = getComputedStyle(el);
          const rect = el.getBoundingClientRect();
          const applies = profiles[name] || null;
          const wanted = (profile) => applies === null || applies.includes(profile);
          const entry = {};
          if (wanted('geometry')) {
            entry.geometry = {
              top: round(rect.top - rootRect.top),
              left: round(rect.left - rootRect.left),
              width: round(rect.width),
              height: round(rect.height),
            };
          }
          if (wanted('spacing')) {
            entry.spacing = {
              'margin-top': style.marginTop,
              'margin-right': style.marginRight,
              'margin-bottom': style.marginBottom,
              'margin-left': style.marginLeft,
              'padding-top': style.paddingTop,
              'padding-right': style.paddingRight,
              'padding-bottom': style.paddingBottom,
              'padding-left': style.paddingLeft,
            };
          }
          if (wanted('typography')) {
            entry.typography = {
              'font-size': style.fontSize,
              'font-weight': style.fontWeight,
              'line-height': style.lineHeight,
              'text-align': style.textAlign,
              color: style.color,
            };
          }
          if (wanted('surface')) {
            entry.surface = { 'background-color': style.backgroundColor };
          }
          if (wanted('semantics')) {
            entry.semantics = {
              tag: el.tagName.toLowerCase(),
              role: el.getAttribute('role'),
              name: accessibleName(el),
              'heading-level': headingLevel(el),
              'reading-order': order.indexOf(name),
            };
          }
          measured[name] = entry;
        }
        return measured;
      },
      [elements, profiles, rootSelector],
    );
  }
  return out;
}

module.exports = { measurePage, WIDTHS, PROFILES };

if (require.main === module) {
  const { chromium } = require('@playwright/test');
  const fs = require('node:fs');
  const path = require('node:path');
  const http = require('node:http');

  const [urlPath, caseName, definitionPath, outPath] = process.argv.slice(2);
  if (!urlPath || !caseName || !definitionPath || !outPath) {
    console.error('usage: measure.js <url-path> <case> <definition.json> <out.json>');
    process.exit(2);
  }
  const definition = JSON.parse(fs.readFileSync(definitionPath, 'utf8'));
  const elements = {};
  const profiles = {};
  for (const [name, spec] of Object.entries(definition.elements)) {
    elements[name] = typeof spec === 'string' ? spec : spec.selector;
    profiles[name] = typeof spec === 'string' ? null : (spec.profiles ?? null);
  }
  const rootSelector = definition.root || '[data-case-root]';

  // A throwaway static server rooted at the repo, mirroring tools/runtime-browser/server.js.
  const ROOT = path.resolve(__dirname, '..', '..', '..');
  const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8' };
  const server = http.createServer((req, res) => {
    const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
    if (!file.startsWith(ROOT) || !fs.existsSync(file)) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': MIME[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });

  (async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch();
    const page = await browser.newPage();
    try {
      const first = await measurePage(page, base + urlPath, elements, profiles, rootSelector);
      // Determinism is a capture-time gate (plan §Frozen references): measure twice, refuse to
      // write a reference whose two measurements differ.
      const second = await measurePage(page, base + urlPath, elements, profiles, rootSelector);
      if (JSON.stringify(first) !== JSON.stringify(second)) {
        console.error(`measure: ${caseName} is not deterministic; refusing to write ${outPath}`);
        process.exit(1);
      }
      fs.mkdirSync(path.dirname(outPath), { recursive: true });
      fs.writeFileSync(
        outPath,
        JSON.stringify({ case: caseName, widths: first }, null, 2) + '\n',
      );
      console.log(`measured ${caseName} -> ${path.relative(ROOT, outPath)}`);
    } finally {
      await browser.close();
      server.close();
    }
  })();
}
