// The content-type listing and archive pages in a real browser (type layouts plan B).
//
// `references/{listing,archive}-original.json` froze today's pages (`listing.twig` and `archive.twig`
// with `_listing_rows.twig` and `_pagination.twig`) before any Release B template or stylesheet
// change (scripts/capture-listing-layout-reference). Every later rendering of the pages without a
// layout is held to them here, with the same measuring procedure, so the pages keep their look
// however the templates and the default theme's stylesheets behind them change.
//
// The pages come from scripts/build-listing-layout-proof-fixtures (gitignored): three posts and a
// category seeded with fixed values, every stylesheet, font and image inlined, scripts dropped.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITION = JSON.parse(
  fs.readFileSync(path.join(REPO, 'tests/fixtures/render/listing-page-reference.json'), 'utf8'),
);
const URL_BASE = '/tools/runtime-browser/fixtures/listing-layout';

const reference = (page) =>
  JSON.parse(fs.readFileSync(path.join(REPO, `tools/runtime-browser/references/${page}-original.json`), 'utf8'));

test("today's listing and archive pages match their frozen reference", async ({ page }) => {
  const definition = { ...DEFINITION, map: DEFINITION.elements };
  for (const name of ['listing', 'archive']) {
    const failures = await compareToReference(page, reference(name), definition, `${URL_BASE}/${name}-original.html`);
    expect(failures, `${name}:\n${failures.join('\n')}`).toEqual([]);
  }
});

// ---------------------------------------------------------------------------------------------
// The starter against the frozen pages (type layouts plan B, B7).
//
// The listing and archive layouts' starter — the Listing title, (on an archive) the Term description,
// the Entry list and the Page navigation — is held to the same references. Its card is block flow,
// so today's row is a container in it (the user's B7 ruling): a row holding the cover beside a column
// of the title, the date and the excerpt. A card's blocks leave the page's rhythm, gutter and reading
// width; its cover, title, date and excerpt take today's sizes; the Listing title and the Page
// navigation today's margins. Each name the reference recorded is found in the layout page by what
// the starter renders for it; on the stage each block sits in a selection wrapper, so the selectors
// name the blocks' own classes.

const { measurePage } = require('../scripts/measure.js');

const CARD = (n) => `#main .thallo-block-entry_loop__cards > .thallo-loop-card:nth-child(${n})`;
const STARTER_MAP = {
  heading: '#main .thallo-block-listing_title',
  rows: '#main .thallo-block-entry_loop__cards',
  row1: CARD(1),
  media1: `${CARD(1)} .thallo-block-entry_cover`,
  content1: `${CARD(1)} .thallo-block-container .thallo-block-container`,
  title1: `${CARD(1)} .thallo-block-entry_title`,
  date1: `${CARD(1)} .thallo-block-entry_date`,
  excerpt1: `${CARD(1)} .thallo-block-entry_excerpt`,
  row2: CARD(2),
  title2: `${CARD(2)} .thallo-block-entry_title`,
  pagination: '#main .thallo-block-pagination',
};

// The differences the user accepted, each relaxed and then asserted: none — every ruled difference
// was fixed (B7 rulings A–E).
const ACCEPTED = [];

for (const name of ['listing', 'archive']) {
  for (const where of ['', '-stage']) {
    test(`the ${name} starter${where ? ' on the stage' : ''} matches today's page`, async ({ page }) => {
      const definition = { ...DEFINITION, map: STARTER_MAP, allow: ACCEPTED };
      const failures = await compareToReference(page, reference(name), definition, `${URL_BASE}/${name}-starter${where}.html`);
      expect(failures, `${name}${where}:\n${failures.join('\n')}`).toEqual([]);
    });
  }
}

// ---------------------------------------------------------------------------------------------
// Authored values, the loop's two modes, a reset and blocks added beside the starter — each on the
// page and on the stage, which agree.

const PARTS = {
  heading: STARTER_MAP.heading,
  row1: STARTER_MAP.row1,
  row2: STARTER_MAP.row2,
  title1: STARTER_MAP.title1,
  content1: STARTER_MAP.content1,
  addedHeading: '#main .thallo-block-heading',
  addedText: '#main .thallo-block-rich_text',
  addedImage: '#main .thallo-block-image',
};
const measure = async (page, state) => {
  const profiles = Object.fromEntries(Object.keys(PARTS).map((name) => [name, ['geometry', 'spacing', 'typography']]));
  const byWhere = {};
  for (const where of ['', '-stage']) {
    byWhere[where ? 'stage' : 'page'] = await measurePage(page, `${URL_BASE}/listing-${state}${where}.html`, PARTS, profiles, '#main');
  }
  return byWhere;
};
const at = (measured, width, name) => {
  const entry = measured[String(width)][name];
  expect(entry && !entry.missing, `${name} at ${width}px`).toBeTruthy();
  return entry;
};
const NEAR = 1;
const near = (actual, expected, what) =>
  expect(Math.abs(Number(actual) - Number(expected)), `${what}: ${actual} vs ${expected}`).toBeLessThanOrEqual(NEAR);
const WIDTHS = [375, 700, 800, 1280];

/** The page and the stage render a state alike: every measured box and value, at every width. */
function agree(measured, names, what) {
  for (const width of WIDTHS) {
    for (const name of names) {
      const pageEntry = at(measured.page, width, name);
      const stageEntry = at(measured.stage, width, name);
      for (const [key, value] of Object.entries(pageEntry.geometry)) {
        near(stageEntry.geometry[key], value, `${what} ${name}.${key} page vs stage @${width}`);
      }
      expect(stageEntry.typography, `${what} ${name} typography page vs stage @${width}`).toEqual(pageEntry.typography);
    }
  }
}

test('the starter on the page and on the stage agree', async ({ page }) => {
  agree(await measure(page, 'starter'), ['heading', 'row1', 'row2', 'title1', 'content1'], 'starter');
});

test('authored values win on the page and on the stage; the grid arranges the cards, not the card', async ({ page }) => {
  const starter = await measure(page, 'starter');
  const authored = await measure(page, 'authored');
  agree(authored, ['heading', 'row1', 'row2', 'title1', 'content1'], 'authored');
  for (const width of WIDTHS) {
    for (const where of ['page', 'stage']) {
      const was = (name) => at(starter[where], width, name);
      const now = (name) => at(authored[where], width, name);
      // The Listing title's size and colour, the card title's size: authored, larger.
      expect(parseFloat(now('heading').typography['font-size']), `${where} heading size @${width}`)
        .toBeGreaterThan(parseFloat(was('heading').typography['font-size']));
      expect(now('heading').typography.color, `${where} heading colour @${width}`).not.toEqual(was('heading').typography.color);
      expect(parseFloat(now('title1').typography['font-size']), `${where} card title size @${width}`)
        .toBeGreaterThan(parseFloat(was('title1').typography['font-size']));
      // Two cards to a row: side by side, the second to the right of the first.
      const row1 = now('row1').geometry;
      const row2 = now('row2').geometry;
      near(row2.top, row1.top, `${where} cards share a row @${width}`);
      expect(row2.left, `${where} second card to the right @${width}`).toBeGreaterThan(row1.left + row1.width - NEAR);
      // The card's heading keeps its own box inside its card: the span stored on it is dormant.
      const title = now('title1').geometry;
      const content = now('content1').geometry;
      near(title.left, content.left, `${where} title starts its column @${width}`);
      near(title.width, content.width, `${where} title spans its column, no more @${width}`);
      expect(title.left + title.width, `${where} title inside its card @${width}`).toBeLessThanOrEqual(row1.left + row1.width + NEAR);
    }
  }
});

test('a flex row of cards: the cards sit in a row where they fit, the card unchanged', async ({ page }) => {
  const starter = await measure(page, 'starter');
  const flex = await measure(page, 'flex');
  agree(flex, ['heading', 'row1', 'row2', 'title1', 'content1'], 'flex');
  for (const width of WIDTHS) {
    for (const where of ['page', 'stage']) {
      const row1 = at(flex[where], width, 'row1').geometry;
      const row2 = at(flex[where], width, 'row2').geometry;
      // A wrapping row: side by side where both fit (the widest page), else the second card starts
      // the next line; a card sizes to its content, never stretched to the list (the phone's is
      // the list's width only because its content needs it).
      if (width === 1280) {
        near(row2.top, row1.top, `${where} cards share a row @${width}`);
        expect(row2.left, `${where} second card to the right @${width}`).toBeGreaterThan(row1.left + row1.width - NEAR);
      } else {
        near(row2.left, row1.left, `${where} second card starts the next line @${width}`);
        expect(row2.top, `${where} second card below @${width}`).toBeGreaterThan(row1.top + row1.height - NEAR);
      }
      if (width > 375) {
        expect(row1.width, `${where} card sized to its content @${width}`).toBeLessThan(width - NEAR);
      }
      expect(at(flex[where], width, 'title1').typography, `${where} card title unchanged @${width}`)
        .toEqual(at(starter[where], width, 'title1').typography);
    }
  }
});

test('removing the values returns the defaults', async ({ page }) => {
  const starter = await measure(page, 'starter');
  const reset = await measure(page, 'reset');
  for (const width of WIDTHS) {
    for (const where of ['page', 'stage']) {
      for (const name of ['heading', 'row1', 'row2', 'title1', 'content1']) {
        const was = at(starter[where], width, name);
        const now = at(reset[where], width, name);
        for (const [key, value] of Object.entries(was.geometry)) near(now.geometry[key], value, `${where} ${name}.${key} @${width}`);
        expect(now.typography, `${where} ${name} typography @${width}`).toEqual(was.typography);
      }
    }
  }
});

test('blocks added beside the starter keep the theme spacing', async ({ page }) => {
  const added = await measure(page, 'added');
  for (const width of WIDTHS) {
    for (const where of ['page', 'stage']) {
      for (const name of ['addedHeading', 'addedText', 'addedImage']) {
        const entry = at(added[where], width, name);
        // The page's block rhythm (--space-5): a card's release does not reach outside the card.
        expect(entry.spacing['margin-top'], `${where} ${name} margin @${width}`).toBe('40px');
      }
    }
  }
});
