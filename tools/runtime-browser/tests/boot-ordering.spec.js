// Proves the theme-runtime spec's boot-ordering contract (README point 4 +
// runtime.js's "boot:footer" comment): the script sits in <head> with
// `defer`, exactly like layout.twig emits it, and the custom elements it
// enhances are parsed AFTER it in source order but BEFORE it executes.
// customElements.define() upgrades the already-parsed hosts synchronously,
// queuing their connectedCallback microtasks; the whole-document class-based
// scan is scheduled on a LATER tick (DOMContentLoaded, which itself flushes
// microtasks first) so it always finds the elements already marked and
// no-ops. A Node hand-stubbed DOM has no real microtask/task ordering to get
// this wrong against — this is exactly the race a real browser can catch.
'use strict';

const { test, expect } = require('@playwright/test');

test('script executes after element parsing, with no double-registration and no duplicate enhancement', async ({ page }) => {
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', (err) => pageErrors.push(String(err)));
  page.on('console', (msg) => { if (msg.type() === 'error') { consoleErrors.push(msg.text()); } });

  await page.goto('/tools/runtime-browser/fixtures/elements.html');

  await page.waitForFunction(() =>
    document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced') === 'carousel' &&
    document.querySelector('thallo-tabs').getAttribute('data-thallo-enhanced') === 'tabs' &&
    document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced') === 'navigation'
  );

  // ThalloRuntime.register() THROWS on a re-registered module name (spec §1).
  // If the boot scheduler ever ran the registration IIFEs or the whole-
  // document scan twice, this would surface as an uncaught error here.
  expect(pageErrors, 'no uncaught page errors (e.g. duplicate module registration)').toEqual([]);
  expect(consoleErrors, 'no console.error from a module enhance() failing').toEqual([]);

  // Marker tokens are exact single values, not "carousel carousel" — proof
  // the class-based document scan (which runs after element projection, on
  // a later tick) found the already-enhanced marker and no-opped instead of
  // re-running enhance() and re-marking.
  const markers = await page.evaluate(() => ({
    carousel: document.querySelector('thallo-carousel').getAttribute('data-thallo-enhanced'),
    tabs: document.querySelector('thallo-tabs').getAttribute('data-thallo-enhanced'),
    navigation: document.querySelector('[data-thallo-enhance="navigation"]').getAttribute('data-thallo-enhanced')
  }));
  expect(markers).toEqual({ carousel: 'carousel', tabs: 'tabs', navigation: 'navigation' });

  // A double-enhancement of the carousel would leave two prev/next/dots
  // nodes behind (each enhance() call appends its own); exactly one of each
  // is the concrete, observable proof of "exactly one enhancement".
  const controlCounts = await page.evaluate(() => {
    const el = document.querySelector('thallo-carousel');
    return {
      prev: el.querySelectorAll('.thallo-block-carousel__prev').length,
      next: el.querySelectorAll('.thallo-block-carousel__next').length,
      dots: el.querySelectorAll('.thallo-block-carousel__dots').length
    };
  });
  expect(controlCounts).toEqual({ prev: 1, next: 1, dots: 1 });

  // Sanity-check the fixture actually mirrors the real risk (README point 4):
  // the <script defer> element must precede the custom-element hosts in
  // source order, and carry `defer` — i.e. this is genuinely the "head
  // script running before its children exist" shape the contract warns
  // about, made safe only by `defer`.
  const shape = await page.evaluate(() => {
    const script = document.querySelector('script[src*="runtime.js"]');
    const carousel = document.querySelector('thallo-carousel');
    return {
      hasDefer: script.defer === true,
      scriptPrecedesElement: !!(script.compareDocumentPosition(carousel) & Node.DOCUMENT_POSITION_FOLLOWING)
    };
  });
  expect(shape).toEqual({ hasDefer: true, scriptPrecedesElement: true });
});
