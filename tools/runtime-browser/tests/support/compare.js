// Compare a current rendering against a frozen reference (container-layout plan, Task 0.1).
//
// The reference holds the OLD rendering's measurements, taken with the same procedure
// (scripts/measure.js). A case definition maps each named element to its selector in the new
// composition and lists the closed disposition rows (§7.7, §7.10) that apply. A row relaxes:
//
//   - the properties it names, on the element it names — and those relaxed values are still
//     asserted against the row's specified result;
//   - `geometry` on every element in the row's `affects` list: the element itself, its following
//     siblings in the same flow container, and every ancestor box whose dimensions depend on it.
//
// Everything else is compared. A difference that no row relaxes fails, naming case, width,
// element, profile and property.
'use strict';

const { measurePage } = require('../../scripts/measure.js');

const TOLERANCE = 0.5; // px — sub-pixel rounding differs between two renderings of equal layout

function elementsOf(definition, side) {
  const elements = {};
  const profiles = {};
  const source = side === 'new' ? definition.map : definition.elements;
  for (const [name, spec] of Object.entries(source)) {
    elements[name] = typeof spec === 'string' ? spec : spec.selector;
    const declared = definition.elements[name];
    profiles[name] =
      typeof declared === 'string' ? null : (declared && declared.profiles) || null;
  }
  return { elements, profiles };
}

/** name => { properties: Set<string>, expected: Record<string,string> } for one width. */
function relaxations(definition, width) {
  const byElement = new Map();
  const geometryRelaxed = new Set();
  for (const row of definition.allow || []) {
    if (row.widths && !row.widths.includes(Number(width))) continue;
    const entry = byElement.get(row.element) || { properties: new Set(), expected: {} };
    for (const property of row.relax || []) entry.properties.add(property);
    Object.assign(entry.expected, row.expect || {});
    byElement.set(row.element, entry);
    for (const name of row.affects || []) geometryRelaxed.add(name);
  }
  return { byElement, geometryRelaxed };
}

function near(a, b) {
  const x = Number(a);
  const y = Number(b);
  return Number.isFinite(x) && Number.isFinite(y) && Math.abs(x - y) <= TOLERANCE;
}

/**
 * `blind` names what this comparison cannot speak about — see the retirement spec, where a canvas
 * reference recorded elements as missing only because the ORIGINAL capture's selector could not
 * see through the stage's annotation wrappers: those elements, and the reading order of every
 * element, since an uncounted element shifts every index after it.
 *
 * @returns {Promise<string[]>} the failures; empty means the composition matches the reference.
 */
async function compareToReference(page, reference, definition, url, blind = {}) {
  const skip = blind.elements ?? new Set();
  const skipProperties = blind.properties ?? new Set();
  const { elements, profiles } = elementsOf(definition, 'new');
  const current = await measurePage(
    page,
    url,
    elements,
    profiles,
    definition.newRoot || definition.root || '[data-case-root]',
  );
  const failures = [];
  for (const [width, expectedElements] of Object.entries(reference.widths)) {
    const actualElements = current[width] || {};
    const { byElement, geometryRelaxed } = relaxations(definition, width);
    for (const [name, expected] of Object.entries(expectedElements)) {
      const actual = actualElements[name];
      const at = `${reference.case} @${width}px ${name}`;
      if (skip.has(name)) continue;
      if (expected.missing) {
        if (actual && !actual.missing) failures.push(`${at}: present but the reference has none`);
        continue;
      }
      if (!actual || actual.missing) {
        failures.push(`${at}: missing from the composition`);
        continue;
      }
      const relaxed = byElement.get(name) || { properties: new Set(), expected: {} };
      for (const [profile, values] of Object.entries(expected)) {
        for (const [property, value] of Object.entries(values)) {
          const actualValue = (actual[profile] || {})[property];
          if (skipProperties.has(property)) continue;
          if (profile === 'geometry' && geometryRelaxed.has(name)) continue;
          if (relaxed.properties.has(property)) {
            const want = relaxed.expected[property];
            if (want !== undefined && String(actualValue) !== String(want)) {
              failures.push(
                `${at} ${profile}.${property}: relaxed to "${want}" but rendered "${actualValue}"`,
              );
            }
            continue;
          }
          const same =
            profile === 'geometry'
              ? near(value, actualValue)
              : String(value) === String(actualValue);
          if (!same) {
            failures.push(
              `${at} ${profile}.${property}: reference "${value}", composition "${actualValue}"`,
            );
          }
        }
      }
    }
  }
  return failures;
}

module.exports = { compareToReference, TOLERANCE };
