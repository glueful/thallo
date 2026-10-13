// The header on a phone, as a browser lays it out on a real published page (scripts/build-header-
// layout-fixtures): with nothing set the logo starts the row and the menu button and what follows it
// end it; a Navigation block given Order: First on phones sits before the logo there and back in place
// on a wide screen; Push takes the row's free space; the Menu button section draws the button. The
// menu opens as a panel under the header row, so the row stays where it is wherever the button sits.
'use strict';

const { test, expect } = require('@playwright/test');

const page = (name) => `/tools/runtime-browser/fixtures/header-layout/${name}.html`;
const PHONE = { width: 390, height: 800 };
const WIDE = { width: 1200, height: 800 };

const box = (p, selector) => p.locator(selector).first().evaluate((el) => {
  const r = el.getBoundingClientRect();
  return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width };
});
const BUTTON = '.thallo-block-navigation__hamburger';
const LOGO = '.thallo-block-logo';
const CART = '.thallo-block-button';
const HEADER = '.site-header';
const LIST = '.thallo-block-navigation__list';

test('with nothing set, the logo starts the row and the menu button ends it with what follows it', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('default'));
  const [logo, button, cart, header] = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART), box(p, HEADER)]);
  expect(logo.right).toBeLessThan(button.left);
  expect(button.right).toBeLessThanOrEqual(cart.left);
  // The free space sits after the logo: the button is next to the cart, the cart at the far end.
  expect(cart.left - button.right).toBeLessThan(32);
  expect(header.right - cart.right).toBeLessThan(40);
});

test('Order: First puts the menu button before the logo on phones and the links back in place on a wide screen', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('first'));
  const [logo, button, cart] = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART)]);
  expect(button.right).toBeLessThanOrEqual(logo.left);
  // The logo follows the button, and the space after the logo pushes the cart to the end.
  expect(logo.left - button.right).toBeLessThan(32);
  expect(cart.left).toBeGreaterThan(logo.right + 40);

  await p.setViewportSize(WIDE);
  const [wideLogo, firstLink] = await Promise.all([box(p, LOGO), box(p, '.thallo-block-navigation__link')]);
  expect(wideLogo.right).toBeLessThan(firstLink.left);
});

test('a header of a logo and a menu alone keeps them together when the menu goes first', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('pair'));
  const [logo, button] = await Promise.all([box(p, LOGO), box(p, BUTTON)]);
  expect(button.right).toBeLessThanOrEqual(logo.left);
  expect(logo.left - button.right).toBeLessThan(32);
});

test('Push: Both sides centres the logo between the menu button and the cart', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('centre'));
  const [logo, button, cart] = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART)]);
  expect(button.right).toBeLessThanOrEqual(logo.left);
  expect(logo.right).toBeLessThanOrEqual(cart.left);
  const gapBefore = logo.left - button.right;
  const gapAfter = cart.left - logo.right;
  expect(Math.abs(gapBefore - gapAfter)).toBeLessThan(2);
  expect(gapBefore).toBeGreaterThan(20);
});

test('the Menu button section draws the button: its colours, border, corners and icon size', async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('first'));
  const plain = await p.locator(BUTTON).evaluate((el) => {
    const cs = getComputedStyle(el);
    return { border: cs.borderTopWidth, icon: el.querySelector('.thallo-block-navigation__hamburger-icon').getBoundingClientRect().width };
  });
  // The theme's own button: a thin outline and an 18px icon.
  expect(plain.border).toBe('1px');
  expect(Math.round(plain.icon)).toBe(18);

  await p.goto(page('styled'));
  const styled = await p.locator(BUTTON).evaluate((el) => {
    const cs = getComputedStyle(el);
    const icon = el.querySelector('.thallo-block-navigation__hamburger-icon');
    return {
      background: cs.backgroundColor, color: cs.color, border: cs.borderTopWidth,
      radius: parseFloat(cs.borderTopLeftRadius), icon: icon.getBoundingClientRect().width,
      bar: getComputedStyle(icon).backgroundColor,
    };
  });
  expect(styled.background).toBe('rgb(255, 255, 255)');
  expect(styled.border).toBe('0px');
  expect(styled.radius).toBeGreaterThan(20);
  expect(Math.round(styled.icon)).toBe(28);
  // The bars are the button's text colour: the accent, not the header's white.
  expect(styled.bar).toBe(styled.color);
  expect(styled.color).not.toBe('rgb(255, 255, 255)');
});

for (const name of ['first', 'default']) {
  test(`the open menu (${name}) is a panel under the header row, over the page, and the row stays put`, async ({ page: p }) => {
    await p.setViewportSize(PHONE);
    await p.goto(page(name));
    const before = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART), box(p, 'main')]);
    await p.locator(BUTTON).click();
    await expect(p.locator(LIST)).toBeVisible();
    const after = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART), box(p, 'main')]);
    expect(after).toEqual(before);
    const [list, header] = await Promise.all([box(p, LIST), box(p, HEADER)]);
    expect(Math.abs(list.top - header.bottom)).toBeLessThan(2);
    expect(Math.abs(list.width - header.width)).toBeLessThan(2);
    // Over the page: the list covers the top of the main content, which has not moved.
    expect(list.bottom).toBeGreaterThan(before[3].top);
  });
}

test("the open menu's panel is the header's colour", async ({ page: p }) => {
  await p.setViewportSize(PHONE);
  await p.goto(page('styled'));
  await p.locator(BUTTON).click();
  const [panel, header] = await Promise.all([
    p.locator(LIST).evaluate((el) => getComputedStyle(el).backgroundColor),
    p.locator(HEADER).evaluate((el) => getComputedStyle(el).backgroundColor),
  ]);
  expect(header).toBe('rgb(0, 0, 0)');
  expect(panel).toBe('rgb(0, 0, 0)');
});

test('a Large header logo is drawn at the Medium height on phones, and at its own on a wide screen', async ({ page: p }) => {
  const logo = (q) => q.locator('.site-header .thallo-block-logo__image').evaluate((el) => {
    const r = el.getBoundingClientRect();
    return { width: r.width, height: r.height };
  });
  await p.setViewportSize(PHONE);
  await p.goto(page('large'));
  const phone = await logo(p);
  expect(Math.round(phone.height)).toBe(40);
  expect(Math.round(phone.width)).toBe(120);
  // The row stays one line: the logo, the menu button and the button share a top edge.
  const [l, b, c] = await Promise.all([box(p, LOGO), box(p, BUTTON), box(p, CART)]);
  expect(Math.abs(l.top - c.top)).toBeLessThan(20);
  expect(Math.abs(b.top - c.top)).toBeLessThan(20);

  await p.setViewportSize(WIDE);
  expect(Math.round((await logo(p)).height)).toBe(56);
});
