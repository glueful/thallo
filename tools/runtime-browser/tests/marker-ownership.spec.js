// Proves the data-thallo-enhanced marker lands on the right node per module —
// the carousel/tabs modules mark the custom-element host itself (the default
// resolveTarget), the navigation module marks the INNER details it actually
// enhances (registerElement's resolveTarget override), and the color-mode
// toggle — the one documented pipeline exception — gets no marker at all
// because it never goes through registerElement/runComponent.
'use strict';

const { test, expect } = require('@playwright/test');

test('marker sits on the resolved target for each module, and nowhere else', async ({ page }) => {
  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced') === 'carousel' &&
    document.querySelector('thallo-tabs').getAttribute('data-thallo-enhanced') === 'tabs' &&
    document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced') === 'navigation'
  );

  const markers = await page.evaluate(() => ({
    carouselHost: document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced'),
    tabsHost: document.querySelector('thallo-tabs').getAttribute('data-thallo-enhanced'),
    navInnerDetails: document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced'),
    // The outer <thallo-navigation> element is NOT the enhance target — the
    // module enhances the drawer details, so the marker must not be here.
    navOuterHost: document.querySelector('thallo-navigation').getAttribute('data-thallo-enhanced'),
    // The toggle is the documented no-registerElement exception: it never
    // enters runComponent, so it must never carry the shared marker.
    toggleHost: document.querySelector('thallo-color-mode-toggle').getAttribute('data-thallo-enhanced')
  }));

  expect(markers).toEqual({
    carouselHost: 'carousel',
    tabsHost: 'tabs',
    navInnerDetails: 'navigation',
    navOuterHost: null,
    toggleHost: null
  });
});

// Marker REMOVAL on disconnect is covered end-to-end (with the element kept
// alive via detach + re-append, so the marker's absence can actually be
// observed on the node) in disconnect-reconnect.spec.js.
