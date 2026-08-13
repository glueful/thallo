# runtime-browser

Real-Chromium smoke gate for the theme-runtime custom elements
(`thallo-carousel`, `thallo-tabs`, `thallo-navigation`,
`thallo-color-mode-toggle` — see `packages/thallo-render/runtime/runtime.js`
and the "Theme runtime elements" section of `packages/thallo-render/README.md`).

## Why this exists

The rest of the runtime is tested against a Node hand-stubbed DOM
(`packages/thallo-render/tests`), which is fast but cannot model:

- real custom-element **upgrade** and reconnect timing (`connectedCallback`/
  `disconnectedCallback` on the actual customElements reaction queue);
- native **attribute → `.dataset` reflection** (the production code
  deliberately relies on this instead of a manual dual-write);
- real **CSS computation** (the no-JS `display` floor);
- true **microtask/task ordering** (the boot-ordering contract that makes a
  `defer`-loaded script race-safe against elements it hasn't seen yet).

This package is an isolated, Chromium-only Playwright suite that answers
exactly those questions and nothing else. It is intentionally NOT part of
the admin app or its build.

## What it loads

Fixtures under `fixtures/` are static HTML pages containing the four README
examples, copied verbatim. `server.js` serves the **repo root** (not this
package) so the fixtures load the real files by their real path — nothing
is copied or mocked:

- `packages/thallo-render/themes/default/assets/site.css` (CSS custom
  properties the block styles consume — `--accent`, `--surface`, etc.)
- `packages/thallo-render/themes/default/assets/blocks.css`
- `packages/thallo-render/themes/default/assets/navigation.css`
- `packages/thallo-render/runtime/runtime.js`, loaded with `defer`, in
  `<head>` before the elements it enhances — mirroring
  `themes/default/templates/layout.twig` exactly.

`fixtures/no-runtime.html` is the same markup with the `<script>` tag
omitted, for the no-JS / "before the runtime loads" display spec.

`fixtures/blocks.html` hand-renders the modern-blocks additions
(animated_text, gallery, and the hero-carousel preset) from their `.twig`
templates' class contract, plus `packages/thallo-render/runtime/block-animated-text.js`
and `block-gallery.js` loaded `defer` after `runtime.js`, exactly as `block_script()`
emits them; `fixtures/media/*.svg` are small checked-in real image files (not
copies of anything, not data: URIs — top-level navigation to `data:` URLs is
blocked by Chromium, which the no-JS gallery spec below needs to work around).

Why a hand-rolled static server instead of `php -S` or an `http-server`
package: PHP happens to be on this machine's `PATH`, but the CI job for this
gate never installs PHP (it only needs Node + a Chromium binary), and an
npm static-file-server dependency is unnecessary weight for two lines of
`node:http`. See `server.js` for the ~40-line implementation.

## Specs

- `element-upgrade.spec.js` — all four tags are defined and their
  server-parsed instances are real upgraded instances (`instanceof`); the
  microtask-deferred enhancement actually completes.
- `option-projection.spec.js` — `arrows`/`dots` sugar on `thallo-carousel`
  projects to `data-arrows="1"`/`data-dots="1"` AND is visible natively at
  `el.dataset.arrows === '1'` with no manual dataset write in the runtime;
  `thallo-tabs`/`thallo-navigation` project their root class.
- `marker-ownership.spec.js` — `data-thallo-enhanced` lands on the resolved
  target per module: the host itself for carousel/tabs, the inner
  `[data-thallo-enhance="navigation"]` details for navigation (not the outer
  tag), and never on the toggle (the documented no-`registerElement`
  exception).
- `disconnect-reconnect.spec.js` — detaching a live carousel/navigation
  element removes its injected controls, unmarks it, and restores the no-JS
  fallback markup; re-appending the SAME node re-runs `connectedCallback`
  and re-enhances exactly once (no duplicate controls or markers).
- `boot-ordering.spec.js` — the `defer` script (positioned in `<head>`,
  before the elements in source order — the real risk shape) produces no
  page/console errors (no double module registration), and leaves exactly
  one enhancement per element, proving element projection wins the race
  against the later whole-document class-based scan.
- `no-js-display.spec.js` — with no runtime loaded at all: `thallo-carousel`
  / `thallo-tabs` / `thallo-navigation` compute `display: block`; the toggle
  computes `display: inline-flex` via the `:where()` alias when
  `html[data-color-mode-enabled="true"]`, and `display: none` when that
  attribute is absent.
- `block-assets.spec.js` — against `fixtures/blocks.html`: animated_text's
  exact visible phrase, reveal-on-scroll timing and rotation settling, and
  the `prefers-reduced-motion` static floor; the gallery's nested
  `.thallo-block-image` reset, aspect-ratio-box geometry, and fixed-crop
  caption overlay; the gallery lightbox's real `<dialog>`/backdrop/Escape/
  focus-return/status/independent-instance behavior and its no-JS anchor
  floor; the hero-carousel preset's full-bleed/one-viewport-per-slide/
  shared-grid-cell/uncapped-image/scrim/no-image-fallback geometry at
  desktop and mobile widths; and the two block assets' real deferred load
  order enhancing on first load with exactly one marker token each.
- `print-media.spec.js` — the admin invoice/receipt print gate. See
  "The print-media gate" section below for why this lives here rather than
  in the admin app's own Vitest suite.

## The print-media gate

`admin/src/assets/print.css` (the ONE print stylesheet the admin SPA loads)
and its `[data-print-root]`/`[data-print-chrome]`/`[data-print-shell]`
contract (`admin/src/layouts/default.vue`) plus the `.invoice-a4` /
`.invoice-thermal-80` / `.invoice-thermal-58` document classes
(`admin/src/pages/commerce/orders/components/InvoiceDocument.vue`) exist so
a printed order invoice/receipt hides dashboard chrome, survives the
dashboard shell's `position: fixed`/`overflow: hidden`, repeats table
headers across pages, and never clips a long line-item description. None of
that is something Vitest can verify: Vue Test Utils runs against jsdom,
which does not implement CSS Paged Media (`@page`), `@media print`
evaluation, `-webkit-line-clamp`, or real box-model layout — every one of
those is exactly what this contract depends on. Vitest owns Vue/query
correctness (all three presets render, the untoggleable core is present
regardless of settings toggles, optional sections respond to their
toggles, footer text is escaped); this gate owns **browser interpretation**
of the print CSS itself, the one thing only a real browser can check.

`fixtures/invoice-print.html` is a static page that reproduces the
production `data-print-*`/class contract byte-for-byte (see the fixture's
header comment for the exact source lines it mirrors) and loads the REAL
`admin/src/assets/print.css` by its real repo path — nothing is copied. It
seeds 15 invoice line rows, one of which carries a genuinely long
multi-line description under a fixture-only truncation baseline (2-line
`-webkit-line-clamp` on the exact `.invoice-document td, th` selector
`print.css`'s `@media print` block resets), so "is this row actually
uncropped" is a real question with a real wrong answer to catch — not a
check that would pass no matter what.

`print-media.spec.js` drives `page.emulateMedia({ media: 'print' })` for
all three presets (`a4`, `thermal_80`, `thermal_58`, via
`?preset=` on the fixture URL) and asserts: `[data-print-chrome]` is
hidden and `[data-print-shell]`/the document stay visible; the
untoggleable core (order number, "Order status", a line name, grand
total) is present; the table's `thead` computes `display:
table-header-group`; every line-item cell computes visible overflow, no
line-clamp, and no max-height; every row's bounding box fully CONTAINS
every one of its descendants' bounding boxes (the real containment check —
deliberately NOT the `scrollHeight <= clientHeight` proxy, which a clipped
but non-scrolling box can satisfy while still visibly cropping content);
and each preset's document/content width matches its mm size at 96dpi
within a couple of pixels. It deliberately does **not** assert the `@page`
rule / paper-size selection — that's a print-dialog concern `emulateMedia`
never exercises, not something a browser exposes to script either way.

## Running

```sh
cd tools/runtime-browser
npm install
npx playwright install chromium   # or: npm run install-browsers
npm test
```

## CI

`.github/workflows/runtime-browser.yml` is a separate gate from the main PHP
CI workflow, triggered on changes under `packages/thallo-render/**` or
`tools/runtime-browser/**`, plus the admin paths the print-media gate covers
(`admin/src/assets/print.css`,
`admin/src/pages/commerce/orders/components/InvoiceDocument.vue`,
`admin/src/pages/commerce/orders/**/invoice.vue` — the standalone print
route, matched via `**` rather than the literal `[uuid]` segment because
GitHub Actions path filters treat `[...]` as a character class, and
`admin/src/layouts/default.vue`) — so a print-affecting change can't land
without this gate running. It caches `~/.cache/ms-playwright` keyed on the
installed Playwright version.
