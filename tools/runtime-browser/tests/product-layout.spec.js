// The shop's product page in a real browser (type layouts plan C1).
//
// `references/product-original.json` froze today's product page (`shop/product.twig`) before any
// C1 template or stylesheet change (scripts/capture-product-layout-reference). Every later
// rendering of the page without a layout is held to it here, with the same measuring procedure,
// so the page keeps its look however the templates behind it are reorganised.
//
// The product layout's starter is held to the same reference, with exactly the differences the user
// accepted (type layouts plan C1): equal desktop columns, a 2.5rem gallery-to-information gap on
// desktop and mobile, and the information column's 0.5rem gap unchanged. What follows from them —
// the columns' lefts and widths, the cover's 4:3 height at the new width, and what sits below it —
// is relaxed and then asserted as a consequence; everything else is compared.
//
// Values authored on a product block win over the shop's defaults on the page and on the stage, and
// removing them returns the defaults.
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

// ---------------------------------------------------------------------------------------------
// The starter against the frozen page.

const { measurePage } = require('../scripts/measure.js');

const INFO_PARTS = [
  'category', 'name', 'rating', 'price', 'priceCurrent', 'priceCompare', 'description', 'buy', 'form',
  'stepper', 'submit', 'availability',
];
const COLUMN_PARTS = ['media', 'cover', 'thumbs', 'info', ...INFO_PARTS];
const CONTENT_SIZED = ['priceCurrent', 'priceCompare', 'stepper'];
const ONE_COLUMN = [375, 700];
const TWO_COLUMNS = [800, 1280];
const GAP = 40; // 2.5rem, the accepted gallery-to-information gap
const TODAY_GAP = 32; // 2rem, today's
const NEAR = 1;

// The starter's two columns are the inner containers that hold the gallery and the name (on the
// stage each block sits in a selection wrapper, so they are found by what they contain).
const STARTER_MAP = {
  ...Object.fromEntries(Object.entries(DEFINITION.elements).map(([name, spec]) => [name, spec.selector])),
  media: '.thallo-block-container__inner .thallo-block-container:has(.shop-product__gallery)',
  info: '.thallo-block-container__inner .thallo-block-container:has(.shop-product__name)',
};

// Relax only what the accepted geometry moves; each relaxed value is asserted below. One more row,
// not geometry: in the frame the price's root carries the amount's size and weight — and so its own
// line height (so a size set on the Product price block reaches it); the amounts and every box are
// today's.
function acceptedRows() {
  return [
    {
      element: 'price',
      relax: ['font-size', 'font-weight', 'line-height'],
      expect: { 'font-size': '20px', 'font-weight': '700', 'line-height': '30px' },
    },
    ...[...INFO_PARTS, 'info', 'story'].map((element) => ({ element, relax: ['top'], widths: ONE_COLUMN })),
    ...COLUMN_PARTS.map((element) => ({ element, relax: ['left', 'width'], widths: TWO_COLUMNS })),
    ...['media', 'cover'].map((element) => ({ element, relax: ['height'], widths: TWO_COLUMNS })),
    ...['thumbs', 'story'].map((element) => ({ element, relax: ['top'], widths: TWO_COLUMNS })),
  ];
}

function geometry(measured, width, name) {
  const entry = measured[String(width)][name];
  expect(entry && entry.geometry, `${name} measured at ${width}px`).toBeTruthy();
  return entry.geometry;
}

const near = (actual, expected, what) =>
  expect(Math.abs(actual - expected), `${what}: ${actual} vs ${expected}`).toBeLessThanOrEqual(NEAR);

test("the starter matches today's page except for the accepted geometry", async ({ page }) => {
  const definition = { ...DEFINITION, map: STARTER_MAP, allow: acceptedRows() };
  const failures = await compareToReference(page, REFERENCE, definition, `${URL_BASE}/starter.html`);
  expect(failures, failures.join('\n')).toEqual([]);

  const elements = {};
  const profiles = {};
  for (const [name, spec] of Object.entries(DEFINITION.elements)) {
    elements[name] = STARTER_MAP[name] ?? spec.selector;
    profiles[name] = ['geometry'];
  }
  const starter = await measurePage(page, `${URL_BASE}/starter.html`, elements, profiles, DEFINITION.root);
  const today = REFERENCE.widths;

  for (const width of ONE_COLUMN) {
    // The accepted gap: 2.5rem between the gallery and the information column, one column.
    const media = geometry(starter, width, 'media');
    near(geometry(starter, width, 'info').top - (media.top + media.height), GAP, `gap @${width}`);
    const todayMedia = today[String(width)].media.geometry;
    near(today[String(width)].info.geometry.top - (todayMedia.top + todayMedia.height), TODAY_GAP, `today's gap @${width}`);
    // Everything below it moves down by exactly the difference, and nothing else moves.
    for (const name of [...INFO_PARTS, 'info', 'story']) {
      near(geometry(starter, width, name).top, today[String(width)][name].geometry.top + (GAP - TODAY_GAP), `${name} top @${width}`);
    }
  }

  for (const width of TWO_COLUMNS) {
    const media = geometry(starter, width, 'media');
    const info = geometry(starter, width, 'info');
    // The accepted columns: equal, 2.5rem apart (today 1.05fr / 1fr, 2rem).
    near(media.width, info.width, `equal columns @${width}`);
    near(info.left - (media.left + media.width), GAP, `column gap @${width}`);
    const t = today[String(width)];
    near(t.info.geometry.left - (t.media.geometry.left + t.media.geometry.width), TODAY_GAP, `today's gap @${width}`);
    // The cover keeps its 4:3 at the new width, the thumbnails follow it, the gallery wraps both.
    const cover = geometry(starter, width, 'cover');
    near(cover.height, cover.width * 0.75, `4:3 cover @${width}`);
    const thumbs = geometry(starter, width, 'thumbs');
    near(thumbs.top - (cover.top + cover.height), t.thumbs.geometry.top - (t.cover.geometry.top + t.cover.geometry.height), `thumbs below the cover @${width}`);
    near(thumbs.width, media.width, `thumbs span the gallery @${width}`);
    // Inside the information column every part keeps its offset from the column's left edge; a part
    // the column sizes keeps its distance from the right edge, one its content sizes keeps its width.
    for (const name of INFO_PARTS) {
      const part = geometry(starter, width, name);
      const was = t[name].geometry;
      near(part.left - info.left, was.left - t.info.geometry.left, `${name} left in its column @${width}`);
      if (CONTENT_SIZED.includes(name)) {
        near(part.width, was.width, `${name} keeps its width @${width}`);
      } else {
        near(info.width - part.width, t.info.geometry.width - was.width, `${name} width in its column @${width}`);
      }
    }
    // The story sits as far below the taller column as it does today.
    const story = geometry(starter, width, 'story');
    const bottom = (g) => g.top + g.height;
    const todayBottom = Math.max(bottom(t.media.geometry), bottom(t.info.geometry));
    near(story.top - Math.max(bottom(media), bottom(info)), t.story.geometry.top - todayBottom, `story below @${width}`);
  }
});

test("the information column keeps today's 0.5rem gap, and each part's own margin on top of it", async ({ page }) => {
  for (const [file, selector] of [
    ['original.html', '.shop-product__info'],
    ['starter.html', `${STARTER_MAP.info} > .thallo-block-container__inner`],
    ['starter-stage.html', `${STARTER_MAP.info} > .thallo-block-container__inner`],
  ]) {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${URL_BASE}/${file}`);
    const gap = await page.evaluate((s) => getComputedStyle(document.querySelector(s)).rowGap, selector);
    expect(gap, `${file}: the column's gap`).toBe('8px');
  }
  // The visible distance between consecutive parts — the gap plus the part's margin — is today's.
  const order = ['category', 'name', 'rating', 'price', 'description', 'buy', 'availability'];
  const elements = Object.fromEntries(order.map((n) => [n, STARTER_MAP[n] ?? DEFINITION.elements[n].selector]));
  const profiles = Object.fromEntries(order.map((n) => [n, ['geometry']]));
  const starter = await measurePage(page, `${URL_BASE}/starter.html`, elements, profiles, DEFINITION.root);
  for (const width of [...ONE_COLUMN, ...TWO_COLUMNS]) {
    for (let i = 0; i + 1 < order.length; i++) {
      const [a, b] = [order[i], order[i + 1]];
      const distance = (m) => m[b].geometry.top - (m[a].geometry.top + m[a].geometry.height);
      near(distance(starter[String(width)]), distance(REFERENCE.widths[String(width)]), `${a} → ${b} @${width}`);
    }
  }
});

test('the stage shows the starter as the site serves it', async ({ page }) => {
  const profiles = Object.fromEntries(Object.entries(DEFINITION.elements).map(([n, spec]) => [n, spec.profiles]));
  const served = await measurePage(page, `${URL_BASE}/starter.html`, STARTER_MAP, profiles, DEFINITION.root);
  const reference = { case: 'starter (page)', widths: served };
  const failures = await compareToReference(
    page,
    reference,
    { ...DEFINITION, elements: DEFINITION.elements, map: STARTER_MAP },
    `${URL_BASE}/starter-stage.html`,
  );
  expect(failures, failures.join('\n')).toEqual([]);
});

// ---------------------------------------------------------------------------------------------
// Authored values win; removing them returns the defaults.

/** The values the authored test reads, with each authored token resolved in the page itself. */
async function styles(page, file) {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${URL_BASE}/${file}`);
  await page.evaluate(() => document.fonts && document.fonts.ready);
  return page.evaluate(() => {
    const css = (s) => getComputedStyle(document.querySelector(s));
    const resolve = (property, value, beside) => {
      const probe = document.createElement('span');
      probe.style[property] = value;
      document.querySelector(beside).parentElement.appendChild(probe);
      const out = getComputedStyle(probe)[property];
      probe.remove();
      return out;
    };
    const name = css('.shop-product__name');
    const price = css('.shop-product__price-current');
    const compare = css('.shop-product__price-compare');
    const rating = css('.shop-product__rating');
    const description = css('.shop-product__description');
    return {
      nameSize: name.fontSize,
      nameColor: name.color,
      priceColor: price.color,
      priceSize: price.fontSize,
      priceWeight: price.fontWeight,
      compareSize: compare.fontSize,
      ratingSize: rating.fontSize,
      starWidth: document.querySelector('.shop-product__star').getBoundingClientRect().width,
      descriptionPadding: [description.paddingTop, description.paddingBottom],
      descriptionMargin: description.marginBottom,
      tokens: {
        size3xl: resolve('fontSize', 'var(--t-typography-size-3xl)', '.shop-product__name'),
        accent: resolve('color', 'var(--t-color-accent)', '.shop-product__name'),
        muted: resolve('color', 'var(--t-color-muted)', '.shop-product__price-current'),
        size2xl: resolve('fontSize', 'var(--t-typography-size-2xl)', '.shop-product__price'),
        sizeLg: resolve('fontSize', 'var(--t-typography-size-lg)', '.shop-product__rating'),
        lg: resolve('paddingTop', 'var(--t-spacing-lg)', '.shop-product__description'),
      },
    };
  });
}

for (const surface of ['', '-stage']) {
  const where = surface === '' ? 'on the page' : 'on the stage';

  test(`authored settings win over the product defaults ${where}`, async ({ page }) => {
    const authored = await styles(page, `authored${surface}.html`);
    expect(authored.nameSize).toBe(authored.tokens.size3xl);
    expect(authored.nameColor).toBe(authored.tokens.accent);
    expect(authored.priceColor).toBe(authored.tokens.muted);
    expect(authored.descriptionPadding).toEqual([authored.tokens.lg, authored.tokens.lg]);
    expect(authored.descriptionMargin).toBe('0px');
    // A size and weight set on the price reach the amount; the "was" price keeps its proportion.
    expect(authored.priceSize).toBe(authored.tokens.size2xl);
    expect(authored.priceWeight).toBe('400');
    expect(parseFloat(authored.compareSize)).toBeCloseTo(parseFloat(authored.tokens.size2xl) * 0.8, 1);
    // A size set on the rating scales its stars with its text.
    expect(authored.ratingSize).toBe(authored.tokens.sizeLg);
    expect(authored.starWidth).toBeCloseTo(parseFloat(authored.tokens.sizeLg), 0);

    // The starter shows the product defaults: 1.75rem name, the description's 1.5rem margin.
    const starter = await styles(page, `starter${surface}.html`);
    expect(starter.nameSize).toBe('28px');
    expect(starter.nameSize).not.toBe(authored.nameSize);
    expect(starter.nameColor).not.toBe(authored.nameColor);
    expect(starter.descriptionMargin).toBe('24px');
    expect(starter.descriptionPadding).toEqual(['0px', '0px']);
    expect([starter.priceSize, starter.priceWeight, starter.compareSize]).toEqual(['20px', '700', '16px']);
    expect([starter.ratingSize, starter.starWidth]).toEqual(['14px', 14]);
  });

  test(`removing an authored value returns the default ${where}`, async ({ page }) => {
    const reset = await styles(page, `reset${surface}.html`);
    const starter = await styles(page, `starter${surface}.html`);
    const { tokens: _r, ...resetValues } = reset;
    const { tokens: _s, ...starterValues } = starter;
    expect(resetValues).toEqual(starterValues);
  });
}

// ---------------------------------------------------------------------------------------------
// Blocks placed beside the starter keep the theme's spacing: the frame resets only the product
// blocks at its top level (and the starter's container sets its own margins to none).

/** Each added block's margins, beside a clone of it outside the product page: the theme's own. */
async function addedSpacing(page, file) {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${URL_BASE}/${file}`);
  await page.evaluate(() => document.fonts && document.fonts.ready);
  return page.evaluate(() => {
    const frame = document.querySelector('.shop-product--layout');
    const margins = (el) => {
      const css = getComputedStyle(el);
      return [css.marginTop, css.marginBottom];
    };
    const out = {};
    for (const type of ['heading', 'rich_text', 'image']) {
      const el = frame.querySelector(`.thallo-block-${type}`);
      const clone = el.cloneNode(true);
      document.body.appendChild(clone);
      out[type] = { inFrame: margins(el), theme: margins(clone) };
      clone.remove();
    }
    return out;
  });
}

/** Where the starter's breadcrumb and grid sit, and the grid's margins. */
async function starterGeometry(page, file) {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${URL_BASE}/${file}`);
  await page.evaluate(() => document.fonts && document.fonts.ready);
  return page.evaluate(() => {
    const frame = document.querySelector('.shop-product--layout');
    // The outer grid: the container holding the container that holds the name.
    const grid = frame.querySelector('.thallo-block-container:has(.thallo-block-container .shop-product__name)');
    const css = getComputedStyle(grid);
    return {
      breadcrumb: frame.querySelector('.shop-product__breadcrumb').getBoundingClientRect().top,
      grid: grid.getBoundingClientRect().top,
      gridMargins: [css.marginTop, css.marginBottom],
    };
  });
}

for (const surface of ['', '-stage']) {
  const where = surface === '' ? 'on the page' : 'on the stage';

  test(`a heading, text and an image added beside the starter keep their spacing ${where}`, async ({ page }) => {
    const added = await addedSpacing(page, `added${surface}.html`);
    for (const type of ['heading', 'rich_text', 'image']) {
      expect(added[type].inFrame, `${type} keeps the theme's margins`).toEqual(added[type].theme);
      expect(parseFloat(added[type].inFrame[0]), `${type} has a top margin to keep`).toBeGreaterThan(0);
    }
    // The starter above them is where it was, its grid flush.
    const beside = await starterGeometry(page, `added${surface}.html`);
    const alone = await starterGeometry(page, `starter${surface}.html`);
    expect(beside.gridMargins).toEqual(['0px', '0px']);
    expect(beside.breadcrumb).toBeCloseTo(alone.breadcrumb, 0);
    expect(beside.grid).toBeCloseTo(alone.grid, 0);
  });
}
