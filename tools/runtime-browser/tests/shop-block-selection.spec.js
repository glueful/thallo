// Featured product and Add to cart without a product (sections and templates design §6): shop.js
// hides a block whose endpoint found nothing to show, instead of leaving "Loading…" forever, and an
// Add to cart whose chosen product is gone says so.
'use strict';

const { test, expect } = require('@playwright/test');

const FIXTURE = '/tools/runtime-browser/fixtures/shop-block-selection.html';

async function open(page, featured, cart) {
  await page.route('**/_shop/blocks/featured-product*', (route) => route.fulfill({ json: featured }));
  await page.route('**/_shop/blocks/add-to-cart*', (route) => route.fulfill({ json: cart }));
  await page.goto(FIXTURE);
}

const featured = (page) => page.locator('[data-shop-block="featured-product"]');
const cart = (page) => page.locator('[data-shop-block="add-to-cart"]');

test("an unlinked page's featured product hides itself", async ({ page }) => {
  await open(page, { product: null, unconfigured: true }, { mode: 'unavailable', unconfigured: true });
  await expect(featured(page)).toBeHidden();
  await expect(page.getByText('Loading…')).toHaveCount(2);
  await expect(page.getByText('Loading…').first()).toBeHidden();
});

test("a gone product's featured product hides itself", async ({ page }) => {
  await open(page, { product: null }, { mode: 'unavailable' });
  await expect(featured(page)).toBeHidden();
});

test("an unlinked page's add to cart hides itself", async ({ page }) => {
  await open(page, { product: null, unconfigured: true }, { mode: 'unavailable', unconfigured: true });
  await expect(cart(page)).toBeHidden();
});

test("a gone product's add to cart says so", async ({ page }) => {
  await open(page, { product: null }, { mode: 'unavailable' });
  await expect(cart(page)).toBeVisible();
  await expect(cart(page).getByText('This product is not available.')).toBeVisible();
});

// Without JavaScript, on real pages (scripts/build-shop-block-proof-fixtures, gitignored): an
// unlinked page and a page naming a deleted product. Neither shows "Loading…" — nothing would ever
// finish it — and each block offers a link to the shop, never a product link that may be gone.
test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  const fs = require('node:fs');
  const path = require('node:path');
  const dir = path.resolve(__dirname, '..', 'fixtures', 'shop-block-selection');
  const shop = () => fs.readFileSync(path.join(dir, 'shop-url.txt'), 'utf8').trim();

  for (const name of ['unlinked', 'gone']) {
    test(`the ${name} page shows a link to the shop and no loading line`, async ({ page }) => {
      await page.goto(`/tools/runtime-browser/fixtures/shop-block-selection/${name}.html`);
      await expect(page.getByText('Loading…').first()).toBeHidden();
      for (const [block, text] of [
        ['featured-product', 'Browse the shop'],
        ['add-to-cart', 'Browse the shop to add this product to your cart'],
      ]) {
        const root = page.locator(`[data-shop-block="${block}"]`);
        const link = root.getByRole('link', { name: text, exact: true });
        await expect(link, `${name}: ${block}`).toBeVisible();
        await expect(link).toHaveAttribute('href', shop());
        const hrefs = await root.locator('a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
        expect(hrefs.filter((h) => h.includes('/products/')), `${name}: ${block} links no product`).toEqual([]);
      }
    });
  }
});
