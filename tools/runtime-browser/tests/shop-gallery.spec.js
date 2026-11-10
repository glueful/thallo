// @ts-check
const { test, expect } = require('@playwright/test');

// The product gallery's thumbnail swap with sized copies (fixtures/shop-gallery.html, the real
// shop.js): a browser picks the main image from the WebP <source> and the srcset before src, so a
// click must move all three, and an image without a WebP copy must not keep the previous one's.
const PAGE = '/tools/runtime-browser/fixtures/shop-gallery.html';

// A 1×1 PNG, whatever the URL asks for: the spec checks which URL the browser chose.
const PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);

test.beforeEach(async ({ page }) => {
  await page.route('**/blobs/**', (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL }),
  );
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(PAGE);
});

const cover = (page) => page.locator('[data-shop-cover]');
const chosen = (page) =>
  cover(page).evaluate((img) => new Promise((resolve) => {
    const done = () => resolve(/** @type {HTMLImageElement} */ (img).currentSrc);
    /** @type {HTMLImageElement} */ (img).complete ? done() : img.addEventListener('load', done, { once: true });
  }));

test('the cover loads a WebP copy at its size, not the upload', async ({ page }) => {
  expect(await chosen(page)).toMatch(/\/blobs\/aaa\?width=\d+&format=webp$/);
});

test('a thumb click moves the WebP source, the srcset and src to that image', async ({ page }) => {
  await page.getByRole('button', { name: 'Two' }).click();

  await expect(page.locator('[data-shop-cover-webp]')).toHaveAttribute('srcset', /\/blobs\/bbb\?width=320&format=webp 320w/);
  await expect(cover(page)).toHaveAttribute('srcset', /\/blobs\/bbb\?width=320 320w/);
  await expect(cover(page)).toHaveAttribute('src', '/blobs/bbb');
  await expect(cover(page)).toHaveAttribute('alt', 'Second');
  await expect.poll(() => chosen(page)).toMatch(/\/blobs\/bbb\?width=\d+&format=webp$/);
});

test('an image without a WebP copy shows itself, not the last WebP', async ({ page }) => {
  await page.getByRole('button', { name: 'Two' }).click();
  await page.getByRole('button', { name: 'Three' }).click();

  await expect(page.locator('[data-shop-cover-webp]')).not.toHaveAttribute('srcset', /.+/);
  await expect.poll(() => chosen(page)).toMatch(/\/blobs\/ccc\?width=\d+$/);
});
