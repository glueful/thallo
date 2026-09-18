// The frozen references' own shape (container-layout plan, Task 0.1). Permanent: it reads only
// committed files — the case definitions and the measurements — never the capture pages, which are
// gitignored, and never the capture script, which is deleted with the retired types (Task 4.2).
//
// What it holds to: every case has measurements for both renderings at every width; every named
// element of a case definition is measured; each element carries exactly the profiles its
// definition declares; and no layout-mechanism value was recorded, since the old and new
// mechanisms differ by design and are proven separately by layout.spec.js.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { WIDTHS, PROFILES } = require('../scripts/measure.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITIONS = path.join(REPO, 'tests/fixtures/layout/references');
const MEASUREMENTS = path.join(REPO, 'tools/runtime-browser/references');

const MECHANISM = [
  'display',
  'flex-direction',
  'flex-wrap',
  'justify-content',
  'align-items',
  'column-gap',
  'row-gap',
  'grid-template-columns',
  'max-width',
  'min-height',
];

const cases = fs
  .readdirSync(DEFINITIONS)
  .filter((file) => file.endsWith('.json'))
  .map((file) => JSON.parse(fs.readFileSync(path.join(DEFINITIONS, file), 'utf8')));

test('there are reference cases to compare against', () => {
  expect(cases.length).toBeGreaterThan(0);
});

for (const definition of cases) {
  for (const suffix of ['', '.canvas']) {
    test(`${definition.case}${suffix} is measured at every width, for every named element`, () => {
      const file = path.join(MEASUREMENTS, `${definition.case}${suffix}.json`);
      expect(fs.existsSync(file), `${file} is missing — re-run the capture`).toBe(true);
      const reference = JSON.parse(fs.readFileSync(file, 'utf8'));
      expect(reference.case).toBe(`${definition.case}${suffix}`);
      expect(Object.keys(reference.widths).map(Number).sort((a, b) => a - b)).toEqual(WIDTHS);

      for (const [width, elements] of Object.entries(reference.widths)) {
        for (const [name, spec] of Object.entries(definition.elements)) {
          const measured = elements[name];
          expect(measured, `${definition.case} @${width} has no ${name}`).toBeDefined();
          if (measured.missing) continue; // an element a configuration omits (no links, no title)
          const declared = typeof spec === 'string' ? PROFILES : spec.profiles;
          expect(Object.keys(measured).sort()).toEqual([...declared].sort());
          for (const values of Object.values(measured)) {
            for (const property of Object.keys(values)) {
              expect(
                MECHANISM.includes(property),
                `${definition.case} @${width} ${name} recorded the mechanism value ${property}`,
              ).toBe(false);
            }
          }
        }
      }
    });
  }
}
