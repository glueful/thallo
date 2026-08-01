// Proves the full connect -> disconnect -> reconnect lifecycle against real
// customElements timing: disconnectedCallback tears down injected controls
// and restores the no-JS fallback; a later reconnect (the SAME node
// re-inserted, not a fresh element) re-runs connectedCallback and
// re-enhances exactly once. None of this exists in the Node hand-stubbed DOM
// — there is no customElements upgrade queue to model reconnect against.
'use strict';

const { test, expect } = require('@playwright/test');

test('carousel: disconnect removes injected controls and restores the fallback; reconnect re-enhances', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced') === 'carousel'
  );

  const afterConnect = await page.evaluate(() => {
    const el = document.querySelector('thallo-carousel');
    return {
      marker: el.getAttribute('data-thallo-enhanced'),
      arrowsAttr: el.getAttribute('data-arrows'),
      prevButtons: el.querySelectorAll('.thallo-block-carousel__prev').length,
      nextButtons: el.querySelectorAll('.thallo-block-carousel__next').length,
      dotButtons: el.querySelectorAll('.thallo-block-carousel__dots').length
    };
  });
  expect(afterConnect).toEqual({
    marker: 'carousel',
    arrowsAttr: '1',
    prevButtons: 1,
    nextButtons: 1,
    dotButtons: 1
  });

  // Detach (not remove-and-discard): stash the same node in a JS variable so
  // it can be re-appended below — this is a reconnect of the SAME element,
  // which is the scenario connectedCallback/disconnectedCallback exist for.
  await page.evaluate(() => {
    const el = document.querySelector('thallo-carousel');
    window.__detached = el;
    el.remove();
  });

  const afterDisconnect = await page.evaluate(() => {
    const el = window.__detached;
    return {
      inDom: document.contains(el),
      marker: el.getAttribute('data-thallo-enhanced'),
      arrowsAttr: el.getAttribute('data-arrows'),
      projectedClass: el.classList.contains('thallo-block-carousel'),
      prevButtons: el.querySelectorAll('.thallo-block-carousel__prev').length,
      nextButtons: el.querySelectorAll('.thallo-block-carousel__next').length,
      dotButtons: el.querySelectorAll('.thallo-block-carousel__dots').length,
      // The no-JS floor (viewport/track/slides) is untouched markup, never
      // removed by teardown — this IS "fallback restored".
      viewportStillPresent: !!el.querySelector('.thallo-block-carousel__viewport')
    };
  });
  expect(afterDisconnect).toEqual({
    inDom: false,
    marker: null,
    arrowsAttr: null,
    projectedClass: false,
    prevButtons: 0,
    nextButtons: 0,
    dotButtons: 0,
    viewportStillPresent: true
  });

  // Reconnect: re-append the SAME node.
  await page.evaluate(() => {
    document.body.appendChild(window.__detached);
  });

  await page.waitForFunction(() =>
    window.__detached.getAttribute('data-thallo-enhanced') === 'carousel'
  );

  const afterReconnect = await page.evaluate(() => {
    const el = window.__detached;
    return {
      marker: el.getAttribute('data-thallo-enhanced'),
      arrowsAttr: el.getAttribute('data-arrows'),
      arrowsDataset: el.dataset.arrows,
      // Exactly one set of controls — a double-enhancement bug would leave
      // two of each behind after a reconnect.
      prevButtons: el.querySelectorAll('.thallo-block-carousel__prev').length,
      nextButtons: el.querySelectorAll('.thallo-block-carousel__next').length,
      dotButtons: el.querySelectorAll('.thallo-block-carousel__dots').length
    };
  });
  expect(afterReconnect).toEqual({
    marker: 'carousel',
    arrowsAttr: '1',
    arrowsDataset: '1',
    prevButtons: 1,
    nextButtons: 1,
    dotButtons: 1
  });
});

test('navigation: disconnect unmarks the resolved target and removes the projected class; reconnect re-enhances', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced') === 'navigation'
  );

  await page.evaluate(() => {
    const el = document.querySelector('thallo-navigation');
    window.__detachedNav = el;
    el.remove();
  });

  const afterDisconnect = await page.evaluate(() => {
    const el = window.__detachedNav;
    const details = el.querySelector('[data-thallo-enhance="navigation"]');
    return {
      inDom: document.contains(el),
      hostProjectedClass: el.classList.contains('thallo-block-navigation'),
      detailsMarker: details.getAttribute('data-thallo-enhanced'),
      // The no-JS floor (the native <details>/<summary> disclosure) is
      // untouched markup — this IS "fallback restored".
      detailsStillPresent: !!details
    };
  });
  expect(afterDisconnect).toEqual({
    inDom: false,
    hostProjectedClass: false,
    detailsMarker: null,
    detailsStillPresent: true
  });

  await page.evaluate(() => {
    document.body.appendChild(window.__detachedNav);
  });

  await page.waitForFunction(() =>
    window.__detachedNav.querySelector('[data-thallo-enhance="navigation"]')
      .getAttribute('data-thallo-enhanced') === 'navigation'
  );

  const afterReconnect = await page.evaluate(() => {
    const el = window.__detachedNav;
    return {
      hostProjectedClass: el.classList.contains('thallo-block-navigation'),
      detailsMarker: el.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced')
    };
  });
  expect(afterReconnect).toEqual({ hostProjectedClass: true, detailsMarker: 'navigation' });
});
