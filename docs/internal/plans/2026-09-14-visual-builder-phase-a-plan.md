# Visual Builder Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phase A of the visual builder: typed block settings in the document, the platform vocabulary with layered delivery and the compiled style artifact, intent-based history with the three-revision apply protocol, generated Style and Advanced controls, the conversion that removes the old per-block style fields under the cutover contract, and the fragment subsystem shipped disabled behind its flag.

**Architecture:** six release slices. Each slice lands schema, validator, renderer, migration, admin behaviour and tests together and leaves Thallo installable, renderable, editable, migratable and green, so any slice can be a beta. Contracts come first (settings document, resolver parity, vocabulary, accurate capabilities and targets for every block, artifact), history wraps the stable document model, and generated controls for a block ship in the same slice that removes that block's legacy presentation fields, so a control never competes with a field. Fragments arrive complete but off.

**Tech Stack:** PHP 8.3 / Glueful 1.85.6, Twig under `TemplatePolicy`, Nuxt UI admin (Vue 3, vitest), PHPUnit, Playwright (Chromium, Firefox, WebKit) for computed-style proofs.

**Spec:** `docs/internal/superpowers/specs/2026-09-14-visual-builder-design.md` (§0–§3 and §7 govern Phase A). Section numbers below refer to it.

## Global Constraints

- Every managed value is typed (`token`, `choice`, `identifier`, `reset`; `literal` reserved and rejected). Every setting definition declares the kinds it accepts (§1.1).
- Breakpoint-first cascade exactly as the §1.6 algorithm; the resolver returns a managed value or the sentinel `theme-default`, never a theme value.
- Implicit theme defaults: the compiler emits a class only where the managed cascade establishes a value; `reset` compiles to `revert-layer`.
- Breakpoints `base`, `md` (≥768px), `lg` (≥1024px); previews at 390, 768, 1280.
- **Undeclared capabilities mean none.** There is no wildcard in the persisted registry; a block type with `style_capabilities: null` accepts no settings and shows no Style controls.
- **A managed style setting never competes with a legacy presentation field.** While a block type's `flags.legacy_presentation` is true, the validator rejects any non-empty `settings.style` for that type (`settings.advanced` is allowed) and the inspector shows no Style controls. The same commit removes the block's legacy fields, converts existing data, clears the flag, and thereby lets the validator accept and the inspector show managed style for that block.
- **No legacy code, no compatibility.** Every removal deletes the old code path, its helpers, its templates and its tests in the same commit: no dual rendering paths, no shims, no deprecated fields kept "for now". `flags.legacy_presentation` is itself transitional and is deleted from the registry, the DTOs and the admin in A5 once no block type carries it.
- **Conversion progress is tracked by named stages, not by the settings schema version.** `_schema.settings` says what shape settings has; `_schema.conversions` lists the named presentation conversions that have run on the document. The converter executes every pending stage and skips completed ones.
- Shared value types live in `thallo-contracts`; core implements, packs consume. The boundary checker (`composer boundaries`) is a gate on every commit.
- Depth cap stays 3 in Phase A (five is Phase B, §5.2); the constant remains defined once per runtime.
- No compatibility layer. The slice that removes a field ships its validator, templates, artifact, editor support and reconstruction paths (§7.6).
- Test gates one at a time (`COMPOSER_PROCESS_TIMEOUT=0 composer test`, ~10 min, never concurrent on `app_test`); phpcs judged by exit code; admin files formatted with `pnpm exec oxfmt <files>` only; no AI attribution trailers; never push.
- `TemplatePolicy::CACHE_VERSION` is bumped with a `// bumped:` log line every time a Twig helper joins the allowlist.
- Starter count stays 47.

## Shared contracts (named once, used by every slice)

Package `thallo-contracts`, namespace `Thallo\Contracts\Style` unless noted:

- `ValueKind` enum: `Token`, `Choice`, `Identifier`, `Reset`, `Literal`.
- `PropertyDefinition` (final readonly): `path`, `group`, `kinds: list<ValueKind>`, `responsive: bool`, `tokenDomain: ?string`, `choices: ?list<string>`.
- `StyleSchema` (final, data only): `VERSION = 1`, `BREAKPOINTS = ['base','md','lg']`, `properties(): array<string, PropertyDefinition>` (§1.3 verbatim), `advanced(): array<string, PropertyDefinition>` (§1.4).
- `StyleCapabilities` (final readonly): `fromDeclaration(?array $pathsOrGroups): self` (null → none), `allows(string $path): bool`, `paths(): list<string>`, `none()`.
- `TargetKind` enum: `Text`, `Row`, `Stack`, `Box`.
- `StyleTargets` (final readonly): `fromDeclaration(array $decl): self` where `$decl = ['targets' => ['root' => ['kind' => 'row', 'optional' => false], …], 'map' => ['spacing' => 'root', 'radius' => 'control', 'advanced.anchor' => 'root', …]]`; `targetFor(string $path): ?string`; `validateAgainst(StyleCapabilities): list<string>` errors (kind rules of §1.7; every capability mapped; each advanced path mapped exactly once).
- `Vocabulary` (final, data only): baseline names per §2.1, `VERSION = 1`, `isBaseline()`, `domain()`.
- `Thallo\Contracts\Content\Block` (final readonly): `id`, `type`, `data`, `settings`, `children` (nested block lists keyed by field); `fromArray()`, `toArray()`, `withData()`, `withSettings()`.
- `Thallo\Contracts\Style\BlockStyleRegistry` interface: `capabilitiesFor(string $type): StyleCapabilities`, `targetsFor(string $type): ?StyleTargets`, `flagsFor(string $type): array`. Bound by core (`CoreServiceProvider`) to `BlockTypeRepository`-backed `EngineBlockStyleRegistry`; consumed by the render pack.

Absent versus null in operations and DTOs is encoded as `{present: false}` versus `{present: true, value: T | null}` (`ChangeValue<T>` in TypeScript, `ChangeValue` in PHP); `undefined` never crosses JSON.

---

## Slice A1 — typed settings in the document

Outcome: every stored block carries `settings`; settings are validated against the §1 schema and the block's capabilities (none, until A2 declares them); the resolver exists in PHP and TypeScript with one fixture set; nothing renders or edits settings yet.

### Task A1.1: contracts

**Files:** create the classes listed under "Shared contracts" in `packages/thallo-contracts/src/Style/` and `packages/thallo-contracts/src/Content/Block.php`; tests `tests/Unit/Contracts/StyleSchemaTest.php` (the §1.3 table is complete; every property declares kinds; `reset` accepted by every style property), `StyleTargetsTest` (kind rules; an advanced path mapped twice is an error), `BlockTest` (round trip preserves key order of `settings.classes`).
- [ ] **Steps: failing tests → RED → implement → GREEN**, phpcs, `composer boundaries`. **Commit** `feat(contracts): style schema, capabilities, targets, vocabulary and the Block value object`.

### Task A1.2: the settings validator

**Files:**
- Create: `core/src/Content/Style/SettingsValidator.php`, `core/src/Content/Style/EngineBlockStyleRegistry.php` (implements `BlockStyleRegistry`; memoises rows from `BlockTypeRepository`)
- Modify: `core/src/Content/Validation/FieldValidator.php:472-485`, `core/src/Providers/CoreServiceProvider.php` (bind the registry)
- Test: `tests/Integration/Content/BlockSettingsValidationTest.php`

**Interface:** `SettingsValidator::validate(mixed $settings, StyleCapabilities $caps, bool $legacyPresentation): array{0: array, 1: array<string,string>}` returning normalised settings and errors keyed by a path relative to the block (`settings.style.spacing.padding.top.md`, `settings.classes.2`, `settings.advanced.anchor`).

- [ ] **Step 1: failing tests.** Omitted settings normalise to `[]`; with no capabilities any style property is rejected with `not styleable on this block`; with capabilities declared but `flags.legacy_presentation` true, any non-empty `settings.style` is rejected with `styling for this block arrives with its conversion` while `settings.advanced` is accepted; with `spacing` declared, a token at `md` only is accepted (sparse); `visibility` rejects a token and accepts a choice; `radius` rejects a breakpoint map and accepts a bare value; `reset` accepted for every declared property; `literal` rejected as `reserved value kind`; unknown path rejected; `advanced.anchor` accepts `pricing`, rejects `Pricing Table`; `advanced.attributes` rejects `data-thallo-x` as `reserved prefix`; `advanced.css_classes` rejects `a b!`; `classes` is a list of strings with order preserved; nested blocks validated; every returned block has exactly `id, type, data, settings`.
- [ ] **Step 2: RED. Step 3: implement.** In `validateBlocks()` after the `data` recursion, with `$path` being the block's own path:

```php
[$cleanSettings, $settingsErrors] = $this->settingsValidator->validate(
    $block['settings'] ?? null,
    $this->styleRegistry->capabilitiesFor($type),
    (bool) ($this->styleRegistry->flagsFor($type)['legacy_presentation'] ?? false),
);
foreach ($settingsErrors as $settingsPath => $message) {
    $errors["{$path}.{$settingsPath}"] = $message;   // e.g. blocks.3.settings.style.radius
}
$clean[] = Block::fromArray(['id' => $id, 'type' => $type, 'data' => $cleanData, 'settings' => $cleanSettings])->toArray();
```

- [ ] **Step 4: GREEN**, phpcs exit 0, boundaries, then `BlocksRenderingTest`, `EditInPlaceMarkingTest`, `SeedBlockTypesTest` (whole-block assertions gain `settings`).
- [ ] **Step 5: commit** `feat(content): typed block settings validated against block capabilities`.

### Task A1.3: block type registry keys

**Files:**
- Create: `core/database/migrations/0XX_AddStyleColumnsToBlockTypes.php` (`style_capabilities json null`, `style_targets json null`, `flags json null`, `starter_content json null`)
- Modify: `BlockTypeRepository.php:48-61, :127-134, :211, :228` (insert, update, validation through `StyleCapabilities::fromDeclaration()` and `StyleTargets::fromDeclaration()->validateAgainst()`, hydrate); `StarterBlockTypes.php` (every definition gains `'flags' => ['legacy_presentation' => true]`; capabilities and targets stay null in A1); `SyncBlockTypesCommand.php:78-101` (sync the four keys); `BlockTypeItemData.php`; `admin/src/queries/blockTypes.ts`; `pnpm gen:api`
- Test: `tests/Integration/Content/BlockTypeStyleKeysTest.php`, `SeedBlockTypesTest`, `admin/src/__tests__/blockTypesPage.spec.ts`
- [ ] Failing tests: round trip; invalid target kind rejected on create; `thallo:blocks:sync --dry-run` reports missing keys; `BlockTypeController::index()` returns them. **RED → implement → GREEN**, gen:api, admin gates.
- [ ] **Commit** `feat(blocks): block types persist style capabilities, targets, flags and starter content`.

### Task A1.4: the resolver, twice, one fixture set

**Files:**
- Create: `packages/thallo-render/src/Style/CascadeResolver.php`, `packages/thallo-render/src/Style/Resolution.php` (`value: ?array`, `source: 'instance'|'class:<id>'|'theme-default'`, `state: 'explicit'|'inherited'|'theme-default'|'reset'`, `breakpoint`), `packages/thallo-render/resolver-fixtures/v1/README.md` and `{table,sparse,reset-terminates,later-breakpoint-after-reset,non-responsive,dormant}.json`, `admin/src/style/resolver.ts`, `admin/src/style/types.ts`
- Test: `tests/Integration/Render/CascadeResolverFixturesTest.php` (every fixture; byte-equal normalised JSON; asserts the TS spec lists the same fixture files), `admin/src/__tests__/style-resolver.spec.ts` (reads `../../packages/thallo-render/resolver-fixtures/v1`)

Fixture format:

```json
{
  "name": "table-row-1",
  "property": "spacing.padding.top",
  "classes": [{ "id": "c1", "style": { "spacing": { "padding": { "top": { "base": {"type":"token","value":"spacing.lg"}, "md": {"type":"token","value":"spacing.xl"} } } } } }],
  "instance": { "spacing": { "padding": { "top": { "base": {"type":"token","value":"spacing.sm"} } } } },
  "expect": {
    "base": { "value": {"type":"token","value":"spacing.sm"}, "source": "instance", "state": "explicit" },
    "md":   { "value": {"type":"token","value":"spacing.xl"}, "source": "class:c1", "state": "explicit" },
    "lg":   { "value": {"type":"token","value":"spacing.xl"}, "source": "class:c1", "state": "inherited" }
  }
}
```

- [ ] **Step 1: fixtures** (the §1.6 table as four cases in `table.json`; `reset-terminates.json`: A `md: xl`, B `md: lg`, instance `md: reset` → `theme-default` at md; `later-breakpoint-after-reset.json`: explicit `lg` after a reset at `md` applies). **Step 2: RED in both runtimes.** **Step 3:** the §1.6 loop:

```php
foreach (self::breakpointsDownFrom($target) as $bp) {
    $exact = $this->exactDeclarationsAt($bp, $classes, $instance, $property); // highest precedence first
    if ($exact !== []) {
        [$layer, $value] = $exact[0];
        if (($value['type'] ?? null) === 'reset') { return Resolution::themeDefault($target, 'reset', $layer); }
        return new Resolution($value, $layer, $bp === $target ? 'explicit' : 'inherited', $target);
    }
}
return Resolution::themeDefault($target);
```

- [ ] **Step 4: GREEN in both. Step 5: commit** `feat(style): breakpoint-first cascade resolver in PHP and TypeScript with one fixture contract`.

### Task A1.5: the Block value object everywhere, and the completeness test

**Files:**
- Modify to construct blocks only through `Block`: `FieldValidator` (A1.2), `BlockInstanceWalker::rewrite()`, `RegionRepository`, `PreviewWorkingCopyStore::put()`, entry version restore, `packages/thallo-importers` import path, `EntryBlocksRenderer`, `RenderContextExtension::blocks()` (`:1034-1047` passes `settings`), `admin/src/fields/components/blocks/useBlockListOps.ts` (`BlockInstance.settings`; `duplicateById`, `idMapBetween` copy it; `insertAt` initialises `{}`)
- Test: `tests/Integration/Content/BlockSettingsCompletenessTest.php` (one fixture document with nested blocks carrying ordered class ids, sparse maps, a reset and advanced settings; every path — save draft, publish, restore version, region save and read, preview apply and read, duplicate, move across, import round trip, `GET /entries/{uuid}` — returns them byte-identical), `admin/src/__tests__/blockListOps.spec.ts`
- [ ] **Steps 1–4**, boundaries. **Commit** `feat(content): settings survive every reconstruction path through the Block value object`.

### Task A1.6: the populated upgrade fixture and the rehearsal job

**Files:**
- Create: `scripts/build-upgrade-fixture` (creates a beta.28 install from Packagist in a temp directory, seeds: two drafts, two published entries with two retained versions each, header and footer regions, a heading with `color: #ff0000` and `align: left`, a container with padding box `17px`, a hex overlay, `min_height_px: 420`, `gap: 12` and `max_width: 900`, a style block with `shadow_color: #123456` and `shadow_opacity: 40`, an animated text with three hex colours, an image with sizing, a carousel with `transition_duration: 1.5`; then `pg_dump --clean --if-exists` (schema and data) to `tests/Fixtures/upgrade/beta28.sql`), `tests/Fixtures/upgrade/README.md`, `scripts/upgrade-rehearsal` (each scenario restores the fixture fresh into `app_test`: `migrate` → `thallo:doctor` → every fixture entry renders 200), `.github/workflows/upgrade-rehearsal.yml`
- [ ] Write, run locally (not concurrently with the suite), CI green. **Commit** `test(upgrade): populated beta.28 fixture and the upgrade rehearsal gate`.

### Task A1.7: slice gates and release

- [ ] `CHANGELOG.md`: "Blocks carry typed style settings (schema v1); block types can declare style capabilities and targets; nothing reads them yet."
- [ ] Full `composer test`, admin gates, `composer test:skeleton`, `composer test:distribution`, phpcs exit 0, boundaries, upgrade rehearsal. Commit `docs(changelog): slice A1`. Beta on the user's word.

---

## Slice A2 — vocabulary, delivery and accurate declarations

Outcome: the theme declares its vocabulary and stylesheets; CSS is delivered in layers; the compiled style artifact exists with its lifecycle; every block type declares accurate capabilities and targets and every template emits the helpers on them; the lint checks target correctness. Legacy presentation fields and their inline styles remain (they are removed with their controls in A4 and A5), so pages render identically and no control exists yet.

### Task A2.1: vocabulary and theme manifest

**Files:**
- Create: `packages/thallo-render/src/Style/ThemeVocabulary.php` (`fromThemeJson(array $json, string $theme): self`; throws `ThemeConfigError` naming every missing baseline name; `value(string $token): string`)
- Modify: `packages/thallo-render/themes/default/theme.json` (`vocabulary` for every baseline name, e.g. `"spacing.lg": "var(--space-4)"`, `"color.accent": "var(--accent)"`, `"radius.full": "999px"`, `"typography.size.lg": "clamp(1.125rem, 1rem + 0.5vw, 1.35rem)"`; `stylesheets: ["assets/site.css", "assets/blocks.css", "assets/navigation.css", "assets/stepper.css"]`), `ThemeLocator.php:151` and `RenderThemeValidator.php:20` (both validate `vocabulary` and `stylesheets`), `Doctor.php` (`themeVocabularyCheck()`)
- Test: `tests/Integration/Render/ThemeVocabularyTest.php`, `DoctorTest`
- [ ] **Steps 1–4. Commit** `feat(theme): platform vocabulary and stylesheet manifest, validated at load and on switch`.

### Task A2.2: layered delivery and the theme artifact

**Files:**
- Create: `packages/thallo-render/assets/style/layers.css` (`@layer theme, settings;`), `packages/thallo-render/src/Style/ThemeStylesheetArtifact.php` (`build(list<string> $files, list<string> $contributed): array{css: string, hash: string}`; concatenates inside `@layer theme { … }`; rejects `@import`, `@charset`, `@namespace` with file and line; runs `ThemeCssLint`), `packages/thallo-render/src/Style/ThemeCssLint.php` (property-aware: `!important` on a §1.3 managed property in a rule whose selector targets `.thallo-block*` is an error; elsewhere ignored), `packages/thallo-render/src/Contribution/StylesheetContributor.php` (packages register sheets; `thallo-commerce` registers its storefront sheet and `shop_styles_url()` is removed)
- Modify: `RenderController` (serve `/_thallo/layers.css` and `/theme-assets/theme.{hash}.css`, immutable), `layout.twig:30-33` and `region-preview.twig` (layers link, theme artifact link, `theme_colors_style()`, custom CSS), `RenderContextExtension` (`theme_stylesheet_url()`), `TemplatePolicy` (allowlist + bump), `ThemeAppearanceSource::fingerprint()` (gains the theme artifact hash)
- Test: `tests/Integration/Render/LayerOrderTest.php` replaces the order assertion of `ThemeColorsLayoutTest` (layers first; theme artifact wrapped; colours block variables only; custom CSS last), `ThemeStylesheetArtifactTest` (rejections; `!important` outside managed properties passes), `ThemeArtifactFingerprintTest` (changing a manifest stylesheet or a contributed package stylesheet changes the artifact hash and the render cache appearance fingerprint while the vocabulary is unchanged), commerce tests that asserted the shop link
- [ ] **Steps 1–4**, lint gate. **Commit** `feat(render): theme CSS delivered inside @layer theme from the manifest; fingerprinted theme artifact`.

### Task A2.3: the compiler and the compiled style artifact

**Files:**
- Create: `packages/thallo-render/src/Style/ClassNames.php` (`for(string $property, string $value, string $bp): string` → `t-pt-lg`, `md:t-pt-lg`, `md:t-pt-reset`), `StyleCompiler.php` (`VERSION = 1`; `compile(ThemeVocabulary): string` emits `@layer settings { :root{--t-*} … }` in base, md, lg order with `revert-layer` utilities), `StyleArtifactStore.php` (`publish(css, hash)`, `activate(hash)`, `current()`, retention: last three for 24 h, under `storage/app/style-artifacts/`), `StyleArtifactHash.php` (sha256 of vocabulary values, `Vocabulary::VERSION`, `StyleCompiler::VERSION`)
- Modify: `RenderController` (serve `/_thallo/settings.{hash}.css`), `layout.twig` (link after the theme artifact), `ThemeAppearanceSource::fingerprint()` (gains the settings artifact hash; the existing accent-neutral fingerprint is extended, not created), `UpgradeCaches` (recompile, publish, purge; label `compiled style artifact`), `ProvisionCommand` (compile step between block seed and cache clear; **failure is fatal**: provision stops before activation, the previous usable artifact stays active, exit code non-zero), theme switch in `GeneralSettingsController` (validate and compile before activating; failure is 422 naming the cause), `Doctor` (`styleArtifactCheck()`)
- Test: `StyleCompilerTest` (deterministic; order; reset utilities; no `!important`; hash changes with vocabulary and with `StyleCompiler::VERSION`), `StyleArtifactLifecycleTest` (publish, activate, retain three, previous hash still served, purge), `RenderPageCacheTest` (key contains both hashes), `GeneralSettingsAppearanceTest` (failed compile does not activate), `ProvisionCommandTest` (a theme with a broken vocabulary fails provision with the previous artifact intact)
- [ ] **Steps 1–4**, boundaries. **Commit** `feat(style): compiled style artifact — compiler, lifecycle, fingerprint, fatal at provision`.

### Task A2.4: helpers, targets and capabilities for every block type

**Files:**
- Create: `packages/thallo-render/src/Style/BlockStyleEmitter.php` (`classesFor(Block, StyleTargets, string $target, array $classDefinitions): string`; `attrsFor(...)`), `packages/thallo-render/src/Style/TargetLint.php`
- Modify: `RenderContextExtension` (`style_classes(target)`, `style_attrs(target)`, `token_class(property, value)`; block frame gains `settings` and the block type's targets from `BlockStyleRegistry`; `TemplatePolicy` allowlist and bump), `TemplateLinter` (rules active for block types with declared targets: every declared target used; no undeclared target; **the `style=` rule is not activated here**), `StarterBlockTypes.php` (accurate `style_capabilities` and `style_targets` for all 47 per §1.7, `flags.renders_children_inline` on accordion, tabs, stepper, gallery, pricing_table, carousel, `legacy_presentation` true where legacy fields exist), contributed types in `thallo-account` and `thallo-commerce`, every block template in `themes/default/templates/blocks/` and the two packs (emit the helpers on the right elements; legacy inline styles stay for now)
- Test: `tests/Integration/Render/StyleTargetsRenderTest.php` (renders through `BlockStyleEmitter` and a fixture block type registered with `legacy_presentation` false, since every shipped type still carries legacy fields in A2; heading-shaped fixture md-only padding → `md:t-pt-lg` on the heading element; button radius on the link, alignment on the root; columns children independent; hero `media` dormant when absent; `token_class` emits `t-text-accent`), `StyleTargetsKindTest`, `StarterTemplatesTest` (every starter renders a padding setting on its root when its capabilities include spacing), `ShippedTemplatesLintGateTest` (target rules on)
- [ ] **Steps 1–4. Commit** `feat(render): style targets and helpers on every block type; target lint`.

### Task A2.5: three-engine proofs

**Files:** `tools/style-proofs/` (`package.json`, `playwright.config.ts` with chromium, firefox, webkit projects; `fixtures/` built by `scripts/build-style-proof-fixtures` from the real artifacts; specs `cascade-table`, `secondary-variant-reset`, `responsive-padding-reset` asserting `getComputedStyle` at 390, 768, 1280), `.github/workflows/style-proofs.yml` (all three engines).
- [ ] Write, run locally in all engines, CI green. **Commit** `test(style): computed-style proofs in Chromium, Firefox and WebKit`.

### Task A2.6: slice gates and release

- [ ] `THEMING.md` (§2 contracts), `docs/production.md` (browser floor Chrome 111 / Firefox 113 / Safari 16.2 and the tested matrix), `CHANGELOG.md`. All gates. Beta on the user's word.

---

## Slice A3 — history and the revision protocol

### Task A3.1: operations, appliers, transactions, history

**Files:**
- Create: `admin/src/editor/ops/types.ts` (§3.1 set; `op_id`, `transaction_id`, `at`, `session`; `from`/`to` as `ChangeValue<T>`; `MoveBlock` with `{parent, slot, index}` both ends; `InsertBlocks` carrying allocated subtrees; `SetPageSettings`), `apply.ts`, `invert.ts`, `history.ts` (`beginTransaction`, `record`, `commit` normalising to the minimal delta, `cancel`, `undo`, `redo`; positions are monotonic sequence numbers `baseSequence`, `currentSequence`, `savedSequence`; `isDirty = currentSequence !== savedSequence`; when eviction moves `baseSequence` past `savedSequence`, the document stays dirty until the next save; caps 200 entries and 2 MiB), `session.ts`
- Test: `editor-ops.spec.ts` (apply and invert to the identical tree; `InsertBlocks` redo reuses ids; `{present:false}` round-trips through JSON), `editor-history.spec.ts` (sm→md→lg→xl commits one op; typing commits one; cancel leaves nothing; new edit clears redo, replay does not; undo past saved is dirty; eviction past the saved sequence keeps dirty and forbids undo to clean)
- [ ] **Steps 1–4**, admin gates. **Commit** `feat(editor): intent operations, transactions and sequence-numbered history`.

### Task A3.2: the apply protocol with atomic acceptance

**Files:**
- Modify: `ApplyPreviewData.php` (+ `epoch: ?string`, `base_revision: ?int`, `operations: ?list<array>` (the committed transaction's sanitised ops; used for invalidation intent in A6, validated against both documents), no `changed` field), create `ApplyPreviewResultData.php` (`revision`, `baseline`, `style_generation`, `applied_at`), `PreviewWorkingCopyStore.php` (a per entry+locale record `{epoch, revision, fields, ops, accepted_at}` in the cache with a companion lock key; acceptance is compare-and-set under the lock: the request's `epoch` and `base_revision` must equal the stored pair exactly, else 409 `PREVIEW_REVISION_STALE` carrying the current pair; a null pair initialises only when no record exists, minting a fresh epoch (a ULID) with revision 0; save and expiry end the lifetime: save clears the record only if the record's revision equals the revision the save was submitted from, so an older save never clears a newer accepted copy; the next apply after save or expiry starts a new epoch; every response, the mint response, the rendered canvas page (`data-thallo-epoch`, `data-thallo-revision`) and fragments carry the pair, and the bridge and the parent reject any response whose epoch is not the current one and any revision older than displayed within the epoch), create `core/src/Content/Style/SiteStyleGeneration.php` (system flag `style.generation`, incremented in Phase B), `EntryController::applyPreview()`, `PreviewController` mint response (+ current `revision` so a second editor initialises from accepted state), `RenderController::preview()` (canvas mode embeds `data-thallo-revision` on `<main>` from the working copy), `preview-bridge.js` (`stage-refresh` answers `stage-refreshed {revision}` read from the fetched page, never echoed from the request; a fetched revision older than displayed is refused), `useCanvasBridge.ts`, `[locale].vue` (local, accepted, displayed; §3.5 table; undo/redo buttons and ⌘Z/⇧⌘Z; every mutation through ops)
- Test: `PreviewRevisionTest.php` (two simultaneous same-base applies: one wins with r+1, the other 409; display 10 → save → new apply starts epoch 2 revision 1 and is accepted by the client; expiry then apply likewise; a delayed response from epoch 1 arriving during epoch 2 is rejected; a save submitted from revision 9 racing an accepted apply at 10 does not clear the copy; a second editor minting gets the accepted pair; a refresh racing another editor's apply displays the newer revision), `canvas-revisions.spec.ts` (every table row; save of revision 10 completing after an edit to 11 leaves 11 dirty; stale response dropped; baseline mismatch refreshes), `canvas-page.spec.ts` (undo reverts a stage move)
- [ ] **Steps 1–4**, `pnpm gen:api`, admin gates, phpcs. **Commit** `feat(canvas): three-revision apply protocol with atomic acceptance; undo and redo`.

### Task A3.3: slice gates and release

- [ ] `CHANGELOG.md`; full gates; beta on the user's word.

---

## Slice A4 — controls and conversion, group one

Outcome: the block inspector with generated Style and Advanced controls exists; the converter infrastructure exists; the legacy presentation fields of heading, button, animated text, image and carousel are removed and converted; those block types get their controls. Container and style block keep legacy fields and show a "styling arrives in the next release" notice in the Style tab.

### Task A4.1: style-schema endpoint

**Files:** `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php` (`GET /v1/admin/render/style-schema` → properties with kinds and choices, breakpoints, and the active theme's vocabulary values), DTOs, `admin/src/queries/styleSchema.ts`; test `StyleSchemaEndpointTest.php`. This endpoint is the single UI source of the property table; OpenAPI types stay compile-time only.
- [ ] **Steps 1–4. Commit** `feat(render): style schema and vocabulary endpoint`.

### Task A4.2: the block inspector and controls

**Files:**
- Create: `admin/src/editor/inspector/BlockInspector.vue` (Content, Style, Advanced for the selected block; Content reuses `BlockFields.vue` extracted from `BlockCard.vue:57-165`), `StyleTab.vue` (groups from `style_capabilities`; disabled with a notice when `flags.legacy_presentation` is true), `AdvancedTab.vue` (anchor, Style classes read-only, CSS classes, attributes, label), `controls/TokenScaleControl.vue`, `ChoiceControl.vue`, `IdentifierControl.vue`, `ResponsiveField.vue` (breakpoint indicator bound to the active breakpoint; state badge; source; Reset; Apply to all breakpoints), `admin/src/editor/breakpoint.ts`
- Modify: `[locale].vue` (Block tab when a block is selected; edits emit `SetSetting`/`SetAdvanced`)
- Test: `block-inspector.spec.ts` (a heading shows spacing, text alignment, typography, text colour, visibility only; editing at active breakpoint md writes `md`; lg shows inherited; Reset writes reset; Apply to all writes three; a container shows the notice and no controls; Advanced rejects `data-thallo-x`), `responsive-field.spec.ts`
- [ ] **Steps 1–4. Commit** `feat(admin): block inspector with generated Style and Advanced controls`.

### Task A4.3: the `token` field type

**Files:** `FieldDefinition.php` (`TYPES` gains `token`; `fromArray` parses `domain`), `FieldValidator` (a token field value is `{type:"token", value}` whose domain matches), `FieldSchemaData` (OpenAPI descriptor), `admin/src/fields/normalize.ts`, `admin/src/fields/registry.ts` (`token: TokenField` rendering `TokenScaleControl`), `admin/src/fields/components/TokenField.vue`; tests `FieldValidatorTokenTest.php`, `tokenField.spec.ts`.
- [ ] **Steps 1–4. Commit** `feat(fields): token field type for block-specific presentation`.

### Task A4.4: the document sources registry

**Files:** `core/src/Content/Blocks/Sources/BlockDocumentSources.php` and `BlockDocumentSource` interface (`id()`, `each(callable)`, `persist(DocumentRef, array $fields)` atomic per document), `EntryDraftsSource`, `EntryVersionsSource` (every retained version), `RegionsSource`; `BlockBackfillRunner` iterates the registry; test `BlockDocumentSourcesTest.php` (a rename reaches a region and a non-current version).
- [ ] **Steps 1–4. Commit** `feat(blocks): one registry of block-bearing document sources`.

### Task A4.5: the converter

**Files:**
- Create: `core/src/Content/Style/Conversion/ConversionTable.php` (§7.2 as data per field: `convert(closure)`, `keep`, `unmappable`), `Converter.php`, `DiagnosticsReport.php` (JSON lines with a source-generic identity: `source_type`, `source_id`, `source_revision`, `locale`, `block_id`, `field`, `old_value`, `status` in `converted|kept|unmappable|superseded|discarded`, `reason`, `document_hash`, `converter_version`), `DecisionsFile.php` (keyed by `source_type:source_id:source_revision:block_id:field`, storing `document_hash`, `converter_version`, `action`, `value`; a decision is invalid when either hash or version differs), `ConversionStage.php` (a named stage with its table rows: `presentation-group-1` in A4, `presentation-group-2` in A5; `ConversionStages::pending(array $fields): list<ConversionStage>` reads `_schema.conversions`), `ConvertSettingsCommand.php` (`thallo:blocks:convert-settings --dry-run --report=path`, `--decisions=path`; runs every pending stage in order; refuses live while any diagnostic of a pending stage is unresolved; each document is converted for all pending stages and persisted atomically with `_schema.settings = 1` and the completed stage names appended to `_schema.conversions` in the same persistence operation; idempotent per stage; runs under the migration gate)
- Modify: `ProvisionCommand` (runs the converter only when a dry run has zero unresolved diagnostics; otherwise prints the report path and stops before activation), `FieldValidator` (`validateSchemaStamp()` for the reserved `_schema` key)
- Collision rule: when a legacy field and an explicit settings value both exist, the settings value wins and the legacy field is reported `superseded`, unless the table says otherwise.
- Test: `ConverterTest.php` (button shape → radius; heading align → alignment.text; a `17px` padding → unmappable; a decision converts it; a stale decision by hash or by converter version is rejected; live refuses with one unresolved; a rerun with no pending stage is a no-op; a document already at `presentation-group-1` still runs `presentation-group-2` when that stage exists; regions and old versions convert; `heading.align = left` with `settings.alignment.text.base = center` keeps center and reports superseded; a crash injected between convert and persist leaves the document unstamped and unconverted), `ConvertSettingsCommandTest`
- [ ] **Steps 1–4. Commit** `feat(migration): settings converter with dry run, source-generic diagnostics and durable decisions`.

### Task A4.6: conversion group one

One commit per block, each removing the legacy fields from the schema, converting the template to helpers only, adding the table rows, and clearing `flags.legacy_presentation`:
- [ ] heading: remove `align`, `color`, `size`; `StarterTemplatesTest.php:238-247` asserts `t-text-*` and no `style=`.
- [ ] button: `align` → `alignment.content` on root; `shape` → radius token on control; `block` stays.
- [ ] animated text: `prefix_color`, `rotate_color`, `suffix_color` become `token` fields (domain `color`); template uses `token_class('colors.text', …)`; conversion: hex → unmappable (author picks a token or discards).
- [ ] image: sizing fields become `width` token and `alignment.self`; inline sizing style removed.
- [ ] carousel: `transition_duration` → `speed: choice slow|normal|fast`; runtime reads `data-speed`; conversion by thresholds stated in the table.
- [ ] The rehearsal decisions file `tests/Fixtures/upgrade/beta28.decisions.json` gains this group's decisions.

### Task A4.7: the rehearsal, real

- [ ] `scripts/upgrade-rehearsal` runs four scenarios, each from a fresh restore of the fixture: (1) dry run → expected unmappables → decisions → live → stamps and rendering asserted; (2) interrupted: kill after the first source → rerun → identical result to (1); no document is half-converted (stamps and tree agree everywhere); (3) stale decisions: a document edited after the decisions file → live refuses naming it; (4) backup restore returns the pre-state. CI job runs all four. The rehearsal keeps a snapshot after (1) as `tests/Fixtures/upgrade/after-group-1.sql` for the sequential scenario added in A5.
- [ ] `docs/production.md` gains the §7.4 cutover contract; `RELEASING.md`: breaking betas require the rehearsal green; `CHANGELOG.md` breaking notice; `THEMING.md` retired fields.
- [ ] **Commit** `docs(upgrade): cutover contract; rehearsal exercises the converter in four scenarios`.

### Task A4.8: slice gates and release

- [ ] Full gates including rehearsal and style proofs. Beta on the user's word. thallo.dev: fresh install (the trivial cutover path); style headings and buttons from the inspector; file gaps.

---

## Slice A5 — conversion group two and the inline-style lint

Outcome: container and style block legacy fields are gone; every managed inline style is gone; the "no `style=`" lint is active; every block type has controls.

### Task A5.1: container and style block

- [ ] container: remove padding preset and boxes, margin, radius, border, shadow, background colour, overlay colour and opacity, max width, `min_height_px`, `gap`; overlay becomes `overlay: choice none|light|dark` plus `overlay_opacity: choice 25|50|75`; `gap` becomes a spacing token field; `min_height` stays a choice and `min_height_px` is unmappable; `background_image` renders as `<img class="thallo-block-container__bg" alt="" aria-hidden="true">` whose `loading` follows the priority-image contract (`claim_priority_image()`), not a hard-coded `lazy`.
- [ ] style block: remove `padding`, `margin`, `shadow`, `shadow_color`, `shadow_opacity` (hex and opacity unmappable); keep accent/neutral re-skin and `class_hook` (moved to `advanced.css_classes` by conversion).
- [ ] Table rows, decisions fixture rows, `flags.legacy_presentation` cleared everywhere; the inspector notice disappears.
- [ ] **Commit per block** `refactor(blocks): <block> presentation moves to settings`.

### Task A5.2: activate the inline-style lint

- [ ] `TemplateLinter`: `style=` attribute is an error; selector-bearing inline `<style>` is an error except the three enumerated variable-only or `@font-face` emitters. `ShippedTemplatesLintGateTest` green with no new pins.
- [ ] **Commit** `feat(render): the managed settings system emits no inline styles — lint active`.

### Task A5.3: delete the transitional flag

- [ ] Remove `flags.legacy_presentation` from `StarterBlockTypes`, the repository validation, `BlockTypeItemData`, the admin types and the inspector notice, and the validator branch that read it. Nothing references it afterwards (a grep is the test).
- [ ] **Commit** `chore(blocks): the legacy_presentation flag is gone; every block type is styled through settings`.

### Task A5.4: slice gates and release

- [ ] Rehearsal: the four direct scenarios extended with group two, plus the sequential scenario: restore `after-group-1.sql` → add a draft with a legacy container and edit an existing entry's container → upgrade to A5 → convert → group-two fields converted and diagnosed, group one untouched → rerun → byte-identical. CI runs it.
- [ ] Full gates; `CHANGELOG.md`; beta on the user's word. thallo.dev: re-author the homepage entirely from the inspector.

---

## Slice A6 — fragments, disabled

### Task A6.1: render-scope resolver and fragment renderer

**Files:**
- Create: `packages/thallo-render/src/Fragments/AffectedBlocks.php` (derives affected blocks from the apply's `operations` validated against the accepted-before and validated-after documents: a removed block yields its former parent, a move yields both parents, an omitted or inconsistent operations list yields the whole-page path; in development the derived set is asserted against a client-supplied `debug_changed` list and a mismatch logs a warning), `RenderScopeResolver.php` (§3.5 table; lifts to `renders_children_inline` parents; inspects every proposed root's subtree for `claim_priority_image` users and page dependencies and escalates to whole page; repeats until stable), `FragmentRenderer.php`, `FragmentVerification.php` (template hashes)
- Modify: `EntryController::applyPreview()` (with the flag on: derive roots and return `fragments`), `ApplyPreviewResultData`, `config/render.php` (`fragments.enabled` default false)
- Test: `AffectedBlocksTest` (deletion, cross-container move, omitted operations → whole page), `RenderScopeResolverTest` (every table row; a tabs label edit lifts to the tabs block; a container padding change with an image inside and an earlier priority image elsewhere escalates), `FragmentVerificationTest` (block-by-block equals whole page for every fixture including nested tabs, a pricing table and a multi-image page; a template hash change invalidates)
- [ ] **Steps 1–4. Commit** `feat(render): server-derived affected blocks, render-scope resolver and fragment renderer (disabled)`.

### Task A6.2: the bridge swap and instrumentation

**Files:** `preview-bridge.js` (`thallo:fragments {epoch, revision, baseline_epoch, baseline_revision, fragments}`: reject an obsolete epoch, validate every target and the baseline pair against the displayed pair, swap, restore selection, tear down and re-init runtime components, answer `stage-refreshed`; on failure answer `fragments-failed` and the parent falls back to `stage-refresh`), `useCanvasBridge.ts`, `[locale].vue` (path by flag; performance marks `thallo:input`, `thallo:request`, `thallo:response`, `thallo:paint`; dev-only overlay with median, p95 and fallback count per path); tests `preview-bridge-dom.spec.ts`, `canvas-fragments.spec.ts`.
- [ ] **Steps 1–4. Commit** `feat(canvas): fragment swaps behind a flag with apply-to-paint instrumentation`.

### Task A6.3: measurement record and release

- [ ] Record the thallo.dev numbers at equal debounce in this plan; flip the flag only if the §3.5 gate is met, in its own commit. `CHANGELOG.md`; full gates; beta on the user's word. Phase A complete.

---

## Self-review

**Spec coverage:** §1.1–1.5 → A1.1–A1.2; §1.2 Block and completeness → A1.1, A1.5; §1.6 → A1.4; §1.7 → A1.3, A2.4; §2.1–2.2 → A2.1; §2.3 → A2.2; §2.4 → A2.3; §2.5 → A2.4, A5.2; §2.6 floor, proofs, dispositions → A2.5, A4.6, A5.1; §3.1–3.2 → A3.1; §3.3 → A1.4; §3.4 → A4.1–A4.2; §3.5 → A3.2, A6; §7.2–7.6 → A4.5–A4.7, A5. Depth five and style classes UI are Phase B.

**Release-slice check:** A1 changes storage only, additively. A2 changes delivery with identical output and declares capabilities without controls. A3 changes the editor protocol with no new values written. A2 declares capabilities but the validator still rejects managed style for every shipped type (all carry `legacy_presentation`), so no setting can compete with a field through the API either. A4 removes fields only for the block types whose controls it ships, and the converter records `presentation-group-1`. A5 removes the rest, runs `presentation-group-2` on documents already converted by A4 (proved by the sequential rehearsal), activates the inline-style lint only once nothing emits one, and deletes the transitional flag. A6 is off.

**Placeholder scan:** no TBD; every task names files, tests and commits; shapes shown where load-bearing (validator wiring with the outer path preserved, fixture format, resolver loop, contracts list, `ChangeValue`, diagnostics identity, CAS acceptance).

**Type consistency:** contract names are listed once under "Shared contracts" and used verbatim; `ClassNames`, `CascadeResolver`, `Resolution`, `BlockDocumentSources`, `ApplyPreviewResultData`, `AffectedBlocks`, `RenderScopeResolver` are named identically across tasks; the settings stamp is `_schema.settings` equal to `StyleSchema::VERSION`.
