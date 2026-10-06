// A block that paints nothing — a Links block with neither title nor links — is stubbed on the
// stage ("Empty links — select it to add content, or delete it") so it can be seen and selected.
// The bridge re-checks after every load, patch, swap, mirror and parent message; the stub it
// paints gives the block a height of its own, which must never read as content: a re-check
// keeps the stub. The stage is a real canvas render (scripts/build-typography-bridge-fixtures)
// with the real preview bridge.
'use strict';

const { test, expect } = require('@playwright/test');

const STAGE = '/tools/runtime-browser/fixtures/typography-bridge/stage.html';
const NONCE = 'empty-block-proof';

async function stage(page) {
  await page.goto(STAGE);
  await page.evaluate((nonce) => {
    window.postMessage({ type: 'thallo:canvas-hello', nonce }, window.location.origin);
  }, NONCE);
}

/** A parent message the bridge answers by re-marking the stage (no structure is on offer). */
async function recheck(page) {
  await page.evaluate((nonce) => {
    window.postMessage({ type: 'thallo:structure-offer', nonce, offers: [] }, window.location.origin);
  }, NONCE);
}

const marked = (page) =>
  page.locator('[data-thallo-block="linksempty01"]').evaluate((w) => ({
    empty: w.hasAttribute('data-thallo-block-empty'),
    label: w.firstElementChild.getAttribute('data-thallo-empty-label'),
    height: Math.round(w.firstElementChild.getBoundingClientRect().height),
  }));

test('an empty block keeps its stub however often the stage re-checks', async ({ page }) => {
  await stage(page);
  await expect.poll(() => marked(page)).toMatchObject({ empty: true, label: 'Empty links' });
  for (let i = 0; i < 4; i++) {
    await recheck(page);
    await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => r())));
    expect(await marked(page), `after re-check ${i + 1}`).toMatchObject({ empty: true, label: 'Empty links' });
  }
  expect((await marked(page)).height).toBeGreaterThan(0); // the stub is what makes it visible
});

test('a block with content is never stubbed', async ({ page }) => {
  await stage(page);
  await recheck(page);
  await recheck(page);
  const notitle = await page
    .locator('[data-thallo-block="linksnotitle"]')
    .evaluate((w) => w.hasAttribute('data-thallo-block-empty'));
  expect(notitle).toBe(false);
});
