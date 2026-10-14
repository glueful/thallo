// A Container's overlay, as a browser draws it on a real published page (scripts/build-overlay-
// fixtures): the Overlay field alone covers the whole container at every width; the Style tab's
// overlay sets it per width — dark on a phone and gone on a wide screen — and wins over the field;
// a container inside an overlaid one does not take its parent's overlay; set to Colour, it takes the
// colour chosen from the palette.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/container-overlay/page.html';
const PHONE = { width: 390, height: 800 };
const WIDE = { width: 1200, height: 800 };

/** Each container by the words it holds (a public page carries no block ids). */
const WORDS = {
  ovfield: 'The Overlay field alone.',
  ovnest: 'A container inside it',
  ovstyle: 'Dark on phones',
  ovboth: 'The field dark',
  ovcolor: 'Colour, the accent',
  ovcolornest: 'Colour inside it',
};

/** The overlay layer drawn for the container `id`. */
const overlay = (p, id) => p.evaluate((words) => {
  const text = [...document.querySelectorAll('p')].find((el) => el.textContent.startsWith(words));
  const root = text.closest('.thallo-block-container');
  const el = root.querySelector(':scope > .thallo-block-container__overlay');
  const cs = getComputedStyle(el);
  const box = el.getBoundingClientRect();
  const frame = root.getBoundingClientRect();
  return {
    background: cs.backgroundColor,
    opacity: cs.opacity,
    fills: Math.abs(box.width - frame.width) < 1 && Math.abs(box.height - frame.height) < 1,
  };
}, WORDS[id]);

test.beforeEach(async ({ page: p }) => {
  await p.goto(PAGE);
});

test('the Overlay field alone covers the whole container at every width', async ({ page: p }) => {
  for (const size of [PHONE, WIDE]) {
    await p.setViewportSize(size);
    const o = await overlay(p, 'ovfield');
    expect(o.background).toBe('rgb(0, 0, 0)');
    expect(o.opacity).toBe('0.75');
    expect(o.fills).toBe(true);
  }
});

test("the Style tab's overlay is dark on a phone and gone from lg", async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  const phone = await overlay(p, 'ovstyle');
  expect(phone.background).toBe('rgb(0, 0, 0)');
  expect(phone.opacity).toBe('0.5');
  expect(phone.fills).toBe(true);

  await p.setViewportSize(WIDE);
  expect((await overlay(p, 'ovstyle')).background).toBe('rgba(0, 0, 0, 0)');
});

test("the Style tab's overlay at a width wins over the Overlay field", async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  expect((await overlay(p, 'ovboth')).background).toBe('rgb(0, 0, 0)');
  await p.setViewportSize(WIDE);
  expect((await overlay(p, 'ovboth')).background).toBe('rgba(0, 0, 0, 0)');
});

test("a container inside an overlaid one does not take its parent's overlay", async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  expect((await overlay(p, 'ovnest')).background).toBe('rgba(0, 0, 0, 0)');
  await p.setViewportSize(WIDE);
  expect((await overlay(p, 'ovnest')).background).toBe('rgb(255, 255, 255)');
});

test('an overlay set to Colour takes the colour chosen from the palette', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  const accent = await p.evaluate(() => {
    const probe = document.createElement('div');
    probe.style.color = 'var(--t-color-accent)';
    document.body.append(probe);
    return getComputedStyle(probe).color;
  });
  expect(accent).not.toBe('rgba(0, 0, 0, 0)');
  const o = await overlay(p, 'ovcolor');
  expect(o.background).toBe(accent);
  expect(o.fills).toBe(true);
  // A container inside it set to Colour, with no colour of its own, takes none of its parent's.
  expect((await overlay(p, 'ovcolornest')).background).toBe('rgba(0, 0, 0, 0)');
});
