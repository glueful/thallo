// Proves the modern-blocks additions (animated_text, gallery, and the
// hero-carousel preset) against a REAL browser, exactly as the rest of this
// gate does for the runtime elements — real CSS cascade/specificity, real
// IntersectionObserver timing, real <dialog> focus/Escape semantics, and real
// deferred-script boot ordering, none of which the Node hand-stubbed DOM
// (packages/thallo-render/tests) can model.
//
// Fixture: fixtures/blocks.html — hand-rendered from animated_text.twig,
// gallery.twig, carousel.twig and hero.twig's class contract (see the
// fixture's own header comment for the exact field values each markup block
// corresponds to).
'use strict';

const { test, expect } = require('@playwright/test');

const FIXTURE = '/tools/runtime-browser/fixtures/blocks.html';

test.describe('animated text', () => {
  test('the in-viewport instance renders the exact visible phrase, with no --in-view before it is on screen elsewhere', async ({ page }) => {
    await page.goto(FIXTURE);

    // The core marker lands synchronously inside enhance() (no promise
    // deferral, unlike the custom-element bridge) — safe to read immediately.
    const inview = await page.evaluate(() => {
      const el = document.querySelector('[data-fixture="animated-text-inview"] .thallo-block-animated_text');
      // The stable phrase now also lives in a visually-hidden __sr span (clip-path,
      // NOT display:none/visibility:hidden), so el.innerText would double up the
      // phrase — read visible text from the aria-hidden wrapper span specifically.
      const visible = el.querySelector('span[aria-hidden="true"]');
      const sr = el.querySelector('.thallo-block-animated_text__sr');
      return {
        // innerText inserts a forced line break around the rotate span
        // (its computed display is inline-grid, one of the display values
        // the innerText algorithm always breaks around) — collapse
        // whitespace/newlines to compare the visible WORDS, not formatting.
        text: visible.innerText.replace(/\s+/g, ' ').trim(),
        srText: sr.textContent,
        prepared: el.classList.contains('thallo-block-animated_text--prepared'),
        marker: el.getAttribute('data-thallo-enhanced')
      };
    });
    // innerText respects visibility: the two visibility:hidden alternates
    // ("Websites", "Stores") contribute width via the same-grid-cell layout
    // but never paint, so only the prefix + the active word are rendered.
    expect(inview.text).toBe('Build fast with Thallo');
    // The visually-hidden span carries the FULL stable phrase for assistive tech,
    // regardless of which word is currently the visible/active one.
    expect(inview.srText).toBe('Build fast with Thallo');
    expect(inview.prepared).toBe(true);
    expect(inview.marker).toBe('animated-text');
  });

  test('the below-fold instance has no --in-view before scroll, reveals on scroll, and its rotation settles on the last word within 5s', async ({ page }) => {
    await page.goto(FIXTURE);

    const belowFoldSelector = '[data-fixture="animated-text-below-fold"] .thallo-block-animated_text';

    // Premise: genuinely below the fold, and prepared-but-not-revealed —
    // --prepared is added unconditionally by enhance() (before IO reports
    // anything); --in-view only follows a real intersection.
    const beforeScroll = await page.evaluate((sel) => {
      const el = document.querySelector(sel);
      const rect = el.getBoundingClientRect();
      return {
        belowViewport: rect.top >= window.innerHeight,
        prepared: el.classList.contains('thallo-block-animated_text--prepared'),
        inView: el.classList.contains('thallo-block-animated_text--in-view')
      };
    }, belowFoldSelector);
    expect(beforeScroll.belowViewport).toBe(true);
    expect(beforeScroll.prepared).toBe(true);
    expect(beforeScroll.inView).toBe(false);

    await page.locator(belowFoldSelector).scrollIntoViewIfNeeded();

    await page.waitForFunction((sel) => {
      const el = document.querySelector(sel);
      return el.classList.contains('thallo-block-animated_text--in-view');
    }, belowFoldSelector, { timeout: 5000 });

    // Rotation is a 1s/word setInterval starting once in-view; with 3 words
    // ("Watch" -> "This" -> "Reveal") it settles on the last word by ~2s —
    // comfortably inside the spec's 5s ceiling.
    await page.waitForFunction((sel) => {
      const active = document.querySelector(sel + ' .thallo-block-animated_text__word--active');
      return !!active && active.textContent === 'Reveal';
    }, belowFoldSelector, { timeout: 5000 });
  });

  test('prefers-reduced-motion: no --prepared class, and the static text stays fully visible', async ({ page }) => {
    // Set BEFORE navigation so matchMedia('(prefers-reduced-motion: reduce)')
    // already reports true when block-animated-text.js's enhance() runs.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(FIXTURE);

    const data = await page.evaluate(() => {
      const el = document.querySelector('[data-fixture="animated-text-inview"] .thallo-block-animated_text');
      // Same rationale as the in-viewport test above: read visible text from the
      // aria-hidden wrapper span, not el.innerText, since the visually-hidden __sr
      // span (clip-path only, not display:none) would otherwise double the phrase.
      const visible = el.querySelector('span[aria-hidden="true"]');
      return {
        prepared: el.classList.contains('thallo-block-animated_text--prepared'),
        inView: el.classList.contains('thallo-block-animated_text--in-view'),
        // No --prepared means none of the opacity/animation rules engage —
        // the static floor's own opacity (1, unset) is what's left.
        opacity: getComputedStyle(el).opacity,
        // innerText inserts a forced line break around the rotate span
        // (its computed display is inline-grid, one of the display values
        // the innerText algorithm always breaks around) — collapse
        // whitespace/newlines to compare the visible WORDS, not formatting.
        text: visible.innerText.replace(/\s+/g, ' ').trim(),
        // enhance() returns false before ever calling RT's mark() — the
        // component is never enhanced at all under reduced motion.
        marker: el.getAttribute('data-thallo-enhanced')
      };
    });
    expect(data.prepared).toBe(false);
    expect(data.inView).toBe(false);
    expect(data.opacity).toBe('1');
    expect(data.text).toBe('Build fast with Thallo');
    expect(data.marker).toBeNull();
  });
});

test.describe('gallery layout', () => {
  test('natural mode: the nested .thallo-block-image reset has zero standalone margin/padding and preserves the natural aspect height', async ({ page }) => {
    await page.goto(FIXTURE);

    const data = await page.evaluate(() => {
      const item = document.querySelector('[data-fixture="gallery-natural"] .thallo-block-gallery__item');
      const imageDiv = item.querySelector('.thallo-block-image');
      const img = item.querySelector('img');
      const style = getComputedStyle(imageDiv);
      const rect = imageDiv.getBoundingClientRect();
      return {
        margin: [style.marginTop, style.marginRight, style.marginBottom, style.marginLeft],
        padding: [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft],
        maxWidth: style.maxWidth,
        width: rect.width,
        height: rect.height,
        naturalWidth: img.naturalWidth,
        naturalHeight: img.naturalHeight
      };
    });
    expect(data.margin).toEqual(['0px', '0px', '0px', '0px']);
    expect(data.padding).toEqual(['0px', '0px', '0px', '0px']);
    expect(data.maxWidth).toBe('none');
    // natural-1.svg is 800x450 (16:9) — the rendered height must track that
    // intrinsic ratio (nothing here forces a square/landscape aspect-ratio
    // box), within 1 CSS pixel of rounding.
    const expectedHeight = data.width * (data.naturalHeight / data.naturalWidth);
    expect(Math.abs(data.height - expectedHeight)).toBeLessThanOrEqual(1);
  });

  test('square and landscape item boxes match their declared ratio within 1px, and the image fills/crops the box', async ({ page }) => {
    await page.goto(FIXTURE);

    const data = await page.evaluate(() => {
      function measure(sel) {
        const item = document.querySelector(sel);
        const img = item.querySelector('img');
        const itemRect = item.getBoundingClientRect();
        const imgRect = img.getBoundingClientRect();
        return {
          itemW: itemRect.width,
          itemH: itemRect.height,
          imgW: imgRect.width,
          imgH: imgRect.height,
          objectFit: getComputedStyle(img).objectFit
        };
      }
      return {
        square: measure('[data-fixture="gallery-square"] .thallo-block-gallery__item'),
        landscape: measure('[data-fixture="gallery-landscape"] .thallo-block-gallery__item')
      };
    });

    // Square: width === height, within 1px.
    expect(Math.abs(data.square.itemW - data.square.itemH)).toBeLessThanOrEqual(1);
    expect(data.square.objectFit).toBe('cover');
    expect(Math.abs(data.square.imgW - data.square.itemW)).toBeLessThanOrEqual(1);
    expect(Math.abs(data.square.imgH - data.square.itemH)).toBeLessThanOrEqual(1);

    // Landscape: 3:2, within 1px.
    expect(Math.abs(data.landscape.itemW - data.landscape.itemH * 1.5)).toBeLessThanOrEqual(1);
    expect(data.landscape.objectFit).toBe('cover');
    expect(Math.abs(data.landscape.imgW - data.landscape.itemW)).toBeLessThanOrEqual(1);
    expect(Math.abs(data.landscape.imgH - data.landscape.itemH)).toBeLessThanOrEqual(1);
  });

  test('a fixed-crop caption overlays the tile instead of expanding it', async ({ page }) => {
    await page.goto(FIXTURE);

    const data = await page.evaluate(() => {
      const item = document.querySelector('[data-fixture="gallery-square"] a[aria-label="Fixed-crop caption item"]');
      const itemRect = item.getBoundingClientRect();
      const caption = item.querySelector('figcaption');
      const captionRect = caption.getBoundingClientRect();
      const style = getComputedStyle(caption);
      return {
        itemW: itemRect.width,
        itemH: itemRect.height,
        position: style.position,
        captionBottom: captionRect.bottom,
        itemBottom: itemRect.bottom,
        background: style.backgroundColor
      };
    });
    // The tile stays square (caption did not grow it) — same 1px tolerance.
    expect(Math.abs(data.itemW - data.itemH)).toBeLessThanOrEqual(1);
    expect(data.position).toBe('absolute');
    // Overlaid at the bottom edge of the tile, not pushing it taller.
    expect(Math.abs(data.captionBottom - data.itemBottom)).toBeLessThanOrEqual(1);
    expect(data.background).not.toBe('rgba(0, 0, 0, 0)');
  });
});

test.describe('gallery behavior', () => {
  test('clicking a thumbnail opens a real dialog[open] with a backdrop', async ({ page }) => {
    await page.goto(FIXTURE);
    await page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').first().click();

    const data = await page.evaluate(() => {
      const dialog = document.querySelector('dialog.thallo-block-gallery__dialog');
      const backdrop = getComputedStyle(dialog, '::backdrop');
      return {
        exists: !!dialog,
        open: dialog ? dialog.open : false,
        backdropBackground: backdrop.backgroundColor
      };
    });
    expect(data.exists).toBe(true);
    expect(data.open).toBe(true);
    expect(data.backdropBackground).toBe('rgba(0, 0, 0, 0.8)');
  });

  test('Escape closes the dialog and returns focus to the triggering thumbnail', async ({ page }) => {
    await page.goto(FIXTURE);
    const thumb = page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').nth(1);
    await thumb.click();
    await page.waitForSelector('dialog.thallo-block-gallery__dialog[open]');

    await page.keyboard.press('Escape');

    await page.waitForFunction(() => {
      const dialog = document.querySelector('dialog.thallo-block-gallery__dialog');
      return dialog && dialog.open === false;
    });

    const focusIsThumb = await page.evaluate(() => {
      const thumb = document.querySelectorAll('[data-fixture="gallery-natural"] .thallo-block-gallery__item')[1];
      return document.activeElement === thumb;
    });
    expect(focusIsThumb).toBe(true);
  });

  test('next/prev update the "n of m" status region', async ({ page }) => {
    await page.goto(FIXTURE);
    await page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').nth(1).click();
    await page.waitForSelector('dialog.thallo-block-gallery__dialog[open]');

    const status = () => page.locator('dialog.thallo-block-gallery__dialog .thallo-block-gallery__status').innerText();
    expect(await status()).toBe('2 of 3');

    await page.locator('dialog.thallo-block-gallery__dialog .thallo-block-gallery__next').click();
    expect(await status()).toBe('3 of 3');

    await page.locator('dialog.thallo-block-gallery__dialog .thallo-block-gallery__next').click();
    expect(await status()).toBe('1 of 3'); // wraps

    await page.locator('dialog.thallo-block-gallery__dialog .thallo-block-gallery__prev').click();
    expect(await status()).toBe('3 of 3');
  });

  test('two gallery instances keep independent dialog state', async ({ page }) => {
    await page.goto(FIXTURE);

    // Open + close the natural gallery's first item.
    await page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').first().click();
    await page.waitForSelector('dialog.thallo-block-gallery__dialog[open]');
    expect(await page.locator('dialog.thallo-block-gallery__dialog .thallo-block-gallery__status').innerText()).toBe('1 of 3');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.querySelectorAll('dialog.thallo-block-gallery__dialog[open]').length === 0);

    // Open the square gallery's captioned (second) item — a DIFFERENT
    // gallery's own enhance() closure, hence a distinct <dialog>.
    await page.locator('[data-fixture="gallery-square"] .thallo-block-gallery__item').nth(1).click();
    await page.waitForFunction(() => document.querySelectorAll('dialog.thallo-block-gallery__dialog[open]').length === 1);

    const afterSecondOpen = await page.evaluate(() => {
      const dialogs = Array.from(document.querySelectorAll('dialog.thallo-block-gallery__dialog'));
      const openDialog = dialogs.find((d) => d.open);
      return {
        dialogCount: dialogs.length,
        status: openDialog.querySelector('.thallo-block-gallery__status').textContent,
        imgSrc: openDialog.querySelector('img').getAttribute('src')
      };
    });
    expect(afterSecondOpen.dialogCount).toBe(2); // one persistent dialog per gallery that has ever opened
    expect(afterSecondOpen.status).toBe('2 of 3');
    expect(afterSecondOpen.imgSrc).toContain('square-2.svg');

    // Reopening the natural gallery must show ITS OWN first image again —
    // proof the square gallery's interactions left it untouched.
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.querySelectorAll('dialog.thallo-block-gallery__dialog[open]').length === 0);
    await page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').first().click();
    const reopened = await page.evaluate(() => {
      const dialogs = Array.from(document.querySelectorAll('dialog.thallo-block-gallery__dialog'));
      const openDialog = dialogs.find((d) => d.open);
      return {
        status: openDialog.querySelector('.thallo-block-gallery__status').textContent,
        imgSrc: openDialog.querySelector('img').getAttribute('src')
      };
    });
    expect(reopened.status).toBe('1 of 3');
    expect(reopened.imgSrc).toContain('natural-1.svg');
  });

  test('with JavaScript disabled, thumbnails navigate to the full image URL', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false });
    const page = await context.newPage();
    try {
      await page.goto(FIXTURE);
      await Promise.all([
        page.waitForURL(/natural-1\.svg$/),
        page.locator('[data-fixture="gallery-natural"] .thallo-block-gallery__item').first().click()
      ]);
      expect(page.url()).toContain('/tools/runtime-browser/fixtures/media/natural-1.svg');
    } finally {
      await context.close();
    }
  });
});

test.describe('hero-carousel preset', () => {
  async function readHeroLayout(page) {
    return page.evaluate(() => {
      const carousel = document.querySelector('[data-fixture="hero-carousel"] .thallo-block-carousel');
      const withMedia = document.querySelector('.thallo-block-hero[data-slide="with-media"]');
      const noMedia = document.querySelector('.thallo-block-hero[data-slide="no-media"]');
      const wrapper = withMedia.querySelector('.thallo-block-hero__wrapper');
      const media = withMedia.querySelector('.thallo-block-hero__media');
      const img = media.querySelector('img');
      const inner = withMedia.querySelector('.thallo-block-hero__inner');
      const imgStyle = getComputedStyle(img);
      const afterStyle = getComputedStyle(inner, '::after');
      const wrapperStyle = getComputedStyle(wrapper);
      const mediaStyle = getComputedStyle(media);
      const imgRect = img.getBoundingClientRect();
      const mediaRect = media.getBoundingClientRect();
      return {
        viewportWidth: document.documentElement.clientWidth,
        carouselWidth: carousel.getBoundingClientRect().width,
        withMediaWidth: withMedia.getBoundingClientRect().width,
        noMediaWidth: noMedia.getBoundingClientRect().width,
        wrapperGridRow: wrapperStyle.gridRowStart,
        wrapperGridCol: wrapperStyle.gridColumnStart,
        mediaGridRow: mediaStyle.gridRowStart,
        mediaGridCol: mediaStyle.gridColumnStart,
        imgMaxWidth: imgStyle.maxWidth,
        imgMaxHeight: imgStyle.maxHeight,
        imgObjectFit: imgStyle.objectFit,
        imgW: imgRect.width,
        imgH: imgRect.height,
        mediaW: mediaRect.width,
        mediaH: mediaRect.height,
        afterContent: afterStyle.content,
        afterBackgroundImage: afterStyle.backgroundImage
      };
    });
  }

  function assertHeroLayout(data) {
    // 1 + 2: full-bleed root, and every slide spans the full viewport even
    // though --per-3 is also present on the same root (cascade-order win,
    // not a media-query gate — holds at any width).
    expect(Math.abs(data.carouselWidth - data.viewportWidth)).toBeLessThanOrEqual(1);
    expect(Math.abs(data.withMediaWidth - data.viewportWidth)).toBeLessThanOrEqual(1);
    expect(Math.abs(data.noMediaWidth - data.viewportWidth)).toBeLessThanOrEqual(1);

    // 3: wrapper and media share the same stacked grid cell.
    expect(data.wrapperGridRow).toBe('1');
    expect(data.wrapperGridCol).toBe('1');
    expect(data.mediaGridRow).toBe('1');
    expect(data.mediaGridCol).toBe('1');

    // 3b: the image has no 40rem/26rem cap and fills its cell.
    expect(data.imgMaxWidth).toBe('none');
    expect(data.imgMaxHeight).toBe('none');
    expect(data.imgObjectFit).toBe('cover');
    expect(Math.abs(data.imgW - data.mediaW)).toBeLessThanOrEqual(1);
    expect(Math.abs(data.imgH - data.mediaH)).toBeLessThanOrEqual(1);

    // 4: the scrim pseudo-element exists between media and text.
    expect(data.afterContent).not.toBe('none');
    expect(data.afterBackgroundImage.toLowerCase()).toContain('gradient');
  }

  test('desktop (1440px): carousel spans the viewport; each slide is one viewport wide even with --per-3; wrapper/media share a grid cell; image fills uncapped; scrim present', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);
    assertHeroLayout(await readHeroLayout(page));
  });

  test('mobile (390px): the same guarantees hold', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(FIXTURE);
    assertHeroLayout(await readHeroLayout(page));
  });

  async function readNoImageSlide(page) {
    return page.evaluate(() => {
      const noMedia = document.querySelector('.thallo-block-hero[data-slide="no-media"]');
      const title = noMedia.querySelector('.thallo-block-hero__title');
      return {
        hasMediaDiv: !!noMedia.querySelector('.thallo-block-hero__media'),
        backgroundColor: getComputedStyle(noMedia).backgroundColor,
        backgroundImage: getComputedStyle(noMedia).backgroundImage,
        titleColor: getComputedStyle(title).color
      };
    });
  }

  test('the no-image slide falls back to the theme-invariant dark base, keeping the white on-media text readable in light mode', async ({ page }) => {
    await page.goto(FIXTURE);

    const data = await readNoImageSlide(page);
    expect(data.hasMediaDiv).toBe(false);
    // "6: no-image fallback" in blocks.css — a media-less slide has no
    // media/scrim to cover it, so it falls straight to `background:
    // var(--hero-fallback-bg)` (site.css :root, #0f172a === rgb(15, 23, 42))
    // instead of the light standard hero gradient, which is what made white
    // on-media text unreadable.
    expect(data.backgroundColor).toBe('rgb(15, 23, 42)');
    // No longer the light standard-hero gradient.
    expect(data.backgroundImage).toBe('none');
    // A declared, opaque text color — not literally invisible/transparent.
    expect(data.titleColor).not.toBe('rgba(0, 0, 0, 0)');
    // White text (--accent-ink) is now on a genuinely dark base, not near-white.
    expect(data.titleColor).toBe('rgb(255, 255, 255)');
  });

  test('the no-image slide stays on the same dark base in dark mode — --hero-fallback-bg is theme-invariant, unlike --ink', async ({ page }) => {
    // Set data-theme before first paint (no-flash resolver's attribute,
    // color-mode spec §3.3) so the dark-mode variable overrides are already
    // active when the stylesheet is applied.
    await page.addInitScript(() => {
      document.documentElement.dataset.theme = 'dark';
    });
    await page.goto(FIXTURE);

    const data = await readNoImageSlide(page);
    expect(data.hasMediaDiv).toBe(false);
    // Regression guard: --ink flips to #e2e8f0 (rgb(226, 232, 240)) in dark
    // mode, but --hero-fallback-bg has no [data-theme="dark"] override on
    // purpose (the overlay ink --accent-ink is #ffffff in BOTH modes), so
    // the background must stay the same dark rgb(15, 23, 42).
    expect(data.backgroundColor).toBe('rgb(15, 23, 42)');
    expect(data.backgroundImage).toBe('none');
    expect(data.titleColor).not.toBe('rgba(0, 0, 0, 0)');
    expect(data.titleColor).toBe('rgb(255, 255, 255)');
  });

  test('slide sizing survives the canvas/preview carrier: one slide per view with .thallo-preview-block wrappers present', async ({ page }) => {
    // Preview-session renders wrap every blocks() instance in a display:contents
    // carrier (preview.css:5). The carrier's box does not exist, so slide-sizing
    // rules using only `__track > *` would silently target the carrier and drop
    // flex-basis/scroll-snap on the real slide — the regression this test pins:
    // slides rendered side-by-side (auto width) in every preview surface.
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);

    const widths = await page.evaluate(() => {
      // Mirror the real preview carrier rule verbatim.
      const style = document.createElement('style');
      style.textContent = '.thallo-preview-block { display: contents; }';
      document.head.appendChild(style);

      const car = document.querySelector('.thallo-block-carousel--hero');
      const track = car.querySelector('.thallo-block-carousel__track');
      for (const slide of Array.from(track.children)) {
        const carrier = document.createElement('div');
        carrier.className = 'thallo-preview-block';
        carrier.setAttribute('data-thallo-block', 'test-' + Math.floor(performance.now()));
        track.insertBefore(carrier, slide);
        carrier.appendChild(slide);
      }
      const slides = Array.from(track.querySelectorAll('.thallo-preview-block > *'));
      return {
        carouselWidth: car.getBoundingClientRect().width,
        slideWidths: slides.map((s) => s.getBoundingClientRect().width)
      };
    });

    expect(widths.slideWidths.length).toBeGreaterThan(1);
    for (const w of widths.slideWidths) {
      // One slide per view: each carrier-wrapped slide spans the full carousel
      // width (within one CSS pixel), never auto-shrunk side-by-side.
      expect(Math.abs(w - widths.carouselWidth)).toBeLessThanOrEqual(1);
    }
  });
});

test.describe('image-slider (bare image blocks as slides)', () => {
  async function readImageSliderLayout(page) {
    return page.evaluate(() => {
      const car = document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel');
      const slides = Array.from(car.querySelectorAll('.thallo-block-carousel__track > *'));
      return {
        carouselWidth: car.getBoundingClientRect().width,
        slides: slides.map((s) => {
          const img = s.querySelector('img');
          return {
            width: s.getBoundingClientRect().width,
            imgWidth: img ? img.getBoundingClientRect().width : 0
          };
        })
      };
    });
  }

  test('each bare image slide spans the full carousel: the image block\'s standalone page cap (max-width: var(--container)) and side padding must not survive inside a slider', async ({ page }) => {
    // 1440 viewport > the 72rem (1152px) --container cap, so an unneutralized
    // image block reproduces the live bug: slide clamped to 1152 on a 1440
    // carousel, next slide poking into view despite slides_per_view=1.
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);

    const data = await readImageSliderLayout(page);
    expect(data.slides.length).toBe(2);
    for (const slide of data.slides) {
      // flex-basis:100% must be the USED width — no intrinsic max-width clamp.
      expect(Math.abs(slide.width - data.carouselWidth)).toBeLessThanOrEqual(1);
      // Hero-style slides are edge-to-edge: no page gutters around the image.
      expect(Math.abs(slide.imgWidth - data.carouselWidth)).toBeLessThanOrEqual(1);
    }
  });

  test('hero slider chrome is seamless and overlaid: no scrollbar, no inter-slide gap, uniform slide height, dots and pause float over the image', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);
    await page.waitForFunction(() =>
      !!document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel[data-thallo-enhanced="carousel"]'));

    const data = await page.evaluate(() => {
      const car = document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel');
      const viewport = car.querySelector('.thallo-block-carousel__viewport');
      const track = car.querySelector('.thallo-block-carousel__track');
      const rect = car.getBoundingClientRect();
      const imgs = Array.from(track.querySelectorAll('img'));
      const dots = car.querySelector('.thallo-block-carousel__dots');
      const pause = car.querySelector('.thallo-block-carousel__pause');
      const dotsRect = dots ? dots.getBoundingClientRect() : null;
      const pauseRect = pause ? pause.getBoundingClientRect() : null;
      return {
        scrollbarWidth: getComputedStyle(viewport).scrollbarWidth,
        trackGap: getComputedStyle(track).columnGap,
        imgHeights: imgs.map((i) => Math.round(i.getBoundingClientRect().height)),
        hasDots: !!dots,
        dotsOverlaid: !!dotsRect && dotsRect.bottom <= rect.bottom + 1 && dotsRect.top >= rect.top,
        hasPause: !!pause,
        pausePosition: pause ? getComputedStyle(pause).position : null,
        pauseOverlaid: !!pauseRect && pauseRect.bottom <= rect.bottom + 1 && pauseRect.right <= rect.right + 1 && pauseRect.top >= rect.top
      };
    });

    // Scroll-snap plumbing must not show: the raw viewport scrollbar is hidden.
    expect(data.scrollbarWidth).toBe('none');
    // Full-bleed slides sit flush — no page background stripe between them.
    expect(data.trackGap).toBe('0px');
    // Every image slide renders at the same height (object-fit: cover — no distortion).
    expect(data.imgHeights.length).toBe(2);
    expect(data.imgHeights[0]).toBeGreaterThan(0);
    expect(Math.abs(data.imgHeights[0] - data.imgHeights[1])).toBeLessThanOrEqual(1);
    // Dots and the pause control overlay the image instead of dangling below it.
    expect(data.hasDots).toBe(true);
    expect(data.dotsOverlaid).toBe(true);
    expect(data.hasPause).toBe(true);
    expect(data.pausePosition).toBe('absolute');
    expect(data.pauseOverlaid).toBe(true);
  });

  test('vertical page scrolling over the slider never sticky-pauses autoplay — it keeps rotating', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);
    const slider = page.locator('[data-fixture="image-slider"] .thallo-block-carousel');
    await page.waitForFunction(() =>
      !!document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel[data-thallo-enhanced="carousel"]'));

    // The user's exact gesture: reading the page, wheel-scrolling vertically
    // with the cursor over the full-bleed slider. The slider owns the x axis;
    // the page owns the y axis — this must NOT count as slide interaction.
    await slider.scrollIntoViewIfNeeded();
    const box = await slider.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + Math.min(box.height / 2, 200));
    await page.mouse.wheel(0, 40);

    const pausedAfterScroll = await page.evaluate(() => {
      const pause = document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel__pause');
      return pause ? pause.getAttribute('aria-pressed') : null;
    });
    expect(pausedAfterScroll).toBe('false');

    // And autoplay actually still ticks: the 5s rotation advances the viewport.
    await page.waitForFunction(() => {
      const vp = document.querySelector('[data-fixture="image-slider"] .thallo-block-carousel__viewport');
      return vp && vp.scrollLeft > 0;
    }, undefined, { timeout: 8000 });
  });
});

test.describe('late-registration order', () => {
  test('runtime.js and both block assets load in the real deferred order; each block enhances on first load with exactly one marker token', async ({ page }) => {
    const pageErrors = [];
    const consoleErrors = [];
    page.on('pageerror', (err) => pageErrors.push(String(err)));
    page.on('console', (msg) => { if (msg.type() === 'error') { consoleErrors.push(msg.text()); } });

    await page.goto(FIXTURE);

    // Shape: runtime.js, then block-animated-text.js, then block-gallery.js —
    // all `defer`, in that source order, exactly as block_script() emits
    // them relative to the templates that call it.
    const shape = await page.evaluate(() => {
      const scripts = Array.from(document.querySelectorAll('script[src]'));
      return {
        srcs: scripts.map((s) => s.getAttribute('src')),
        allDefer: scripts.every((s) => s.defer === true)
      };
    });
    expect(shape.allDefer).toBe(true);
    expect(shape.srcs).toEqual([
      '/packages/thallo-render/runtime/runtime.js',
      '/packages/thallo-render/runtime/block-animated-text.js',
      '/packages/thallo-render/runtime/block-gallery.js'
    ]);

    // No double-registration / no module throwing despite two block asset
    // files self-enhancing ahead of the boot scheduler's own scan.
    expect(pageErrors, 'no uncaught page errors (e.g. duplicate module registration)').toEqual([]);
    expect(consoleErrors, 'no console.error from a module enhance() failing').toEqual([]);

    // The in-viewport animated_text marks synchronously; wait for it as the
    // readiness gate before reading every marker below.
    await page.waitForFunction(() =>
      document.querySelector('[data-fixture="animated-text-inview"] .thallo-block-animated_text')
        .getAttribute('data-thallo-enhanced') === 'animated-text'
    );

    const markers = await page.evaluate(() => ({
      animatedText: Array.from(document.querySelectorAll('.thallo-block-animated_text'))
        .map((el) => el.getAttribute('data-thallo-enhanced')),
      galleries: Array.from(document.querySelectorAll('.thallo-block-gallery'))
        .map((el) => el.getAttribute('data-thallo-enhanced'))
    }));
    // Exactly one token each — "animated-text"/"gallery", never doubled
    // ("animated-text animated-text") by a later duplicate enhancement pass.
    expect(markers.animatedText).toEqual(['animated-text', 'animated-text']);
    expect(markers.galleries).toEqual(['gallery', 'gallery', 'gallery']);
  });
});

test.describe('visual artifacts', () => {
  // Screenshots for the operator's visual pass — the computed-style
  // assertions above remain the automated authority; these are not asserted
  // against pixels. Written under test-results/, which .gitignore already
  // excludes wholesale.
  test('desktop screenshot (1440px)', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(FIXTURE);
    await page.waitForFunction(() =>
      document.querySelectorAll('.thallo-block-gallery[data-thallo-enhanced="gallery"]').length === 3
    );
    await page.screenshot({ path: 'test-results/artifacts/blocks-desktop-1440.png', fullPage: true });
  });

  test('mobile screenshot (390px)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(FIXTURE);
    await page.waitForFunction(() =>
      document.querySelectorAll('.thallo-block-gallery[data-thallo-enhanced="gallery"]').length === 3
    );
    await page.screenshot({ path: 'test-results/artifacts/blocks-mobile-390.png', fullPage: true });
  });
});
