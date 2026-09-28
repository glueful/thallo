// The shop home and category pages in a real browser (type layouts plan C2).
//
// `references/shop-{index,category}-original.json` froze today's pages (`shop/index.twig` and
// `shop/category.twig` with `_product_card.twig` and `_pagination.twig`) before any C2 template or
// stylesheet change (scripts/capture-shop-layout-reference). Every later rendering of the pages
// without a layout is held to them here, with the same measuring procedure, so the pages keep their
// look however the templates and the shop stylesheet behind them change.
//
// The references hold on any platform's fonts: a box its text sizes is measured by its top and
// height (or height alone, where a baseline places it) and its computed style — never by a width
// set by a font's advance widths. When CI disagrees with a local capture, diagnose the property
// first: a regression is fixed; a difference that is only font metrics is re-expressed as a
// layout-determined assertion of the same behaviour, never simply dropped, and no tolerance widens.
//
// The pages come from scripts/build-shop-layout-proof-fixtures (gitignored): the shop of
// ShopPageSeed seeded with fixed values, every stylesheet, font and image inlined, scripts dropped.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITION = JSON.parse(
  fs.readFileSync(path.join(REPO, 'tests/fixtures/commerce/shop-page-reference.json'), 'utf8'),
);
const URL_BASE = '/tools/runtime-browser/fixtures/shop-layout';

const reference = (page) =>
  JSON.parse(fs.readFileSync(path.join(REPO, `tools/runtime-browser/references/shop-${page}-original.json`), 'utf8'));

test("today's shop home and category pages match their frozen reference", async ({ page }) => {
  const definition = { ...DEFINITION, map: DEFINITION.elements };
  for (const name of ['index', 'category']) {
    const failures = await compareToReference(page, reference(name), definition, `${URL_BASE}/shop-${name}-original.html`);
    expect(failures, `${name}:\n${failures.join('\n')}`).toEqual([]);
  }
});
