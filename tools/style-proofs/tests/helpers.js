'use strict';

const WIDTHS = { base: 390, md: 768, lg: 1280 };

/** Computed `property` of the case's target element and of its unstyled control twin. */
async function computed(page, caseName, target, property) {
  return page.evaluate(([caseName, target, property]) => {
    const section = document.querySelector(`[data-case="${caseName}"]`);
    const styled = section.querySelector(`[data-target="${target}"]`);
    const control = section.querySelector(`[data-control="${target}"]`);
    return {
      styled: getComputedStyle(styled).getPropertyValue(property),
      theme: getComputedStyle(control).getPropertyValue(property)
    };
  }, [caseName, target, property]);
}

/** The computed value of a vocabulary token, read off the fixture's probe element. */
async function token(page, name, property) {
  return page.evaluate(([name, property]) => {
    const probe = document.querySelector(`[data-probe="${name}"]`);
    // Probes sit in a hidden container; computed lengths still resolve.
    return getComputedStyle(probe).getPropertyValue(property);
  }, [name, property]);
}

async function at(page, breakpoint) {
  await page.setViewportSize({ width: WIDTHS[breakpoint], height: 800 });
}

module.exports = { WIDTHS, computed, token, at };
