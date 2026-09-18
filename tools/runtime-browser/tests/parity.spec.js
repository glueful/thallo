// Compositions measured against the frozen references (container-layout plan, Tasks 2.1 and 4.1).
//
// A case definition holds the OLD tree, whose rendering was frozen before any shared CSS changed,
// and the NEW tree that is meant to replace it. `scripts/build-parity-fixtures` renders the new
// tree with today's templates, theme and compiler; `compareToReference` measures it with the same
// procedure the reference used and reports every difference the definition's `allow` rows do not
// account for.
//
// A case with no `new` tree yet is skipped by name, so the ones that do have a composition are
// gated from the moment they are written rather than at the end of the retirement.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITIONS = path.join(REPO, 'tests/fixtures/layout/references');
const MEASUREMENTS = path.join(REPO, 'tools/runtime-browser/references');
const FIXTURES = path.join(REPO, 'tools/runtime-browser/fixtures/parity');
const URL_BASE = '/tools/runtime-browser/fixtures/parity';

const definitions = fs
  .readdirSync(DEFINITIONS)
  .filter((file) => file.endsWith('.json'))
  .map((file) => JSON.parse(fs.readFileSync(path.join(DEFINITIONS, file), 'utf8')))
  .sort((a, b) => a.case.localeCompare(b.case));

// The container's own cutover (Task 2.1). The retired types' compositions are compared by
// retirement.spec.js, which carries their disposition rules.
const composed = definitions.filter(
  (definition) => definition.new && definition.case.startsWith('container-'),
);

test('the container compositions are all written', () => {
  // The cutover is complete when every container case has a composition (plan Task 2.1); the
  // remaining block types get theirs as they are retired (Task 4.1).
  const containers = definitions.filter((d) => d.case.startsWith('container-'));
  expect(containers.length).toBeGreaterThan(0);
  for (const definition of containers) {
    expect(definition.new, `${definition.case} has no composition`).toBeDefined();
    expect(definition.map, `${definition.case} has no selector map`).toBeDefined();
    // Every named element must be findable in the composition, or the comparison silently
    // measures fewer elements than the reference recorded.
    expect(Object.keys(definition.map).sort()).toEqual(Object.keys(definition.elements).sort());
  }
});

// The narrow claim of spec §3.2 / §11.1, and exactly as narrow as its evidence: the ordinary
// stacks that were CAPTURED — three of them — keep their distances. It is not a claim about every
// possible stack. The old container's
// block flow spaced its children by their own margins, collapsing to --space-5; the composition
// spaces them by the default gap. The disposition rows relax these elements' geometry (their widths
// change with the containment release, §7.10), so the distance is measured here on its own, against
// the numbers frozen before anything changed.
//
// "Untouched" is exact: block layout AND no minimum height. A band with a minimum height was never
// block flow — the old theme made its content area a centred flex column, where margins do not
// collapse — and §7.11's closing paragraph dispositions that one separately.
const stacks = composed.filter(
  (definition) =>
    (definition.old.data.layout || 'block') === 'block' &&
    (definition.old.data.min_height || 'auto') === 'auto',
);

test('there are frozen block-flow stacks to hold the claim against', () => {
  expect(stacks.length).toBeGreaterThan(0);
});

for (const definition of stacks) {
  for (const [suffix, rendering] of [
    ['', 'public'],
    ['.canvas', 'annotated'],
  ]) {
    test(`${definition.case} keeps its stack's vertical distances (${rendering})`, async ({ page }) => {
      const reference = JSON.parse(
        fs.readFileSync(path.join(MEASUREMENTS, `${definition.case}${suffix}.json`), 'utf8'),
      );
      for (const [width, frozen] of Object.entries(reference.widths)) {
        const was = frozen.richtext.geometry.top - (frozen.heading.geometry.top + frozen.heading.geometry.height);
        await page.setViewportSize({ width: Number(width), height: 900 });
        await page.goto(`${URL_BASE}/${definition.case}${suffix}.html`);
        await page.evaluate(() => document.fonts && document.fonts.ready);
        const now = await page.evaluate(
          ([heading, richtext]) =>
            document.querySelector(richtext).getBoundingClientRect().top -
            document.querySelector(heading).getBoundingClientRect().bottom,
          [definition.map.heading, definition.map.richtext],
        );
        expect(Math.abs(was - now), `${definition.case} @${width}: was ${was}, now ${now}`).toBeLessThan(0.5);
      }
    });
  }
}

for (const definition of composed) {
  for (const [suffix, rendering] of [
    ['', 'public'],
    ['.canvas', 'annotated'],
  ]) {
    test(`${definition.case} matches its frozen reference (${rendering})`, async ({ page }) => {
      const file = path.join(MEASUREMENTS, `${definition.case}${suffix}.json`);
      expect(fs.existsSync(file), `${file} is missing — re-run the capture`).toBe(true);
      expect(
        fs.existsSync(path.join(FIXTURES, `${definition.case}${suffix}.html`)),
        `${definition.case}${suffix}: run scripts/build-parity-fixtures`,
      ).toBe(true);

      const reference = JSON.parse(fs.readFileSync(file, 'utf8'));
      const failures = await compareToReference(
        page,
        reference,
        definition,
        `${URL_BASE}/${definition.case}${suffix}.html`,
      );
      expect(failures, failures.join('\n')).toEqual([]);
    });
  }
}
