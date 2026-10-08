// The Product grid in a browser (product grid spec §3.4, §5.1, §7.2): authored part styles win
// over the shop's defaults after shop.js starts and with a mini cart on the page; cards work
// without JavaScript; long category labels wrap; card lift and image zoom only where hover exists.
'use strict';

const { test, expect } = require('@playwright/test');

const BASE = '/tools/runtime-browser/fixtures/product-grid/';
/** A form field from a request body, urlencoded or multipart (shop.js posts FormData). */
const field = (body, name) => {
  const multipart = new RegExp('name="' + name + '"\\r?\\n\\r?\\n([^\\r\\n]*)').exec(body || '');
  return multipart ? multipart[1] : new URLSearchParams(body || '').get(name);
};
const grid = (page, n) => page.locator('.thallo-block-product-grid').nth(n);
const probe = (page, prop, value) =>
  page.evaluate(([p, v]) => {
    const el = document.createElement('span');
    el.style[p] = v;
    document.body.appendChild(el);
    const out = getComputedStyle(el)[p];
    el.remove();
    return out;
  }, [prop, value]);

// The mini cart reads the cart once on load: answer it empty.
const EMPTY_CART = {
  items: [], item_count: 0, discount_code: null, subtotal: 0, subtotal_formatted: '$0.00',
  discount_total: 0, discount_total_formatted: '$0.00', shipping_total: 0, shipping_total_formatted: '$0.00',
  tax_total: 0, tax_total_formatted: '$0.00', grand_total: 0, grand_total_formatted: '$0.00', currency: 'USD',
  cart_url: '/cart', checkout_url: '/checkout',
};
const withCart = async (page) => {
  await page.route('**/_shop/cart', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(EMPTY_CART) }),
  );
  await page.goto(BASE + 'grid-js.html');
};

test('authored part styles hold after shop.js initializes, with a mini cart on the page', async ({ page }) => {
  await withCart(page);
  await page.waitForFunction(() => document.querySelector('[data-shop-wishlist-toggle]:not([hidden])'));
  expect(await page.locator('[data-shop-mini-cart]').count()).toBeGreaterThan(0);
  const card = grid(page, 0).locator('.shop-grid__item').first();
  expect(await card.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-surface)'),
  );
  expect(await card.locator('.shop-grid__name-link').evaluate((el) => getComputedStyle(el).color)).toBe(
    await probe(page, 'color', 'var(--t-color-black)'),
  );
  // The Button part's authored background, over the shop's own (accent) action-button background.
  expect(await card.locator('.shop-grid__action--cart').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-black)'),
  );
});

test('add to cart posts that product and the cart shows it; the heart saves it', async ({ page }) => {
  await withCart(page);
  const card = grid(page, 0).locator('.shop-grid__item').filter({ has: page.locator('.shop-grid__action--cart') }).first();
  const variant = await card.locator('input[name="variant_uuid"]').getAttribute('value');
  const productName = await card.locator('.shop-grid__name-link').innerText();
  let posted = null;
  await page.route('**/_shop/cart/add', async (route) => {
    posted = route.request().postData();
    // ShopCartController::add() answers an XHR add with CartViewModel::toArray(); shop.js's onSuccess()
    // keys on `item_count` and paints the mini cart from it.
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: [{
          variant_uuid: variant, sku: 'fixture-sku', product_name: productName, quantity: 1,
          unit_price: 1000, unit_price_formatted: '$10.00', line_total: 1000, line_total_formatted: '$10.00',
          currency: 'USD', addons: [],
        }],
        item_count: 1, discount_code: null,
        subtotal: 1000, subtotal_formatted: '$10.00', discount_total: 0, discount_total_formatted: '$0.00',
        shipping_total: 0, shipping_total_formatted: '$0.00', tax_total: 0, tax_total_formatted: '$0.00',
        grand_total: 1000, grand_total_formatted: '$10.00', currency: 'USD',
        cart_url: '/cart', checkout_url: '/checkout',
      }),
    });
  });
  const heart = card.locator('[data-shop-wishlist-toggle]');
  await expect(heart).toBeVisible();
  await heart.click();
  await expect(heart).toHaveAttribute('aria-pressed', 'true');
  await card.locator('.shop-grid__action--cart').click();
  await expect.poll(() => posted).not.toBeNull();
  expect(field(posted, 'variant_uuid')).toBe(variant);
  expect(field(posted, 'quantity')).toBe('1');
  await expect(page.locator('[data-shop-cart-count]').first()).toHaveText('1');
  await expect(page.locator('[data-shop-cart-count]').first()).toBeVisible();
});

test('without JavaScript a card adds to the cart by posting its form', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  expect(await card.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
    await probe(page, 'backgroundColor', 'var(--t-color-surface)'),
  );
  await expect(card.locator('.shop-grid__name-link')).toHaveAttribute('href', /\/shop\//);
  await expect(card.locator('.shop-grid__media-link')).toHaveAttribute('href', /\/shop\//);
  await expect(card.locator('[data-shop-wishlist-toggle]')).toBeHidden();
  const form = grid(page, 0).locator('form.shop-grid__cart-form').first();
  const variant = await form.locator('input[name="variant_uuid"]').getAttribute('value');
  let posted = null;
  await page.route('**/_shop/cart/add', async (route) => {
    posted = { method: route.request().method(), body: route.request().postData() };
    await route.fulfill({ status: 303, headers: { Location: '/cart-landed' } });
  });
  await page.route('**/cart-landed', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>cart</p>' }));
  await form.locator('button[type="submit"]').click(); // a real form submission: no script on this page
  await page.waitForURL('**/cart-landed');
  expect(posted.method).toBe('POST');
  expect(field(posted.body, 'variant_uuid')).toBe(variant);
});

test('the title link answers pointer, keyboard and the forced preview', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const link = grid(page, 0).locator('.shop-grid__name-link').first();
  const rest = await link.evaluate((el) => getComputedStyle(el).color);
  const hoverColour = await probe(page, 'color', 'var(--t-color-accent)'); // the fixture's Title hover colour
  await link.hover();
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
  await page.mouse.move(0, 0);
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(rest);
  await link.focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab'); // keyboard focus → :focus-visible
  expect(await link.evaluate((el) => el === document.activeElement)).toBe(true);
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
  await link.evaluate((el) => el.blur());
  await link.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
  expect(await link.evaluate((el) => getComputedStyle(el).color)).toBe(hoverColour);
});

test('with the title, cart and wishlist hidden, the keyboard reaches the named image link', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const link = grid(page, 3).locator('.shop-grid__media-link').first();
  await expect(link).toHaveAttribute('aria-label', /.+/);
  await expect(link).not.toHaveAttribute('tabindex', '-1');
  await expect(link).toHaveAccessibleName(await link.getAttribute('aria-label'));
  await link.evaluate((el) => el.closest('.thallo-block-product-grid').previousElementSibling?.querySelector('a, button')?.focus());
  for (let i = 0; i < 40 && !(await link.evaluate((el) => el === document.activeElement)); i++) {
    await page.keyboard.press('Tab');
  }
  expect(await link.evaluate((el) => el === document.activeElement)).toBe(true);
});

test('several long category labels wrap without overflowing the card', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const labels = grid(page, 1).locator('.shop-grid__labels').first();
  const box = await labels.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth, h: el.getBoundingClientRect().height,
    line: parseFloat(getComputedStyle(el).lineHeight) || 16 }));
  expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
  expect(box.h).toBeGreaterThan(box.line * 1.5);
});

test('card lift and image zoom under hover; ratio, fit and badge position', async ({ page }) => {
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  const image = card.locator('.shop-grid__image');
  const ratio = await image.evaluate((el) => el.getBoundingClientRect().width / el.getBoundingClientRect().height);
  expect(ratio).toBeCloseTo(4 / 5, 1);
  expect(await image.evaluate((el) => getComputedStyle(el).objectFit)).toBe('cover');
  const badges = card.locator('.shop-grid__badges');
  const [b, c] = [await badges.boundingBox(), await card.boundingBox()];
  expect(c.x + c.width - (b.x + b.width)).toBeLessThan(20);
  const before = await card.evaluate((el) => el.getBoundingClientRect().top);
  await card.hover();
  await page.waitForTimeout(400);
  expect(await card.evaluate((el) => el.getBoundingClientRect().top)).toBeLessThan(before);
  expect(await image.evaluate((el) => getComputedStyle(el).transform)).not.toBe('none');
});

test('a tap on a touch screen leaves no lift or zoom behind', async ({ browser }) => {
  const touch = await browser.newContext({ hasTouch: true, isMobile: true });
  const page = await touch.newPage();
  await page.goto(BASE + 'public.html');
  const card = grid(page, 0).locator('.shop-grid__item').first();
  // Tap the card's price (not a link), as a shopper scrolling past would.
  await card.locator('.shop-grid__price').tap();
  await page.waitForTimeout(400);
  expect(await card.evaluate((el) => getComputedStyle(el).transform)).toBe('none');
  expect(await card.locator('.shop-grid__image').evaluate((el) => getComputedStyle(el).transform)).toBe('none');
  await touch.close();
});

test('no movement with reduced motion; the forced preview draws the effects', async ({ browser }) => {
  const ctx = await browser.newContext();
  const reduced = await ctx.newPage();
  await reduced.emulateMedia({ reducedMotion: 'reduce' });
  await reduced.goto(BASE + 'public.html');
  const card = grid(reduced, 0).locator('.shop-grid__item').first();
  await card.hover();
  expect(await card.evaluate((el) => getComputedStyle(el).transform)).toBe('none');

  const stage = await ctx.newPage();
  await stage.goto(BASE + 'stage.html');
  const forced = grid(stage, 0).locator('.shop-grid__item').first();
  await forced.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
  expect(await forced.evaluate((el) => getComputedStyle(el).transform)).not.toBe('none');
  await ctx.close();
});
