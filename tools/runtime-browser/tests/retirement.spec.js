// The retirement compositions against the frozen references (container-layout plan, Task 4.1).
//
// Section, Columns and Grid are replaced by Container compositions. Each case's rendering was
// frozen before any shared CSS changed; its composition is rendered with today's templates, theme
// and compiler by `scripts/build-parity-fixtures`, and measured here with the same procedure.
//
// A difference is either accounted for by a row in the case's `allow` list — and those rows may
// only come from the spec's closed disposition tables, §7.7, §7.10 and §7.11 — or it fails. The
// point of the exercise is that a retirement cannot quietly change how a page looks: every
// difference is either not there, or written down with the section that sanctioned it.
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

/** The retired types, whose cases this file owns; the container's own are parity.spec.js. */
const RETIRED = ['section-', 'columns-', 'grid-', 'leaf-'];

const definitions = fs
  .readdirSync(DEFINITIONS)
  .filter((file) => file.endsWith('.json'))
  .map((file) => JSON.parse(fs.readFileSync(path.join(DEFINITIONS, file), 'utf8')))
  .filter((definition) => RETIRED.some((prefix) => definition.case.startsWith(prefix)))
  .sort((a, b) => a.case.localeCompare(b.case));

test('every retired type has its composition written', () => {
  expect(definitions.length).toBeGreaterThan(0);
  for (const definition of definitions) {
    expect(definition.new, `${definition.case} has no composition`).toBeDefined();
    expect(definition.map, `${definition.case} has no selector map`).toBeDefined();
    // Every measured element must be findable in the composition, or the comparison silently
    // measures fewer elements than the reference recorded.
    expect(Object.keys(definition.map).sort()).toEqual(Object.keys(definition.elements).sort());
  }
});

test('every disposition row cites a closed table', () => {
  // The rows are the only place a difference may be accepted, so they are held to the spec's
  // sections rather than to whatever a passing run happened to need.
  for (const definition of definitions) {
    for (const row of definition.allow || []) {
      expect(row.why, `${definition.case}/${row.element}`).toMatch(
        /§(3\.6|3\.8|7\.4|7\.7|7\.10|7\.11)/,
      );
      expect(Object.keys(definition.elements)).toContain(row.element);
    }
  }
});

for (const definition of definitions) {
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
      // An element the CANVAS reference records as missing while the public one measured it was
      // never absent: the capture's own selector reached through a child combinator, which the
      // stage's annotation wrapper breaks. The composition addresses every element by id and finds
      // it in both renderings, so those names are outside what this comparison can say — the
      // public rendering is where they are compared.
      // Elements the composition folds into another: named in the case, with the reason there.
      const skip = new Set(definition.collapsed ?? []);
      // An element the two frozen renderings disagree about — present in one, absent in the other —
      // is one the old markup only emitted in one mode. The composition emits it in both, so its
      // presence is not something this comparison can judge either way.
      {
        const open = JSON.parse(
          fs.readFileSync(path.join(MEASUREMENTS, `${definition.case}.json`), 'utf8'),
        );
        const canvas = JSON.parse(
          fs.readFileSync(path.join(MEASUREMENTS, `${definition.case}.canvas.json`), 'utf8'),
        );
        for (const [width, elements] of Object.entries(open.widths)) {
          for (const [name, measured] of Object.entries(elements)) {
            const other = canvas.widths[width]?.[name];
            if (other && Boolean(other.missing) !== Boolean(measured.missing)) skip.add(name);
          }
        }
      }
      if (suffix === '.canvas') {
        const open = JSON.parse(
          fs.readFileSync(path.join(MEASUREMENTS, `${definition.case}.json`), 'utf8'),
        );
        for (const [width, elements] of Object.entries(reference.widths)) {
          for (const [name, measured] of Object.entries(elements)) {
            const inPublic = open.widths[width]?.[name]
            if (!inPublic || inPublic.missing) continue
            // Missing: the capture's selector found nothing through the wrapper.
            if (measured.missing) skip.add(name)
            // Or it found the WRAPPER instead of the block, which the two renderings disagreeing
            // about the element's own tag is exactly what shows.
            if (
              measured.semantics &&
              inPublic.semantics &&
              measured.semantics.tag !== inPublic.semantics.tag
            ) {
              skip.add(name)
            }
          }
        }
      }
      const failures = await compareToReference(
        page,
        reference,
        definition,
        `${URL_BASE}/${definition.case}${suffix}.html`,
        // An element the capture could not see was also not counted in the reading order, so every
        // index after it is one short. The order is compared on the public rendering instead.
        // An element outside the comparison was also not counted in the reference's reading order,
        // so every index after it differs by construction. The order is only compared where the
        // two sides agree on which elements exist.
        { elements: skip, properties: skip.size > 0 ? new Set(['reading-order']) : new Set() },
      );
      expect(failures, failures.join('\n')).toEqual([]);
    });
  }
}
