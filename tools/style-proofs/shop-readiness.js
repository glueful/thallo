'use strict';

// Readiness of the JavaScript-painted shop blocks, for a thumbnail that pictures them
// (sections and templates design §6): a picture is taken only once EVERY shop block on the page
// has painted — a featured product its body, an add to cart its form or its link — no loading,
// empty or error line is showing, and every image has loaded. (A Product grid renders on the
// server: it is not one of these.) A page where one
// block painted and another did not is not ready.

/** Runs in the page: null when ready, else a reason naming the first block that is not. */
function unready(expected) {
  const found = document.querySelectorAll('[data-shop-block]').length;
  if (found < expected) return `expected ${expected} shop blocks, found ${found}`;
  const visible = (el) => !!el && !el.hidden && el.getClientRects().length > 0;
  for (const block of document.querySelectorAll('[data-shop-block]')) {
    const kind = block.getAttribute('data-shop-block');
    const reason = (() => {
      if (!visible(block)) return 'the block is hidden';
      for (const el of block.querySelectorAll('*')) {
        if (visible(el) && el.children.length === 0 && /^Loading/.test(el.textContent.trim())) {
          return `it still says "${el.textContent.trim()}"`;
        }
      }
      for (const sel of ['[data-shop-featured-empty]', '[data-shop-add-to-cart-status]']) {
        const el = block.querySelector(sel);
        if (visible(el)) return `it shows "${el.textContent.trim()}"`;
      }
      if (kind === 'featured-product') {
        if (!visible(block.querySelector('[data-shop-featured-body]'))) return 'its product has not painted';
      } else if (kind === 'add-to-cart') {
        const form = block.querySelector('[data-shop-add-to-cart-form]');
        const link = block.querySelector('[data-shop-add-to-cart-link]');
        if (!visible(form) && !visible(link)) return 'neither its form nor its link is showing';
      }
      for (const img of block.querySelectorAll('img')) {
        if (!img.complete || img.naturalWidth === 0) return `an image has not loaded (${img.getAttribute('src')})`;
      }
      return null;
    })();
    if (reason !== null) return `shop block "${kind}" not ready: ${reason}`;
  }
  return null;
}

/**
 * Resolve once the page holds `expectedBlocks` shop blocks and every one of them is ready; reject
 * naming the first one that is not by `timeoutMs`. The count guards against a block that did not
 * render at all (a missing template), which would otherwise leave nothing to wait for.
 */
async function waitForShopReady(page, timeoutMs = 10000, expectedBlocks = 1) {
  const check = `(${unready.toString()})(${Number(expectedBlocks)})`;
  try {
    await page.waitForFunction(`${check} === null`, null, { timeout: timeoutMs, polling: 100 });
  } catch (error) {
    const reason = await page.evaluate(check).catch(() => null);
    throw new Error(reason || `shop blocks not ready: ${error.message}`);
  }
}

module.exports = { waitForShopReady };
