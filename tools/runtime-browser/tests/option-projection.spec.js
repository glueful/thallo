// Proves attribute-sugar projection AND native dataset reflection. The
// production dual-write (an explicit `host.dataset.arrows = '1'` alongside
// `setAttribute`) was removed — runtime.js now relies on the browser
// reflecting `data-*` attributes into `.dataset` on its own. A Node hand-
// stubbed DOM has no native attribute/dataset reflection at all, so this is
// exactly the divergence a real browser is needed to catch.
'use strict';

const { test, expect } = require('@playwright/test');

test('carousel sugar attributes project to data-* and reflect natively into .dataset', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced') === 'carousel'
  );

  const projected = await page.evaluate(() => {
    const el = document.querySelector('thallo-carousel');
    return {
      arrowsAttr: el.getAttribute('data-arrows'),
      dotsAttr: el.getAttribute('data-dots'),
      autoplayAttr: el.getAttribute('data-autoplay'),
      // Native reflection — never written by runtime.js directly.
      arrowsDataset: el.dataset.arrows,
      dotsDataset: el.dataset.dots,
      autoplayDataset: el.dataset.autoplay,
      hasProjectedClass: el.classList.contains('thallo-block-carousel')
    };
  });

  expect(projected).toEqual({
    arrowsAttr: '1',
    dotsAttr: '1',
    autoplayAttr: null,
    arrowsDataset: '1',
    dotsDataset: '1',
    autoplayDataset: undefined,
    hasProjectedClass: true
  });
});

test('tabs and navigation project their root class without sugaring extra data-* options', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('thallo-tabs').getAttribute('data-thallo-enhanced') === 'tabs' &&
    document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced') === 'navigation'
  );

  const result = await page.evaluate(() => ({
    tabsClass: document.querySelector('thallo-tabs').classList.contains('thallo-block-tabs'),
    navClass: document.querySelector('thallo-navigation').classList.contains('thallo-block-navigation')
  }));

  expect(result).toEqual({ tabsClass: true, navClass: true });
});
