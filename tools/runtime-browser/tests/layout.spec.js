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
// The theme's default gap, on both axes (spec §3.8): --space-5, the margin it replaced.
const GAP = 40;
// A track of an n-track grid with no gap authored — so the default one — and the same for a span
// or an asymmetric split, which takes its tracks and the gaps between them.
const track = (of, parts = 1) => (width) =>
  ((CONTENT[width] - (of - 1) * GAP) / of) * parts + (parts - 1) * GAP;
// One part of an asymmetric split ('1-2' is parts [1, 2]): fr tracks share what the gaps leave.
const split = (parts, index) => (width) => {
  const free = CONTENT[width] - (parts.length - 1) * GAP;
  return (free * parts[index]) / parts.reduce((a, b) => a + b, 0);
};
const full = (width) => CONTENT[width];
// Where that content area starts: hard against the gutter until the measure is reached, then
// centred in the viewport.
const contentLeft = (width) => (width > 1152 + 48 ? (width - 1152) / 2 + 24 : 24);

/** One line of a code snippet: 0.875rem at a line-height of 1.6. */
const LINE = 22.4;

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
// A container's children carry no default vertical margin in either mode (spec §3.8): the gaps
// are the one source of spacing. `gap.above` is measured — the distance to the previous sibling's
// bottom edge, or for a first child to the top of the content area.
const NO_MARGIN = { 'margin-block-start': '0px', 'margin-block-end': '0px' };
const FIRST = { ...NO_MARGIN, 'gap.above': 0 };
const SPACED = { ...NO_MARGIN, 'gap.above': GAP };
const LAST = { ...SPACED, 'gap.below': 0 };
// `width.narrow` is 36rem. Alone on a line an item fills up to it; two in a nowrap row share the line.
const NARROW = 576;
const narrowFill = (width) => Math.min(CONTENT[width], NARROW);
const narrowShare = (width) => (CONTENT[width] - GAP) / 2;
// The content measure an authored `width.content` names (--content: 46rem).
const CONTENT_MEASURE = 736;
const placedWidth = (width) => Math.min(CONTENT[width], CONTENT_MEASURE);
const placedLeft = (placement) => (width) => {
  const free = CONTENT[width] - placedWidth(width);
  return contentLeft(width) + { start: 0, center: free / 2, end: free }[placement];
};

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
    // The second child wraps to the next row's first track — the `1` of the 1-2 split.
    'c0.child1': at({ 'rect.width': split([1, 2], 0) }),
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

  // §3.8 spacing: the gaps are the one source of spacing, in both modes, and nothing — no mode, no
  // breakpoint, no reset — brings a child's default margin back.
  'spacing-normalization': {
    'c0.child0': at(FIRST),
    'c0.child1': at(SPACED),
    'c0.child2': at(LAST),
    // The grid nested inside it: its two children sit in one row, neither with a margin.
    'c1.child0': at(NO_MARGIN),
    'c1.child1': at(NO_MARGIN),
  },
  'spacing-flex-then-reset-md': {
    // A reset lands on the theme default, which is a flex column: the fixture authors no gap, so
    // the default one spaces the children at every width and no margin ever returns.
    'c0.inner': at({ display: 'flex', 'flex-direction': 'column' }),
    'c0.child0': at(FIRST),
    'c0.child1': at(SPACED),
    'c0.child2': at(LAST),
  },
  // §3.8, §11.1: nothing authored. The stack block flow gave — consecutive children --space-5
  // apart, the container's own edges released — now comes from the default mode and its gap.
  'spacing-untouched-stack': {
    'c0.inner': at({ display: 'flex', 'flex-direction': 'column', 'row-gap': '40px' }),
    'c0.child0': at(FIRST),
    'c0.child1': at(SPACED),
    'c0.child2': at(SPACED),
    'c0.child3': at(LAST),
  },
  // §3.8: auto inline margins stop a flex item's cross-axis stretch, so a placed child must still
  // fill up to its authored width — and never past the space there is, at any viewport.
  'placed-child-in-flex-column': {
    'c0.root': at({ 'doc.overflow': 0 }),
    'c0.child0': at({ 'rect.width': placedWidth, 'rect.left': placedLeft('start') }),
    'c0.child1': at({ 'rect.width': placedWidth, 'rect.left': placedLeft('center') }),
    'c0.child2': at({ 'rect.width': placedWidth, 'rect.left': placedLeft('end') }),
  },

  // §3.2: an authored width asks for the width as well as limiting it — "fill the space there is,
  // up to this". In a flex ROW that is not a no-op: `flex-basis: auto` takes the item's starting
  // size from `width`, so it sizes items and can wrap them. Every number below is the flexbox
  // algorithm's, from the available space, the 576px of `width.narrow` and the default 40px gap.
  'width-in-flex-row': {
    'c0.root': at({ 'doc.overflow': 0 }),
    // nowrap, basis auto: each starts at 100% capped to 576; two never fit, and being identical
    // they shrink to equal shares of what the gap leaves.
    'c1.child0': at({ 'rect.width': narrowShare }),
    'c1.child1': at({ 'rect.width': narrowShare }),
    // wrap: each line holds one — the second item starts a new line, a row gap below the first.
    'c2.child0': at({ 'rect.width': narrowFill }),
    'c2.child1': at({ 'rect.width': narrowFill, 'gap.above': GAP }),
    // An explicit basis is the starting size instead of the width; the maximum does not bind.
    'c3.child0': at({ 'rect.width': (width) => CONTENT[width] / 3 }),
    'c3.child1': at({ 'rect.width': (width) => CONTENT[width] / 3 }),
    // Reset at md: both declarations go (`width` and `max-width`), and the item is its text again.
    'c4.child0': at({ 'rect.width': narrowFill }, { 'rect.width': { below: 120 } }),
  },

  // A shortcode's look is its `content` target: the pill, not the wrapper. The point a PHP test
  // cannot make is the cascade — the settings are in the layer above the theme and WIN over the
  // pill's own background, radius and (absent) border — and that the dot is drawn in the text's
  // colour. `site.version` is null in a fixture, so this is the muted "development checkout" pill.
  'shortcode-content-target': {
    // The wrapper is never painted: a background there would be a bar across the page.
    'c0.child0': at({ 'background-color': 'rgba(0, 0, 0, 0)', 'box-shadow': 'none', 'border-top-width': '0px' }),
    'c0.child0>.thallo-shortcode-version': at({
      'background-color': 'rgb(255, 255, 255)', // color.background, over the theme's accent tint
      color: 'rgb(15, 23, 42)', // color.text, over the theme's muted
      'border-top-width': '1px', // a width alone shows: the theme gives it a style and a colour
      'border-top-style': 'solid',
      'border-top-left-radius': '6px', // radius.sm, over the theme's 999px
      'box-shadow': /^(?!none)/,
    }),
    'c0.child0>.thallo-shortcode-version::before': at({ 'background-color': 'rgb(15, 23, 42)' }),
    // Untouched: exactly the theme's pill.
    'c0.child1>.thallo-shortcode-version': at({
      color: 'rgb(100, 116, 139)',
      'border-top-width': '0px',
      'border-top-left-radius': '999px',
      'box-shadow': 'none',
    }),
    'c0.child1>.thallo-shortcode-version::before': at({ 'background-color': 'rgb(100, 116, 139)' }),
    // params.dot_color names the dot apart from the text.
    'c0.child2>.thallo-shortcode-version': at({ color: 'rgb(15, 23, 42)' }),
    'c0.child2>.thallo-shortcode-version::before': at({ 'background-color': 'rgb(37, 99, 235)' }),
  },

  // A shell snippet as a terminal. What only a browser can show: the prompt is DRAWN (generated
  // content, so neither copied nor selected) in the accent; an empty line, a block holding nothing
  // but its newline, still has a line's height; one line is one line high — the newline inside a
  // line adds none; and a long command wraps instead of scrolling the page or the snippet.
  'code-shell-lines': {
    'c0.root': at({ 'doc.overflow': 0 }),
    'c0.child0>.thallo-block-code__panel': at({ 'background-color': 'rgb(255, 255, 255)' }),
    'c0.child0>.thallo-block-code__caption': at({ 'background-color': 'rgb(246, 247, 249)' }),
    'c0.child0>.thallo-block-code__pre': at({ 'white-space': 'pre-wrap' }),
    'c0.child0>.thallo-block-code__line--prompt::before': at({ content: '"$ "', color: 'rgb(37, 99, 235)' }),
    'c0.child0>.thallo-block-code__line--comment': at({ color: 'rgb(100, 116, 139)', 'rect.height': LINE }),
    // The fourth line is empty.
    'c0.child0>.thallo-block-code__line:nth-child(4)': at({ 'rect.height': LINE }),
    // The long command: more than one line high on a phone, exactly one where it fits.
    // Wrapping follows the width of the screen, not a breakpoint: only the phone is too narrow.
    'c0.child0>.thallo-block-code__line--prompt': at({
      'rect.height': (width) => (width === 375 ? { above: LINE + 1 } : LINE),
    }),
    // Five lines are five lines high, plus the snippet's padding: a line's own newline adds none,
    // and there is no sixth, empty line after the last.
    'c0.child0>.thallo-block-code__pre': at({ 'rect.height': (width) => (width === 375 ? { above: 5 * LINE + 2 * 16 } : 5 * LINE + 2 * 16) }),
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
      // An address may go on into the element: `c0.child0>.selector`, and `::before` for what
      // the theme draws there. A block's look can belong to an inner element — a shortcode's pill
      // inside its page-measure wrapper — and that element is then what has to be measured.
      const match = /^c(\d+)\.(root|inner|child(\d+))(?:>(.+?))?(::before)?$/.exec(address);
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
      if (!match[4]) return child;
      const within = child.querySelector(match[4]);
      if (!within) throw new Error(`${address}: nothing matches ${match[4]}`);
      return within;
    };
    const pseudoOf = (address) => (address.endsWith('::before') ? '::before' : null);
    const out = {};
    for (const [address, properties] of Object.entries(requests)) {
      const el = locate(address);
      const style = getComputedStyle(el, pseudoOf(address));
      const rect = el.getBoundingClientRect();
      const values = {};
      // Distances the contract speaks of but no single element's style holds.
      const box = (node) => unwrap(node).getBoundingClientRect();
      const area = () => {
        const parent = el.closest('.thallo-block-container__inner');
        const r = parent.getBoundingClientRect();
        const s = getComputedStyle(parent);
        return { top: r.top + parseFloat(s.paddingTop), bottom: r.bottom - parseFloat(s.paddingBottom) };
      };
      const slotChild = () => (el.parentElement.classList.contains('thallo-preview-block') ? el.parentElement : el);
      const derived = {
        'gap.above': () => {
          const previous = slotChild().previousElementSibling;
          return round(rect.top - (previous ? box(previous).bottom : area().top));
        },
        'gap.below': () => round(area().bottom - rect.bottom),
        'doc.overflow': () =>
          Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
      };
      for (const property of properties) {
        if (derived[property]) values[property] = derived[property]();
        else if (property.startsWith('rect.')) values[property] = round(rect[property.slice(5)]);
        else values[property] = style.getPropertyValue(property);
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

test('nothing of the stage reaches a public page', () => {
  // The grid outline, the slot marking it hangs from and the placeholder are the design stage's
  // (container-layout spec §11.2): canvas only, never part of what a visitor is served.
  for (const name of names) {
    const html = fs.readFileSync(path.join(FIXTURES, `${name}.html`), 'utf8');
    for (const marker of ['thallo-grid-outline', 'data-thallo-slot', 'thallo-slot-placeholder']) {
      expect(html.includes(marker), `${name}.html carries ${marker}`).toBe(false);
    }
  }
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
            } else if (value && typeof value === 'object' && 'above' in value) {
              // Wrapped: the contract says it took more than one line, not how many.
              expect(actual, where).toBeGreaterThan(value.above);
            } else if (value && typeof value === 'object' && 'below' in value) {
              // Content-sized: the contract says it is no longer the authored width, not how wide
              // two letters of the theme's heading face are.
              expect(actual, where).toBeLessThan(value.below);
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
      // Flex and Grid only (spec §11.1): the content area is never a block box.
      expect(measured.innerDisplay, `${at} inner display`).toBe('flex');
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
