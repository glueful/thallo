// The hover state (hover state spec §4.1, §4.3) as a browser computes it, on the published page and on
// the editor's canvas stage, both real renders of one entry (scripts/build-hover-fixtures): the stage's
// forced preview ([data-thallo-hover]) draws exactly what the pointer draws — the theme's hover look
// alone, and with one authored property over it; an authored value beats the resting utility and the
// theme; keyboard focus shows it and keeps the focus ring; a reset keeps the resting colour; and on a
// device whose primary input cannot hover, a tap leaves nothing behind.
'use strict';

const { test, expect, devices } = require('@playwright/test');

const PAGES = {
  public: '/tools/runtime-browser/fixtures/hover/public.html',
  stage: '/tools/runtime-browser/fixtures/hover/stage.html',
};
const VARIANTS = ['solid', 'outline', 'soft', 'subtle', 'ghost', 'link'];
const PROPS = ['color', 'backgroundColor', 'borderTopColor', 'opacity', 'transform', 'textDecorationThickness'];

// The fixture's elements, in document order (a published page carries no block ids).
const button = (page, i) => page.locator('main .thallo-block-button__link').nth(i);
const plainButton = (page, v) => button(page, VARIANTS.indexOf(v));
const authoredButton = (page, v) => button(page, VARIANTS.length + VARIANTS.indexOf(v));
const resetButton = (page) => button(page, VARIANTS.length * 2);
const link = (page, block) => page.locator('main .thallo-block-links').nth(block).locator('.thallo-block-links__link').first();
const fileLink = (page, i) => page.locator('main .thallo-block-file__link').nth(i);
const social = (page, row, i = 0) =>
  page.locator('main .thallo-block-social_links').nth(row).locator('.thallo-block-social_link__link').nth(i);

const look = (loc) =>
  loc.evaluate((el, props) => {
    const cs = getComputedStyle(el);
    return Object.fromEntries(props.map((p) => [p, cs[p]]));
  }, PROPS);

/** A token's computed colour, through a probe element. */
const colour = (page, token) =>
  page.evaluate((t) => {
    const probe = document.createElement('span');
    probe.style.color = `var(--t-color-${t})`;
    document.body.appendChild(probe);
    const c = getComputedStyle(probe).color;
    probe.remove();
    return c;
  }, token);

const hovered = (loc) => loc.evaluate((el) => el.matches(':hover'));
/** Into the middle of the viewport: an element scrolled only to the edge sits under the sticky header. */
const centre = (loc) =>
  loc.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' }));

/** The real pointer over `loc`. Chromium does not always update :hover on the first move after a
 * scroll, so the pointer nudges until it does (a few pixels at most); the test asserts :hover holds,
 * so a look is never taken at rest. */
async function pointAt(page, loc) {
  await centre(loc);
  const box = await loc.boundingBox();
  for (let nudge = 0; nudge < 6 && !(await hovered(loc)); nudge++) {
    await page.mouse.move(box.x + box.width / 2 + nudge, box.y + box.height / 2);
  }
  expect(await hovered(loc)).toBe(true);
}

/** Away from every element of the fixture: the page's bottom-right corner. */
async function pointAway(page) {
  const size = page.viewportSize();
  await page.mouse.move(size.width - 2, size.height - 2);
  await page.mouse.move(size.width - 1, size.height - 1);
}

/** At rest, under the real pointer, and forced as the stage forces it. */
async function pointerAndForced(page, loc) {
  await centre(loc);
  await pointAway(page);
  expect(await loc.evaluate((el) => el.matches(':hover'))).toBe(false);
  const rest = await look(loc);
  await pointAt(page, loc);
  const pointer = await look(loc);
  await pointAway(page);
  await loc.evaluate((el) => el.setAttribute('data-thallo-hover', ''));
  const forced = await look(loc);
  await loc.evaluate((el) => el.removeAttribute('data-thallo-hover'));
  return { rest, pointer, forced };
}

test.use({ reducedMotion: 'reduce' });

/** The page with every transition and smooth scroll off: the proofs compare end states, never a value
 * mid-flight (the theme's own reduced-motion rules do not cover every element), and point at an element
 * that has finished moving. */
async function open(page, url) {
  await page.goto(url);
  await page.addStyleTag({
    content: '*, *::before, *::after { transition: none !important; } html { scroll-behavior: auto !important; }',
  });
}

for (const [where, url] of Object.entries(PAGES)) {
  test.describe(where, () => {
    test.beforeEach(async ({ page }) => {
      await open(page, url);
    });

    test('pointer hover and forced preview match with no declarations', async ({ page }) => {
      const plain = [
        ...VARIANTS.map((v) => plainButton(page, v)),
        link(page, 0),
        fileLink(page, 0),
        social(page, 0),
      ];
      for (const loc of plain) {
        const { pointer, forced } = await pointerAndForced(page, loc);
        expect(forced).toEqual(pointer);
      }
      // The theme's own hover look is in both: the ghost tint, and the lift.
      const ghost = await pointerAndForced(page, plainButton(page, 'ghost'));
      expect(ghost.pointer.backgroundColor).not.toBe(ghost.rest.backgroundColor);
      const solid = await pointerAndForced(page, plainButton(page, 'solid'));
      expect(solid.pointer.transform).not.toBe(solid.rest.transform);
      const icon = await pointerAndForced(page, social(page, 0));
      expect(icon.pointer.color).not.toBe(icon.rest.color);
    });

    test('pointer hover and forced preview match with one authored property', async ({ page }) => {
      const accent = await colour(page, 'accent');
      const background = async (token) =>
        page.evaluate((t) => {
          const probe = document.createElement('span');
          probe.style.backgroundColor = `var(--t-color-${t})`;
          document.body.appendChild(probe);
          const c = getComputedStyle(probe).backgroundColor;
          probe.remove();
          return c;
        }, token);
      const accentBg = await background('accent');

      for (const v of VARIANTS) {
        const authored = await pointerAndForced(page, authoredButton(page, v));
        const plain = await pointerAndForced(page, plainButton(page, v));
        expect(authored.forced, v).toEqual(authored.pointer);
        expect(authored.pointer.color, v).toBe(accent);
        // Still the theme's: the lift (or the link variant's none), and the tint.
        expect(authored.pointer.transform, v).toBe(plain.pointer.transform);
        if (['ghost', 'soft', 'subtle'].includes(v)) {
          expect(authored.pointer.backgroundColor, v).toBe(plain.pointer.backgroundColor);
        }
      }

      const links = await pointerAndForced(page, link(page, 1));
      const plainLinks = await pointerAndForced(page, link(page, 0));
      expect(links.forced).toEqual(links.pointer);
      expect(links.pointer.color).toBe(accent);
      expect(links.pointer.backgroundColor).toBe(plainLinks.pointer.backgroundColor);

      const plainFile = await pointerAndForced(page, fileLink(page, 0));
      const fileText = await pointerAndForced(page, fileLink(page, 1));
      expect(fileText.forced).toEqual(fileText.pointer);
      expect(fileText.pointer.color).toBe(accent);
      expect(fileText.pointer.backgroundColor).toBe(plainFile.pointer.backgroundColor);
      const fileBg = await pointerAndForced(page, fileLink(page, 2));
      expect(fileBg.forced).toEqual(fileBg.pointer);
      expect(fileBg.pointer.backgroundColor).toBe(accentBg);
      expect(fileBg.pointer.color).toBe(plainFile.pointer.color);

      const plainIcon = await pointerAndForced(page, social(page, 0));
      const iconBg = await pointerAndForced(page, social(page, 1));
      expect(iconBg.forced).toEqual(iconBg.pointer);
      expect(iconBg.pointer.backgroundColor).toBe(accentBg);
      expect(iconBg.pointer.color).toBe(plainIcon.pointer.color);
    });

    test('an authored hover colour beats the resting utility and the theme', async ({ page }) => {
      const accent = await colour(page, 'accent');
      // The row's resting muted icon turns accent: over the resting utility and the theme's ink.
      const icon = await pointerAndForced(page, social(page, 2));
      expect(icon.rest.color).toBe(await colour(page, 'muted'));
      expect(icon.pointer.color).toBe(accent);
      const plainLinks = await pointerAndForced(page, link(page, 0));
      const links = await pointerAndForced(page, link(page, 1));
      expect(links.pointer.color).toBe(accent);
      expect(links.pointer.color).not.toBe(plainLinks.pointer.color);
    });

    test('a reset keeps the resting colour, hovered and forced', async ({ page }) => {
      const reset = await pointerAndForced(page, resetButton(page));
      expect(reset.forced).toEqual(reset.pointer);
      const black = await page.evaluate(() => {
        const probe = document.createElement('span');
        probe.style.backgroundColor = 'var(--t-color-black)';
        document.body.appendChild(probe);
        const c = getComputedStyle(probe).backgroundColor;
        probe.remove();
        return c;
      });
      expect(reset.pointer.backgroundColor).toBe(black);
      const second = await pointerAndForced(page, social(page, 2, 1));
      expect(second.forced).toEqual(second.pointer);
      expect(second.pointer.color).toBe(await colour(page, 'muted'));
    });
  });
}

test('keyboard focus shows the authored hover colour and keeps the focus ring', async ({ page }) => {
  await open(page, PAGES.public);
  const accent = await colour(page, 'accent');
  await page.keyboard.press('Tab');
  const icon = social(page, 2);
  await icon.focus();
  expect(await icon.evaluate((el) => el.matches(':focus-visible'))).toBe(true);
  const focused = await look(icon);
  expect(focused.color).toBe(accent);
  expect(await icon.evaluate((el) => getComputedStyle(el).outlineStyle)).not.toBe('none');
  // The theme's own focus branch stays: the plain File link's surface.
  const plainFile = fileLink(page, 0);
  await centre(plainFile);
  await pointAway(page);
  const rest = await look(plainFile);
  await plainFile.focus();
  expect((await look(plainFile)).backgroundColor).not.toBe(rest.backgroundColor);
});

// A phone's viewport and inputs (isMobile, hasTouch), in the project's own browser.
const { defaultBrowserType: _browser, ...PHONE } = devices['Pixel 7'];

test.describe('a touch-primary device', () => {
  test.use(PHONE);

  test('a tap leaves nothing behind', async ({ page }) => {
    await page.addInitScript(() => document.addEventListener('click', (e) => e.preventDefault(), true));
    await open(page, PAGES.public);
    expect(await page.evaluate(() => matchMedia('(hover: none)').matches)).toBe(true);
    const elements = [
      ...VARIANTS.map((v) => plainButton(page, v)),
      ...VARIANTS.map((v) => authoredButton(page, v)),
      link(page, 0),
      link(page, 1),
      fileLink(page, 0),
      fileLink(page, 2),
      social(page, 0),
      social(page, 2),
    ];
    for (const loc of elements) {
      await loc.scrollIntoViewIfNeeded();
      const rest = await look(loc);
      await loc.tap();
      // Focus is not hover: a tapped link is not keyboard-focused, so no focus branch applies.
      expect(await look(loc)).toEqual(rest);
    }
  });
});
