// Letter spacing, text transform and text decoration (settings version 12), as a browser computes
// them: on the published page and on the editor's canvas stage, both real renders of one entry
// (scripts/build-text-style-fixtures). Each setting is measured against an untouched control:
// a value applies, `none` is an explicit value, a reset gives back exactly what the theme draws —
// at rest and under the pointer — a block's own value beats its style class's, and decoration is
// set by its longhand, so the theme's offset and hover thickness survive an underline.
'use strict';

const { test, expect } = require('@playwright/test');

const PAGES = {
  public: '/tools/runtime-browser/fixtures/text-style/public.html',
  stage: '/tools/runtime-browser/fixtures/text-style/stage.html',
};

// The fixture's blocks, in document order (a published page carries no block ids).
const HEADINGS = ['plain', 'case', 'normal', 'reset', 'class', 'italic'];
const LINKS = ['plain', 'underline', 'none', 'reset'];
const BUTTONS = ['plain', 'underline', 'none', 'reset'];

const heading = (page, name) => page.locator('main .thallo-block-heading').nth(HEADINGS.indexOf(name));
const link = (page, name) =>
  page.locator('main .thallo-block-links').nth(LINKS.indexOf(name)).locator('.thallo-block-links__link').first();
const button = (page, name) => page.locator('main .thallo-block-button__link').nth(BUTTONS.indexOf(name));
const separator = (page, name) =>
  page.locator('main .thallo-block-separator').nth(['plain', 'styled', 'short', 'medium'].indexOf(name));

/** A separator's line colours, and what its label draws. */
const separatorDrawn = (locator) =>
  locator.evaluate((el) => ({
    lines: [...el.querySelectorAll('.thallo-block-separator__line')].map((l) => getComputedStyle(l).borderTopColor),
    accent: (() => {
      const probe = document.createElement('span');
      probe.style.color = 'var(--t-color-accent)';
      el.appendChild(probe);
      const colour = getComputedStyle(probe).color;
      probe.remove();
      return colour;
    })(),
    label: getComputedStyle(el.querySelector('.thallo-block-separator__content')).textTransform,
  }));

/** What the browser draws for an element: casing, tracking (in em) and its decoration longhands. */
const drawn = (locator) =>
  locator.evaluate((el) => {
    const cs = getComputedStyle(el);
    const size = parseFloat(cs.fontSize);
    return {
      transform: cs.textTransform,
      spacing: cs.letterSpacing === 'normal' ? 'normal' : Math.round((parseFloat(cs.letterSpacing) / size) * 1000) / 1000,
      line: cs.textDecorationLine,
      thickness: cs.textDecorationThickness,
      offset: cs.textUnderlineOffset,
    };
  });

/** Drawn under the pointer, once any transition has settled. */
async function hovered(page, locator) {
  await locator.hover();
  let last;
  await expect
    .poll(async () => {
      const now = JSON.stringify(await drawn(locator));
      const settled = now === last;
      last = now;
      return settled;
    }, { intervals: [100, 100, 200, 400] })
    .toBe(true);
  await page.mouse.move(0, 0);
  return JSON.parse(last);
}

for (const [where, url] of Object.entries(PAGES)) {
  test.describe(`${where} rendering`, () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(url);
      await expect(page.locator('main .thallo-block-heading')).toHaveCount(HEADINGS.length);
      await expect(page.locator('main .thallo-block-links')).toHaveCount(LINKS.length);
      await expect(page.locator('main .thallo-block-button__link')).toHaveCount(BUTTONS.length);
      await expect(page.locator('main .thallo-block-separator')).toHaveCount(4);
      await expect(page.locator('main .thallo-block-logos')).toHaveCount(5);
    });

    test('a heading takes its casing and tracking, and neither brings the other', async ({ page }) => {
      const plain = await drawn(heading(page, 'plain'));
      expect(plain.transform).toBe('none');
      expect(plain.spacing).toBe(-0.02); // the theme's heading tracking
      const cased = await drawn(heading(page, 'case'));
      expect(cased.transform).toBe('uppercase');
      expect(cased.spacing).toBe(0.1);
      expect(cased.line).toBe('none');
    });

    test("Normal overrides the theme's tracking; a reset gives it back", async ({ page }) => {
      const plain = await drawn(heading(page, 'plain'));
      expect((await drawn(heading(page, 'normal'))).spacing).toBe('normal');
      expect((await drawn(heading(page, 'normal'))).transform).toBe(plain.transform);
      expect(await drawn(heading(page, 'reset'))).toEqual(plain);
    });

    test("a block's own casing beats its style class; the class's other values stay", async ({ page }) => {
      const classed = await drawn(heading(page, 'class'));
      expect(classed.transform).toBe('lowercase'); // the block's, over the class's uppercase
      expect(classed.spacing).toBe(0.05); // the class's Wide
      expect(classed.line).toBe('underline'); // the class's underline
    });

    test("a Links link's underline holds at rest and under the pointer", async ({ page }) => {
      expect((await drawn(link(page, 'plain'))).line).toBe('none'); // the theme's
      expect((await drawn(link(page, 'underline'))).line).toBe('underline');
      expect((await hovered(page, link(page, 'underline'))).line).toBe('underline');
    });

    test("a Links link's None stays none, and its reset draws what the theme draws", async ({ page }) => {
      expect((await drawn(link(page, 'none'))).line).toBe('none');
      expect((await hovered(page, link(page, 'none'))).line).toBe('none');
      expect(await drawn(link(page, 'reset'))).toEqual(await drawn(link(page, 'plain')));
      expect(await hovered(page, link(page, 'reset'))).toEqual(await hovered(page, link(page, 'plain')));
    });

    test("over a theme that underlines, None removes it and a reset restores it", async ({ page }) => {
      const plain = await drawn(button(page, 'plain'));
      const plainHover = await hovered(page, button(page, 'plain'));
      expect(plain.line).toBe('underline'); // the link variant's own underline
      expect(plainHover.thickness).toBe('2px');
      expect((await drawn(button(page, 'none'))).line).toBe('none');
      expect((await hovered(page, button(page, 'none'))).line).toBe('none');
      expect(await drawn(button(page, 'reset'))).toEqual(plain);
      expect(await hovered(page, button(page, 'reset'))).toEqual(plainHover);
    });

    test("an underline is set by its longhand: the theme's offset and hover thickness survive", async ({ page }) => {
      const plain = await drawn(button(page, 'plain'));
      const under = await drawn(button(page, 'underline'));
      expect(under).toEqual(plain);
      expect(under.offset).toBe('3px');
      expect(await hovered(page, button(page, 'underline'))).toEqual(await hovered(page, button(page, 'plain')));
    });

    test("a separator's border colour draws both its lines, and its label takes Typography", async ({ page }) => {
      const plain = await separatorDrawn(separator(page, 'plain'));
      const styled = await separatorDrawn(separator(page, 'styled'));
      expect(styled.lines).toHaveLength(2);
      expect(styled.lines).toEqual([styled.accent, styled.accent]);
      expect(plain.lines[0]).not.toBe(styled.accent); // the theme's line colour, untouched
      expect(styled.label).toBe('uppercase');
      expect(plain.label).toBe('none');
    });

    test('a short separator is a 3.5rem accent at the start, a medium one 8rem at the end', async ({ page }) => {
      const box = (loc) =>
        loc.evaluate((el) => {
          const root = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          const line = el.querySelector('.thallo-block-separator__line').getBoundingClientRect();
          return {
            start: root.left + parseFloat(cs.paddingLeft),
            end: root.right - parseFloat(cs.paddingRight),
            left: line.left,
            right: line.right,
            width: line.width,
            thickness: parseFloat(getComputedStyle(el.querySelector('.thallo-block-separator__line')).borderTopWidth),
          };
        });
      const rem = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
      const short = await box(separator(page, 'short'));
      expect(short.width).toBeCloseTo(3.5 * rem, 0);
      expect(short.left).toBeCloseTo(short.start, 0);
      expect(short.thickness).toBe(2);
      const medium = await box(separator(page, 'medium'));
      expect(medium.width).toBeCloseTo(8 * rem, 0);
      expect(medium.right).toBeCloseTo(medium.end, 0);
      // The full line still fills its row.
      const full = await box(separator(page, 'plain'));
      expect(full.width).toBeGreaterThan(medium.width);
    });

    test('a Logos block draws its logos at the size set for the width, and spaces them as set', async ({ page }) => {
      const strip = (i) => page.locator('main .thallo-block-logos').nth(i);
      const measured = (i) =>
        strip(i).evaluate((el) => {
          const track = getComputedStyle(el.querySelector('.thallo-block-logos__track'));
          return {
            heights: [...el.querySelectorAll('.thallo-block-logos__image')].map((img) => img.getBoundingClientRect().height),
            columnGap: track.columnGap,
            rowGap: track.rowGap,
            space1: getComputedStyle(el).getPropertyValue('--space-1').trim(),
          };
        });
      await page.setViewportSize({ width: 1280, height: 900 }); // lg
      const plain = await measured(0);
      const sized = await measured(1);
      expect(plain.heights).toEqual([40, 40]); // the theme's 2.5rem
      expect(sized.heights).toEqual([80, 80]); // Extra large, from lg up
      expect(sized.columnGap).not.toBe(plain.columnGap);
      expect(sized.rowGap).toBe('0px');
      // Every logo of a scrolling strip, its copied run too, takes the size.
      expect((await measured(2)).heights).toEqual([56, 56, 56, 56]);

      await page.setViewportSize({ width: 600, height: 900 }); // base
      expect((await measured(1)).heights).toEqual([28, 28]); // Small
      expect((await measured(0)).heights).toEqual([40, 40]);
    });

    test('a logo max width scales a wider logo down whole and leaves a narrower one alone', async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      const logos = await page
        .locator('main .thallo-block-logos')
        .nth(3)
        .evaluate((el) =>
          [...el.querySelectorAll('.thallo-block-logos__image')].map((img) => {
            const box = img.getBoundingClientRect();
            return { width: Math.round(box.width), height: box.height, fit: getComputedStyle(img).objectFit };
          }),
        );
      // Extra large is 80px tall: the 4:3 logo would be 107px wide, over the Narrow cap of 96px.
      expect(logos).toEqual([
        { width: 96, height: 80, fit: 'contain' },
        { width: 80, height: 80, fit: 'contain' },
      ]);
    });

    test("the logos start at the block's top, or right under its title", async ({ page }) => {
      const gaps = (i) =>
        page
          .locator('main .thallo-block-logos')
          .nth(i)
          .evaluate((el) => {
            const track = el.querySelector('.thallo-block-logos__track').getBoundingClientRect();
            const title = el.querySelector('.thallo-block-logos__title');
            return {
              fromTop: Math.round(track.top - el.getBoundingClientRect().top),
              fromTitle: title ? Math.round(track.top - title.getBoundingClientRect().bottom) : null,
              titleMargin: title ? Math.round(parseFloat(getComputedStyle(title).marginBottom)) : null,
            };
          });
      // Untitled: no space of the theme's own above the logos; the block's Spacing decides it.
      expect((await gaps(0)).fromTop).toBe(0);
      // Titled: the title's bottom margin, and nothing stacked on it.
      const titled = await gaps(4);
      expect(titled.fromTitle).toBe(titled.titleMargin);
      expect(titled.titleMargin).toBeGreaterThan(0);
    });

    test('Font style italicises a heading', async ({ page }) => {
      const style = await page.locator('main .thallo-block-heading').last().evaluate((el) => getComputedStyle(el).fontStyle);
      expect(style).toBe('italic');
    });

    test("the Footer's divider is the top section's bottom edge, styled, and never a box", async ({ page }) => {
      const edges = (i) =>
        page.locator('main .thallo-block-footer__top').nth(i).evaluate((el) => {
          const cs = getComputedStyle(el);
          return { bottom: `${cs.borderBottomWidth} ${cs.borderBottomStyle}`, color: cs.borderBottomColor, top: cs.borderTopWidth, left: cs.borderLeftWidth };
        });
      const plain = await edges(0);
      const styled = await edges(1);
      expect(plain.bottom).toBe('1px solid'); // the theme's line
      expect(styled.bottom).toBe('4px dashed');
      expect(styled.color).not.toBe(plain.color);
      expect([styled.top, styled.left]).toEqual(['0px', '0px']);
    });

    test("Social links' Icon section styles every icon", async ({ page }) => {
      const drawn = (i) =>
        page.locator('main .thallo-block-social_links').nth(i).evaluate((row) =>
          [...row.querySelectorAll('.thallo-block-social_link__link')].map((a) => {
            const cs = getComputedStyle(a);
            const svg = a.querySelector('svg');
            return { background: cs.backgroundColor, radius: cs.borderTopLeftRadius, icon: svg ? Math.round(svg.getBoundingClientRect().width) : 0 };
          }),
        );
      const plain = await drawn(0);
      const styled = await drawn(1);
      expect(styled).toHaveLength(2);
      for (const icon of styled) {
        expect(icon.background).toBe('rgb(0, 0, 0)');
        expect(icon.radius).not.toBe('0px');
        expect(icon.icon).toBeGreaterThan(plain[0].icon); // Size scales the icon
      }
      expect(plain[0].background).toBe('rgba(0, 0, 0, 0)');
    });

    test("a Social link's own Icon section beats the row's, and its Text colour reaches the icon", async ({ page }) => {
      const links = page.locator('main .thallo-block-social_links').nth(2).locator('.thallo-block-social_link__link');
      const look = (i) => links.nth(i).evaluate((a) => {
        const cs = getComputedStyle(a);
        const svg = a.querySelector('svg');
        return { background: cs.backgroundColor, color: cs.color, border: cs.borderTopWidth, radius: cs.borderTopLeftRadius, icon: svg ? Math.round(svg.getBoundingClientRect().width) : 0 };
      });
      const accent = await page.evaluate(() => {
        const probe = document.createElement('span');
        probe.style.color = 'var(--accent)';
        document.body.append(probe);
        const c = getComputedStyle(probe).color;
        probe.remove();
        return c;
      });
      const own = await look(0);
      const plainRow = await page.locator('main .thallo-block-social_links').nth(0).locator('.thallo-block-social_link__link').first().evaluate((a) => Math.round(a.querySelector('svg').getBoundingClientRect().width));
      expect(own.background).toBe(accent); // not the row's black
      expect(own.color).toBe('rgb(255, 255, 255)');
      expect(own.border).toBe('2px');
      expect(own.radius).not.toBe('0px');
      expect(own.icon).toBeGreaterThan(plainRow);
      const tinted = await look(1);
      expect(tinted.background).toBe('rgb(0, 0, 0)'); // the row's, where the link sets none
      expect(tinted.color).toBe(accent); // the link's Text colour, not the theme's muted grey
    });
  });
}
