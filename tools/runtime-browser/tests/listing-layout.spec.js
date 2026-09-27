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
