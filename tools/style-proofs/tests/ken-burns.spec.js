// Ken Burns: the class lands on a FRAME, which clips; the picture that is the frame's direct child
// drifts, slowly and for ever; a picture deeper inside the frame does not. A visitor who asked for
// reduced motion gets a still picture.
'use strict';

const { test, expect } = require('@playwright/test');
const { computed, at } = require('./helpers');

const NAME = 'ken-burns-zoom-in';

test('the frame clips and its own picture drifts; a picture in its content does not', async ({ page }) => {
  await page.goto('/tools/style-proofs/fixtures/index.html');
  await at(page, 'lg');

  const clip = await computed(page, NAME, 'root', 'overflow-x');
  expect(clip.theme).toBe('visible');
  expect(clip.styled).toBe('clip');

  const background = await computed(page, NAME, 'background', 'animation-name');
  expect(background.theme).toBe('none');
  expect(background.styled).toBe('t-kenburns');
  expect((await computed(page, NAME, 'background', 'animation-iteration-count')).styled).toBe('infinite');
  expect((await computed(page, NAME, 'background', 'animation-direction')).styled).toBe('alternate');

  const content = await computed(page, NAME, 'content', 'animation-name');
  expect(content.styled).toBe('none');

  // It really moves: sampled twice, the picture's transform has changed.
  const sample = () =>
    page.evaluate((name) => {
      const el = document.querySelector(`[data-case="${name}"] [data-target="background"]`);
      return getComputedStyle(el).transform;
    }, NAME);
  const first = await sample();
  await page.waitForTimeout(600);
  expect(await sample()).not.toBe(first);
});

test('with reduced motion requested the picture is still', async ({ browser }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto('/tools/style-proofs/fixtures/index.html');
  const background = await computed(page, NAME, 'background', 'animation-name');
  expect(background.styled).toBe('none');
  await context.close();
});
