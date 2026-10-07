// A Feature's Style settings as a browser draws them (scripts/build-text-style-fixtures): the space
// between the marker and the text is one setting beside and above it (and the untouched spacing is
// what it was); the marker's size, colour and background beat the Marker content fields; a marker
// background gets the chip's padding; the description takes its own size and space above it.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/text-style/public.html';
const IDS = ['plain-beside', 'plain-above', 'styled-beside', 'styled-above'];
const feature = (page, name) => page.locator('main .thallo-block-feature').nth(IDS.indexOf(name));

/** The space between the marker and the body, along the orientation's axis, in px. */
const gap = (loc) =>
  loc.evaluate((el) => {
    const m = el.querySelector('.thallo-block-feature__marker').getBoundingClientRect();
    const b = el.querySelector('.thallo-block-feature__body').getBoundingClientRect();
    return el.classList.contains('thallo-block-feature--vertical') ? b.top - m.bottom : b.left - m.right;
  });
const rem = (page) => page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
const probe = (page, prop, value) =>
  page.evaluate(([p, v]) => {
    const el = document.createElement('span');
    el.style[p] = v;
    document.body.appendChild(el);
    const out = getComputedStyle(el)[p];
    el.remove();
    return out;
  }, [prop, value]);

test.beforeEach(async ({ page }) => {
  await page.goto(PAGE);
});

test('untouched, the marker sits 1rem beside the text and 2rem above it, as before', async ({ page }) => {
  const r = await rem(page);
  expect(Math.round(await gap(feature(page, 'plain-beside')))).toBe(Math.round(r));
  expect(Math.round(await gap(feature(page, 'plain-above')))).toBe(Math.round(2 * r));
});

test('the gap setting is the whole space, beside and above', async ({ page }) => {
  const r = await rem(page);
  // spacing.lg is --space-4, 1.5rem.
  expect(Math.round(await gap(feature(page, 'styled-beside')))).toBe(Math.round(1.5 * r));
  expect(Math.round(await gap(feature(page, 'styled-above')))).toBe(Math.round(1.5 * r));
});

test("the marker's size, colour and background beat the Marker content fields", async ({ page }) => {
  const plain = feature(page, 'plain-beside').locator('.thallo-block-feature__marker');
  const styled = feature(page, 'styled-beside').locator('.thallo-block-feature__marker');
  const look = (loc) =>
    loc.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { size: cs.fontSize, colour: cs.color, background: cs.backgroundColor, padding: cs.paddingTop };
    });
  const [p, s] = [await look(plain), await look(styled)];
  expect(s.size).toBe(await probe(page, 'fontSize', 'var(--t-typography-size-2xl)'));
  expect(s.size).not.toBe(p.size);
  expect(s.colour).toBe(await probe(page, 'color', 'var(--t-color-accent)'));
  expect(s.background).toBe(await probe(page, 'backgroundColor', 'var(--t-color-surface)'));
  // A background is a chip: the glyph gets room around it, as a content background gives it.
  expect(parseFloat(s.padding)).toBeGreaterThan(0);
  expect(parseFloat(p.padding)).toBe(0);
});

test('the description takes its own size and the space above it', async ({ page }) => {
  const r = await rem(page);
  const d = (name) =>
    feature(page, name)
      .locator('.thallo-block-feature__description')
      .evaluate((el) => ({ size: getComputedStyle(el).fontSize, top: parseFloat(getComputedStyle(el).marginTop) }));
  const [plain, styled] = [await d('plain-beside'), await d('styled-beside')];
  expect(styled.size).toBe(await probe(page, 'fontSize', 'var(--t-typography-size-lg)'));
  expect(styled.size).not.toBe(plain.size);
  expect(Math.round(styled.top)).toBe(Math.round(1.5 * r));
  expect(Math.round(plain.top)).toBe(Math.round(0.5 * r));
});

// Align icon: a large icon beside a title and a description.
const aligned = (page, align) => page.locator('main .thallo-block-feature').nth(IDS.length + ['start', 'center', 'end'].indexOf(align));
/** The drawn icon's box and the text's box, in px. */
const boxes = (loc) =>
  loc.evaluate((el) => {
    const box = (r) => ({ top: r.top, bottom: r.bottom, middle: (r.top + r.bottom) / 2 });
    return {
      icon: box(el.querySelector('.thallo-block-feature__marker svg').getBoundingClientRect()),
      title: box(el.querySelector('.thallo-block-feature__title').getBoundingClientRect()),
      body: box(el.querySelector('.thallo-block-feature__body').getBoundingClientRect()),
    };
  });

test('Align icon Start puts the icon level with the top of the title', async ({ page }) => {
  const b = await boxes(aligned(page, 'start'));
  expect(Math.abs(b.icon.top - b.title.top)).toBeLessThanOrEqual(1);
});

test('Align icon Center puts the icon on the middle of the text', async ({ page }) => {
  const b = await boxes(aligned(page, 'center'));
  expect(Math.abs(b.icon.middle - b.body.middle)).toBeLessThanOrEqual(1);
});

test('Align icon End puts the icon level with the bottom of the text', async ({ page }) => {
  const b = await boxes(aligned(page, 'end'));
  expect(Math.abs(b.icon.bottom - b.body.bottom)).toBeLessThanOrEqual(1);
});
