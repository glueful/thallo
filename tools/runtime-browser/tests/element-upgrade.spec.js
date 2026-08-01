// Proves real custom-element upgrade: definition, instanceof, and that
// connectedCallback's one-microtask-deferred enhancement actually ran — none
// of which the Node hand-stubbed DOM (packages/thallo-render/tests) can model.
'use strict';

const { test, expect } = require('@playwright/test');

test.describe('element upgrade', () => {
  test('all four tags are defined and upgrade their parsed instances', async ({ page }) => {
    await page.goto('/tools/runtime-browser/fixtures/elements.html');

    const tags = [
      'thallo-carousel',
      'thallo-tabs',
      'thallo-navigation',
      'thallo-color-mode-toggle'
    ];

    for (const tag of tags) {
      const upgraded = await page.evaluate((t) => {
        const ctor = customElements.get(t);
        const el = document.querySelector(t);
        return !!ctor && !!el && el instanceof ctor;
      }, tag);
      expect(upgraded, `${tag} should be defined and its parsed instance upgraded`).toBe(true);
    }
  });

  test('carousel, tabs and navigation reach the enhanced marker after upgrade', async ({ page }) => {
    await page.goto('/tools/runtime-browser/fixtures/elements.html');

    // The marker only appears once the connectedCallback microtask (real
    // upgrade timing, not a synchronous stub) has run.
    await page.waitForFunction(() => {
      const carousel = document.querySelector('thallo-carousel');
      const tabs = document.querySelector('thallo-tabs');
      const nav = document.querySelector('[data-thallo-enhance="navigation"]');
      return (
        carousel && carousel.getAttribute('data-thallo-enhanced') === 'carousel' &&
        tabs && tabs.getAttribute('data-thallo-enhanced') === 'tabs' &&
        nav && nav.getAttribute('data-thallo-enhanced') === 'navigation'
      );
    });

    // Reaching here without timing out is the proof; assert once more for a
    // readable failure message if something regresses later in the file.
    const enhanced = await page.evaluate(() => ({
      carousel: document.querySelector('thallo-carousel').dataset.thalloEnhanced,
      tabs: document.querySelector('thallo-tabs').dataset.thalloEnhanced,
      navigation: document.querySelector('[data-thallo-enhance="navigation"]').dataset.thalloEnhanced
    }));
    expect(enhanced).toEqual({ carousel: 'carousel', tabs: 'tabs', navigation: 'navigation' });
  });
});
