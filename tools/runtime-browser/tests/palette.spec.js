// The custom palette (custom palette spec §3, §5.3) as a browser computes it, on the published page and
// on the editor's canvas stage, both real renders of one entry (scripts/build-palette-fixtures): a
// brand colour paints its hex in light mode and a derived value in dark mode, with the black or white
// text the server picked; an id above three (Sky, 5) paints from the workspace's colours stylesheet;
// a hover colour naming a cleared colour leaves the resting colour on hover, focus
// and the stage's forced preview ([data-thallo-hover]); a heading naming an unset slot keeps the colour
// it had without that setting; and a Custom neutral paints the cream ground and Surface 2.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGES = {
  public: '/tools/runtime-browser/fixtures/palette/public.html',
  stage: '/tools/runtime-browser/fixtures/palette/stage.html',
};

const rgb = (hex) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgb(${n >> 16}, ${(n >> 8) & 255}, ${n & 255})`;
};

/** The fixture's Button link inside the block anchored `id`. */
const link = (page, id) => page.locator(`#${id} a, #${id} button`).first();

test.describe('custom palette', () => {
  test('Brand 1 paints its hex in light mode, a derived value in dark mode, and its text colour on it', async ({ page }) => {
    await page.goto(PAGES.public);
    const btn = link(page, 'brand');
    await expect(btn).toHaveCSS('background-color', rgb('#8a6a2a'));
    const ink = await btn.evaluate((el) => getComputedStyle(el).color);
    expect([rgb('#000000'), rgb('#ffffff')]).toContain(ink);
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    // the button transitions its colours: wait for them to settle
    await expect(btn).not.toHaveCSS('background-color', rgb('#8a6a2a'));
    await expect
      .poll(() => btn.evaluate((el) => getComputedStyle(el).color))
      .toMatch(/^rgb\((0, 0, 0|255, 255, 255)\)$/);
  });

  test('a brand colour above three paints from the colours stylesheet, on the page and the stage', async ({ page }) => {
    for (const url of [PAGES.public, PAGES.stage]) {
      await page.goto(url);
      await expect(link(page, 'brand-sky')).toHaveCSS('background-color', rgb('#38bdf8'));
    }
  });

  test('a hover colour naming an unset slot keeps the resting colour on hover, focus and the forced preview', async ({ page }) => {
    await page.goto(PAGES.public);
    const btn = link(page, 'hover-unset');
    const resting = await btn.evaluate((el) => getComputedStyle(el).color);
    await btn.hover();
    await expect(btn).toHaveCSS('color', resting);
    await page.mouse.move(0, 0);
    await btn.focus();
    await expect(btn).toHaveCSS('color', resting);

    await page.goto(PAGES.stage);
    const staged = link(page, 'hover-unset');
    const stagedResting = await staged.evaluate((el) => getComputedStyle(el).color);
    expect(stagedResting).toBe(resting);
    await staged.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
    await expect(staged).toHaveCSS('color', resting);
  });

  test('a heading naming an unset slot keeps the colour it had without that setting', async ({ page }) => {
    await page.goto(PAGES.public);
    const heading = page.locator('#heading-unset').locator('xpath=descendant-or-self::*[self::h1 or self::h2 or self::h3]').first();
    const container = await page.locator('#heading-unset').evaluate((el) => getComputedStyle(el.parentElement).color);
    expect(container).toBe(rgb('#6b6156')); // the container's Muted
    await expect(heading).toHaveCSS('color', container);
  });

  test('a Custom neutral paints the cream ground and the Surface 2 band', async ({ page }) => {
    await page.goto(PAGES.public);
    await expect(page.locator('body')).toHaveCSS('background-color', rgb('#f8f4ec'));
    await expect(page.locator('#band')).toHaveCSS('background-color', rgb('#efe7d8'));
  });
});
