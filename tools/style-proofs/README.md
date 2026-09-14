# Style proofs

Real-browser proofs of the visual builder's layered delivery (visual builder spec §2.6): the
compiled settings artifact, the theme artifact and the layer order sheet are built from the
real theme by `scripts/build-style-proof-fixtures`, a fixture page styles blocks through the
real `BlockStyleEmitter`, and the specs assert `getComputedStyle` at 390, 768 and 1280 pixels
in Chromium, Firefox and WebKit.

What is proven:

- the §1.6 cascade table rows against real button and heading theme CSS (an exact declaration
  at a larger breakpoint in a lower-precedence class beats an inherited instance value);
- a reset terminates resolution at its breakpoint and a later explicit breakpoint still applies
  (`revert-layer` returns the theme's own value, including the secondary button variant's
  transparent background);
- responsive padding with a base value, an `md` reset and an `lg` override;
- radius and per-breakpoint visibility.

The public-site floor is Chrome 111, Firefox 113 and Safari 16.2 (cascade layers,
`revert-layer` and `color-mix()`); the tested matrix is whatever stable engine the pinned
Playwright ships for each family.

```
php scripts/build-style-proof-fixtures   # writes fixtures/ (gitignored)
npm ci && npm run install-browsers
npm test
```
