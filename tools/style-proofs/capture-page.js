'use strict';

// Captures rendered pages as JPEGs, for the pictures Thallo ships of its own output:
//
//   node capture-page.js <jobs.json>
//
// `jobs.json` is a list of { page, out, width, height?, scale?, maxHeight?, trim? }. With a `height` the
// capture is exactly that viewport (a theme's 1200×900 gallery screenshot); without one it is the
// page's full height, up to `maxHeight` (a section's or a starter page's thumbnail). `scale` is
// the device scale factor: 0.5 turns a 1200px-wide render into a 600px-wide picture. `trim` cuts
// the picture to the blocks themselves, leaving out the outer margins a theme puts around them.
// One browser for the whole list. Called by scripts/build-theme-screenshot and
// scripts/build-pattern-thumbnails, which render the pages.
const { chromium } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const [jobsFile] = process.argv.slice(2);
if (!jobsFile) {
  console.error('usage: node capture-page.js <jobs.json>');
  process.exit(2);
}
const jobs = JSON.parse(fs.readFileSync(jobsFile, 'utf8'));

(async () => {
  const browser = await chromium.launch();
  try {
    for (const job of jobs) {
      const width = job.width || 1200;
      const context = await browser.newContext({
        viewport: { width, height: job.height || 900 },
        deviceScaleFactor: job.scale || 1,
        colorScheme: 'light',
        reducedMotion: 'reduce',
      });
      const page = await context.newPage();
      await page.goto('file://' + path.resolve(job.page));
      await page.evaluate(() => document.fonts.ready);
      // The content's own height: the document's is never less than the viewport's, which would
      // pad a short section with empty space.
      const box = await page.evaluate(() => {
        const main = document.querySelector('main') || document.body;
        const blocks = Array.from(main.children).filter((el) => el.getBoundingClientRect().height > 0);
        const top = blocks.length ? blocks[0].getBoundingClientRect().top : 0;
        const bottom = blocks.length
          ? blocks[blocks.length - 1].getBoundingClientRect().bottom
          : main.getBoundingClientRect().bottom;
        return { top: Math.max(0, Math.floor(top)), bottom: Math.ceil(bottom) };
      });
      const y = job.trim ? box.top : 0;
      const full = box.bottom - y;
      const height = job.height || Math.min(full, job.maxHeight || full);
      await page.screenshot({
        path: job.out,
        type: 'jpeg',
        quality: job.quality || 82,
        fullPage: !job.height,
        clip: { x: 0, y, width, height },
      });
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
