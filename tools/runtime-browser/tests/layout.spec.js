// What the compiled layout classes DO, measured in Chromium (container-layout plan, Task 1.2).
//
// The PHP suite pins which classes a case emits; only a browser can say what they resolve to —
// whether a span clamps when its parent loses tracks, whether a hidden band stays hidden once it
// gains a minimum height, whether a child's default margin comes back when its parent leaves flex.
// Each case in `tests/fixtures/layout/cases/` is built into two pages by
// `scripts/build-layout-proof-fixtures` (public and annotated), and both are measured here at the
// four widths that surround the breakpoints: 375 and 700 are base, 800 is md, 1280 is lg.
//
// Addresses name elements inside a page: `c0` is the outermost container, `c1` the next one in
// document order, `.root` its band, `.inner` its content area, `.child0` the first BLOCK child of
// that content area — the annotated rendering wraps every block root in a `display: contents`
// element, and stepping through those wrappers is exactly how the two renderings stay comparable.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const REPO = path.resolve(__dirname, '..', '..', '..');
const CASES_DIR = path.join(REPO, 'tests/fixtures/layout/cases');
const FIXTURES = path.join(REPO, 'tools/runtime-browser/fixtures/layout');
const URL_BASE = '/tools/runtime-browser/fixtures/layout';

const WIDTHS = [375, 700, 800, 1280];

// The inner content area of a boxed container at each width: the viewport up to the container
// measure (1152px), less the page gutter on both sides (24px).
const CONTENT = { 375: 327, 700: 652, 800: 752, 1280: 1104 };
// A track of an n-track grid with no gap authored, and the same for an asymmetric split.
const track = (of, parts = 1) => (width) => (CONTENT[width] / of) * parts;
const full = (width) => CONTENT[width];
// Where that content area starts: hard against the gutter until the measure is reached, then
// centred in the viewport.
const contentLeft = (width) => (width > 1152 + 48 ? (width - 1152) / 2 + 24 : 24);

/** Expectations per width: `md` and `lg` merge onto the state below them, as the cascade does. */
const at = (base, md = {}, lg = {}) => ({
  375: base,
  700: base,
  800: { ...base, ...md },
  1280: { ...base, ...md, ...lg },
});

const SPANS_TWO = { 'grid-column-start': 'span 2', 'grid-column-end': 'auto' };
const CLAMPED = { 'grid-column-start': '1', 'grid-column-end': '-1' };
const ONE_CELL = { 'grid-column-start': 'auto', 'grid-column-end': 'auto' };
const TWO_TRACKS = /^\d+(\.\d+)?px \d+(\.\d+)?px$/;
// A block child's default vertical rhythm outside flex and grid (--space-5), with the first and
// last edges released so a container never adds space at its own boundary.
const RHYTHM = { 'margin-block-start': '40px', 'margin-block-end': '40px' };
const FIRST = { 'margin-block-start': '0px', 'margin-block-end': '40px' };
const LAST = { 'margin-block-start': '40px', 'margin-block-end': '0px' };
const NO_MARGIN = { 'margin-block-start': '0px', 'margin-block-end': '0px' };

const CASES = {
  // §3.3 dormancy: a property of the mode that is not in force must not reach the element.
  'dormancy-flex-then-grid-md': {
    'c0.inner': at(
      { display: 'flex', 'grid-template-columns': 'none' },
      { display: 'grid', 'grid-template-columns': TWO_TRACKS },
    ),
  },
  'dormancy-grid-inherited': {
    'c0.inner': at({ display: 'grid', 'grid-template-columns': TWO_TRACKS }),
    // The third child wraps to a new row rather than a third track.
    'c0.child2': at({ 'rect.left': contentLeft, 'rect.width': track(2) }),
  },
  'nested-flex-grid-flex': {
    'c0.inner': at({ display: 'flex', 'grid-template-columns': 'none' }),
    'c1.inner': at({ display: 'grid', 'grid-template-columns': TWO_TRACKS }),
    'c2.inner': at({ display: 'flex', 'grid-template-columns': 'none' }),
  },

  // §3.7 span: a span resolves against the tracks in force at that width, and clamps to them.
  'span-mobile-stack': {
    'c0.child0': at({ ...CLAMPED, 'rect.width': full }, { ...SPANS_TWO, 'rect.width': full }),
    'c0.child1': at({ 'rect.width': full }, { 'rect.width': track(2) }),
  },
  'span-asymmetric': {
    'c0.inner': at({ 'grid-template-columns': TWO_TRACKS }),
    'c0.child0': at({ ...SPANS_TWO, 'rect.width': full }),
    'c0.child1': at({ 'rect.width': track(3) }),
  },
  'span-inherited': {
    // Three requested, one track at base and two at md: clamped at every width, never overflowing.
    'c0.child0': at({ ...CLAMPED, 'rect.width': full }),
  },
  'span-nested-grid': {
    // The span resolves against the NEAREST container's tracks, not an ancestor's.
    'c0.child0': at({ ...SPANS_TWO, 'rect.width': track(4, 2) }),
    'c1.child0': at({ ...SPANS_TWO, 'rect.width': track(4, 2) }),
    'c1.child1': at({ 'rect.width': track(4) }),
  },
  'span-clamped-then-unclamped': {
    'c0.child0': at(
      { ...CLAMPED, 'rect.width': full },
      { ...SPANS_TWO, 'rect.width': track(4, 2) },
    ),
  },
  'span-unclamped-then-clamped': {
    'c0.child0': at(
      { ...SPANS_TWO, 'rect.width': track(4, 2) },
      { ...CLAMPED, 'rect.width': full },
    ),
  },
  'span-reset-md': {
    'c0.child0': at({ ...SPANS_TWO, 'rect.width': track(4, 2) }, { ...ONE_CELL, 'rect.width': track(4) }),
  },
  'span-parent-tracks-absent': {
    // No tracks authored anywhere: the default state is one track, and the span clamps to it.
    'c0.child0': at({ ...CLAMPED, 'rect.width': full }),
  },
  'span-parent-tracks-reset': {
    'c0.child0': at({ ...SPANS_TWO, 'rect.width': track(4, 2) }, { ...CLAMPED, 'rect.width': full }),
  },
  'span-parent-grid-then-reset-md': {
    'c0.inner': at({ 'grid-template-columns': TWO_TRACKS }, { 'grid-template-columns': /^\d+(\.\d+)?px$/ }),
    'c0.child0': at({ ...SPANS_TWO, 'rect.width': full }, { ...CLAMPED, 'rect.width': full }),
  },

  // §3.4 gutter: boxed carries the page gutter, full-width carries none, an authored value wins.
  'gutter-boxed-to-full': {
    'c0.inner': at(
      { 'padding-inline-start': '24px', 'max-width': '1152px' },
      { 'padding-inline-start': '0px', 'max-width': 'none' },
    ),
  },
  'gutter-authored-kept': {
    'c0.inner': at({ 'padding-inline-start': '64px' }, { 'max-width': 'none' }),
  },
  'gutter-reset': {
    // Reset returns the gutter to the default for the width in force, not to nothing.
    'c0.inner': at({ 'padding-inline-start': '64px' }, { 'padding-inline-start': '24px' }),
  },
  'gutter-none-explicit': {
    'c0.inner': at({ 'padding-inline-start': '0px', 'max-width': '1152px' }),
  },
  'gutter-nested-full-in-boxed': {
    'c0.inner': at({ 'padding-inline-start': '24px', 'max-width': '1152px' }),
    'c1.inner': at({ 'padding-inline-start': '0px', 'max-width': 'none' }),
  },

  // §3.5 min height, and its composition with managed visibility.
  'band-half-centred': {
    'c0.root': at({ display: 'flex', 'min-height': '450px', 'flex-direction': 'column' }),
    'c0.inner': at({ 'justify-content': 'center' }),
  },
  'minh-half-lg-only': {
    'c0.root': at(
      { display: 'block', 'min-height': '0px' },
      {},
      { display: 'flex', 'min-height': '450px' },
    ),
  },
  'minh-half-then-auto-md': {
    'c0.root': at({ display: 'flex', 'min-height': '450px' }, { display: 'block', 'min-height': '0px' }),
  },
  'minh-half-then-reset-md': {
    // Reset and auto land in the same place: the theme default.
    'c0.root': at({ display: 'flex', 'min-height': '450px' }, { display: 'block', 'min-height': '0px' }),
  },
  'minh-hidden-base-half-lg': {
    // Visibility keeps sole authority over `display`: a minimum height introduced at lg sizes the
    // band without revealing it.
    'c0.root': at({ display: 'none', 'min-height': '0px' }, {}, { display: 'none', 'min-height': '450px' }),
  },
  'minh-half-hidden-then-visible-md': {
    'c0.root': at(
      { display: 'none', 'min-height': '450px', 'rect.height': 0 },
      { display: 'flex', 'min-height': '450px', 'rect.height': 450 },
    ),
    'c0.inner': at({ 'justify-content': 'center' }),
  },
  'minh-reset-while-hidden': {
    'c0.root': at({ display: 'none', 'min-height': '450px' }, { display: 'none', 'min-height': '0px' }),
  },

  // §3.8 spacing normalization: flex and grid own the spacing between children through gap, so the
  // children's own rhythm stands down — and comes back exactly when the mode does.
  'spacing-normalization': {
    'c0.child0': at(FIRST),
    'c0.child1': at(RHYTHM),
    'c0.child2': at(LAST),
  },
  'spacing-flex-then-block-md': {
    'c0.child0': at(NO_MARGIN, FIRST),
    'c0.child1': at(NO_MARGIN, RHYTHM),
    'c0.child2': at(NO_MARGIN, LAST),
  },
  'spacing-flex-then-reset-md': {
    // Reset is the same state as block — the theme default — so the margins return identically.
    'c0.child0': at(NO_MARGIN, FIRST),
    'c0.child1': at(NO_MARGIN, RHYTHM),
    'c0.child2': at(NO_MARGIN, LAST),
  },
  'spacing-grid-then-block-lg': {
    'c0.child0': at(NO_MARGIN, {}, FIRST),
    'c0.child1': at(NO_MARGIN, {}, RHYTHM),
    'c0.child2': at(NO_MARGIN, {}, LAST),
  },

  // §3.6 containment: the release strips the page measure a block carries, and nothing else.
  'containment-preserves-component-padding': {
    'c0.child0': at({ 'padding-top': '24px', 'padding-left': '24px' }),
    'c0.child1': at({ 'padding-left': '24px' }),
  },
  'nested-clamp-authored': {
    // An authored value is not a default: it survives the release at every width.
    'c0.child0': at({ 'max-width': '576px' }),
    'c0.child1': at({ 'padding-left': '64px' }),
    'c0.child2': at({ 'flex-basis': '50%' }),
  },
  'item-participation': {
    'c0.child0': at(NO_MARGIN),
    'c0.child3': at({ ...NO_MARGIN, 'padding-top': '24px' }),
  },
};

/** Read the requested properties for each address, in the viewport of one width. */
async function measure(page, url, width, requests) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url);
  await page.evaluate(() => document.fonts && document.fonts.ready);
  return page.evaluate((requests) => {
    const round = (n) => Math.round(n * 100) / 100;
    // The annotated rendering wraps each block root in a display:contents element.
    const unwrap = (el) =>
      el.classList.contains('thallo-preview-block') ? el.firstElementChild : el;
    const containers = [...document.querySelectorAll('.thallo-block-container')];
    const locate = (address) => {
      const match = /^c(\d+)\.(root|inner|child(\d+))$/.exec(address);
      if (!match) throw new Error(`bad address ${address}`);
      const root = containers[Number(match[1])];
      if (!root) throw new Error(`${address}: no container ${match[1]}`);
      if (match[2] === 'root') return root;
      const inner = root.querySelector(':scope > .thallo-block-container__inner');
      if (!inner) throw new Error(`${address}: no content area`);
      if (match[2] === 'inner') return inner;
      const children = [...inner.children].map(unwrap).filter(Boolean);
      const child = children[Number(match[3])];
      if (!child) throw new Error(`${address}: no child ${match[3]}`);
      return child;
    };
    const out = {};
    for (const [address, properties] of Object.entries(requests)) {
      const el = locate(address);
      const style = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      const values = {};
      for (const property of properties) {
        values[property] = property.startsWith('rect.')
          ? round(rect[property.slice(5)])
          : style.getPropertyValue(property);
      }
      out[address] = values;
    }
    return out;
  }, requests);
}

const names = fs
  .readdirSync(CASES_DIR)
  .filter((file) => file.endsWith('.json'))
  .map((file) => file.replace(/\.json$/, ''))
  .sort();

test('every layout case has a built fixture and an expectation', () => {
  expect(names.length).toBeGreaterThan(0);
  for (const name of names) {
    expect(
      fs.existsSync(path.join(FIXTURES, `${name}.html`)),
      `${name}: run scripts/build-layout-proof-fixtures`,
    ).toBe(true);
    expect(fs.existsSync(path.join(FIXTURES, `${name}.canvas.html`))).toBe(true);
    expect(CASES[name], `${name} has no expectation in layout.spec.js`).toBeDefined();
  }
  // And no expectation names a case that no longer exists.
  expect(Object.keys(CASES).sort()).toEqual(names);
});

for (const name of names) {
  const expectations = CASES[name] || {};
  for (const [suffix, rendering] of [
    ['', 'public'],
    ['.canvas', 'annotated'],
  ]) {
    test(`${name} resolves as the contract says (${rendering})`, async ({ page }) => {
      const url = `${URL_BASE}/${name}${suffix}.html`;
      const requests = {};
      for (const [address, perWidth] of Object.entries(expectations)) {
        const properties = new Set();
        for (const width of WIDTHS) {
          for (const property of Object.keys(perWidth[width] || {})) properties.add(property);
        }
        requests[address] = [...properties];
      }
      for (const width of WIDTHS) {
        const measured = await measure(page, url, width, requests);
        for (const [address, perWidth] of Object.entries(expectations)) {
          for (const [property, wanted] of Object.entries(perWidth[width] || {})) {
            const actual = measured[address][property];
            const where = `${name} @${width} ${address} ${property}`;
            const value = typeof wanted === 'function' ? wanted(width) : wanted;
            if (value instanceof RegExp) {
              expect(actual, where).toMatch(value);
            } else if (typeof value === 'number') {
              // Sub-pixel track division is the browser's business, not the contract's.
              expect(actual, where).toBeGreaterThan(value - 0.5);
              expect(actual, where).toBeLessThan(value + 0.5);
            } else {
              expect(actual, where).toBe(value);
            }
          }
        }
      }
    });
  }
}

// The container's own mechanism after the cutover (container-layout plan, Task 2.1). The parity
// spec proves the compositions LOOK like the old renderings; this proves they are built the way
// §4 says — the band's display comes from the root-layout variable that min height sets, and the
// arrangement is on the content area. Measured on the same candidate pages parity uses.
const PARITY_DEFINITIONS = path.join(REPO, 'tests/fixtures/layout/references');
const PARITY_URL = '/tools/runtime-browser/fixtures/parity';

const JUSTIFY = {
  start: 'flex-start', center: 'center', end: 'flex-end',
  between: 'space-between', around: 'space-around', evenly: 'space-evenly',
};
const ALIGN_ITEMS = {
  start: 'flex-start', center: 'center', end: 'flex-end', stretch: 'stretch', baseline: 'baseline',
};
const FROM_CONTENT_ALIGN = { top: 'flex-start', center: 'center', bottom: 'flex-end' };
const MEASURE = { contained: '--container', narrow: '--content', full: null };

const containers = fs
  .readdirSync(PARITY_DEFINITIONS)
  .filter((file) => file.startsWith('container-') && file.endsWith('.json'))
  .map((file) => JSON.parse(fs.readFileSync(path.join(PARITY_DEFINITIONS, file), 'utf8')))
  .filter((definition) => definition.new)
  .sort((a, b) => a.case.localeCompare(b.case));

for (const definition of containers) {
  test(`${definition.case} is built the way the cutover says`, async ({ page }) => {
    const old = definition.old.data;
    const flex = (old.layout || 'block') === 'flex';
    const tall = (old.min_height || 'auto') !== 'auto';

    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${PARITY_URL}/${definition.case}.html`);
      const measured = await page.evaluate((measureVariable) => {
        const root = document.querySelector('.thallo-block-container');
        const inner = root.querySelector(':scope > .thallo-block-container__inner');
        const rootStyle = getComputedStyle(root);
        const innerStyle = getComputedStyle(inner);
        return {
          rootDisplay: rootStyle.display,
          rootLayout: rootStyle.getPropertyValue('--thallo-root-layout').trim(),
          minHeight: rootStyle.minHeight,
          innerDisplay: innerStyle.display,
          direction: innerStyle.flexDirection,
          wrap: innerStyle.flexWrap,
          justify: innerStyle.justifyContent,
          alignItems: innerStyle.alignItems,
          columnGap: innerStyle.columnGap,
          rowGap: innerStyle.rowGap,
          maxWidth: innerStyle.maxWidth,
          // The theme's own value for the measure the composition asked for, resolved here so the
          // proof names the token rather than a pixel count that moves with the theme.
          measure: (() => {
            if (!measureVariable) return null;
            const probe = document.createElement('div');
            probe.style.width = `var(${measureVariable})`;
            document.body.appendChild(probe);
            const resolved = getComputedStyle(probe).width;
            probe.remove();
            return resolved;
          })(),
        };
      }, MEASURE[old.width || 'contained']);

      const at = `${definition.case} @${width}`;
      // Min height never sets `display` directly: it sets the variable the theme reads, so managed
      // visibility keeps sole authority over display (spec §3.5).
      expect(measured.rootLayout, `${at} root-layout variable`).toBe(tall ? 'flex' : 'block');
      expect(measured.rootDisplay, `${at} root display`).toBe(tall ? 'flex' : 'block');
      expect(measured.minHeight, `${at} min-height`).toBe(
        old.min_height === 'half' ? '450px' : old.min_height === 'screen' ? '900px' : '0px',
      );

      // A tall band centres its content through an explicit flex column (spec §3.5); a flex
      // container arranges its children; anything else stays in normal flow.
      expect(measured.innerDisplay, `${at} inner display`).toBe(flex || tall ? 'flex' : 'block');
      if (flex) {
        expect(measured.direction, `${at} direction`).toBe(old.flex_direction || 'row');
        expect(measured.wrap, `${at} wrap`).toBe(old.flex_wrap || 'nowrap');
        expect(measured.justify, `${at} justify`).toBe(JUSTIFY[old.justify || 'start']);
        expect(measured.alignItems, `${at} align-items`).toBe(ALIGN_ITEMS[old.align_items || 'stretch']);
        const gap = (old.gap || {}).value;
        if (gap) {
          // The token is authored in rem and the gap computes in px, so the theme's value is
          // resolved through a probe rather than compared as text.
          const token = await page.evaluate((name) => {
            const probe = document.createElement('div');
            probe.style.width = `var(${name})`;
            document.body.appendChild(probe);
            const resolved = getComputedStyle(probe).width;
            probe.remove();
            return resolved;
          }, `--t-spacing-${gap.replace('spacing.', '')}`);
          expect(measured.columnGap, `${at} column gap`).toBe(token);
          expect(measured.rowGap, `${at} row gap`).toBe(token);
        }
      } else if (tall) {
        expect(measured.direction, `${at} direction`).toBe('column');
        expect(measured.justify, `${at} justify`).toBe(
          FROM_CONTENT_ALIGN[old.content_align || 'center'],
        );
      }

      // The old width enum is a content-width token now, and the theme's measure is what it
      // resolves to.
      expect(measured.maxWidth, `${at} measure`).toBe(measured.measure ?? 'none');
    }
  });
}
