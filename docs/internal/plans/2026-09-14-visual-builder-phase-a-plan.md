# Visual Builder Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phase A of the visual builder: typed block settings in the document, the platform vocabulary with layered delivery and the compiled style artifact, intent-based history with the three-revision apply protocol, generated Style and Advanced controls, the conversion that removes the old per-block style fields under the cutover contract, and the fragment subsystem shipped disabled behind its flag.

**Architecture:** six release slices. Each slice lands schema, validator, renderer, migration, admin behaviour and tests together and leaves Thallo installable, renderable, editable, migratable and green, so any slice can be a beta. Contracts come first (settings document, resolver parity, vocabulary, targets, artifact), history wraps the stable document model, controls write to it last, and fragments arrive complete but off.

**Tech Stack:** PHP 8.3 / Glueful 1.85.6, Twig under `TemplatePolicy`, Nuxt UI admin (Vue 3, vitest), PHPUnit, Playwright (Chromium, Firefox, WebKit) for computed-style proofs.

**Spec:** `docs/internal/superpowers/specs/2026-09-14-visual-builder-design.md` (§0–§3 and §7 govern Phase A). Section numbers below refer to it.

## Global Constraints

- Every managed value is typed (`token`, `choice`, `identifier`, `reset`; `literal` reserved and rejected). Every setting definition declares the kinds it accepts (§1.1).
- Breakpoint-first cascade exactly as the §1.6 algorithm; the resolver returns a managed value or the sentinel `theme-default`, never a theme value.
- Implicit theme defaults: the compiler emits a class only where the managed cascade establishes a value; `reset` compiles to `revert-layer`.
- Breakpoints `base`, `md` (≥768px), `lg` (≥1024px); previews at 390, 768, 1280.
- Depth cap stays 3 in Phase A (five is Phase B, §5.2); the constant remains defined once per runtime.
- No compatibility layer. The slice that removes a field ships its validator, templates, artifact, editor support and reconstruction paths (§7.6).
- Test gates one at a time (`COMPOSER_PROCESS_TIMEOUT=0 composer test`, ~10 min, never concurrent on `app_test`); phpcs judged by exit code; admin files formatted with `pnpm exec oxfmt <files>` only; no AI attribution trailers; never push.
- `TemplatePolicy::CACHE_VERSION` is bumped with a `// bumped:` log line every time a Twig helper joins the allowlist.
- Starter count stays 47.

---

## Slice A1 — typed settings in the document

Outcome: every stored block carries `settings`; settings are validated against the §1 schema; the resolver exists in PHP and TypeScript with one fixture set; nothing renders or edits settings yet. A beta can ship here.

### Task A1.1: the settings schema and validator

**Files:**
- Create: `core/src/Content/Style/ValueKind.php`, `core/src/Content/Style/StyleSchema.php`, `core/src/Content/Style/SettingsValidator.php`, `core/src/Content/Style/StyleCapabilities.php`
- Modify: `core/src/Content/Validation/FieldValidator.php:472-485` (validate `settings` between the `data` recursion and the reconstruction line)
- Test: `tests/Integration/Content/BlockSettingsValidationTest.php`

**Interfaces:**
- `ValueKind` enum: `Token`, `Choice`, `Identifier`, `Reset`, `Literal` (rejected).
- `StyleSchema::properties(): array<string, PropertyDefinition>` where `PropertyDefinition` is a readonly record `{path, group, kinds: list<ValueKind>, responsive: bool, tokenDomain: ?string, choices: ?list<string>}`. The table is §1.3 verbatim (spacing, width, alignment.text/content/self, typography.size/weight, visibility, shadow, radius, colors.surface/text/border, border.width/style).
- `StyleSchema::BREAKPOINTS = ['base', 'md', 'lg']`, `StyleSchema::VERSION = 1`.
- `StyleCapabilities::fromDeclaration(?array $paths): self` expands groups to property paths; `allows(string $path): bool`; `all()`.
- `SettingsValidator::validate(mixed $settings, StyleCapabilities $caps): array{0: array, 1: array<string,string>}` returns the normalised settings and dot-path errors (`settings.style.spacing.padding.top.md`, `settings.classes.2`, `settings.advanced.anchor`).

- [ ] **Step 1: failing tests.** Cases: omitted settings normalise to `[]`; a token value for `spacing.padding.top` at `md` only is accepted (sparse); `visibility` rejects a token and accepts `{type:"choice",value:"hidden"}`; `radius` rejects a breakpoint map (non-responsive) and accepts a bare value; `reset` is accepted for every property in the table; `literal` is rejected with `reserved value kind`; an unknown property path is rejected; a property outside capabilities is rejected with `not styleable on this block`; `advanced.anchor` accepts `pricing`, rejects `Pricing Table`; `advanced.attributes` rejects `data-thallo-x` with `reserved prefix`; `advanced.css_classes` rejects `a b!`; `classes` must be a list of strings and order is preserved; nested blocks are validated too; the top-level entry validator returns `['id','type','data','settings']` for every block.
- [ ] **Step 2: RED.** `vendor/bin/phpunit tests/Integration/Content/BlockSettingsValidationTest.php`.
- [ ] **Step 3: implement.** In `FieldValidator::validateBlocks()` after the `data` recursion:

```php
[$cleanSettings, $settingsErrors] = $this->settingsValidator->validate(
    $block['settings'] ?? null,
    $this->capabilitiesFor($type), // A1: StyleCapabilities::all(); narrowed per type in A2/A5
);
foreach ($settingsErrors as $path => $message) {
    $errors["{$path}.settings.{$path}"] = $message;
}
$clean[] = ['id' => $id, 'type' => $type, 'data' => $cleanData, 'settings' => $cleanSettings];
```

`capabilitiesFor()` reads the block type row's `style_capabilities` (A1.2) and falls back to `StyleCapabilities::all()` when null.
- [ ] **Step 4: GREEN**, phpcs by exit code, then `BlocksRenderingTest`, `EditInPlaceMarkingTest`, `SeedBlockTypesTest` (their fixtures gain `settings` in assertions where they compare whole blocks).
- [ ] **Step 5: commit** `feat(content): typed block settings — schema, validator, normalisation`.

### Task A1.2: block type registry keys

**Files:**
- Create: `core/database/migrations/0XX_AddStyleColumnsToBlockTypes.php` (`style_capabilities json null`, `style_targets json null`, `flags json null`, `starter_content json null`)
- Modify: `core/src/Content/Blocks/BlockTypeRepository.php:48-61` (insert map), `:127-134` (update map), `:211` (`assertBlockSchema` validates the new keys), `:228` (`hydrate` decodes them), plus `schemasBySlug()` gains a sibling `capabilitiesBySlug(): array<string, StyleCapabilities>`; `core/src/Content/Blocks/StarterBlockTypes.php` (every definition gains `'style_capabilities' => ['*']` in A1; narrowed later); `core/src/Content/Blocks/SyncBlockTypesCommand.php:78-101` (sync the new top-level keys); `core/src/Content/Http/DTOs/Responses/BlockTypes/BlockTypeItemData.php`; `admin/src/queries/blockTypes.ts` (`BlockType` gains `style_capabilities`, `style_targets`, `flags`, `starter_content`); `admin/src/api/schema.d.ts` via `pnpm gen:api`
- Test: `tests/Integration/Content/BlockTypeStyleKeysTest.php`, `SeedBlockTypesTest` (assert every starter declares capabilities), `admin/src/__tests__/blockTypesPage.spec.ts` (fixture gains the keys)

- [ ] **Step 1: failing tests** — create/hydrate round-trips the four keys; `assertBlockSchema` rejects an unknown capability path and a target without a `kind`; `thallo:blocks:sync --dry-run` reports a starter whose row lacks `style_capabilities`; `BlockTypeController::index()` returns the keys.
- [ ] **Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, `pnpm gen:api`, admin lint/type-check/test.
- [ ] **Step 5: commit** `feat(blocks): block types declare style capabilities, targets, flags and starter content`.

### Task A1.3: the resolver, twice, one fixture set

**Files:**
- Create: `packages/thallo-render/src/Style/CascadeResolver.php`, `packages/thallo-render/src/Style/Resolution.php` (value object `{value: array|null, source: 'instance'|'class:<id>'|'theme-default', state: 'explicit'|'inherited'|'theme-default'|'reset', breakpoint: string}`), `packages/thallo-render/resolver-fixtures/v1/README.md`, `packages/thallo-render/resolver-fixtures/v1/{table,sparse,reset-terminates,later-breakpoint-after-reset,non-responsive,dormant}.json`, `admin/src/style/resolver.ts`, `admin/src/style/types.ts`
- Test: `tests/Integration/Render/CascadeResolverFixturesTest.php` (loads every fixture and asserts byte-equal normalised JSON output), `admin/src/__tests__/style-resolver.spec.ts` (same fixtures, read from `../../packages/thallo-render/resolver-fixtures/v1`)

**Fixture format:**

```json
{
  "name": "table-row-1",
  "property": "spacing.padding.top",
  "responsive": true,
  "classes": [{ "id": "c1", "style": { "spacing": { "padding": { "top": { "base": {"type":"token","value":"spacing.lg"}, "md": {"type":"token","value":"spacing.xl"} } } } } }],
  "instance": { "spacing": { "padding": { "top": { "base": {"type":"token","value":"spacing.sm"} } } } },
  "expect": {
    "base": { "value": {"type":"token","value":"spacing.sm"}, "source": "instance", "state": "explicit" },
    "md":   { "value": {"type":"token","value":"spacing.xl"}, "source": "class:c1", "state": "explicit" },
    "lg":   { "value": {"type":"token","value":"spacing.xl"}, "source": "class:c1", "state": "inherited" }
  }
}
```

**Interfaces:** `CascadeResolver::resolve(string $property, array $classes, array $instance, PropertyDefinition $def): array<string, Resolution>` (one entry per breakpoint, or one `base` entry for non-responsive properties). TS: `resolve(property, classes, instance, def): Record<Breakpoint, Resolution>`.

- [ ] **Step 1: write the six fixture files** (the §1.6 table is `table.json` with four cases; `reset-terminates.json` has classes A `md: xl`, B `md: lg`, instance `md: reset` → `theme-default` at md; `later-breakpoint-after-reset.json` proves an explicit `lg` after a reset at `md` applies).
- [ ] **Step 2: RED** in both runtimes (`vendor/bin/phpunit --filter CascadeResolverFixturesTest`; `pnpm vitest run src/__tests__/style-resolver.spec.ts`).
- [ ] **Step 3: implement** the §1.6 algorithm in both:

```php
foreach (self::breakpointsDownFrom($target) as $bp) {
    $exact = $this->exactDeclarationsAt($bp, $classes, $instance, $property); // highest precedence first
    if ($exact !== []) {
        [$layer, $value] = $exact[0];
        if (($value['type'] ?? null) === 'reset') { return Resolution::themeDefault($target, "reset by {$layer}"); }
        return new Resolution($value, $layer, $bp === $target ? 'explicit' : 'inherited', $target);
    }
}
return Resolution::themeDefault($target);
```

- [ ] **Step 4: GREEN** in both; the PHP test also asserts the TS fixture list equals the PHP fixture list (no fixture can be added to one runtime only).
- [ ] **Step 5: commit** `feat(style): breakpoint-first cascade resolver in PHP and TypeScript with one fixture contract`.

### Task A1.4: the Block value object and the completeness test

**Files:**
- Create: `core/src/Content/Blocks/Block.php` (`final readonly class Block { id, type, data, settings, children }`, `fromArray()`, `toArray()`, `withSettings()`, `withData()`)
- Modify: every reconstruction path to go through `Block`: `FieldValidator` (A1.1), `BlockInstanceWalker::rewrite()`, `RegionRepository` save/find, `PreviewWorkingCopyStore::put()`, entry version restore, the import path (`packages/thallo-importers`), `EntryBlocksRenderer` context (`block` in the Twig context gains `settings`), `RenderContextExtension::blocks()` (`:1034-1047` passes `settings` through), `admin/src/fields/components/blocks/useBlockListOps.ts` (`BlockInstance` gains `settings`; `duplicateById` and `idMapBetween` copy it; `insertAt` initialises `{}`)
- Test: `tests/Integration/Content/BlockSettingsCompletenessTest.php`, `admin/src/__tests__/blockListOps.spec.ts`

- [ ] **Step 1: failing tests.** One fixture document with nested blocks carrying ordered class ids, sparse maps, a reset and advanced settings. For each path (save draft, publish, restore version, region save and read, preview apply and read, duplicate, move across, import round-trip, `GET /entries/{uuid}`), assert the settings come back byte-identical. The admin spec asserts `duplicateById` and `moveAcross` preserve `settings`.
- [ ] **Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, admin gates.
- [ ] **Step 5: commit** `feat(content): one Block value object; settings survive every reconstruction path`.

### Task A1.5: the populated upgrade fixture and the rehearsal job

**Files:**
- Create: `scripts/build-upgrade-fixture` (boots a beta.28 install from Packagist into a temp dir, seeds drafts, published entries with two retained versions, a header and footer region, a heading with `color: #ff0000`, a container with freeform padding `17px` and a hex overlay, an animated text with three hex colours, then `pg_dump --data-only` to `tests/Fixtures/upgrade/beta28.sql`), `tests/Fixtures/upgrade/beta28.sql`, `tests/Fixtures/upgrade/README.md`, `scripts/upgrade-rehearsal` (restores the fixture into `app_test`, runs `migrate:run`, `thallo:doctor`, and in A5 the converter), `.github/workflows/upgrade-rehearsal.yml`
- [ ] **Step 1:** write the scripts; the rehearsal asserts every fixture entry renders with HTTP 200 after migration and that `thallo:doctor` passes.
- [ ] **Step 2:** run locally against `app_test` (not concurrently with the suite). **Step 3:** CI job green.
- [ ] **Step 4: commit** `test(upgrade): populated beta.28 fixture and the upgrade rehearsal gate`.

### Task A1.6: slice gates and release

- [ ] `CHANGELOG.md` Unreleased: "Blocks carry typed style settings (schema v1); block types declare style capabilities; nothing reads them yet." `docs/internal/OUTSTANDING.md` unchanged.
- [ ] Full `composer test`, admin gates, `composer test:skeleton`, `composer test:distribution`, phpcs exit 0, boundaries, upgrade rehearsal.
- [ ] Commit `docs(changelog): slice A1`. Cut a beta on the user's word.

---

## Slice A2 — vocabulary and delivery

Outcome: the theme declares its vocabulary and stylesheets; CSS is delivered in layers; the compiled style artifact exists with its lifecycle; the five proof blocks render settings through targets; the three-engine proofs run in CI. Content still has no settings, so pages render identically to A1.

### Task A2.1: vocabulary and theme manifest

**Files:**
- Create: `packages/thallo-render/src/Style/Vocabulary.php` (baseline names per §2.1, `VERSION = 1`, `isBaseline(string $token): bool`, `domain(string $token): string`), `packages/thallo-render/src/Style/ThemeVocabulary.php` (`fromThemeJson(array $json, string $theme): self`, throws `ThemeConfigError` naming every missing baseline name; `value(string $token): string`)
- Modify: `packages/thallo-render/themes/default/theme.json` (add `vocabulary` mapping every baseline name, e.g. `"spacing.lg": "var(--space-4)"`, `"color.accent": "var(--accent)"`, `"radius.full": "999px"`, `"typography.size.lg": "clamp(1.125rem, 1rem + 0.5vw, 1.35rem)"`; add `stylesheets: ["assets/site.css", "assets/blocks.css", "assets/navigation.css", "assets/stepper.css"]`), `packages/thallo-render/src/ThemeLocator.php:151` and `packages/thallo-render/src/RenderThemeValidator.php:20` (both parse and validate `vocabulary` and `stylesheets`), `core/src/Setup/Doctor/Doctor.php` (`themeVocabularyCheck()`)
- Test: `tests/Integration/Render/ThemeVocabularyTest.php` (shipped theme complete; a fixture theme missing `radius.full` fails with its name; `stylesheets` must exist on disk), `DoctorTest`

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN.** **Step 5: commit** `feat(theme): platform vocabulary and stylesheet manifest, validated at load and on switch`.

### Task A2.2: layered delivery

**Files:**
- Create: `packages/thallo-render/assets/style/layers.css` (`@layer theme, settings;`), `packages/thallo-render/src/Style/ThemeStylesheetArtifact.php` (`build(ThemeVocabulary, list<string> $files, list<string> $contributed): string` concatenates the manifest files and contributed package sheets inside `@layer theme { … }`, rejects `@import`, `@charset`, `@namespace` with file and line, and `!important` on a managed property of a `.thallo-block*` selector via `ThemeCssLint`), `packages/thallo-render/src/Style/ThemeCssLint.php`, `packages/thallo-render/src/Contribution/StylesheetContributor.php` (packages register sheets; `thallo-commerce` registers its storefront sheet here and `shop_styles_url()` is removed)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (serve `/_thallo/layers.css` and `/theme-assets/theme.{hash}.css`, immutable), `packages/thallo-render/themes/default/templates/layout.twig:30-33` and `region-preview.twig` (one `layers.css` link, one theme artifact link, then `theme_colors_style()`, then custom CSS), `RenderContextExtension` (`theme_stylesheet_url()`), `TemplatePolicy` (allowlist + `CACHE_VERSION` bump)
- Test: replace `tests/Integration/Render/ThemeColorsLayoutTest.php` order assertion with `tests/Integration/Render/LayerOrderTest.php` (layers link first; theme artifact wrapped in `@layer theme`; colours block contains no selector outside `:root`/`html[data-theme="dark"]`; custom CSS last), `ThemeStylesheetArtifactTest` (rejections; `!important` outside managed properties passes), `tests/Integration/Commerce/*` that assert the shop sheet link now assert it is inside the theme artifact

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN**, `ShippedTemplatesLintGateTest`. **Step 5: commit** `feat(render): theme CSS delivered inside @layer theme from the manifest; layer order stylesheet`.

### Task A2.3: the compiler and the compiled style artifact

**Files:**
- Create: `packages/thallo-render/src/Style/ClassNames.php` (`for(string $property, string $token|choice, string $bp): string`, e.g. `t-pt-lg`, `md:t-pt-lg`, reset `md:t-pt-reset`; one place, shared by the compiler and the helpers), `packages/thallo-render/src/Style/StyleCompiler.php` (`compile(ThemeVocabulary $v): string` emits `@layer settings { :root{--t-spacing-lg:…} .t-pt-lg{padding-top:var(--t-spacing-lg)} … @media (min-width:768px){ .md\:t-pt-lg{…} .md\:t-pt-reset{padding-top:revert-layer} } … }` in base, md, lg order), `packages/thallo-render/src/Style/StyleArtifactStore.php` (`publish(string $css, string $hash): void`, `current(): ?string` hash, `activate(string $hash)`, retention of the last three for 24 h, storage under `storage/app/style-artifacts/`), `packages/thallo-render/src/Style/StyleArtifactHash.php` (sha256 of vocabulary values + `Vocabulary::VERSION` + `StyleCompiler::VERSION`)
- Modify: `RenderController` (serve `/_thallo/settings.{hash}.css`, immutable), `layout.twig` (link after the theme artifact), `packages/thallo-render/src/ThemeAppearanceSource.php` (`fingerprint()` gains the artifact hash), `core/src/Setup/UpgradeCaches.php` (recompile + publish + purge; returned label `compiled style artifact`), `core/src/Setup/Console/ProvisionCommand.php` (compile step between block seed and cache clear; failure is fatal for a theme switch but a warning at provision), `GeneralSettingsController` theme switch (validate + compile before activating; failure returns 422 naming the cause), `Doctor` (`styleArtifactCheck()`)
- Test: `tests/Integration/Render/StyleCompilerTest.php` (deterministic output; base→md→lg order; reset utilities; no `!important`; hash changes with vocabulary and with `StyleCompiler::VERSION`), `StyleArtifactLifecycleTest` (publish, activate, retain three, purge, previous hash still served), `RenderPageCacheTest` (key contains the hash), `GeneralSettingsAppearanceTest` (a theme whose compile fails is not activated)

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN**, phpcs, boundaries. **Step 5: commit** `feat(style): compiled style artifact — compiler, lifecycle, cache fingerprint, provision and doctor`.

### Task A2.4: targets, helpers and the five proof blocks

**Files:**
- Create: `packages/thallo-render/src/Style/StyleTargets.php` (`fromDeclaration(array $targets): self`; each target `{kind: text|row|stack|box, optional: bool}` plus `map: array<capability path, target name>`; `validateAgainst(StyleCapabilities)` enforces kind rules: `alignment.text`→text, `alignment.content`→row, `alignment.self`→box), `packages/thallo-render/src/Style/BlockStyleEmitter.php` (`classesFor(Block, StyleTargets, string $target, array $classDefinitions): string` runs the resolver per capability mapped to the target and joins `ClassNames`; `attrsFor(...)`)
- Modify: `RenderContextExtension` (functions `style_classes(target)`, `style_attrs(target)`, `token_class(property, value)`; the block frame pushed at `:1024` gains `settings` and the block type's targets; `TemplatePolicy` allowlist and `CACHE_VERSION` bump), `packages/thallo-render/src/Templates/TemplateLinter.php` (`$deny` branches: `style=` attribute anywhere; a template for a block type with declared targets must call `style_classes()` for every declared target and no undeclared one), `StarterBlockTypes.php` (heading, button, columns, hero, animated_text get real `style_capabilities` and `style_targets` per §1.7; animated_text keeps its hex fields until A5 but declares its targets now), `themes/default/templates/blocks/{heading,button,columns,hero,animated_text}.twig` (emit the helpers on the right elements; heading keeps its inline colour until A5), `ShippedTemplatesLintGateTest` (the new rules are active for block types with declared targets)
- Test: `tests/Integration/Render/StyleTargetsRenderTest.php` (a heading with `spacing.padding.top` md-only renders `md:t-pt-lg` on the `<h2>`; a button with radius on control renders on the `<a>`, alignment on the root; columns children style independently; hero `media` dormant when absent; `token_class('colors.text', token)` emits `t-text-accent`; an unknown target name throws at lint), `tests/Integration/Render/StyleTargetsKindTest.php` (alignment.text on a row target is rejected)

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement.** Example, `button.twig` root and control:

```twig
<div class="thallo-block thallo-block-button thallo-block-button--{{ align }}{{ style_classes('root') }}"{{ style_attrs('root') }}>
  <{{ tag }} class="{{ linkClass }}{{ style_classes('control') }}"{% if url %} href="{{ url }}"{% endif %}{{ style_attrs('control') }}>
```

- [ ] **Step 4: GREEN**, lint gate, phpcs. **Step 5: commit** `feat(render): style targets and the style_classes/style_attrs/token_class helpers on the proof blocks`.

### Task A2.5: three-engine proofs

**Files:**
- Create: `tools/style-proofs/` (`package.json` with `@playwright/test`, `playwright.config.ts` with projects chromium, firefox, webkit; `fixtures/` HTML pages built from the real compiled artifact and the real theme artifact by `scripts/build-style-proof-fixtures` (PHP, writes static HTML into `tools/style-proofs/fixtures/`); `tests/cascade-table.spec.ts`, `tests/secondary-variant-reset.spec.ts`, `tests/responsive-padding-reset.spec.ts` asserting `getComputedStyle` at widths 390, 768, 1280), `.github/workflows/style-proofs.yml` (installs all three engines, path-filtered to the render pack and the tool)
- [ ] **Step 1:** write specs; **Step 2:** run locally in all three engines; **Step 3:** CI green.
- [ ] **Step 4: commit** `test(style): computed-style proofs in Chromium, Firefox and WebKit`.

### Task A2.6: slice gates and release

- [ ] `THEMING.md`: vocabulary, manifest, layers, targets and helpers (§2 contracts); `docs/production.md`: browser floor (Chrome 111, Firefox 113, Safari 16.2) and the tested matrix; `CHANGELOG.md`.
- [ ] All gates including the upgrade rehearsal and style proofs. Commit, cut a beta on the user's word.

---

## Slice A3 — history and the revision protocol

Outcome: every edit is an operation; undo and redo work; the apply exchange carries revisions; the saved position is tracked. No new controls yet.

### Task A3.1: operations, appliers, transactions, history

**Files:**
- Create: `admin/src/editor/ops/types.ts` (the §3.1 operation set with `op_id`, `transaction_id`, `at`, `session`, `from`, `to`; `MoveBlock` with `{parent, slot, index}` both ends; `InsertBlocks` carrying allocated subtrees), `admin/src/editor/ops/apply.ts` (one pure applier per op, delegating to `useBlockListOps`), `admin/src/editor/ops/invert.ts`, `admin/src/editor/history.ts` (`beginTransaction()`, `record(op)`, `commit()` normalising to the minimal delta, `cancel()`, `undo()`, `redo()`, `savedPosition`, `isDirty`, count cap 200 and byte cap 2 MiB), `admin/src/editor/session.ts` (session id)
- Test: `admin/src/__tests__/editor-ops.spec.ts` (every op applies and inverts to the identical tree; `InsertBlocks` redo reuses ids), `admin/src/__tests__/editor-history.spec.ts` (slider sm→md→lg→xl commits one `SetSetting` sm→xl; typing commits one `SetField`; cancel leaves no entry; new edit clears redo, replay does not; undo past the saved position marks dirty; byte cap evicts oldest)

- [ ] **Step 1: failing specs. Step 2: RED. Step 3: implement. Step 4: GREEN**, lint, type-check, fmt on touched files. **Step 5: commit** `feat(editor): intent operations, transactions and history`.

### Task A3.2: the apply protocol

**Files:**
- Modify: `core/src/Content/Http/DTOs/ApplyPreviewData.php` (+ `base_revision: ?int`, `changed: ?list<string>` hint), create `core/src/Content/Http/DTOs/Responses/ApplyPreviewResultData.php` (`revision`, `baseline`, `style_generation`, `applied_at`), `core/src/Content/Preview/PreviewWorkingCopyStore.php` (stores `revision` with the copy; `put()` returns the new revision; a `base_revision` older than the accepted one is rejected with 409 `PREVIEW_REVISION_STALE`), create `core/src/Content/Style/SiteStyleGeneration.php` (system flag `style.generation`, `current(): int`; Phase B increments it), `EntryController::applyPreview()`, `packages/thallo-render/assets/preview/preview-bridge.js` (`thallo:stage-refresh` carries `revision`; the bridge records the displayed revision and answers `thallo:stage-refreshed {revision}`; refuses a refresh whose revision is older than displayed), `admin/src/composables/useCanvasBridge.ts`, `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (local, accepted, displayed revisions; the §3.5 transition table; undo/redo buttons and ⌘Z/⇧⌘Z; every existing mutation routed through operations)
- Test: `tests/Integration/Content/PreviewRevisionTest.php` (monotonic revisions; stale base rejected; response carries generation), `admin/src/__tests__/canvas-revisions.spec.ts` (each row of the transition table; save of revision 10 completing after an edit to 11 leaves 11 dirty; a stale response is dropped; baseline mismatch triggers refresh), `admin/src/__tests__/canvas-page.spec.ts` (undo reverts a stage move)

- [ ] **Step 1: failing tests. Step 2: RED. Step 3: implement. Step 4: GREEN**, `pnpm gen:api`, all admin gates, phpcs. **Step 5: commit** `feat(canvas): three-revision apply protocol, undo and redo`.

### Task A3.3: slice gates and release

- [ ] `CHANGELOG.md`; full gates; beta on the user's word.

---

## Slice A4 — generated Style and Advanced controls

Outcome: selecting a block opens a block inspector with Content, Style and Advanced; Style is generated from capabilities; the active breakpoint is editor state; the inspector explains each value.

### Task A4.1: the block inspector and controls

**Files:**
- Create: `admin/src/editor/inspector/BlockInspector.vue` (tabs Content, Style, Advanced for the selected block; Content reuses `BlockCard`'s field rendering through a shared `BlockFields.vue` extracted from `BlockCard.vue:57-165`), `StyleTab.vue` (groups spacing, size, typography, colours, effects, visibility from `style_capabilities`), `AdvancedTab.vue` (anchor, Style classes list read-only until Phase B, CSS classes, attributes, label), `controls/TokenScaleControl.vue` (segmented control over the ordinal scale, theme value preview from A4.2), `controls/ChoiceControl.vue`, `controls/IdentifierControl.vue`, `controls/ResponsiveField.vue` (breakpoint indicator bound to the active breakpoint, state badge `explicit|inherited|theme-default|reset`, source label, Reset and Apply-to-all-breakpoints actions), `admin/src/editor/breakpoint.ts` (active breakpoint store; the viewport switcher sets it, the user can pin it)
- Modify: `[locale].vue` (a Block tab appears in the inspector when a block is selected; edits emit `SetSetting`/`SetAdvanced` ops), `admin/src/style/types.ts` (property table mirrored from `StyleSchema` through `pnpm gen:api`-generated constants or a JSON export `GET /v1/admin/render/style-schema`)
- Test: `admin/src/__tests__/block-inspector.spec.ts` (a heading shows spacing, text alignment, typography, text colour, visibility and nothing else; editing padding at md with the active breakpoint md writes `md`; the state badge shows inherited for lg; Reset writes `{type:"reset"}`; Apply to all writes base, md and lg; Advanced rejects `data-thallo-x` inline), `admin/src/__tests__/responsive-field.spec.ts`

- [ ] **Step 1: failing specs. Step 2: RED. Step 3: implement. Step 4: GREEN.** **Step 5: commit** `feat(admin): block inspector with generated Style and Advanced controls`.

### Task A4.2: vocabulary previews and the style schema endpoint

**Files:**
- Create: `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php` (`GET /v1/admin/render/style-schema` → properties, kinds, choices, breakpoints, and the active theme's vocabulary values), DTOs, admin query `admin/src/queries/styleSchema.ts`
- Test: `tests/Integration/Render/StyleSchemaEndpointTest.php`, admin spec for the token control preview swatch/size
- [ ] **Steps 1–4** as above. **Step 5: commit** `feat(render): style schema and vocabulary endpoint for the admin`.

### Task A4.3: slice gates and release

- [ ] `CHANGELOG.md`; docs page `docs/builder.md` (how to style a block, breakpoints, reset); full gates; beta on the user's word. Dogfood on thallo.dev: style the homepage hero, buttons and cards from the inspector; file gaps.

---

## Slice A5 — conversion

Outcome: every starter template declares targets and capabilities; the old style fields are gone; the converter runs under the cutover contract; the rehearsal exercises it.

### Task A5.1: targets and capabilities everywhere

- [ ] `StarterBlockTypes.php`: real `style_capabilities` and `style_targets` for the remaining 42 starters and for `thallo-account` and `thallo-commerce` contributed types; `flags.renders_children_inline` on accordion, tabs, stepper, gallery, pricing_table, carousel.
- [ ] Every block template emits `style_classes()`/`style_attrs()` on its targets; the lint gate's target rules apply to all block types. `SyncBlockTypesCommand` syncs the keys to existing rows; `thallo:blocks:sync` runs in provision.
- [ ] Tests: `StarterTemplatesTest` extended so every starter renders a padding setting on its root; lint gate green.
- [ ] Commit `feat(blocks): style targets and capabilities on every block type`.

### Task A5.2: style-source dispositions

**Files:** the §2.6 table, one commit per row group.
- [ ] heading: remove `align`, `color`, `size` from the schema; `StarterTemplatesTest.php:238-247` asserts `colors.text` renders `t-text-*` and no `style=`.
- [ ] animated_text: `prefix_color`, `rotate_color`, `suffix_color` become `{type: 'token', domain: 'color'}` fields (new field type `token` in `FieldDefinition::TYPES` with `domain`); template uses `token_class('colors.text', …)`.
- [ ] container: remove padding preset and boxes, margin, radius, border, shadow, background colour, overlay colour and opacity, max width; overlay becomes `overlay: choice none|light|dark` plus `overlay_opacity: choice 25|50|75`; `background_image` renders as `<img class="thallo-block-container__bg" loading="lazy" …>`; `width` and `min_height` stay as block semantics.
- [ ] carousel: `transition_duration` number becomes `speed: choice slow|normal|fast`; runtime reads `data-speed`.
- [ ] image: inline sizing style becomes classes from its typed fields.
- [ ] Commit per group: `refactor(blocks): <block> presentation moves to settings`.

### Task A5.3: the document sources registry

**Files:**
- Create: `core/src/Content/Blocks/Sources/BlockDocumentSources.php` (registry of `BlockDocumentSource` implementations: `EntryDraftsSource`, `EntryVersionsSource` (every retained version, not only current publications), `RegionsSource`; `each(callable)` with locking and write barrier; later sources register in their providers)
- Modify: `BlockBackfillRunner` iterates the registry (regions and all versions now migrate too)
- Test: `tests/Integration/Content/BlockDocumentSourcesTest.php` (a rename op reaches a region and a non-current version)
- [ ] **Steps 1–4. Step 5: commit** `feat(blocks): one registry of block-bearing document sources for migrations and conversion`.

### Task A5.4: the converter

**Files:**
- Create: `core/src/Content/Style/Conversion/ConversionTable.php` (the §7.2 table as data: field → `settings` translation closure, `keep`, or `unmappable`), `Converter.php` (walks every source; per block applies the table; emits diagnostics), `DiagnosticsReport.php` (JSON lines: entry, locale, block id, field, old value, status `converted|kept|unmappable|discarded`, reason, document revision hash, converter version), `DecisionsFile.php` (JSON keyed by `documentHash:blockId:field` → `{action: token|transform|discard, value}`; a decision whose document hash changed is invalid), `core/src/Content/Console/ConvertSettingsCommand.php` (`thallo:blocks:convert-settings --dry-run --report=path` and `--decisions=path`; live run refuses while any diagnostic is unresolved; stamps `_schema.settings = 1`; idempotent by stamp; runs inside the migration gate)
- Modify: `ProvisionCommand` (runs the converter automatically only when a dry run reports zero unresolved diagnostics; otherwise prints the report path and stops before activating), `FieldValidator::validatePresentation` sibling `validateSchemaStamp()` for the reserved `_schema` key
- Test: `tests/Integration/Content/ConverterTest.php` (button shape → radius token; heading align → alignment.text; container padding preset → spacing token; `17px` → unmappable; a decision converts it; a stale decision is rejected; live run refuses with one unresolved; stamp makes a rerun a no-op; regions and old versions convert), `ConvertSettingsCommandTest`

- [ ] **Steps 1–4. Step 5: commit** `feat(migration): settings converter with dry run, diagnostics and durable decisions`.

### Task A5.5: cutover contract and the real rehearsal

- [ ] `scripts/upgrade-rehearsal` now: restore fixture → dry run → assert the expected unmappables (the `17px` padding, the hex overlay, the three animated-text colours) → apply the committed `tests/Fixtures/upgrade/beta28.decisions.json` → live run → assert stamps and rendering → simulate interruption (kill after the first source) → rerun → identical result → restore backup → assert pre-state. The CI job runs it.
- [ ] `docs/production.md` gains the §7.4 cutover contract; `docs/internal/RELEASING.md` gains "breaking betas require the rehearsal green"; `CHANGELOG.md` carries the breaking notice; `THEMING.md` the retired fields.
- [ ] Commit `docs(upgrade): cutover contract; rehearsal exercises the converter`.

### Task A5.6: slice gates and release

- [ ] Full gates including rehearsal and style proofs. Beta on the user's word. thallo.dev: fresh install (the trivial cutover path), re-author the homepage from the inspector.

---

## Slice A6 — fragments, disabled

Outcome: the fragment subsystem is complete behind `render.fragments.enabled = false`, with instrumentation to decide activation.

### Task A6.1: render-scope resolver and fragment renderer

**Files:**
- Create: `packages/thallo-render/src/Fragments/RenderScopeResolver.php` (§3.5 table; lifts to parents flagged `renders_children_inline`; inspects each proposed root's subtree for `claim_priority_image` users and page dependencies and escalates to whole-page; repeats lifting until stable), `FragmentRenderer.php` (renders a block subtree with the page context in annotation mode; returns `{id, html, root_id}`), `FragmentVerification.php` (records template hashes for the default entry template's block set)
- Modify: `EntryController::applyPreview()` (when the flag is on and `changed` is given, resolve roots and return `fragments`), `ApplyPreviewResultData`
- Test: `tests/Integration/Render/RenderScopeResolverTest.php` (every table row; tabs label edit lifts to the tabs block; a container padding change with an image inside and an earlier priority image elsewhere escalates to whole page), `FragmentVerificationTest` (block-by-block equals whole-page for every fixture including nested tabs, a pricing table and a multi-image page; a changed template hash invalidates)
- [ ] **Steps 1–4. Step 5: commit** `feat(render): render-scope resolver and fragment renderer (disabled)`.

### Task A6.2: the bridge swap and instrumentation

**Files:**
- Modify: `preview-bridge.js` (`thallo:fragments {revision, baseline, fragments}`: validate every target exists and the baseline matches the displayed revision, swap, restore selection, tear down and re-init runtime components, answer `thallo:stage-refreshed`; any failure answers `thallo:fragments-failed` and the parent falls back to `stage-refresh`), `useCanvasBridge.ts`, `[locale].vue` (path selection by flag; performance marks `thallo:input`, `thallo:request`, `thallo:response`, `thallo:paint` for both paths; a dev-only overlay showing median and p95 per path and fallback count), `packages/thallo-render/config/render.php` (`fragments.enabled` default false)
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (swap, selection restore, baseline mismatch fallback), `admin/src/__tests__/canvas-fragments.spec.ts`
- [ ] **Steps 1–4. Step 5: commit** `feat(canvas): fragment swaps behind a flag with apply-to-paint instrumentation`.

### Task A6.3: measurement record and release

- [ ] `docs/internal/plans/2026-09-14-visual-builder-phase-a-plan.md` §A6 gains the measured numbers from thallo.dev at equal debounce; the flag flips only if the §3.5 gate is met, in its own commit.
- [ ] `CHANGELOG.md`; full gates; beta on the user's word. Phase A complete; Phase B planning starts after the thallo.dev gap list is filed.

---

## Self-review

**Spec coverage (Phase A = §1–§3, §7):** §1.1–1.5 → A1.1; §1.2 Block object and completeness → A1.4; §1.6 resolver and table → A1.3; §1.7 capabilities and targets → A1.2, A2.4, A5.1; §2.1–2.2 → A2.1; §2.3 layers → A2.2; §2.4 artifact and lifecycle → A2.3; §2.5 helpers and lint → A2.4; §2.6 floor, proofs, dispositions → A2.5, A5.2; §3.1–3.2 → A3.1; §3.3 parity → A1.3; §3.4 tabs → A4; §3.5 revisions and fragments → A3.2, A6; §7.2–7.6 → A5. Depth five (§5.2) is Phase B by the constraints. Style classes UI (§4) is Phase B; A4 renders the list read-only.

**Placeholder scan:** no TBD; every task names files, tests and commits; code shown where the shape is load-bearing (validator wiring, fixture format, resolver loop, template helper use).

**Type consistency:** `StyleCapabilities`, `StyleTargets`, `ClassNames`, `CascadeResolver`, `Resolution`, `Block`, `BlockDocumentSources`, `ApplyPreviewResultData` are named identically across tasks; `settings_schema_version` is `StyleSchema::VERSION` and the `_schema.settings` stamp.

**Discrepancies resolved while planning (recorded in the spec on 2026-09-14):** the document sources registry, the schema stamp, the revision fields and the block-level inspector are new machinery, not extensions.
