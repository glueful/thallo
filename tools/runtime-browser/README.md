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

## Running

```sh
cd tools/runtime-browser
npm install
npx playwright install chromium   # or: npm run install-browsers
npm test
```

## CI

`.github/workflows/runtime-browser.yml` is a separate gate from the main PHP
CI workflow, triggered only on changes under `packages/thallo-render/**` or
`tools/runtime-browser/**`. It caches `~/.cache/ms-playwright` keyed on the
installed Playwright version.
