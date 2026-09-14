# Website Phase 1 Product Pieces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** the two product gaps the website plan predicts for the admin-authored landing page — a `code` starter block with a language label and a copy button, and a `thallo-version` shortcode rendering the installed version — shipped in the block library and the default theme, so the homepage needs no `html` block and no custom template.

**Architecture:** the block is a data-only definition in the starter library plus a theme template, CSS in `blocks.css`, and a lazily loaded per-block runtime asset behind the closed `block_script()` catalog (same-origin, so the site's `default-src 'self'` policy holds; the no-JS floor is the plain `<pre><code>`). The version reaches templates as `site.version`, built by one shared `SiteContext` helper in the render pack from a `SiteVersionProvider` contract that core binds to Composer's installed-version registry; the shortcode is a theme partial like `copyright`.

**Tech Stack:** PHP 8.3 / Glueful 1.85.4, Twig under `TemplatePolicy`, hand-written ES5-compatible runtime JS (no build), PHPUnit with the Node asset harness.

**Spec:** `docs/internal/plans/2026-09-11-website-and-docs.md` (phase 1, decision 7, the gap table).

## Global Constraints

- Shortcode names follow the block's grammar `[a-z][a-z0-9_-]*`: the shortcode is **`thallo-version`** (decision 7 wrote `thallo:version`; a colon is not a valid name).
- `blocks/code.twig` must lint clean under `TemplatePolicy` (no `|raw`); Twig autoescape renders the snippet text.
- `runtime/block-code.js` ≤ 3,072 bytes gzip (`BlockAssetBudgetTest`), registers once, tolerates re-execution, self-enhances on late load (the `block-gallery.js` contract).
- The render pack never imports core: the version crosses through `Thallo\Contracts\Delivery\SiteVersionProvider`.
- Starter count 46 → 47 (`SeedBlockTypesTest`); every new slug renders with `thallo-block-{slug}` (`StarterTemplatesTest`).
- No syntax highlighting here; that is phase 2c's shared work. The block ships the language as a `data-language` attribute and a `language-{x}` class for it.

---

### Task 1: `site.version` and the `thallo-version` shortcode

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/SiteVersionProvider.php`, `core/src/Updates/ComposerSiteVersion.php`, `packages/thallo-render/src/SiteContext.php`, `packages/thallo-render/themes/default/templates/shortcodes/thallo-version.twig`
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind the contract), `packages/thallo-render/src/Http/Controllers/RenderController.php` and `packages/thallo-render/src/EntryBlocksRenderer.php` (build `site` through `SiteContext`)
- Test: `tests/Integration/Render/VersionShortcodeTest.php`

**Interfaces:**
- `SiteVersionProvider::installedVersion(): ?string` — the installed Thallo version without a leading `v`; null for a development checkout.
- `SiteContext::build(ApplicationContext $context, string $locale): array{name: string, locale: string, locales: list<string>, version: ?string}`.
- Shortcode params: none required; `params.prefix` (string) is rendered before the version. Output: `<span class="thallo-shortcode-version">{prefix}{version}</span>`; for a null version `<span class="thallo-shortcode-version thallo-shortcode-version--development">development checkout</span>`.

- [ ] **Step 1: failing tests** — the shortcode renders `site.version` with an optional prefix; renders the development wording when null; the shared container binds `SiteVersionProvider` and, in this repository, reports null; `SiteContext::build()` carries `version`.
- [ ] **Step 2: RED. Step 3: implement. Step 4: GREEN, phpcs, `composer boundaries`.**
- [ ] **Step 5: commit** `feat(render): site.version and the thallo-version shortcode`.

### Task 2: the `code` block

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (definition), `packages/thallo-render/src/RenderContextExtension.php` (`BLOCK_SCRIPT_ASSETS` gains `code`), `packages/thallo-render/themes/default/assets/blocks.css`
- Create: `packages/thallo-render/themes/default/templates/blocks/code.twig`, `packages/thallo-render/runtime/block-code.js`
- Test: `tests/Integration/Content/SeedBlockTypesTest.php` (47), `tests/Integration/Render/StarterTemplatesTest.php` (fixture), `tests/Integration/Render/CodeBlockRenderTest.php` (markup: label, `data-language`, escaped snippet, no button markup without the copy switch, `block_script('code')` emitted once), `tests/Integration/Render/CodeAssetTest.php` (Node harness: the copy button appears on enhance, click writes the snippet to a clipboard stub and flips the label to "Copied", the label restores)

**Definition:** slug `code`, label "Code", icon `i-lucide-code`, category Content, description "A code snippet with a language label and a copy button." Schema: `code` (text, plain, required), `language` (enum: `text`, `bash`, `php`, `json`, `yaml`, `html`, `css`, `javascript`, `typescript`, `twig`, `sql`), `label` (string, an optional caption such as a file name), `copy` (boolean; default true in the template).

**Template:** `<figure class="thallo-block thallo-block-code" data-language="{{ language }}" data-copy="{{ copy ? '1' : '0' }}">`, a `<figcaption>` with the label or the language name and an empty `<span class="thallo-block-code__actions">` slot the runtime fills, `<pre><code class="language-{{ language }}">{{ data.code }}</code></pre>`, then `{{ block_script('code') }}`. The button is created by the runtime, so the no-JS floor has no dead control.

**Runtime:** `ThalloRuntime.register('code', { selector: '.thallo-block-code[data-copy="1"]', enhance(root) })` — creates `<button type="button" class="thallo-block-code__copy">Copy</button>` in the actions slot; on click `navigator.clipboard.writeText(code.textContent)` (fallback: select the text), sets the label to "Copied" and restores it after two seconds; returns a cleanup removing the button.

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, `ShippedTemplatesLintGateTest`, `BlockAssetBudgetTest`, `BlockScriptTest`, `RuntimeSizeBudgetTest`.
- [ ] **Step 5: commit** `feat(blocks): code block with a language label and a copy button`.

### Task 3: docs and gates

- [ ] `packages/thallo-render/docs/THEMING.md` §4.4: the block set is regenerated from the library (add every missing slug, including `code`); `packages/thallo-render/README.md`: the catalog names three assets; `CHANGELOG.md` `[Unreleased]` → Added; the website plan's status line: phase 1 product pieces shipped, page authoring in progress.
- [ ] Full `composer test`, `pnpm test` untouched (no admin change), skeleton smoke not needed (no setup change).
- [ ] Commit `docs(blocks): the code block and the thallo-version shortcode`.

## Self-review

Spec coverage: code block with language + copy (T2), version shortcode from the install's own version (T1), no `html` block or custom template needed (both), gaps not fixed → none expected. Types: `SiteVersionProvider::installedVersion()` used by `SiteContext`; `site.version` read by the shortcode; `BLOCK_SCRIPT_ASSETS` entry `code` matches `block-code.js` and `block_script('code')`.
