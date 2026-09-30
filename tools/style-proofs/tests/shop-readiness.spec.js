// The thumbnail capture's shop readiness (sections and templates design §6): ready only when every
// shop block has painted and its images loaded; one failing block, a missing image or a block still
// loading keeps the page not ready, naming the block.
'use strict';

const { test, expect } = require('@playwright/test');
const { waitForShopReady } = require('../shop-readiness.js');

const PAGE = '/tools/style-proofs/pages/shop-readiness.html';
const IMAGE = '/tests/fixtures/commerce/product-cover.png';

const grid = (cover) => ({
  items: [{ name: 'Stoneware bowl', url: '#', cover_url: cover, category_name: 'Bowls',
    price_formatted: '$32.00', cart_mode: 'link', direct_variant_uuid: null }],
  view_all_url: '/shop',
});
const featured = { product: { name: 'Stoneware bowl', url: '#', price_formatted: '$32.00', currency: 'USD' } };
const cart = { available: true, mode: 'link', product_url: '#' };

async function open(page, answers) {
  for (const [block, answer] of Object.entries(answers)) {
    await page.route(`**/_shop/blocks/${block}*`, answer);
  }
  await page.goto(PAGE);
}

const json = (body) => (route) => route.fulfill({ json: body });

test.beforeEach(({ browserName }) => {
  test.skip(browserName !== 'chromium', 'the thumbnail capture runs in Chromium');
});

test('ready when every block has painted', async ({ page }) => {
  await open(page, { 'product-grid': json(grid(IMAGE)), 'featured-product': json(featured), 'add-to-cart': json(cart) });
  await waitForShopReady(page, 5000);
});

test('not ready when one block fails', async ({ page }) => {
  await open(page, {
    'product-grid': json(grid(IMAGE)),
    'featured-product': (route) => route.fulfill({ status: 500, body: 'boom' }),
    'add-to-cart': json(cart),
  });
  await expect(waitForShopReady(page, 1500)).rejects.toThrow(/featured-product/);
});

test('not ready while an image is missing', async ({ page }) => {
  await open(page, { 'product-grid': json(grid('/missing-cover.png')), 'featured-product': json(featured), 'add-to-cart': json(cart) });
  await expect(waitForShopReady(page, 1500)).rejects.toThrow(/product-grid/);
});

test('not ready while loading shows', async ({ page }) => {
  await open(page, { 'product-grid': json(grid(IMAGE)), 'featured-product': json(featured), 'add-to-cart': () => {} });
  await expect(waitForShopReady(page, 1500)).rejects.toThrow(/add-to-cart/);
});

test('not ready when a shop block is missing from the page', async ({ page }) => {
  await open(page, { 'product-grid': json(grid(IMAGE)), 'featured-product': json(featured), 'add-to-cart': json(cart) });
  await expect(waitForShopReady(page, 1500, 4)).rejects.toThrow(/expected 4 shop blocks, found 3/);
  await waitForShopReady(page, 1500, 3);
});
