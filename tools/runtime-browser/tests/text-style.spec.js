// Letter spacing, text transform and text decoration (settings version 12), as a browser computes
// them: on the published page and on the editor's canvas stage, both real renders of one entry
// (scripts/build-text-style-fixtures). Each setting is measured against an untouched control:
// a value applies, `none` is an explicit value, a reset gives back exactly what the theme draws —
// at rest and under the pointer — a block's own value beats its style class's, and decoration is
// set by its longhand, so the theme's offset and hover thickness survive an underline.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGES = {
  public: '/tools/runtime-browser/fixtures/text-style/public.html',
  stage: '/tools/runtime-browser/fixtures/text-style/stage.html',
};

// The fixture's blocks, in document order (a published page carries no block ids).
const HEADINGS = ['plain', 'case', 'normal', 'reset', 'class'];
const LINKS = ['plain', 'underline', 'none', 'reset'];
const BUTTONS = ['plain', 'underline', 'none', 'reset'];

const heading = (page, name) => page.locator('main .thallo-block-heading').nth(HEADINGS.indexOf(name));
const link = (page, name) =>
  page.locator('main .thallo-block-links').nth(LINKS.indexOf(name)).locator('.thallo-block-links__link').first();
const button = (page, name) => page.locator('main .thallo-block-button__link').nth(BUTTONS.indexOf(name));

/** What the browser draws for an element: casing, tracking (in em) and its decoration longhands. */
const drawn = (locator) =>
  locator.evaluate((el) => {
    const cs = getComputedStyle(el);
    const size = parseFloat(cs.fontSize);
    return {
      transform: cs.textTransform,
      spacing: cs.letterSpacing === 'normal' ? 'normal' : Math.round((parseFloat(cs.letterSpacing) / size) * 1000) / 1000,
      line: cs.textDecorationLine,
      thickness: cs.textDecorationThickness,
      offset: cs.textUnderlineOffset,
    };
  });

/** Drawn under the pointer, once any transition has settled. */
async function hovered(page, locator) {
  await locator.hover();
  let last;
  await expect
    .poll(async () => {
      const now = JSON.stringify(await drawn(locator));
      const settled = now === last;
      last = now;
      return settled;
    }, { intervals: [100, 100, 200, 400] })
    .toBe(true);
  await page.mouse.move(0, 0);
  return JSON.parse(last);
}

for (const [where, url] of Object.entries(PAGES)) {
  test.describe(`${where} rendering`, () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(url);
      await expect(page.locator('main .thallo-block-heading')).toHaveCount(HEADINGS.length);
      await expect(page.locator('main .thallo-block-links')).toHaveCount(LINKS.length);
      await expect(page.locator('main .thallo-block-button__link')).toHaveCount(BUTTONS.length);
    });

    test('a heading takes its casing and tracking, and neither brings the other', async ({ page }) => {
      const plain = await drawn(heading(page, 'plain'));
      expect(plain.transform).toBe('none');
      expect(plain.spacing).toBe(-0.02); // the theme's heading tracking
      const cased = await drawn(heading(page, 'case'));
      expect(cased.transform).toBe('uppercase');
      expect(cased.spacing).toBe(0.1);
      expect(cased.line).toBe('none');
    });

    test("Normal overrides the theme's tracking; a reset gives it back", async ({ page }) => {
      const plain = await drawn(heading(page, 'plain'));
      expect((await drawn(heading(page, 'normal'))).spacing).toBe('normal');
      expect((await drawn(heading(page, 'normal'))).transform).toBe(plain.transform);
      expect(await drawn(heading(page, 'reset'))).toEqual(plain);
    });

    test("a block's own casing beats its style class; the class's other values stay", async ({ page }) => {
      const classed = await drawn(heading(page, 'class'));
      expect(classed.transform).toBe('lowercase'); // the block's, over the class's uppercase
      expect(classed.spacing).toBe(0.05); // the class's Wide
      expect(classed.line).toBe('underline'); // the class's underline
    });

    test("a Links link's underline holds at rest and under the pointer", async ({ page }) => {
      expect((await drawn(link(page, 'plain'))).line).toBe('none'); // the theme's
      expect((await drawn(link(page, 'underline'))).line).toBe('underline');
      expect((await hovered(page, link(page, 'underline'))).line).toBe('underline');
    });

    test("a Links link's None stays none, and its reset draws what the theme draws", async ({ page }) => {
      expect((await drawn(link(page, 'none'))).line).toBe('none');
      expect((await hovered(page, link(page, 'none'))).line).toBe('none');
      expect(await drawn(link(page, 'reset'))).toEqual(await drawn(link(page, 'plain')));
      expect(await hovered(page, link(page, 'reset'))).toEqual(await hovered(page, link(page, 'plain')));
    });

    test("over a theme that underlines, None removes it and a reset restores it", async ({ page }) => {
      const plain = await drawn(button(page, 'plain'));
      const plainHover = await hovered(page, button(page, 'plain'));
      expect(plain.line).toBe('underline'); // the link variant's own underline
      expect(plainHover.thickness).toBe('2px');
      expect((await drawn(button(page, 'none'))).line).toBe('none');
      expect((await hovered(page, button(page, 'none'))).line).toBe('none');
      expect(await drawn(button(page, 'reset'))).toEqual(plain);
      expect(await hovered(page, button(page, 'reset'))).toEqual(plainHover);
    });

    test("an underline is set by its longhand: the theme's offset and hover thickness survive", async ({ page }) => {
      const plain = await drawn(button(page, 'plain'));
      const under = await drawn(button(page, 'underline'));
      expect(under).toEqual(plain);
      expect(under.offset).toBe('3px');
      expect(await hovered(page, button(page, 'underline'))).toEqual(await hovered(page, button(page, 'plain')));
    });
  });
}
