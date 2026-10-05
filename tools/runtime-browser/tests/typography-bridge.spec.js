// What a target renders in (block typeface plan Task 10): the parent asks the stage for a block's
// target or part, and the stage answers with the computed weight and style of the first element in
// that block's own wrapper carrying the target's stage marker — never a nested block's, and nothing
// at all for an optional target the block does not render. The stage is a real canvas render
// (scripts/build-typography-bridge-fixtures) with the real preview bridge.
'use strict';

const { test, expect } = require('@playwright/test');

const STAGE = '/tools/runtime-browser/fixtures/typography-bridge/stage.html';
const NONCE = 'typography-proof';

/** The stage, greeted as a canvas parent would: the page is its own parent at the top level. */
async function stage(page) {
  await page.goto(STAGE);
  await page.evaluate((nonce) => {
    window.__replies = [];
    window.addEventListener('message', (e) => {
      if (e.data && e.data.type === 'thallo:typography-state') window.__replies.push(e.data);
    });
    window.postMessage({ type: 'thallo:canvas-hello', nonce }, window.location.origin);
  }, NONCE);
}

async function ask(page, id, target, seq) {
  await page.evaluate(({ id, target, seq, nonce }) => {
    window.postMessage({ type: 'thallo:typography-request', nonce, id, target, seq }, window.location.origin);
  }, { id, target, seq, nonce: NONCE });
}

/** The computed weight and style of the element `selector` matches. */
const computed = (page, selector) => page.evaluate((sel) => {
  const cs = getComputedStyle(document.querySelector(sel));
  return { weight: Number(cs.fontWeight), style: cs.fontStyle };
}, selector);

const replies = (page) => page.evaluate(() => window.__replies);

test("a target's computed weight and style come back for its block", async ({ page }) => {
  await stage(page);
  await ask(page, 'linksweight1', 'title', 1);
  await expect.poll(() => replies(page)).toHaveLength(1);
  const [reply] = await replies(page);
  const title = await computed(page, '[data-thallo-block="linksweight1"] .thallo-stage-target--title');
  expect(reply).toMatchObject({ id: 'linksweight1', target: 'title', seq: 1, nonce: NONCE, ...title });
  expect(title.weight).toBe(500);
});

test("a part's reply reads the block's first link", async ({ page }) => {
  await stage(page);
  await ask(page, 'linksweight1', 'link', 2);
  await expect.poll(() => replies(page)).toHaveLength(1);
  const [reply] = await replies(page);
  expect(reply).toMatchObject({ id: 'linksweight1', target: 'link', seq: 2, weight: 700 });
  expect(['normal', 'italic', 'oblique']).toContain(reply.style);
});

test("an outer block never reads a nested block's target", async ({ page }) => {
  await stage(page);
  await ask(page, 'container001', 'title', 3);
  // A request the stage can answer, after it: once its reply is in, the first was not answered.
  await ask(page, 'linksnested1', 'title', 4);
  await expect.poll(() => replies(page)).toHaveLength(1);
  const [reply] = await replies(page);
  expect(reply).toMatchObject({ id: 'linksnested1', target: 'title', seq: 4, weight: 600 });
});

test('a missing optional target sends nothing', async ({ page }) => {
  await stage(page);
  await ask(page, 'linksnotitle', 'title', 5);
  await ask(page, 'linksnotitle', 'link', 6);
  await expect.poll(() => replies(page)).toHaveLength(1);
  expect((await replies(page))[0]).toMatchObject({ id: 'linksnotitle', target: 'link', seq: 6 });
});
