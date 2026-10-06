// The Mini cart's and the Wishlist link's look, as a browser draws it on a real published page
// (scripts/build-shop-block-proof-fixtures, chrome.html): Background, Text colour, Border and Corners
// set on the block land on its button — the cart toggle, the wishlist link — and win over the shop
// stylesheet the blocks link themselves (transparent, borderless and pill-shaped by default),
// because that stylesheet is served inside @layer theme. Untouched blocks keep the shop's look.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGE = '/tools/runtime-browser/fixtures/shop-block-selection/chrome.html';

const drawn = (page, selector, index) =>
  page.locator(selector).nth(index).evaluate((el) => {
    const cs = getComputedStyle(el);
    return {
      background: cs.backgroundColor,
      color: cs.color,
      border: `${cs.borderTopWidth} ${cs.borderTopStyle}`,
      radius: cs.borderTopLeftRadius,
    };
  });

for (const [name, selector] of [
  ['Mini cart', '.thallo-block-mini-cart__toggle'],
  ['Wishlist link', '.thallo-block-wishlist-link__link'],
]) {
  test(`the ${name}'s look lands on its button and beats the shop stylesheet`, async ({ page }) => {
    await page.goto(PAGE);
    await expect(page.locator(selector)).toHaveCount(2);
    const plain = await drawn(page, selector, 0);
    const styled = await drawn(page, selector, 1);
    // Untouched: the shop's own look — transparent, no border, a pill.
    expect(plain.background).toBe('rgba(0, 0, 0, 0)');
    expect(plain.border).toMatch(/^0px /);
    expect(plain.radius).toBe('999px');
    // Styled: black, white, a thin solid accent border, small corners.
    expect(styled.background).toBe('rgb(0, 0, 0)');
    expect(styled.color).toBe('rgb(255, 255, 255)');
    expect(styled.border).toBe('1px solid');
    expect(styled.radius).not.toBe('999px');
    expect(styled.radius).not.toBe(plain.radius);
  });
}
