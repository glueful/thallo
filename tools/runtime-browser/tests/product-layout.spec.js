// The shop's product page in a real browser (type layouts plan C1).
//
// `references/product-original.json` froze today's product page (`shop/product.twig`) before any
// C1 template or stylesheet change (scripts/capture-product-layout-reference). Every later
// rendering of the page without a layout is held to it here, with the same measuring procedure,
// so the page keeps its look however the templates behind it are reorganised.
//
// The pages come from scripts/build-product-layout-proof-fixtures (gitignored): one product seeded
// with fixed values, every stylesheet, font and image inlined, scripts dropped.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITION = JSON.parse(
  fs.readFileSync(path.join(REPO, 'tests/fixtures/commerce/product-page-reference.json'), 'utf8'),
);
const REFERENCE = JSON.parse(
  fs.readFileSync(path.join(REPO, 'tools/runtime-browser/references/product-original.json'), 'utf8'),
);
const URL_BASE = '/tools/runtime-browser/fixtures/product-layout';

test("today's product page matches its frozen reference", async ({ page }) => {
  // The page without a layout keeps every selector the reference recorded.
  const definition = { ...DEFINITION, map: DEFINITION.elements };
  const failures = await compareToReference(page, REFERENCE, definition, `${URL_BASE}/original.html`);
  expect(failures, failures.join('\n')).toEqual([]);
});
