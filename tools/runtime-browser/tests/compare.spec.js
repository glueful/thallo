// The comparator's own contract (container-layout plan, Task 0.1), on synthetic pages: the
// retirement gate is only as honest as this file. Proven here: an unchanged page passes; a small
// geometry shift fails and names the element; an allowed row relaxes exactly the properties and
// the `affects` geometry it names and nothing else; a relaxed value that does not equal the row's
// specified result still fails.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { measurePage } = require('../scripts/measure.js');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');

/**
 * Writes a page under the served repo root and returns its URL path. One directory per test
 * (the suite runs fullyParallel; a shared directory would let one worker's cleanup delete
 * another's pages mid-run).
 */
function writePage(testInfo, name, body) {
  const dir = path.join(REPO, 'tools/runtime-browser/fixtures/tmp', testInfo.testId);
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(
    path.join(dir, `${name}.html`),
    `<!doctype html><meta charset="utf-8"><style>
      body { margin: 0; font-family: system-ui; }
      [data-case-root] { width: 100%; }
      .title { font-size: 32px; margin: 0 0 8px; }
      .body { font-size: 16px; margin: 0; }
    </style>${body}`,
  );
  return `/tools/runtime-browser/fixtures/tmp/${testInfo.testId}/${name}.html`;
}

const PAGE = (titleSize, extra = '') => `
  <div data-case-root>
    <div class="group">
      <h2 class="title" style="font-size:${titleSize}">Built for sites that outlive their launch</h2>
      <p class="body" style="${extra}">Thallo is a canonical content source.</p>
    </div>
  </div>`;

const DEFINITION = {
  root: '[data-case-root]',
  elements: {
    group: '.group',
    title: '.title',
    body: '.body',
  },
  map: { group: '.group', title: '.title', body: '.body' },
};

test.afterEach(({}, testInfo) => {
  fs.rmSync(path.join(REPO, 'tools/runtime-browser/fixtures/tmp', testInfo.testId), {
    recursive: true,
    force: true,
  });
});

async function reference(page, url) {
  const widths = await measurePage(
    page,
    url,
    DEFINITION.elements,
    { group: null, title: null, body: null },
    DEFINITION.root,
  );
  return { case: 'synthetic', widths };
}

test('an unchanged rendering matches its reference', async ({ page, baseURL }, testInfo) => {
  const url = baseURL + writePage(testInfo, 'same', PAGE('32px'));
  const ref = await reference(page, url);
  expect(await compareToReference(page, ref, DEFINITION, url)).toEqual([]);
});

test('a small shift fails and names the element and property', async ({ page, baseURL }, testInfo) => {
  const refUrl = baseURL + writePage(testInfo, 'shift-a', PAGE('32px'));
  const ref = await reference(page, refUrl);
  // 12px, not 2px: the title's 8px bottom margin collapses with the paragraph's top margin, so a
  // 2px value would move nothing and prove nothing.
  const url = baseURL + writePage(testInfo, 'shift-b', PAGE('32px', 'margin-top:12px'));
  const failures = await compareToReference(page, ref, DEFINITION, url);
  expect(failures.join('\n')).toContain('body geometry.top');
  expect(failures.join('\n')).toContain('body spacing.margin-top');
});

test('an allowed row relaxes only its properties and its affects geometry', async ({
  page,
  baseURL,
}, testInfo) => {
  const refUrl = baseURL + writePage(testInfo, 'allow-a', PAGE('32px'));
  const ref = await reference(page, refUrl);
  const url = baseURL + writePage(testInfo, 'allow-b', PAGE('24px'));

  // Without a row: the title's font-size AND the geometry it moves both fail.
  const bare = await compareToReference(page, ref, DEFINITION, url);
  expect(bare.join('\n')).toContain('title typography.font-size');
  expect(bare.join('\n')).toContain('body geometry.top');

  // With the row: the named property and the affected geometry are relaxed, nothing else.
  const definition = {
    ...DEFINITION,
    allow: [
      {
        id: 'title-size',
        element: 'title',
        relax: ['font-size'],
        expect: { 'font-size': '24px' },
        // The element itself, the sibling after it, and the ancestor box whose height changes.
        affects: ['title', 'body', 'group'],
      },
    ],
  };
  expect(await compareToReference(page, ref, definition, url)).toEqual([]);

  // A spacing change on the same element is still compared.
  const spaced = baseURL + writePage(testInfo, 'allow-c', PAGE('24px', 'margin-top:12px'));
  const failures = await compareToReference(page, ref, definition, spaced);
  expect(failures.join('\n')).toContain('body spacing.margin-top');
});

test("a relaxed value that differs from the row's specified result fails", async ({
  page,
  baseURL,
}, testInfo) => {
  const refUrl = baseURL + writePage(testInfo, 'expect-a', PAGE('32px'));
  const ref = await reference(page, refUrl);
  const url = baseURL + writePage(testInfo, 'expect-b', PAGE('20px'));
  const definition = {
    ...DEFINITION,
    allow: [
      {
        id: 'title-size',
        element: 'title',
        relax: ['font-size'],
        expect: { 'font-size': '24px' },
        affects: ['title', 'body', 'group'],
      },
    ],
  };
  const failures = await compareToReference(page, ref, definition, url);
  expect(failures.join('\n')).toContain('relaxed to "24px" but rendered "20px"');
});
