'use strict';

// The area a thumbnail crops to (sections and templates design §6): the element's own box, or —
// for an element without one, like the layout stage's `display: contents` annotation wrapper —
// the union of its rendered descendants' boxes. In page coordinates (scroll included), whole
// pixels. Rejects "no rendered box for {selector}" when nothing matches or nothing is rendered.

/** Runs in the page. */
function measure(selector) {
  const el = document.querySelector(selector);
  if (!el) return null;
  const own = (node) => {
    const r = node.getBoundingClientRect();
    return node.getClientRects().length > 0 && r.width > 0 && r.height > 0 ? r : null;
  };
  const bounds = (node) => {
    const r = own(node);
    if (r) return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
    let out = null;
    for (const child of node.children) {
      const b = bounds(child);
      if (!b) continue;
      out = out
        ? {
            left: Math.min(out.left, b.left),
            top: Math.min(out.top, b.top),
            right: Math.max(out.right, b.right),
            bottom: Math.max(out.bottom, b.bottom),
          }
        : b;
    }
    return out;
  };
  const b = bounds(el);
  if (!b) return null;
  const x = Math.floor(b.left + window.scrollX);
  const y = Math.floor(b.top + window.scrollY);
  return { x, y, width: Math.ceil(b.right + window.scrollX) - x, height: Math.ceil(b.bottom + window.scrollY) - y };
}

/** @returns {Promise<{x: number, y: number, width: number, height: number}>} */
async function renderedBox(page, selector) {
  const box = await page.evaluate(measure, selector);
  if (!box) throw new Error(`no rendered box for ${selector}`);
  return box;
}

module.exports = { renderedBox };
