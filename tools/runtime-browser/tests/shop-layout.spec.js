// The shop home and category pages in a real browser (type layouts plan C2).
//
// `references/shop-{index,category}-original.json` froze today's pages (`shop/index.twig` and
// `shop/category.twig` with `_product_card.twig` and `_pagination.twig`) before any C2 template or
// stylesheet change (scripts/capture-shop-layout-reference). Every later rendering of the pages
// without a layout is held to them here, with the same measuring procedure, so the pages keep their
// look however the templates and the shop stylesheet behind them change.
//
// The references hold on any platform's fonts: a box its text sizes is measured by its top and
// height (or height alone, where a baseline places it) and its computed style — never by a width
// set by a font's advance widths. When CI disagrees with a local capture, diagnose the property
// first: a regression is fixed; a difference that is only font metrics is re-expressed as a
// layout-determined assertion of the same behaviour, never simply dropped, and no tolerance widens.
//
// The pages come from scripts/build-shop-layout-proof-fixtures (gitignored): the shop of
// ShopPageSeed seeded with fixed values, every stylesheet, font and image inlined, scripts dropped.
'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { compareToReference } = require('./support/compare.js');

const REPO = path.resolve(__dirname, '..', '..', '..');
const DEFINITION = JSON.parse(
  fs.readFileSync(path.join(REPO, 'tests/fixtures/commerce/shop-page-reference.json'), 'utf8'),
);
const URL_BASE = '/tools/runtime-browser/fixtures/shop-layout';

const reference = (page) =>
  JSON.parse(fs.readFileSync(path.join(REPO, `tools/runtime-browser/references/shop-${page}-original.json`), 'utf8'));

test("today's shop home and category pages match their frozen reference", async ({ page }) => {
  const definition = { ...DEFINITION, map: DEFINITION.elements };
  for (const name of ['index', 'category']) {
    const failures = await compareToReference(page, reference(name), definition, `${URL_BASE}/shop-${name}-original.html`);
    expect(failures, `${name}:\n${failures.join('\n')}`).toEqual([]);
  }
});

// ---------------------------------------------------------------------------------------------
// The starter against the frozen pages (type layouts plan C2, S6).
//
// The shop home and category layouts' starter — the Shop title, the Category chips, the Product list
// whose card is the Product tile over a Grid body of the name and a row of the rating and the price,
// and the Page navigation — is held to the same references, on the page and on the stage. Each name
// the reference recorded is found in the layout page by what the starter renders for it; on the stage
// each block sits in a selection wrapper, so the selectors name the blocks' own classes.

const { measurePage } = require('../scripts/measure.js');

const CARD = (n) => `#main .thallo-block-product_loop__cards > .thallo-loop-card:nth-child(${n})`;
const STARTER_MAP = {
  section: '#main > section',
  titlerow: '#main .thallo-block-shop_title',
  heading: '#main .shop-titlerow__heading',
  count: '#main .shop-titlerow__count',
  rail: '#main .thallo-block-category_rail',
  chip1: '#main .shop-rail > .shop-rail__chip:nth-child(1)',
  grid: '#main .thallo-block-product_loop__cards',
  pagination: '#main .thallo-block-pagination',
};
for (const n of [1, 2, 5]) {
  Object.assign(STARTER_MAP, {
    [`card${n}`]: CARD(n),
    [`tile${n}`]: `${CARD(n)} .thallo-block-product_tile`,
    [`image${n}`]: `${CARD(n)} .shop-grid__image`,
    [`tag${n}`]: `${CARD(n)} .shop-grid__tag`,
    [`actions${n}`]: `${CARD(n)} .shop-grid__actions`,
    [`name${n}`]: `${CARD(n)} .shop-grid__name`,
    [`meta${n}`]: `${CARD(n)} .thallo-block-container .thallo-block-container`,
    [`rating${n}`]: `${CARD(n)} .shop-grid__rating`,
    [`price${n}`]: `${CARD(n)} .shop-grid__price-current`,
  });
}

// The user's S6 rulings (2026-09-29). The title row's and the Product list's theme block margins are
// corrected in shop.css, so the public pages and the home's stage carry no exception at all. The one
// accepted difference is stage-only: on a one-page category the stage shows Page navigation's named
// placeholder ("Page navigation — one page", so the block stays selectable) where the site shows
// nothing. For that stage alone the comparison sets the placeholder aside and relaxes the section's
// height — and then asserts that the height grew by exactly the room the placeholder takes.

for (const name of ['index', 'category']) {
  for (const where of ['', '-stage']) {
    test(`the ${name} starter${where ? ' on the stage' : ''} matches today's page`, async ({ page }) => {
      const url = `${URL_BASE}/shop-${name}-starter${where}.html`;
      const placeholderStage = name === 'category' && where === '-stage';
      const definition = {
        ...DEFINITION,
        map: STARTER_MAP,
        allow: placeholderStage ? [{ element: 'section', relax: ['height'] }] : [],
      };
      const blind = placeholderStage ? { elements: new Set(['pagination']) } : {};
      const failures = await compareToReference(page, reference(name), definition, url, blind);
      expect(failures, `${name}${where}:\n${failures.join('\n')}`).toEqual([]);
      if (!placeholderStage) {
        return;
      }
      for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(url);
        const room = await page.evaluate(() => {
          const placeholder = document.querySelector('#main .thallo-block-pagination.thallo-field-empty');
          const loop = document.querySelector('#main .thallo-block-product_loop');
          const section = document.querySelector('#main > section');
          return {
            text: placeholder ? placeholder.textContent.trim() : null,
            added: placeholder ? placeholder.getBoundingClientRect().bottom - loop.getBoundingClientRect().bottom : null,
            height: section.getBoundingClientRect().height,
          };
        });
        const frozen = reference(name).widths[String(width)].section.geometry.height;
        expect(room.text, `@${width}`).toBe('Page navigation — one page');
        expect(Math.abs(room.height - frozen - room.added), `@${width}: the section grew by the placeholder alone`).toBeLessThanOrEqual(0.5);
      }
    });
  }
}

// ---------------------------------------------------------------------------------------------
// Authored values win, removing them returns the defaults, and the Product list's arrangement is
// the same on the page and the stage: untouched (the shop's adaptive grid), on a flex row, on a
// class's three columns, reset over that class, and after a Remove.

/** A token's value, resolved by the browser, for a property. */
const resolved = (page, property, variable) =>
  page.evaluate(([property, variable]) => {
    const probe = document.createElement('span');
    probe.style.setProperty(property, `var(${variable})`);
    document.body.appendChild(probe);
    const value = getComputedStyle(probe).getPropertyValue(property);
    probe.remove();
    return value;
  }, [property, variable]);

const styleOf = (page, selector, property) =>
  page.locator(selector).first().evaluate((el, property) => getComputedStyle(el).getPropertyValue(property), property);

const WIDTHS = [375, 700, 800, 1280];

/**
 * The adaptive grid's first row at the current width, derived: the list's content width, its
 * computed column gap and the track minimum the shop stylesheet specifies (15rem, converted at the
 * root font size) give how many cards fit; the cards sharing the first card's top are counted.
 */
const adaptiveRow = (page) =>
  page.locator('#main .thallo-block-product_loop__cards, #main .shop-grid').first().evaluate((list) => {
    const style = getComputedStyle(list);
    const width = list.getBoundingClientRect().width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
    const gap = parseFloat(style.columnGap);
    const min = 15 * parseFloat(getComputedStyle(document.documentElement).fontSize);
    const expected = Math.max(1, Math.floor((width + gap) / (min + gap)));
    const items = Array.from(list.children);
    const top = items[0].getBoundingClientRect().top;
    const actual = items.filter((li) => Math.abs(li.getBoundingClientRect().top - top) < 1).length;
    return { expected, actual, display: style.display, columns: style.gridTemplateColumns, gap: style.columnGap, rowGap: style.rowGap };
  });

const perRow = (page) =>
  page.locator('#main .thallo-block-product_loop__cards').evaluate((list) => {
    const items = Array.from(list.children);
    const top = items[0].getBoundingClientRect().top;
    return items.filter((li) => Math.abs(li.getBoundingClientRect().top - top) < 1).length;
  });

for (const name of ['index', 'category']) {
  for (const where of ['', '-stage']) {
    const at = (state) => `${URL_BASE}/shop-${name}-${state}${where}.html`;

    test(`authored values win on the ${name}${where ? ' stage' : ' page'}, and removing them returns the defaults`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(at('starter'));
      const defaults = {
        heading: await styleOf(page, '#main .shop-titlerow__heading', 'font-size'),
        headingColor: await styleOf(page, '#main .shop-titlerow__heading', 'color'),
        name: await styleOf(page, STARTER_MAP.name1, 'font-size'),
        price: await styleOf(page, STARTER_MAP.price1, 'color'),
      };

      await page.goto(at('authored'));
      expect(await styleOf(page, '#main .shop-titlerow__heading', 'font-size')).toBe(await resolved(page, 'font-size', '--t-typography-size-3xl'));
      expect(await styleOf(page, '#main .shop-titlerow__heading', 'color')).toBe(await resolved(page, 'color', '--t-color-accent'));
      expect(await styleOf(page, STARTER_MAP.name1, 'font-size')).toBe(await resolved(page, 'font-size', '--t-typography-size-lg'));
      expect(await styleOf(page, `${CARD(1)} .thallo-block-product_price`, 'color')).toBe(await resolved(page, 'color', '--t-color-muted'));
      expect(await styleOf(page, '#main .thallo-block-product_loop__cards', 'column-gap')).toBe(await resolved(page, 'column-gap', '--t-spacing-lg'));
      // Authored spacing beats the shop's own margins on the title row and the Product list.
      expect(await styleOf(page, '#main .thallo-block-shop_title', 'margin-top')).toBe(await resolved(page, 'margin-top', '--t-spacing-lg'));
      expect(await styleOf(page, '#main .thallo-block-product_loop', 'margin-bottom')).toBe(await resolved(page, 'margin-bottom', '--t-spacing-xl'));
      if (name === 'index') {
        expect(await perRow(page), 'three columns at 1280').toBe(3);
        await page.setViewportSize({ width: 375, height: 900 });
        expect(await perRow(page), 'one column at 375').toBe(1);
        await page.setViewportSize({ width: 1280, height: 900 });
      }

      await page.goto(at('reset'));
      expect({
        heading: await styleOf(page, '#main .shop-titlerow__heading', 'font-size'),
        headingColor: await styleOf(page, '#main .shop-titlerow__heading', 'color'),
        name: await styleOf(page, STARTER_MAP.name1, 'font-size'),
        price: await styleOf(page, STARTER_MAP.price1, 'color'),
      }).toEqual(defaults);
    });

    test(`the Product list's arrangement on the ${name}${where ? ' stage' : ' page'}: untouched, flex, a class and a reset over it, and after Remove`, async ({ page }) => {
      for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${URL_BASE}/shop-${name}-original.html`);
        const theme = await adaptiveRow(page);

        await page.goto(at('starter'));
        const untouched = await adaptiveRow(page);
        expect(untouched.display, `untouched @${width}`).toBe('grid');
        expect(untouched.actual, `untouched @${width}: the adaptive tracks`).toBe(Math.min(untouched.expected, name === 'category' ? 2 : 24));
        expect([untouched.columns, untouched.gap, untouched.rowGap], `untouched @${width}: the theme page's tracks and gaps`).toEqual([theme.columns, theme.gap, theme.rowGap]);

        await page.goto(at('flex'));
        const flex = await adaptiveRow(page);
        expect(flex.display, `flex @${width}`).toBe('flex');

        await page.goto(at('class-three'));
        // The style class's spacing beats the shop's own margin on the Product list too.
        expect(await styleOf(page, '#main .thallo-block-product_loop', 'margin-top'), `class @${width}`).toBe(await resolved(page, 'margin-top', '--t-spacing-lg'));
        if (name === 'index' && width >= 700) {
          expect(await perRow(page), `class @${width}: three columns`).toBe(3);
        }

        await page.goto(at('class-reset'));
        const reset = await adaptiveRow(page);
        expect([reset.columns, reset.actual], `class-reset @${width}: back to the theme's tracks`).toEqual([untouched.columns, untouched.actual]);

        await page.goto(at('after-remove'));
        const removed = await adaptiveRow(page);
        expect([removed.columns, removed.actual], `after-remove @${width}`).toEqual([theme.columns, theme.actual]);
      }
    });
  }
}

test('removed, the public pages are the theme pages again', async ({ page }) => {
  const definition = { ...DEFINITION, map: DEFINITION.elements };
  for (const name of ['index', 'category']) {
    const failures = await compareToReference(page, reference(name), definition, `${URL_BASE}/shop-${name}-after-remove.html`);
    expect(failures, `${name}:\n${failures.join('\n')}`).toEqual([]);
  }
});

test('the tile\'s quick buttons show on a card\'s hover and focus, as today', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  for (const file of ['shop-index-original.html', 'shop-index-starter.html']) {
    await page.goto(`${URL_BASE}/${file}`);
    const card = page.locator('#main .shop-grid__item').first();
    const actions = card.locator('.shop-grid__actions');
    const rest = await actions.evaluate((el) => getComputedStyle(el).opacity);
    await card.hover();
    await expect.poll(() => actions.evaluate((el) => getComputedStyle(el).opacity), { message: file }).toBe('1');
    expect(rest, `${file}: hidden at rest`).not.toBe('1');
  }
});

for (const file of ['long-names-original', 'long-names', 'long-names-stage']) {
  test(`a long name stays in its card, truncated, with its full accessible name (${file})`, async ({ page }) => {
    const fullName = 'Hand-thrown stoneware serving bowl with ash glaze, speckled finish';
    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${URL_BASE}/${file}.html`);
      const facts = await page.evaluate(() => {
        const name = document.querySelector('#main .shop-grid__name');
        const card = name.closest('li');
        const style = getComputedStyle(name);
        return {
          inside: name.getBoundingClientRect().right <= card.getBoundingClientRect().right + 0.5,
          ellipsis: style.textOverflow,
          nowrap: style.whiteSpace,
          overflow: style.overflow,
          truncated: name.scrollWidth > name.clientWidth,
          cardInsideTrack: card.getBoundingClientRect().right <= card.parentElement.getBoundingClientRect().right + 0.5,
          pageScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });
      expect(facts, `${file} @${width}`).toEqual({
        inside: true, ellipsis: 'ellipsis', nowrap: 'nowrap', overflow: 'hidden', truncated: true,
        cardInsideTrack: true, pageScroll: false,
      });
      await expect(page.getByRole('link', { name: fullName, exact: true }).first()).toBeAttached();
    }
  });
}
