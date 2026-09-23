// The docs sidebar and "On this page" are sticky panels that scroll on their own. Their height is
// computed from the viewport, so it has to allow for everything ABOVE them — main's top padding,
// and a preview banner when one is rendered. Size them from where they STICK instead of where
// they START and the box hangs below the fold until the page is scrolled: the last rows cannot be
// reached, and the panel's own scrolling appears not to work (the reported bug).
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/style-proofs/pages/docs-sticky.html';

async function panelBottoms(page) {
  return page.evaluate(() => {
    window.scrollTo(0, 0);
    const read = (sel) => {
      const el = document.querySelector(sel);
      const r = el.getBoundingClientRect();
      return { belowFold: Math.round(r.bottom - window.innerHeight), scrolls: el.scrollHeight > el.clientHeight };
    };
    return { sidebar: read('.docs__sidebar'), toc: read('.docs__toc') };
  });
}

test.describe('the sticky docs panels fit the viewport before the page is scrolled', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  test('a plain visit', async ({ page }) => {
    await page.goto(PAGE);
    const { sidebar, toc } = await panelBottoms(page);
    expect(sidebar.scrolls).toBe(true); // the case that matters: taller than its box
    expect(sidebar.belowFold).toBeLessThanOrEqual(0);
    expect(toc.belowFold).toBeLessThanOrEqual(0);
  });

  test('a signed-in editor, with the preview banner above the header', async ({ page }) => {
    await page.goto(PAGE + '?banner=1');
    const { sidebar, toc } = await panelBottoms(page);
    expect(sidebar.belowFold).toBeLessThanOrEqual(0);
    expect(toc.belowFold).toBeLessThanOrEqual(0);
  });
});
