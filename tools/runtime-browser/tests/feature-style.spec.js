// A Feature's Style settings as a browser draws them (scripts/build-text-style-fixtures): the space
// between the marker and the text is one setting beside and above it (and the untouched spacing is
// what it was); the marker's size, colour and background beat the Marker content fields; a marker
// background gets the chip's padding; the description takes its own size and space above it; a number
// badge takes its Number section's typeface, style, weight and transform; a headline sits above the
// title, spaced out in the accent, and takes its Headline section; the marker takes its own padding.
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

const numbered = (page, i) =>
  page.locator('main .thallo-block-feature').nth(IDS.length + 3 + i).locator('.thallo-block-feature__marker');

test("a number badge takes its Number section's typeface, style, weight and transform", async ({ page }) => {
  const lettering = (loc) =>
    loc.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { family: cs.fontFamily, style: cs.fontStyle, weight: cs.fontWeight, transform: cs.textTransform };
    });
  const [plain, styled] = [await lettering(numbered(page, 0)), await lettering(numbered(page, 1))];
  expect(styled.style).toBe('italic');
  expect(styled.weight).toBe('700');
  expect(styled.transform).toBe('uppercase');
  expect(styled.family).not.toBe(plain.family);
  expect(plain).toMatchObject({ style: 'normal', transform: 'none' });
});

const nth = (page, i) => page.locator('main .thallo-block-feature').nth(IDS.length + 5 + i);
const headline = (loc) =>
  loc.evaluate((el) => {
    const h = el.querySelector('.thallo-block-feature__headline');
    const cs = getComputedStyle(h);
    return {
      text: h.textContent.trim(),
      transform: cs.textTransform,
      spacing: cs.letterSpacing,
      colour: cs.color,
      size: cs.fontSize,
      below: parseFloat(cs.marginBottom),
      bottom: h.getBoundingClientRect().bottom,
      titleTop: el.querySelector('.thallo-block-feature__title').getBoundingClientRect().top,
    };
  });

test('a headline sits above the title, small, spaced out and uppercase in the accent', async ({ page }) => {
  const r = await rem(page);
  const h = await headline(nth(page, 0));
  expect(h.text).toBe('Online store');
  expect(h.transform).toBe('uppercase');
  expect(parseFloat(h.spacing)).toBeGreaterThan(0);
  expect(h.colour).toBe(await probe(page, 'color', 'var(--accent)'));
  expect(parseFloat(h.size)).toBeCloseTo(0.75 * r, 1);
  expect(Math.round(h.below)).toBe(Math.round(0.5 * r));
  expect(h.bottom).toBeLessThanOrEqual(h.titleTop + 0.5);
});

test('a headline takes its Headline section: transform, letter spacing, colour and space below', async ({ page }) => {
  const r = await rem(page);
  const h = await headline(nth(page, 1));
  expect(h.transform).toBe('none');
  expect(h.spacing).toBe('normal');
  expect(h.colour).toBe(await probe(page, 'color', 'var(--t-color-muted)'));
  expect(Math.round(h.below)).toBe(Math.round(1.5 * r));
  expect(Math.round(h.titleTop - h.bottom)).toBe(Math.round(1.5 * r));
});

const chip = (page, i) =>
  nth(page, 2 + i)
    .locator('.thallo-block-feature__marker')
    .evaluate((el) => {
      const cs = getComputedStyle(el);
      const box = el.getBoundingClientRect();
      return {
        padding: [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft].map(parseFloat),
        width: box.width,
        height: box.height,
      };
    });

test("the marker's Padding is the room on all four sides of the chip, and the chip grows with it", async ({ page }) => {
  const r = await rem(page);
  const [plain, padded] = [await chip(page, 0), await chip(page, 1)];
  expect(plain.padding.map(Math.round)).toEqual(Array(4).fill(Math.round(0.5 * r)));
  expect(padded.padding.map(Math.round)).toEqual(Array(4).fill(Math.round(1.5 * r)));
  expect(Math.round(padded.width - plain.width)).toBe(Math.round(2 * r));
  expect(Math.round(padded.height - plain.height)).toBe(Math.round(2 * r));
});

test('a number badge with a Padding is its number and that padding, all round', async ({ page }) => {
  const r = await rem(page);
  const badge = await chip(page, 2);
  expect(badge.padding.map(Math.round)).toEqual(Array(4).fill(Math.round(1.5 * r)));
  // Taller than the fixed 2.5rem badge: the line of text plus 1.5rem above and below.
  expect(badge.height).toBeGreaterThan(3 * r + 0.5 * r);
});
