// Entrances, in a real browser. The compiled stylesheet only NAMES a starting state; what makes a
// block hidden is the page's inline flag, and what reveals it is the deferred motion script when
// the block scrolls into view. This spec supplies those two scripts as a real page gets them and
// proves the four promises: no flash, revealed on scroll, replayed when asked, and never hidden
// for a visitor without JavaScript or one who asked for reduced motion.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const REPO = path.resolve(__dirname, '..', '..', '..');
const RUNTIME = path.join(REPO, 'packages/thallo-render/runtime');
const PAGE = '/tools/runtime-browser/fixtures/layout/motion-entrances.html';

const heading = (page, n) => page.locator('.thallo-block-heading', { hasText: new RegExp(`^Heading ${n}$`) });
const opacity = (locator) => locator.evaluate((el) => getComputedStyle(el).opacity);
const entered = (locator) => locator.evaluate((el) => el.hasAttribute('data-thallo-entered'));

/** The page's scripts: the theme runtime (in the head of a real page) and the motion asset. */
async function withScripts(page) {
  await page.addInitScript({ path: path.join(RUNTIME, 'runtime.js') });
  await page.route('**/_thallo/runtime/block-motion.js', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(RUNTIME, 'block-motion.js')) }),
  );
}

test('a block at the top enters at once; one far below waits for the scroll', async ({ page }) => {
  await withScripts(page);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(PAGE);
  await expect(page.locator('html')).toHaveAttribute('data-thallo-motion', '');

  // Above the fold: entered, and fully shown once its slow, delayed transition has run.
  await expect.poll(() => entered(heading(page, 1))).toBe(true);
  await expect.poll(() => opacity(heading(page, 1)), { timeout: 4000 }).toBe('1');
  // A block with no entrance is never touched.
  expect(await opacity(heading(page, 2))).toBe('1');

  // Two screens down: still hidden, from its starting state, and not entered.
  const far = heading(page, 8);
  expect(await entered(far)).toBe(false);
  expect(await opacity(far)).toBe('0');
  expect(await far.evaluate((el) => getComputedStyle(el).transform)).toMatch(/^matrix\(0\.92/);

  await far.scrollIntoViewIfNeeded();
  await expect.poll(() => entered(far)).toBe(true);
  await expect.poll(() => opacity(far), { timeout: 3000 }).toBe('1');
});

test('an entrance set to replay hides again when it leaves; one set to play once does not', async ({ page }) => {
  await withScripts(page);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(PAGE);
  const replays = heading(page, 8);
  await replays.scrollIntoViewIfNeeded();
  await expect.poll(() => entered(replays)).toBe(true);
  await expect.poll(() => entered(heading(page, 5))).toBe(true);

  await page.evaluate(() => window.scrollTo(0, 0));
  await expect.poll(() => entered(replays)).toBe(false);
  expect(await entered(heading(page, 1))).toBe(true); // played once: stays
});

test('a container staggers its children: each starts later than the one before', async ({ page }) => {
  await withScripts(page);
  await page.goto(PAGE);
  const delays = [];
  for (const n of [5, 6, 7]) {
    delays.push(await heading(page, n).evaluate((el) => getComputedStyle(el).transitionDelay.split(',')[0].trim()));
  }
  expect(delays).toEqual(['0s', '0.15s', '0.3s']);
});

test('without JavaScript nothing is ever hidden', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto(PAGE);
  await expect(page.locator('html')).not.toHaveAttribute('data-thallo-motion', '');
  for (const n of [1, 5, 8]) expect(await opacity(heading(page, n))).toBe('1');
  await context.close();
});

test('a visitor who asked for reduced motion sees everything, still', async ({ browser }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  await withScripts(page);
  await page.goto(PAGE);
  await expect(page.locator('html')).not.toHaveAttribute('data-thallo-motion', '');
  for (const n of [1, 5, 8]) expect(await opacity(heading(page, n))).toBe('1');
  await context.close();
});

test('if the revealing script never arrives, the flag comes off and everything shows', async ({ page }) => {
  // The theme runtime is there, the motion asset 404s: three seconds later nothing is hidden.
  await page.addInitScript({ path: path.join(RUNTIME, 'runtime.js') });
  await page.route('**/_thallo/runtime/block-motion.js', (route) => route.fulfill({ status: 404, body: '' }));
  await page.goto(PAGE);
  await expect(page.locator('html')).toHaveAttribute('data-thallo-motion', '');
  expect(await opacity(heading(page, 8))).toBe('0');
  await expect(page.locator('html')).not.toHaveAttribute('data-thallo-motion', '', { timeout: 5000 });
  expect(await opacity(heading(page, 8))).toBe('1');
});

test('the editor’s canvas hides nothing: no flag is rendered into it', async ({ page }) => {
  await withScripts(page);
  await page.goto(PAGE.replace('.html', '.canvas.html'));
  await expect(page.locator('html')).not.toHaveAttribute('data-thallo-motion', '');
  for (const n of [1, 8]) expect(await opacity(heading(page, n))).toBe('1');
});
