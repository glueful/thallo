'use strict';

// Captures a theme's gallery screenshot: `node screenshot-theme.js <page.html> <out.jpg>`.
// Called by scripts/build-theme-screenshot, which renders the page. 1200×900 at 1x is what the
// admin's theme cards are cut from (4:3); JPEG keeps a thumbnail under a hundred kilobytes.
const { chromium } = require('@playwright/test');
const path = require('node:path');

const [page_, out] = process.argv.slice(2);
if (!page_ || !out) {
  console.error('usage: node screenshot-theme.js <page.html> <out.jpg>');
  process.exit(2);
}

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({
      viewport: { width: 1200, height: 900 },
      deviceScaleFactor: 1,
      colorScheme: 'light',
      reducedMotion: 'reduce',
    });
    await page.goto('file://' + path.resolve(page_));
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: out, type: 'jpeg', quality: 82, clip: { x: 0, y: 0, width: 1200, height: 900 } });
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
