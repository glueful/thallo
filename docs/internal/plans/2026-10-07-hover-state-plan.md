# The hover state — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Authors set what a link or button looks like under the pointer and under keyboard focus — text colour, background, border colour, opacity — on Button, Social link, Social links, Links and File, in the Style tab's Normal / Hover switch, with the stage previewing the full hover look (the theme's and the author's) and nothing sticking on phones.

**Architecture:**
- **Contracts.** Hover values are ordinary managed paths under `hover.` (`hover.colors.text`, `hover.colors.surface`, `hover.colors.border`, `hover.opacity`), plus a resting `opacity`. A target gets a hover path only where `hover` and the resting path land on the same target. That expansion is implemented once, in `StyleCapabilities` / `StyleTargets`.
- **Server.** The registry returns effective capabilities. The block-type payload publishes them as a derived `style_paths`. The compiler emits a gated pointer branch plus an ungated focus/forced branch per hover utility. The default theme's hover rules for these elements take the same three-branch shape.
- **Admin.** One helper reads `style_paths` for every capability consumer. The Style tab gains the Normal / Hover switch. The stage gets `thallo:force-hover`, held by the admin and re-sent on every `stage-state`.

**Tech Stack:**
- PHP 8.4: Glueful, PHPUnit.
- Twig 3.
- Plain JS: `preview-bridge.js`.
- CSS: `@layer settings`, `@media (hover: hover)`.
- Playwright: `tools/runtime-browser` and `admin/e2e`.
- Vue 3 + Nuxt UI admin: vitest, oxfmt, `pnpm type-check`.

**Spec:** `docs/internal/superpowers/specs/2026-10-07-hover-state-design.md` (approved at `d1c103a8`). Every section of it is in this release.

## Rulings made while planning (from the code)

- **Where the target-aware rule lives.**
  - `StyleCapabilities::fromDeclaration` is single-target: parts, and the upper bound for a block. Its final pass drops each hover path whose resting path is absent.
  - `StyleTargets::fromDeclaration` maps hover entries in a second pass over `map`, after every other entry, which makes the result order-independent.
    - The group `hover` maps only the hover paths whose resting path the same target owns.
    - An individual hover path mapped elsewhere throws `InvalidArgumentException`.
  - `StyleTargets::effective(StyleCapabilities)` removes the block-level hover paths that no target owns.
  - `validateAgainst` skips the "has no target" error for those paths, since they were dropped deliberately.
  - Cost if wrong: one method moves.
- **Who returns effective capabilities.**
  - `EngineBlockStyleRegistry::capabilitiesFor()` returns `targetsFor()?->effective($caps) ?? $caps`.
  - Every PHP consumer that asks the registry therefore gets the effective set with no change of its own: `FieldValidator`, `StyleClassJobRunner`, `StyleClassUsage`.
  - `BlockStyleEmitter` already reads per target (`stylePathsFor`) and per part (`partCapabilities`).
- **`style_paths` is computed in the controller, never stored.**
  - `BlockTypeStylePaths::for(array $row): array` (new, core) computes it.
  - `BlockTypeController` attaches it to every row it returns: `index`, `show`, `store`, `update`.
  - It is not added in `BlockTypeRepository`. The repository's rows feed `EngineBlockStyleRegistry`, and a derived field there would be one more thing to keep in step.
- **Admin-made block types are not offered `hover` or `opacity` this release.**
  - `CustomBlockStyle::GROUPS` is unchanged. Their one target is a `box` root, never a link, and the spec scopes hover to the five blocks.
  - Cost if wrong: one list entry later.
- **The schema-list parity test is a committed snapshot.**
  - PHP writes nothing at test time. `tests/Unit/Contracts/StyleSchemaSnapshotTest.php` asserts that `packages/thallo-contracts/style-schema/v1.json` equals `StyleSchema::properties()`.
  - `admin/src/__tests__/style-schema-parity.spec.ts` asserts that `style/schema.ts` equals the same file.
  - A schema change edits the snapshot, and both tests then point at the side that is behind.
- **The admin keeps a local expansion only for synthetic types:**
  - the style-class editor, which takes every group;
  - regions, which take `RegionStyle::CAPABILITIES`.

  The expansion applies the same final hover pass as `StyleCapabilities`, so a synthetic type can never offer a hover path without its resting path. Real block types and their parts always read `style_paths`.
- **Forced preview and the theme.** A theme rule's forced branch is `[data-thallo-hover]` on the same element the pointer branch targets. For the Social link it is the wrapper's `:has(> __link[data-thallo-hover])`.
- **Browser fixtures.**
  - A new builder, `scripts/build-hover-fixtures`, copies `scripts/build-text-style-fixtures`' skeleton (public and stage pages, rolled back) into `tools/runtime-browser/fixtures/hover/`.
  - It gets its own CI step in `runtime-browser.yml`.
  - The e2e page gains three blocks after the last block of `scripts/build-builder-proof-fixtures`' body. Proofs address blocks by id, and Task 9 checks `inspector-content.spec.ts` does too before relying on that.
- **The stage's force state.**
  - `editor/stage/stageHover.ts` provides a `StageHover` (`force(owner, id, target, part)`, `clear(owner)`). Only the owner that set a force clears it, because a part tab and the block's tab are separate `StyleTab` instances.
  - `useStageEditor` provides it and re-sends the active force from its existing `bridge.onStageState` callback.
  - The bridge re-applies its remembered force after each `markEmptySlots()` call that follows an in-place swap (`preview-bridge.js:1278`, `:1354`).

## Capability consumer inventory

Every place that turns a capability declaration into paths. All of them are covered by a task.

| Consumer | Today | After | Task |
|---|---|---|---|
| `StyleCapabilities::fromDeclaration` (`packages/thallo-contracts/src/Style/StyleCapabilities.php:33`) | group expansion | + final hover pass | 1 |
| `StyleTargets::fromDeclaration` map / parts (`StyleTargets.php:47`) | group → target | + second-pass hover mapping, wrong-target error; parts via the line above | 1 |
| `StyleTargets::validateAgainst` (`:284`) | every cap needs a target | dropped hover paths exempt | 1 |
| `EngineBlockStyleRegistry::capabilitiesFor` (`core/src/Content/Style/EngineBlockStyleRegistry.php:26`) | raw caps | effective caps | 2 |
| `FieldValidator` (`core/src/Content/Validation/FieldValidator.php:627`) | registry | unchanged (gets effective) | 2 (test) |
| `SettingsValidator` parts (`core/src/Content/Style/SettingsValidator.php:154`) | `partCapabilities` | unchanged (gets the hover pass) | 2 (test) |
| `StyleClassJobRunner` (`…/Classes/StyleClassJobRunner.php:151`), `StyleClassUsage` (`…/Classes/StyleClassUsage.php:88`) | registry | unchanged (gets effective) | 2 (test) |
| `BlockTypeRepository` save check (`core/src/Content/Blocks/BlockTypeRepository.php:118`) | caps + targets + `validateAgainst` | unchanged; wrong-target hover now refused | 2 (test) |
| `BlockTypeController::styleRefusal` / `CustomBlockStyle` | admin-made types | unchanged (no hover offered) — ruling | — |
| `StyleClassController` (`StyleCapabilities::all()`) | every path | now includes hover paths | 2 (test) |
| `RegionStyle`, `PageStyleCapabilities`, `LayoutValidator` | fixed lists without hover | unchanged | — |
| `BlockStyleEmitter` (`packages/thallo-render/src/Style/BlockStyleEmitter.php:48,53`) | per target / part | unchanged | 5 (test) |
| `RenderContextExtension::parentStyleClasses` | part filter | unchanged | 5 (test) |
| `BlockTypeController` payload | raw rows | + `style_paths` | 4 |
| Admin `BlockType` type (`admin/src/queries/blockTypes.ts:17`) and `schema.d.ts` | — | + `style_paths` (generated + hand type) | 4 |
| Admin `StyleTab.pathsOf` (`admin/src/editor/inspector/StyleTab.vue:145`) | local expansion | `effectivePaths()` | 7 |
| Admin `LayoutTab.pathsOf` (`LayoutTab.vue:106`) | local expansion | `effectivePaths()` | 7 |
| Admin `BlockInspector.declaredPaths` (`BlockInspector.vue:160`) | local expansion | `effectivePaths()` | 7 |
| Admin `BlockInspector.parts` synthetic part type (`BlockInspector.vue:130`) | `style_capabilities: spec.capabilities` | `style_paths: {block: server part paths}` | 7 |
| Admin `useStageEditor` lift and detach (`useStageEditor.ts:2138`, `:2206`) through `capabilityPaths` (`style/detach.ts:14`) | local expansion | `effectivePaths()` | 7 |
| Admin `StyleClassEditor` synthetic type (`StyleClassEditor.vue:30`) | every group | unchanged (local expansion with hover pass) | 7 (test) |
| Admin `RegionStyleEditor` synthetic type (`RegionStyleEditor.vue:28`) | region caps | unchanged (local expansion) | 7 (test) |
| Admin `LayoutTab.parentArranges` (`LayoutTab.vue:332`) | raw names `layout.display` / `layout` | unchanged (not an expansion) | — |
| Admin `stageTypography.typographyTarget`, `layoutContext.ts` | read `style_targets.map` | unchanged; `hoverTarget()` added beside it | 9 |

## Global Constraints

- **Hover paths:** `hover.colors.text`, `hover.colors.surface`, `hover.colors.border`, `hover.opacity`. Group `hover`. Each has the same kinds, domain and choices as its resting path. Not responsive.
- **Opacity:** `opacity`, group `opacity`, choices `100`, `90`, `80`, `70`, `60`, `50`, not responsive, `reset`.
- **Versions:**
  - `StyleSchema::VERSION` 14 → **15**;
  - `StyleCompiler::VERSION` 19 → **20**;
  - re-pin `tests/fixtures/style/compiled-default-artifact.json`.
- **Class names:**
  - stems `hover-fg`, `hover-bg`, `hover-bc`, `hover-opacity`, `opacity`;
  - for example `t-hover-bg-accent`, `t-hover-opacity-70`, `t-opacity-80`, `t-hover-fg-reset`.
- **Hover utility:** `@media (hover: hover) { .X:hover { … } }` plus `.X:focus-visible, .X[data-thallo-hover] { … }`. A hover reset rule is empty.
- **Forced-preview attribute:** `data-thallo-hover` (never `thallo-canvas-hover`). **Bridge message:** `thallo:force-hover {id, target, part}`; `{id: null}` clears.
- **Blocks:**
  - Button `control`: `opacity`, `hover`;
  - Social link `icon` part: `opacity`, `hover`;
  - Social links `icon` part: `opacity`, `hover`;
  - Links `link` part: `hover`;
  - File: new `link` part with `colors`, `radius`, `typography.size`, the four paddings, `opacity`, `hover`.
- **Copy:** `Normal` and `Hover` (the switch); field labels `Opacity`, and `Text colour`, `Background`, `Border colour`, `Opacity` in Hover. Sentence case.
- **Changelog:** a bullet rides in the commit of the change, under `## [Unreleased]`. Upgrade Notes are written in Task 10.
- **Commits:** no `Co-Authored-By` trailer, never push. MAMP PHP first on PATH: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`.
- **Gates:**
  - `vendor/bin/phpcs; echo "phpcs=$?"` must print `phpcs=0`.
  - `COMPOSER_PROCESS_TIMEOUT=0 composer test`, in shards, unprefixed, never concurrently.
  - Admin: `pnpm type-check`, `pnpm lint`, `pnpm fmt:check` (format touched files with `pnpm exec oxfmt <files>`), `pnpm test`.
  - e2e after `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`.
  - `tools/runtime-browser`: `npx playwright test`.

## Review Focus

1. **A block whose `colors` and `hover` sit on different targets**, such as a custom code-declared type, or a future Card with `colors.text` on `title`. The Style tab must not offer a hover colour that would be saved and then refused or silently ignored. Pinned in Task 1 (fixture `different-targets`) and Task 7 (`style-capabilities.spec.ts` runs the same fixture through `StyleTab`).
2. **A style class carrying `hover.colors.surface`, worn by a Heading (which has no `hover`).** The class saves (classes are not target-bound), and the Heading renders without the hover class and without error. Pinned in Task 5 (`testAClassHoverValueOnABlockWithoutHoverEmitsNothing`).
3. **A Button inside a Call to action's slot, with the Hover switch on, during a stage fragment patch of the parent.** The force must survive the parent's swap, which replaces the Button's DOM too. Pinned in Task 9 (e2e `the force survives a parent's fragment patch`).
4. **Tabbing through a Social links row whose row sets a hover colour.** Each link shows the colour on `:focus-visible`, and the theme's focus ring stays. Pinned in Task 6 (`keyboard focus shows the authored hover colour and keeps the focus ring`).
5. **An old document with no hover values, and a page cached before the upgrade.** It renders byte-identically except for the compiled artifact's name (a new hash). Pinned in Task 5 (`testABlockWithoutHoverValuesRendersTheSameClasses`).

## Shared contracts (named once, used by every task)

```php
namespace Thallo\Contracts\Style;

final class StyleSchema
{
    public const VERSION = 15;

    /** Hover path => the resting path it changes (hover state spec §2.1). */
    public const HOVER = [
        'hover.colors.text' => 'colors.text',
        'hover.colors.surface' => 'colors.surface',
        'hover.colors.border' => 'colors.border',
        'hover.opacity' => 'opacity',
    ];

    /** The resting path a hover path changes, or null for any other path. */
    public static function restingPathOf(string $path): ?string;
}

final class StyleCapabilities
{
    /** The paths kept by $keep, in this set's order. */
    public function filter(callable $keep): self; // callable(string $path): bool
}

final class StyleTargets
{
    /** $caps without the hover paths no target owns (spec §2.2). */
    public function effective(StyleCapabilities $caps): StyleCapabilities;
}
```

```php
namespace Thallo\Core\Content\Blocks;

final class BlockTypeStylePaths
{
    /**
     * @param array<string,mixed> $row a block type row (style_capabilities, style_targets decoded)
     * @return array{block: list<string>, parts: array<string, list<string>>}
     */
    public static function for(array $row): array;
}
```

```ts
// admin/src/queries/blockTypes.ts
export interface BlockStylePaths {
  block: string[]
  parts: Record<string, string[]>
}
// BlockType gains: style_paths?: BlockStylePaths | null

// admin/src/style/capabilities.ts
/** Every style path a type offers: the server's `style_paths.block`, or (synthetic types) a local expansion. */
export function effectivePaths(type: BlockType | null | undefined): Set<string>
/** A declaration's paths, with the hover pass (mirror of StyleCapabilities::fromDeclaration). */
export function expandDeclaration(declaration: readonly string[] | null | undefined): Set<string>
/** Hover path → resting path (mirror of StyleSchema::HOVER). */
export const HOVER_OF: Record<string, string>

// admin/src/editor/stage/stageHover.ts
export interface StageHover {
  force(owner: symbol, id: string, target: string | null, part: string | null): void
  clear(owner: symbol): void
}
export const StageHoverKey: InjectionKey<StageHover>
/** The target a type's hover lands on: its map's entry for `hover`, else root. */
export function hoverTarget(type: BlockType | null): string
```

```js
// preview-bridge.js — parent → stage
{ type: 'thallo:force-hover', id: string | null, target: string | null, part: string | null }
```

---

## Task 1: the hover and opacity properties, and the target-aware expansion

**Files:**
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php` (VERSION 15, `HOVER`, `restingPathOf`, five definitions appended after `footer.divider_style`)
- Modify: `packages/thallo-contracts/src/Style/StyleCapabilities.php` (final hover pass, `filter`)
- Modify: `packages/thallo-contracts/src/Style/StyleTargets.php` (two-pass map, wrong-target error, `effective`, `validateAgainst` exemption)
- Create: `packages/thallo-contracts/style-capability-fixtures/v1/README.md`, `expansion.json`, `multi-select.json`
- Create: `packages/thallo-contracts/style-schema/v1.json` (the snapshot)
- Test: `tests/Unit/Contracts/StyleSchemaTest.php` (VERSION 15, path list), `tests/Unit/Contracts/StyleCapabilityFixturesTest.php` (new), `tests/Unit/Contracts/StyleSchemaSnapshotTest.php` (new)

**Interfaces:**
- **Produces:** `StyleSchema::HOVER`, `StyleSchema::restingPathOf()`, `StyleCapabilities::filter()`, `StyleTargets::effective()`. The fixture files (consumed by Tasks 4 and 7) and the schema snapshot (consumed by Task 7).

- [ ] **Step 1: Write the fixtures.**

  `expansion.json`. Each case has a `declaration` (`style_capabilities`, `style_targets`) and either an `expect` (the `style_paths`) or an `error` (a substring of the exception message):
  ```json
  {
    "cases": [
      {
        "name": "text-only Links",
        "declaration": {
          "style_capabilities": ["spacing.padding.top"],
          "style_targets": {
            "targets": {"root": {"kind": "box"}},
            "map": {"spacing.padding.top": "root"},
            "parts": {"link": {"label": "Link", "capabilities": ["hover", "colors.text"]}}
          }
        },
        "expect": {"block": ["spacing.padding.top"], "parts": {"link": ["colors.text", "hover.colors.text"]}}
      },
      {
        "name": "different targets, hover on root",
        "declaration": {
          "style_capabilities": ["colors.text", "colors.surface", "hover"],
          "style_targets": {
            "targets": {"root": {"kind": "box"}, "title": {"kind": "text"}},
            "map": {"colors.text": "title", "colors.surface": "root", "hover": "root"}
          }
        },
        "expect": {"block": ["colors.surface", "colors.text", "hover.colors.surface"], "parts": {}}
      },
      {
        "name": "different targets, hover on title",
        "declaration": {
          "style_capabilities": ["colors.text", "colors.surface", "hover"],
          "style_targets": {
            "targets": {"root": {"kind": "box"}, "title": {"kind": "text"}},
            "map": {"colors.text": "title", "colors.surface": "root", "hover": "title"}
          }
        },
        "expect": {"block": ["colors.surface", "colors.text", "hover.colors.text"], "parts": {}}
      },
      {
        "name": "an individual hover path on the wrong target",
        "declaration": {
          "style_capabilities": ["colors.text", "hover.colors.text"],
          "style_targets": {
            "targets": {"root": {"kind": "box"}, "title": {"kind": "text"}},
            "map": {"colors.text": "title", "hover.colors.text": "root"}
          }
        },
        "error": "hover.colors.text maps to \"root\", but colors.text is on \"title\""
      },
      {
        "name": "order: hover listed first",
        "declaration": {
          "style_capabilities": ["hover", "colors"],
          "style_targets": {"targets": {"root": {"kind": "box"}}, "map": {"hover": "root", "colors": "root"}}
        },
        "expect": {"block": ["colors.surface", "colors.text", "colors.border", "hover.colors.text", "hover.colors.surface", "hover.colors.border"], "parts": {}}
      },
      {
        "name": "order: hover listed last",
        "declaration": {
          "style_capabilities": ["colors", "hover"],
          "style_targets": {"targets": {"root": {"kind": "box"}}, "map": {"colors": "root", "hover": "root"}}
        },
        "expect": {"block": ["colors.surface", "colors.text", "colors.border", "hover.colors.text", "hover.colors.surface", "hover.colors.border"], "parts": {}}
      },
      {
        "name": "sibling parts keep their own",
        "declaration": {
          "style_capabilities": [],
          "style_targets": {
            "targets": {"root": {"kind": "box"}},
            "map": {},
            "parts": {
              "a": {"capabilities": ["hover", "colors.text"]},
              "b": {"capabilities": ["colors.surface"]}
            }
          }
        },
        "expect": {"block": [], "parts": {"a": ["colors.text", "hover.colors.text"], "b": ["colors.surface"]}}
      },
      {
        "name": "no hover",
        "declaration": {
          "style_capabilities": ["colors", "opacity"],
          "style_targets": {"targets": {"root": {"kind": "box"}}, "map": {"colors": "root", "opacity": "root"}}
        },
        "expect": {"block": ["colors.surface", "colors.text", "colors.border", "opacity"], "parts": {}}
      }
    ]
  }
  ```
  `block` lists are in schema table order. The executor fixes the expected orders by the final table (Step 4) and ledgers any reordering as a ruling.

  `multi-select.json`. Sibling blocks' `style_paths` and the rows the Style tab shows for them together (Task 7 reads it):
  ```json
  {
    "cases": [
      {"name": "Button + Button", "slugs": ["button", "button"], "expect_hover": ["hover.colors.text", "hover.colors.surface", "hover.colors.border", "hover.opacity"]},
      {"name": "Button + Links", "slugs": ["button", "links"], "expect_hover": []},
      {"name": "Button + Social link", "slugs": ["button", "social_link"], "expect_hover": []}
    ]
  }
  ```
  `slugs` refer to the shipped starters. Task 4's test writes their real `style_paths` into `packages/thallo-contracts/style-capability-fixtures/v1/starters.json`, which is committed and checked for staleness, so the admin test reads real data without a server.

- [ ] **Step 2: Write the failing tests.**

  `StyleCapabilityFixturesTest`:
  ```php
  final class StyleCapabilityFixturesTest extends TestCase
  {
      private const FIXTURES = __DIR__ . '/../../../packages/thallo-contracts/style-capability-fixtures/v1';

      /** @return iterable<string, array{0: array<string,mixed>}> */
      public static function cases(): iterable
      {
          $doc = json_decode((string) file_get_contents(self::FIXTURES . '/expansion.json'), true, 512, JSON_THROW_ON_ERROR);
          foreach ($doc['cases'] as $case) {
              yield $case['name'] => [$case];
          }
      }

      /** @dataProvider cases @param array<string,mixed> $case */
      public function testFixture(array $case): void
      {
          $decl = $case['declaration'];
          if (isset($case['error'])) {
              $this->expectException(\InvalidArgumentException::class);
              $this->expectExceptionMessage($case['error']);
          }
          $caps = StyleCapabilities::fromDeclaration($decl['style_capabilities']);
          $targets = StyleTargets::fromDeclaration($decl['style_targets']);
          self::assertSame([], $targets->validateAgainst($targets->effective($caps)));
          $parts = [];
          foreach ($targets->parts() as $part) {
              $parts[$part] = $targets->partCapabilities($part)->paths();
          }
          self::assertSame($case['expect'], ['block' => $targets->effective($caps)->paths(), 'parts' => $parts]);
      }

      public function testBothRuntimesReadTheSameFixtureFiles(): void
      {
          $spec = (string) file_get_contents(__DIR__ . '/../../../admin/src/__tests__/style-capabilities.spec.ts');
          self::assertStringContainsString('style-capability-fixtures/v1', $spec);
      }
  }
  ```

  `StyleSchemaTest`:
  - VERSION is 15, in both places;
  - the path list gains `opacity`, `hover.colors.text`, `hover.colors.surface`, `hover.colors.border`, `hover.opacity` after the footer paths;
  - new `testHoverPathsMirrorTheirRestingPaths`: for each `StyleSchema::HOVER` entry, the definition's `kinds`, `tokenDomain` and `choices` equal the resting path's, `responsive` is false, and `group` is `hover`;
  - new `testHoverAloneExpandsToNothing`: `StyleCapabilities::fromDeclaration(['hover'])->paths() === []`.

  `StyleSchemaSnapshotTest`:
  ```php
  public function testTheSnapshotIsTheSchema(): void
  {
      $rows = array_map(static fn (PropertyDefinition $d): array => [
          'path' => $d->path, 'group' => $d->group, 'responsive' => $d->responsive,
          'token_domain' => $d->tokenDomain, 'choices' => $d->choices,
          'kinds' => array_map(static fn (ValueKind $k): string => $k->value, $d->kinds),
      ], array_values(StyleSchema::properties()));
      self::assertSame(
          json_encode(['version' => StyleSchema::VERSION, 'properties' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
          rtrim((string) file_get_contents(__DIR__ . '/../../../packages/thallo-contracts/style-schema/v1.json')),
          'the style schema changed: regenerate packages/thallo-contracts/style-schema/v1.json and update admin/src/style/schema.ts',
      );
  }
  ```

- [ ] **Step 3: Run them and watch them fail.** `vendor/bin/phpunit tests/Unit/Contracts/StyleCapabilityFixturesTest.php tests/Unit/Contracts/StyleSchemaTest.php tests/Unit/Contracts/StyleSchemaSnapshotTest.php`. Expected: FAIL, with unknown capability "hover", VERSION 14 ≠ 15, and a missing snapshot.

- [ ] **Step 4: Implement the schema.** In `StyleSchema::properties()`, after the footer definitions:
  ```php
  // Settings version 15 (hover state spec §2): the element's opacity, and the hover state — the
  // hover version of each colour and of opacity, under `hover.`. One value for every width: hover is
  // a pointer state. A target has a hover path only where it has the resting one (StyleCapabilities,
  // StyleTargets).
  $defs[] = new PropertyDefinition('opacity', 'opacity', $choice, false, null, ['100', '90', '80', '70', '60', '50']);
  foreach (self::HOVER as $hover => $resting) {
      $base = $defs[array_search($resting, array_map(static fn (PropertyDefinition $d): string => $d->path, $defs), true)];
      $defs[] = new PropertyDefinition($hover, 'hover', $base->kinds, false, $base->tokenDomain, $base->choices);
  }
  ```
  Add `HOVER` as in Shared contracts, and:
  ```php
  public static function restingPathOf(string $path): ?string
  {
      return self::HOVER[$path] ?? null;
  }
  ```

- [ ] **Step 5: Implement the hover pass in `StyleCapabilities`.** At the end of `fromDeclaration`, before returning:
  ```php
  // A hover path exists only beside its resting path (hover state spec §2.2), whichever came first.
  foreach (array_keys($paths) as $path) {
      $resting = StyleSchema::restingPathOf($path);
      if ($resting !== null && !isset($paths[$resting])) {
          unset($paths[$path]);
      }
  }
  ```
  and:
  ```php
  /** @param callable(string): bool $keep */
  public function filter(callable $keep): self
  {
      return new self(array_values(array_filter($this->paths, $keep)));
  }
  ```

- [ ] **Step 6: Implement the two-pass map in `StyleTargets::fromDeclaration`.** Split the `map` loop: the first pass skips entries where `$capability === 'hover'` or `StyleSchema::restingPathOf($capability) !== null`. After the first pass, the hover entries are handled:
  ```php
  foreach ($hoverEntries as $capability => $target) {
      if ($capability === 'hover') {
          // The group: the hover paths whose resting path this target owns; the rest are not offered.
          foreach (StyleSchema::HOVER as $hover => $resting) {
              if (($styleMap[$resting] ?? null) === $target) {
                  $styleMap[$hover] = $target;
              }
          }
          continue;
      }
      $resting = (string) StyleSchema::restingPathOf($capability);
      if (($styleMap[$resting] ?? null) !== $target) {
          throw new \InvalidArgumentException(sprintf(
              '%s maps to "%s", but %s is on "%s"',
              $capability, $target, $resting, (string) ($styleMap[$resting] ?? 'no target'),
          ));
      }
      $styleMap[$capability] = $target;
  }
  ```
  The undeclared-target check stays in the first collection loop for both kinds of entry. Add:
  ```php
  public function effective(StyleCapabilities $caps): StyleCapabilities
  {
      return $caps->filter(fn (string $path): bool => StyleSchema::restingPathOf($path) === null || isset($this->styleMap[$path]));
  }
  ```
  In `validateAgainst`, inside the loop, before `$errors[] = "capability {$path} has no target"`:
  ```php
  if (StyleSchema::restingPathOf($path) !== null) {
      continue; // dropped by the target-aware rule (hover state spec §2.2), not missing
  }
  ```

- [ ] **Step 7: Write the snapshot.**
  ```bash
  mkdir -p packages/thallo-contracts/style-schema && php -r '
  require "vendor/autoload.php";
  use Thallo\Contracts\Style\{StyleSchema, PropertyDefinition, ValueKind};
  $rows = array_map(fn (PropertyDefinition $d) => ["path" => $d->path, "group" => $d->group, "responsive" => $d->responsive,
      "token_domain" => $d->tokenDomain, "choices" => $d->choices, "kinds" => array_map(fn (ValueKind $k) => $k->value, $d->kinds)],
      array_values(StyleSchema::properties()));
  echo json_encode(["version" => StyleSchema::VERSION, "properties" => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
  ' > packages/thallo-contracts/style-schema/v1.json
  ```
  Then read the file's tail: the five new rows are last.

- [ ] **Step 8: Run the tests.** The Step 3 command, then `vendor/bin/phpunit tests/Unit/Contracts`. Expected: PASS.

- [ ] **Step 9: Commit** `feat(style): hover and opacity properties, and the target-aware hover expansion`, with a CHANGELOG `[Unreleased]` bullet: settings version 15 adds `opacity` and the hover state.

## Task 2: the registry returns effective capabilities; validation refuses what a target lacks

**Files:**
- Modify: `core/src/Content/Style/EngineBlockStyleRegistry.php`
- Test: `tests/Integration/Content/HoverCapabilityTest.php` (new, under `tests/Integration/Content`, an existing shard entry)

**Interfaces:**
- **Consumes:** `StyleTargets::effective()` (Task 1).
- **Produces:** `BlockStyleRegistry::capabilitiesFor()` returns effective capabilities.

- [ ] **Step 1: Write the failing tests.** `HoverCapabilityTest extends AppTestCase`. Its setUp creates a block type through `BlockTypeRepository` with `style_capabilities: ['colors.text', 'colors.surface', 'hover']` and targets `root` (box) and `title` (text), mapping `colors.text` → `title`, `colors.surface` → `root`, `hover` → `root`. Then `BlockStyleRegistry::reset()`.
  - `testTheRegistryOffersOnlyTheHoverPathsATargetOwns`: `capabilitiesFor('hovercard')` allows `hover.colors.surface` and not `hover.colors.text`.
  - `testSavingAHoverPathTheTargetLacksIsRefused`: an entry body with a block whose `settings.style.hover.colors.text` is a token. Saving through `EntryService`/`FieldValidator` (as `SettingsValidator` tests do) gives a validation error naming `hover.colors.text`.
  - `testAPartHoverPathWithoutItsRestingPathIsRefused`: a type created here with a part `link: ['hover', 'colors.text']`. A block of it with `settings.parts.link.hover.colors.surface` is refused (the part has no surface), and one with `settings.parts.link.hover.colors.text` is accepted.
  - `testAWrongTargetHoverDeclarationIsRefusedOnSave`: `BlockTypeRepository::create/updateStyle` with `'hover.colors.text' => 'root'` while `colors.text` → `title` throws, and the message contains `but colors.text is on "title"`.
  - `testAStyleClassMayCarryHoverValues`: `StyleClassController` validation (`settings->validate(['style' => $style], StyleCapabilities::all())`) accepts `hover.colors.surface`.
  - `testStyleClassUsageCountsAHoverValueOnlyWhereTheTargetHasIt`: a class with `hover.colors.surface` worn by the `hovercard` block and by a `heading`. `StyleClassUsage` reports the class as effective on `hovercard` and as no effect on `heading`, as it does for any undeclared path.
- [ ] **Step 2: Run and watch them fail.** `vendor/bin/phpunit tests/Integration/Content/HoverCapabilityTest.php`. Expected: the first test FAILs (the raw caps allow `hover.colors.text`), and the others fail or pass according to Task 1. Record which.
- [ ] **Step 3: Implement.**
  ```php
  public function capabilitiesFor(string $type): StyleCapabilities
  {
      $declared = $this->row($type)['style_capabilities'] ?? null;
      $caps = StyleCapabilities::fromDeclaration(is_array($declared) ? $declared : null);
      // Effective: a hover path only where its target owns the resting path (hover state spec §2.2).
      return $this->targetsFor($type)?->effective($caps) ?? $caps;
  }
  ```
- [ ] **Step 4: Run.** The Step 2 command, then `vendor/bin/phpunit tests/Integration/Content tests/Integration/Style`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(style): the registry answers effective capabilities, so a hover path a target lacks is refused`.

## Task 3: the compiler — opacity, hover utilities, empty hover resets

**Files:**
- Modify: `packages/thallo-render/src/Style/ClassNames.php` (stems)
- Modify: `packages/thallo-render/src/Style/StyleCompiler.php` (VERSION 20 with a log line; `opacity` in `CHOICE_DECLARATIONS`; hover rules)
- Modify: `tests/fixtures/style/compiled-default-artifact.json` (re-pin)
- Test: `tests/Unit/Render/StyleCompilerTest.php`

**Interfaces:**
- **Consumes:** the Task 1 definitions.
- **Produces:**
  - `ClassNames::for('hover.colors.surface', 'color.accent') === 't-hover-bg-accent'`;
  - `ClassNames::for('opacity', '80') === 't-opacity-80'`;
  - the compiled rules, in the exact shape of Global Constraints.

- [ ] **Step 1: Write the failing tests** in `StyleCompilerTest`:
  ```php
  public function testAHoverUtilityHasAGatedPointerBranchAndAFocusAndForcedBranch(): void
  {
      $css = StyleCompiler::compile($this->vocabulary());
      self::assertStringContainsString(
          "@media (hover: hover) {\n.t-hover-bg-accent:hover { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }",
          $css,
      );
      self::assertStringContainsString(
          '.t-hover-bg-accent:focus-visible, .t-hover-bg-accent[data-thallo-hover] { --t-surface: var(--t-color-accent); background: var(--t-color-accent); }',
          $css,
      );
      self::assertStringContainsString('.t-hover-fg-accent:focus-visible, .t-hover-fg-accent[data-thallo-hover] { color: var(--t-color-accent); }', $css);
      self::assertStringContainsString('.t-hover-bc-accent:focus-visible, .t-hover-bc-accent[data-thallo-hover] { border-color: var(--t-color-accent); }', $css);
      self::assertStringContainsString('.t-hover-opacity-70:focus-visible, .t-hover-opacity-70[data-thallo-hover] { opacity: 0.7; }', $css);
  }

  public function testAHoverResetIsEmpty(): void
  {
      $css = StyleCompiler::compile($this->vocabulary());
      self::assertStringContainsString('.t-hover-bg-reset { }', $css);
      self::assertStringNotContainsString('.t-hover-bg-reset:hover', $css);
  }

  public function testOpacityIsOneValueForEveryWidth(): void
  {
      $css = StyleCompiler::compile($this->vocabulary());
      self::assertStringContainsString('.t-opacity-80 { opacity: 0.8; }', $css);
      self::assertStringContainsString('.t-opacity-reset { opacity: revert-layer; }', $css);
      self::assertStringNotContainsString('md\\:t-opacity-', $css);
  }

  public function testHoverRulesFollowTheBaseUtilities(): void
  {
      $css = StyleCompiler::compile($this->vocabulary());
      self::assertGreaterThan(strpos($css, '.t-bg-accent {'), strpos($css, '.t-hover-bg-accent:hover'));
      self::assertLessThan(strpos($css, '@media (min-width: 768px)'), strpos($css, '.t-hover-bg-accent:hover'));
  }
  ```
  Extend `testClassNamesAreTheOnePlaceASettingBecomesAClass` with the two `ClassNames::for` cases above.
- [ ] **Step 2: Run and watch them fail.** `vendor/bin/phpunit tests/Unit/Render/StyleCompilerTest.php`. Expected: FAIL, with unknown managed property `hover.colors.surface`.
- [ ] **Step 3: Implement.**
  - `ClassNames::STEMS` += `'opacity' => 'opacity'`, `'hover.colors.text' => 'hover-fg'`, `'hover.colors.surface' => 'hover-bg'`, `'hover.colors.border' => 'hover-bc'`, `'hover.opacity' => 'hover-opacity'`.
  - `StyleCompiler`:
    - `CHOICE_DECLARATIONS['opacity'] = ['opacity' => ['100' => '1', '90' => '0.9', '80' => '0.8', '70' => '0.7', '60' => '0.6', '50' => '0.5']]`.
    - In `rules('base')`, skip every path where `StyleSchema::restingPathOf($path) !== null`, and append `self::hoverRules()` after the base loop. That keeps it before `spanRules`' output and inside the base block, so it precedes the media blocks.
    - `hoverRules()`:
      ```php
      /**
       * The hover state (hover state spec §4.1): each value's declarations are its resting path's, on
       * the pointer — only where the primary input can hover, so a tap leaves nothing — and on keyboard
       * focus and the stage's forced preview, everywhere. A reset is empty: no hover value from here.
       */
      private static function hoverRules(): string
      {
          $pointer = '';
          $other = '';
          foreach (StyleSchema::HOVER as $hover => $resting) {
              $def = StyleSchema::property($hover);
              foreach (self::valuesFor($hover, $def->tokenDomain, $def->choices) as $value) {
                  $sel = ClassNames::selector(ClassNames::for($hover, $value));
                  $decl = self::declarations($resting, $value);
                  $pointer .= "{$sel}:hover { {$decl} }\n";
                  $other .= "{$sel}:focus-visible, {$sel}[data-thallo-hover] { {$decl} }\n";
              }
              $other .= ClassNames::selector(ClassNames::reset($hover)) . " { }\n";
          }
          return "@media (hover: hover) {\n{$pointer}}\n{$other}";
      }
      ```
    - VERSION 20, with `// 20: opacity, and the hover state (hover.colors.*, hover.opacity).`
- [ ] **Step 4: Re-pin.** Run the test once and copy the reported sha256 into `tests/fixtures/style/compiled-default-artifact.json` with `version: 20`.
- [ ] **Step 5: Run.** `vendor/bin/phpunit tests/Unit/Render`. Expected: PASS.
- [ ] **Step 6: Commit** `feat(style): opacity and hover utilities — pointer gated on (hover: hover), focus and forced preview ungated`.

## Task 4: `style_paths` on the block-type payload, its OpenAPI schema and the admin types

**Files:**
- Create: `core/src/Content/Blocks/BlockTypeStylePaths.php`
- Modify: `core/src/Content/Http/Controllers/BlockTypeController.php` (`index`, `show`, `store`, `update` attach `style_paths`)
- Modify: `core/src/Content/Http/DTOs/Responses/BlockTypes/BlockTypeItemData.php` (`style_paths`)
- Modify: `docs/openapi.json` (hand-spliced), `admin/src/api/schema.d.ts` (`pnpm gen:api`), `admin/src/queries/blockTypes.ts` (`BlockStylePaths`, `style_paths`)
- Create: `packages/thallo-contracts/style-capability-fixtures/v1/starters.json` (written by the test below)
- Test: `tests/Integration/Http/BlockTypeStylePathsTest.php` (new; `tests/Integration/Http` is an existing shard entry)

**Interfaces:**
- **Consumes:** `BlockStyleRegistry`, `StyleTargets::effective()`.
- **Produces:** `BlockTypeStylePaths::for(array $row)`, plus `style_paths` on every block type in `GET /v1/admin/block-types`, `GET /v1/admin/block-types/{slug}`, and the create and update responses.

- [ ] **Step 1: Write the failing tests.** `BlockTypeStylePathsTest extends AppTestCase` (it syncs the starters' declarations as `SyncsBlockStyleDeclarations` does):
  - `testEveryExpansionFixtureMatchesThePayload`: for each `expansion.json` case without `error`, it creates a type with that declaration and asserts `GET /v1/admin/block-types/{slug}`'s `data.block_type.style_paths` equals `expect`.
  - `testTheListCarriesStylePaths`: every row of `GET /v1/admin/block-types` has a `style_paths` array with `block` and `parts`.
  - `testTheStartersSnapshotIsCurrent`: builds `{slug: style_paths}` for `button`, `links`, `social_link`, `social_links`, `file` from the list. If `THALLO_RECORD_STYLE_PATHS=1`, it writes `starters.json` (JSON_PRETTY_PRINT | UNESCAPED_SLASHES); otherwise it asserts equality with the committed file, saying "re-record with THALLO_RECORD_STYLE_PATHS=1".
- [ ] **Step 2: Run and watch them fail.** `vendor/bin/phpunit tests/Integration/Http/BlockTypeStylePathsTest.php`. Expected: FAIL (no `style_paths`).
- [ ] **Step 3: Implement.**
  ```php
  /**
   * What a block type offers, expanded once (hover state spec §2.2.1): the block's effective style
   * paths — every target's, after the target-aware hover rule — and each part's, in schema order.
   * Derived on read, never stored, so a custom block type has it too.
   */
  final class BlockTypeStylePaths
  {
      /** @param array<string,mixed> $row @return array{block: list<string>, parts: array<string, list<string>>} */
      public static function for(array $row): array
      {
          $declared = is_array($row['style_capabilities'] ?? null) ? array_values($row['style_capabilities']) : null;
          $caps = StyleCapabilities::fromDeclaration($declared);
          $targets = is_array($row['style_targets'] ?? null) ? StyleTargets::fromDeclaration($row['style_targets']) : null;
          $parts = [];
          foreach ($targets?->parts() ?? [] as $part) {
              $parts[$part] = $targets->partCapabilities($part)->paths();
          }
          return ['block' => ($targets?->effective($caps) ?? $caps)->paths(), 'parts' => $parts];
      }
  }
  ```
  In the controller, a private `withStylePaths(array $row): array` returns `$row + ['style_paths' => BlockTypeStylePaths::for($row)]`. Apply it to `$listed` in `index` (`array_map`) and to the row in `show`, `store` and `update`. `BlockTypeItemData` gains:
  ```php
  /** @var array{block: list<string>, parts: array<string, list<string>>}|null What the type offers, expanded (hover state spec §2.2.1). */
  public readonly ?array $style_paths = null,
  ```
- [ ] **Step 4: Run, then record the starters snapshot.** `THALLO_RECORD_STYLE_PATHS=1 vendor/bin/phpunit --filter testTheStartersSnapshotIsCurrent tests/Integration/Http/BlockTypeStylePathsTest.php`, then the whole file without the variable. Expected: PASS. The snapshot changes again in Task 5 (the blocks gain hover), where it is re-recorded.
- [ ] **Step 5: Regenerate the API types.**
  1. `CACHE_DRIVER=array composer docs:openapi`, into a scratch copy: `cp docs/openapi.json "$SCRATCH/openapi.before.json"`, regenerate, `cp docs/openapi.json "$SCRATCH/openapi.full.json"`, then `git checkout docs/openapi.json`.
  2. Confirm with `grep -c style_paths "$SCRATCH/openapi.full.json"` that the field landed (the regeneration exits 0 even when it fails).
  3. Hand-splice only the `BlockTypeItemData` schema change (and any inline copies under the block-type operations) into `docs/openapi.json`, decoding as objects and writing with `json_encode(…, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` with no trailing newline.
  4. `git diff --stat docs/openapi.json` must show only those lines.
  5. `cd admin && pnpm gen:api`. `git diff admin/src/api/schema.d.ts` shows `style_paths` on the block-type operations and nothing else.
- [ ] **Step 6: The admin type.** In `admin/src/queries/blockTypes.ts`, add `BlockStylePaths` as in Shared contracts, and to `BlockType`:
  ```ts
  /** What the type offers, expanded by the server (hover state spec §2.2.1); absent on a synthetic type. */
  style_paths?: BlockStylePaths | null
  ```
  Then `pnpm type-check`. Expected: PASS.
- [ ] **Step 7: Commit** `feat(blocks): block types carry style_paths — what each block and part offers, expanded once`.

## Task 5: the five blocks gain hover and opacity; File gains its Link part

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (Button, Links, Social links, Social link, File)
- Modify: `packages/thallo-render/themes/default/templates/blocks/file.twig` (`{{ style_classes('link') }}`)
- Modify: `packages/thallo-render/fragments-verified.json` (re-record)
- Modify: `packages/thallo-contracts/style-capability-fixtures/v1/starters.json` (re-record)
- Test: `tests/Integration/Render/HoverStyleTest.php` (new, under `tests/Integration/Render`), `tests/Integration/Render/FooterAndSocialStyleTest.php` (extend)

**Interfaces:**
- **Consumes:** Tasks 1–4.
- **Produces:** the declarations of Global Constraints, and the `file` block's `link` part.

- [ ] **Step 0: Capture today's class attributes** for Review Focus 5, before any edit in this task: render the four blocks of `testABlockWithoutHoverValuesRendersTheSameClasses` with a throwaway script in the scratchpad (the `render()` helper's body), print each element's class attribute, and paste the strings into the test.
- [ ] **Step 1: Write the failing tests.** `HoverStyleTest` uses `FooterAndSocialStyleTest`'s `render()` and `classesOf()` helpers, copied with the same bodies:
  - `testAButtonsControlCarriesHoverAndOpacity`: `settings.style` with `opacity` `80`, `hover.colors.surface` `color.ink`, `hover.colors.text` `color.white`, `hover.opacity` `100`. `thallo-block-button__link` carries `t-opacity-80 t-hover-bg-ink t-hover-fg-white t-hover-opacity-100`, and the row wrapper carries none of them.
  - `testALinksBlocksLinksCarryTheHoverTextColour`: `parts.link.hover.colors.text` `color.accent` puts `t-hover-fg-accent` on every `thallo-block-links__link` (two items).
  - `testAFileLinkCarriesItsLinkSection`: `parts.link` with `colors.surface`, `radius`, `hover.colors.surface`. `thallo-block-file__link` carries `t-bg-…`, `t-radius-…` and `t-hover-bg-…`.
  - `testASocialLinkOwnHoverBeatsTheRowsLeafByLeaf`: the row has `parts.icon.hover.colors.text` `color.accent` and `hover.colors.surface` `color.black`. Link A has its own `hover.colors.text` `color.white`. A carries `t-hover-fg-white` and `t-hover-bg-black`, not `t-hover-fg-accent`. Link B carries `t-hover-fg-accent` and `t-hover-bg-black`.
  - `testAResetHoverOnALinkDropsTheRowsValue`: the row's `hover.colors.text` `color.accent`, link A's `hover.colors.text` `{type: reset}`. A carries `t-hover-fg-reset`, not `t-hover-fg-accent`. B carries `t-hover-fg-accent`.
  - `testAResetHoverOverAStyleClassDropsTheClasssValue`: a style class (inserted as in `build-text-style-fixtures`) with `hover.colors.surface` `color.accent`, worn by a Button that sets `hover.colors.surface` reset and `colors.surface` `color.ink`. The control carries `t-bg-ink t-hover-bg-reset` and not `t-hover-bg-accent`.
  - `testAClassHoverValueOnABlockWithoutHoverEmitsNothing` (Review Focus 2): the same class worn by a Heading. The heading renders with no `t-hover-` class and no error.
  - `testABlockWithoutHoverValuesRendersTheSameClasses` (Review Focus 5): a Button (radius and surface set), a Links block (link typography set), a Social links row (icon surface set) and a File (no settings). Each element's class attribute equals the literal string captured in Step 0, and none contains `t-hover-` or `t-opacity-`. The File link is included on purpose: with no part settings, its new `style_classes('link')` must add nothing.
- [ ] **Step 2: Run and watch them fail.** `vendor/bin/phpunit tests/Integration/Render/HoverStyleTest.php`. Expected: FAIL. The hover settings are refused by validation, or render no class.
- [ ] **Step 3: Implement the declarations** in `StarterBlockTypes.php`:
  - **Button:** `style_capabilities` += `'opacity', 'hover'`. The `map` += `'opacity' => 'control', 'hover' => 'control'`.
  - **Links:** the `link` part's capabilities += `'hover'`.
  - **Social links:** the `icon` part's capabilities += `'opacity', 'hover'`.
  - **Social link:** the `icon` part's capabilities += `'opacity', 'hover'`.
  - **File:**
    ```php
    'style_targets' => StyleTargets::root('box', ['spacing', 'visibility', 'layout.item'])
        + ['parts' => ['link' => ['label' => 'Link', 'capabilities' => [
            'colors', 'radius', 'typography.size', 'opacity', 'hover',
            'spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
        ]]]],
    ```
  - `file.twig`: `<a class="thallo-block-file__link{{ style_classes('link') }}" href=…`.
- [ ] **Step 4: Re-record.**
  - `THALLO_RECORD_FRAGMENT_VERIFICATION=1 vendor/bin/phpunit tests/Integration/Templates/FragmentVerificationTest.php`, then without the variable.
  - `THALLO_RECORD_STYLE_PATHS=1 vendor/bin/phpunit --filter testTheStartersSnapshotIsCurrent tests/Integration/Http/BlockTypeStylePathsTest.php`.
  - Read both diffs.
- [ ] **Step 5: Extend `FooterAndSocialStyleTest`.** In `testTheSocialLinksIconSectionStylesEveryLink`, add `'hover' => ['colors' => ['text' => $token('color.accent')]]` to the row's icon settings and `ClassNames::for('hover.colors.text', 'color.accent')` to `$look`.
- [ ] **Step 6: Run.** `vendor/bin/phpunit tests/Integration/Render tests/Integration/Templates tests/Integration/Http/BlockTypeStylePathsTest.php`. Expected: PASS. The template linter test passes unchanged (`style_classes('link')` is emitted).
- [ ] **Step 7: Commit** `feat(blocks): hover and opacity on Button, Links, Social link(s), and File's new Link section`, with a CHANGELOG bullet naming the blocks.

## Task 6: the default theme's hover rules — three branches — and the browser proofs

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (lines 643, 666–668, 679, 969–970, 1081–1082, 1348–1361)
- Create: `scripts/build-hover-fixtures` (from `scripts/build-text-style-fixtures`: same bootstrap, sync, transaction and pages; its own body and output folder `tools/runtime-browser/fixtures/hover/`)
- Create: `tools/runtime-browser/tests/hover.spec.js`
- Modify: `.github/workflows/runtime-browser.yml` (path filter `scripts/build-hover-fixtures`; a step "Build the hover fixtures" after the text style one)

**Interfaces:**
- **Consumes:** Tasks 3 and 5.
- **Produces:** the theme rules in the three-branch form of spec §4.3.

- [ ] **Step 1: Write the fixture builder.** The body, in document order, ids in comments:
  1. Buttons, one per variant (`solid`, `outline`, `soft`, `subtle`, `ghost`, `link`), no settings: `btnplain*`.
  2. A ghost Button with `hover.colors.text` `color.accent` only: `btnhovtext1`.
  3. A solid Button with `colors.surface` `color.ink`, `hover.colors.surface` reset, wearing a class `hoverclass01` whose style is `{hover: {colors: {surface: color.accent}}}`: `btnreset001`.
  4. A Links block, no settings, with two items; a Links block with `parts.link.hover.colors.text` `color.accent`.
  5. A File block, no settings, with a public blob (seeded as `build-text-style-fixtures` seeds images); a File block with `parts.link.hover.colors.surface` `color.accent`.
  6. A Social links row with two links, no settings.
  7. A Social links row with `parts.icon.colors.text` `color.muted` and `parts.icon.hover.colors.text` `color.accent`, whose second link has `parts.icon.hover.colors.text` reset.

  It writes `public.html` and `stage.html` as the text-style builder does.
- [ ] **Step 2: Build it.** `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-hover-fixtures`. Expected: "Wrote public.html, stage.html to tools/runtime-browser/fixtures/hover."
- [ ] **Step 3: Write the failing browser tests** in `hover.spec.js`. Every test runs with `test.use({ reducedMotion: 'reduce' })`, so the theme's transitions are off.
  ```js
  const PROPS = ['color', 'backgroundColor', 'borderTopColor', 'opacity', 'transform', 'textDecorationThickness']
  const look = (loc) => loc.evaluate((el, props) => {
    const cs = getComputedStyle(el); return Object.fromEntries(props.map((p) => [p, cs[p]]))
  }, PROPS)
  async function pointerAndForced(page, loc) {
    await page.mouse.move(0, 0)
    const rest = await look(loc)
    await loc.hover()
    const pointer = await look(loc)
    await page.mouse.move(0, 0)
    await loc.evaluate((el) => el.setAttribute('data-thallo-hover', ''))
    const forced = await look(loc)
    await loc.evaluate((el) => el.removeAttribute('data-thallo-hover'))
    return { rest, pointer, forced }
  }
  ```
  - `pointer hover and forced preview match with no declarations`: for each of the six plain Buttons, the plain Links' first link, the plain File link, and the plain row's first Social link (for that one, the property read is the link's `color`, which inherits from the wrapper the `:has` rule colours), `pointer` deep-equals `forced`. For the ghost Button, `pointer.backgroundColor !== rest.backgroundColor` too, proving the theme tint is in both. For the solid Button, `pointer.transform !== rest.transform` (the lift).
  - `pointer hover and forced preview match with one authored property`: on `btnhovtext1`, `pointer` deep-equals `forced`. `pointer.color` is the accent (read `--t-color-accent` from `:root` and compare it computed through a probe element). `pointer.backgroundColor` equals the plain ghost Button's hovered background (the theme tint, still the theme's).
  - `an authored hover colour beats the resting utility and the theme`: the hovered Links link with the part setting shows the accent, not the theme's `--ink`. The hovered File link with the part setting shows the accent background, not `--surface`.
  - `keyboard focus shows the authored hover colour and keeps the focus ring` (Review Focus 4): pressing Tab onto the second row's first Social link gives the accent colour, and its `outlineStyle` is not `none`. For the plain File link, focus shows `--surface` (the theme's existing focus branch).
  - `a reset keeps the resting colour`: hovered `btnreset001` keeps `--t-color-ink`'s background, from the resting utility, not the class's accent. The second row's second link, hovered, keeps `color.muted`.
  - `a tap leaves nothing on a touch-primary device`: in `test.describe` with `test.use({ ...devices['Pixel 7'] })` (`isMobile`, `hasTouch`; first `expect(await page.evaluate(() => matchMedia('(hover: none)').matches)).toBe(true)`), tapping each plain element and the authored ones leaves `look` equal to `rest`. Navigation is prevented by `page.addInitScript(() => document.addEventListener('click', (e) => e.preventDefault(), true))`, so the tap stays on the page.

  Run the whole file against `public.html`, and the two "match" tests against `stage.html` too (as `text-style.spec.js` iterates `PAGES`).
- [ ] **Step 4: Run and watch them fail.** `cd tools/runtime-browser && npx playwright test tests/hover.spec.js`. Expected:
  - "no declarations" FAILs for the Buttons (forced shows no tint and no lift);
  - "touch" FAILs for the theme's rules (the ghost tint sticks after the tap);
  - the authored-value tests pass (Task 3).
- [ ] **Step 5: Rewrite the theme rules.** For each rule in spec §4.3's table, this shape (the File one shown; the rest follow it exactly):
  ```css
  @media (hover: hover) {
    .thallo-block-file__link:hover { background: var(--surface); }
  }
  .thallo-block-file__link:focus-visible,
  .thallo-block-file__link[data-thallo-hover] { background: var(--surface); }
  ```
  - **Button lift:**
    ```css
    @media (hover: hover) {
      .thallo-block-button__link:hover { transform: translateY(-1px); }
    }
    .thallo-block-button__link[data-thallo-hover] { transform: translateY(-1px); }
    ```
    No `:focus-visible` added.
  - **Ghost, soft and subtle tint:** the same pair, with `--ghost`, `--soft` and `--subtle` each listed in both branches.
  - **The `--link` variant:** the same pair, kept after the lift rule (it overrides the lift).
  - **Social link:**
    ```css
    @media (hover: hover) {
      .thallo-block-social_link:has(> .thallo-block-social_link__link:hover) { color: var(--ink); }
    }
    .thallo-block-social_link:has(> .thallo-block-social_link__link[data-thallo-hover]) { color: var(--ink); }
    ```
    Plus `transition: color .15s ease;` on `.thallo-block-social_link__link`, and on `.thallo-block-social_link`.
  - **Links:** the existing `:hover, :focus-visible` pair becomes the File shape. Add `transition: color .15s ease;` to `.thallo-block-links__link` if absent; its reduced-motion block already exists at 1361.
  - Add the Social link to a `@media (prefers-reduced-motion: reduce)` block beside File's.
- [ ] **Step 6: Run.** `npx playwright test tests/hover.spec.js tests/text-style.spec.js`. Expected: PASS. The text-style "hover thickness" test still passes, because its pointer `hover()` runs on a desktop context where `(hover: hover)` matches.
- [ ] **Step 7: The CI step.** In `runtime-browser.yml`, add `scripts/build-hover-fixtures` to `on.push.paths`, `on.pull_request.paths` if present, and:
  ```yaml
      # The hover state as a browser computes it (hover.spec.js): pointer, forced preview, focus and
      # touch, on one page published and on the canvas stage, then rolled back.
      - name: Build the hover fixtures
        working-directory: .
        run: php scripts/build-hover-fixtures
  ```
- [ ] **Step 8: Commit** `feat(theme): hover rules in three branches — pointer gated for touch, focus kept, forced preview — with browser proofs`. Add a CHANGELOG bullet, under a "Changed" heading if the section has one: on a device whose primary input cannot hover, a tap no longer leaves the theme's hover look on buttons, links, file links and social links.

## Task 7: the admin reads `style_paths` everywhere it expands capabilities

**Files:**
- Create: `admin/src/style/capabilities.ts`
- Modify: `admin/src/style/schema.ts` (the five rows after the footer rows, in PHP order)
- Modify: `admin/src/editor/inspector/StyleTab.vue` (`pathsOf` → `effectivePaths`; drop the local loop)
- Modify: `admin/src/editor/inspector/LayoutTab.vue` (`pathsOf` → `effectivePaths`)
- Modify: `admin/src/editor/inspector/BlockInspector.vue` (`declaredPaths` → `effectivePaths`; the part's synthetic type carries `style_paths: { block: props.blockType?.style_paths?.parts?.[name] ?? [...expandDeclaration(spec.capabilities)], parts: {} }`)
- Modify: `admin/src/editor/stage/useStageEditor.ts` (both `capabilityPaths(type?.style_capabilities)` → `effectivePaths(type)`)
- Modify: `admin/src/style/detach.ts` (`capabilityPaths` delegates to `expandDeclaration` and is kept for its fixture test)
- Test: `admin/src/__tests__/style-capabilities.spec.ts` (new), `admin/src/__tests__/style-schema-parity.spec.ts` (new)

**Interfaces:**
- **Consumes:** `expansion.json`, `multi-select.json` and `starters.json` (Tasks 1, 4, 5); `BlockType.style_paths` (Task 4).
- **Produces:** `effectivePaths`, `expandDeclaration`, `HOVER_OF`, as in Shared contracts.

- [ ] **Step 1: Write the failing tests.**

  `style-schema-parity.spec.ts`:
  ```ts
  const snap = JSON.parse(readFileSync(join(__dirname, '../../../packages/thallo-contracts/style-schema/v1.json'), 'utf8'))
  it('the TS mirror is the PHP schema', () => {
    const ts = styleProperties().map((p) => ({ path: p.path, group: p.group, responsive: p.responsive,
      token_domain: p.tokenDomain, choices: p.choices, kinds: p.kinds }))
    expect(ts).toEqual(snap.properties)
  })
  ```

  `style-capabilities.spec.ts`:
  - For each `expansion.json` case with `expect`, mount `StyleTab` (as `style-tab-backdrop.spec.ts` mounts it) with a type `{ …, style_capabilities: decl.style_capabilities, style_targets: decl.style_targets, style_paths: expect }` and the schema built from the snapshot. Assert `effectivePaths(type)` equals `new Set(expect.block)`, and that the rendered row paths (`data-test` of each field) are exactly the non-hover paths of `expect.block` that `pathsForTab` assigns to Style. Task 8 adds to this test that switching to Hover renders exactly the `hover.*` paths of `expect.block`.
  - For each part in `expect.parts`, `BlockInspector`'s computed part type for `name` gives `effectivePaths` equal to `expect.parts[name]`.
  - For each `multi-select.json` case, the intersection `StyleTab` computes (`allowed`, exposed for the test through the rendered rows plus `effectivePaths` of each type in `starters.json`) contains exactly `expect_hover` among hover paths. Task 8 adds the rendered Hover rows to this test.
  - A synthetic style-class type gives `effectivePaths` containing every hover path. A synthetic region type with `RegionStyle` caps gives none.
  - `expandDeclaration(['hover'])` is empty; `expandDeclaration(['hover', 'colors.text'])` is `{'colors.text', 'hover.colors.text'}`.
  - The file reads the folder `style-capability-fixtures/v1` (string the PHP test checks).
- [ ] **Step 2: Run and watch them fail.** `cd admin && pnpm vitest run src/__tests__/style-capabilities.spec.ts src/__tests__/style-schema-parity.spec.ts`. Expected: FAIL, with a missing module and missing rows.
- [ ] **Step 3: Implement.**
  `capabilities.ts`:
  ```ts
  import type { BlockType } from '@/queries/blockTypes'
  import { propertyDefinition, styleProperties } from './schema'

  // What a block type offers (hover state spec §2.2.1). Real block types and their parts carry the
  // server's expansion, `style_paths`; only synthetic types (a style class, a region) expand here,
  // with the same rule as StyleCapabilities: a hover path only beside its resting path.
  export const HOVER_OF: Record<string, string> = {
    'hover.colors.text': 'colors.text',
    'hover.colors.surface': 'colors.surface',
    'hover.colors.border': 'colors.border',
    'hover.opacity': 'opacity',
  }

  export function expandDeclaration(declaration: readonly string[] | null | undefined): Set<string> {
    const out = new Set<string>()
    for (const entry of declaration ?? []) {
      if (propertyDefinition(entry) !== null) out.add(entry)
      else for (const row of styleProperties()) if (row.group === entry) out.add(row.path)
    }
    for (const path of [...out]) {
      const resting = HOVER_OF[path]
      if (resting !== undefined && !out.has(resting)) out.delete(path)
    }
    return out
  }

  export function effectivePaths(type: BlockType | null | undefined): Set<string> {
    const paths = type?.style_paths?.block
    return Array.isArray(paths) ? new Set(paths) : expandDeclaration(type?.style_capabilities)
  }
  ```
  - Replace the three local loops and both `capabilityPaths` call sites.
  - `StyleTab.pathsOf(type)` becomes `effectivePaths(type)`. `allowed` keeps its intersection over `types.map(effectivePaths)`.
  - `schema.ts` rows:
    ```ts
    // Settings version 15 (hover state spec §2): the element's opacity, and the hover state — the hover
    // version of each colour and of opacity. Not responsive.
    choice('opacity', 'opacity', false, ['100', '90', '80', '70', '60', '50']),
    token('hover.colors.text', 'hover', false, 'color'),
    token('hover.colors.surface', 'hover', false, 'color'),
    token('hover.colors.border', 'hover', false, 'color'),
    choice('hover.opacity', 'hover', false, ['100', '90', '80', '70', '60', '50']),
    ```
- [ ] **Step 4: Run.** The Step 2 command, then `pnpm test`, `pnpm type-check`, and `pnpm exec oxfmt <touched files>`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(admin): every capability consumer reads the server's style_paths; the schema mirror has a parity test`.

## Task 8: the Normal / Hover switch

**Files:**
- Modify: `admin/src/editor/inspector/StyleTab.vue`
- Modify: `admin/src/editor/inspector/choiceLabels.ts` (if opacity choices need `%` labels: `'100' → '100%'` … for `opacity` and `hover.opacity`)
- Test: `admin/src/__tests__/style-tab-hover.spec.ts` (new), plus the hover assertions left from Task 7 in `style-capabilities.spec.ts`

**Interfaces:**
- **Consumes:** `effectivePaths`, `HOVER_OF` (Task 7).
- **Produces:**
  - `StyleTab` state `hoverState: Ref<'normal' | 'hover'>`;
  - emits `set` with `hover.*` paths and a `null` breakpoint;
  - `data-test` hooks `style-state-normal`, `style-state-hover` (per group key, for example `style-state-hover-colors`), and `style-state-dot-<group>`.

- [ ] **Step 1: Write the failing tests** in `style-tab-hover.spec.ts`. They mount `StyleTab` for a Button-like type with `style_paths.block` = Button's from `starters.json`:
  - `shows the switch on Colours and Effects only`: `style-state-hover-colors` and `style-state-hover-effects` exist, and there is none on Typography or Spacing.
  - `Hover swaps the hoverable rows and hides the rest`: clicking Hover on Colours shows the `hover.colors.text`, `hover.colors.surface` and `hover.colors.border` fields and no `colors.*` fields. Effects then shows only `hover.opacity` (no radius, shadow, border width).
  - `one state for the tab`: after Hover on Colours, Effects is in Hover too.
  - `writes hover paths bare`: picking a colour in `hover.colors.surface` emits `set` with `['hover.colors.surface', null, {type: 'token', value: …}]`.
  - `a dot marks a declared hover value`: with `style.hover.colors.text` set, `style-state-dot-colors` is visible while in Normal.
  - `returns to Normal on a new selection`: changing the `block` prop's `id` resets to Normal.
  - `no breakpoint chips in Hover`: the `group-breakpoint-*` buttons are absent while Hover is on.
  - `the hover group never has a section of its own`: there is no `style-group-hover`.
  - `a part tab and a class editor show it`: `context="part"` with the Social link icon part's paths, and `context="class"` with the synthetic class type, both show the switch.
  - `a region editor never shows it`.
- [ ] **Step 2: Run and watch them fail.** `pnpm vitest run src/__tests__/style-tab-hover.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement** in `StyleTab.vue`:
  - `GROUPS`: Effects' match gains `r.group === 'opacity'`. No entry matches `hover`.
  - `LABELS` += `opacity: 'Opacity'`, `'hover.colors.text': 'Text colour'`, `'hover.colors.surface': 'Background'`, `'hover.colors.border': 'Border colour'`, `'hover.opacity': 'Opacity'`.
  - State:
    ```ts
    /** Normal or Hover (hover state spec §6.2): one per Style tab, back to Normal on a new selection. */
    const hoverState = ref<'normal' | 'hover'>('normal')
    watch(() => props.block.id, () => { hoverState.value = 'normal' })
    const RESTING_OF_HOVER = HOVER_OF
    const HOVER_FOR = Object.fromEntries(Object.entries(HOVER_OF).map(([h, r]) => [r, h]))
    ```
  - In `groups`, compute for each group:
    - `hoverRows`: the schema rows whose path is `HOVER_FOR[row.path]` for a resting row in the group, and which `allowed` contains;
    - `hasHover = hoverRows.length > 0`;
    - in Hover, when `hasHover`, `rows = hoverRows`;
    - `responsive` is computed from the rows shown.

    The `allowed` filter for normal rows excludes `hover.*` paths, since no group matches them.
  - Header: when `group.hasHover && !isFolded(group.key)`, render a two-button segmented control before the breakpoint chips:
    ```vue
    <div class="flex gap-0.5" role="group" aria-label="State" :data-test="`style-state-${group.key}`">
      <button v-for="s in (['normal', 'hover'] as const)" :key="s" type="button"
        class="relative rounded px-1.5 py-0.5 text-[10px] normal-case tracking-normal"
        :class="s === hoverState ? 'bg-primary text-inverted' : 'text-muted hover:text-default'"
        :aria-pressed="s === hoverState ? 'true' : 'false'"
        :data-test="`style-state-${s}-${group.key}`"
        @click="hoverState = s">
        {{ s === 'normal' ? 'Normal' : 'Hover' }}
        <span v-if="s === 'hover' && hoverDeclared(group)" class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-warning"
          aria-hidden="true" :data-test="`style-state-dot-${group.key}`" />
      </button>
    </div>
    ```
    `hoverDeclared(group)` is true when any of `group.hoverRows` is `readPath(style.value, settingSegments(row.path, null).slice(1)).present`.
  - `setCount` counts the rows shown.
- [ ] **Step 4: Run.** The Step 2 command, `style-capabilities.spec.ts` (now with its Hover assertions), `pnpm test`, `pnpm type-check`, and `pnpm exec oxfmt` on the touched files. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): the Style tab's Normal / Hover switch`.

## Task 9: the stage's forced hover preview

**Files:**
- Create: `admin/src/editor/stage/stageHover.ts`
- Modify: `admin/src/composables/useCanvasBridge.ts` (`forceHover(id, target, part)`)
- Modify: `admin/src/editor/stage/useStageEditor.ts` (provide `StageHoverKey`; re-send on `onStageState`; clear on selection change)
- Modify: `admin/src/editor/inspector/StyleTab.vue` (inject `StageHoverKey`; force while Hover and a hoverable group is unfolded; clear on Normal, fold and unmount)
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js` (`onForceHover`, `applyForcedHover`, the dispatcher entry, calls after `markEmptySlots()` at the two in-place swaps)
- Modify: `scripts/build-builder-proof-fixtures` (three blocks after `prose0000001`)
- Test: `admin/src/__tests__/stage-hover.spec.ts` (new), `admin/e2e/tests/hover-preview.spec.ts` (new)

**Interfaces:**
- **Consumes:** `hoverState` (Task 8); the theme's and utilities' `[data-thallo-hover]` (Tasks 3, 6).
- **Produces:** `StageHover`, `StageHoverKey`, `hoverTarget`, and the bridge message of Shared contracts.

- [ ] **Step 1: Check the e2e premise.** `grep -n "nth(\|prose0000001" admin/e2e/tests/inspector-content.spec.ts`. The proof must find its blocks by id. If it indexes, ledger a ruling and insert the new blocks before `prose0000001` instead, re-checking the specs that count root blocks (`grep -rn "toHaveCount" admin/e2e/tests | grep -i block`).
- [ ] **Step 2: Add the fixture blocks** after `prose0000001`:
  ```php
  // The hover preview (hover-preview.spec.ts): a Links block with three links, a Container holding
  // another Links block (whose links a force on the first must not reach), and a Social links row.
  ['id' => 'hovlinks0001', 'type' => 'links', 'data' => ['title' => 'More', 'items' => [
      ['label' => 'One', 'url' => '/one'], ['label' => 'Two', 'url' => '/two'], ['label' => 'Three', 'url' => '/three'],
  ]], 'settings' => []],
  ['id' => 'hovnest00001', 'type' => 'container', 'data' => ['element' => 'div', 'content' => [
      ['id' => 'hovlinks0002', 'type' => 'links', 'data' => ['items' => [['label' => 'Inner', 'url' => '/inner']]], 'settings' => []],
  ]], 'settings' => []],
  ['id' => 'hovsocial001', 'type' => 'social_links', 'data' => ['items' => [
      ['id' => 'hovsocialig1', 'type' => 'social_link', 'data' => ['icon' => 'brand:instagram', 'url' => 'https://example.com/ig']],
      ['id' => 'hovsocialx01', 'type' => 'social_link', 'data' => ['icon' => 'brand:x', 'url' => 'https://example.com/x']],
  ]], 'settings' => []],
  ```
  Rebuild: `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`.
- [ ] **Step 3: Write the failing tests.**

  `stage-hover.spec.ts` (vitest), with a fake bridge recording posts:
  - `force then clear by the owner only`: `force(a, 'b1', 'control', null)` posts `{id: 'b1', target: 'control', part: null}`. Then `clear(b)` posts nothing, and `clear(a)` posts `{id: null}`.
  - `a newer force replaces an older one`: `force(a, …)` then `force(b, …)`. `clear(a)` posts nothing.
  - `re-sent on stage-state`: after `force(a, 'b1', null, 'icon')`, firing the bridge's `onStageState` callback posts the same force again. After `clear(a)` it posts nothing on `stage-state`.
  - `cleared on a selection change`: `useStageEditor`'s `selected` change clears the active force.
  - StyleTab:
    - switching to Hover calls `force(owner, block.id, hoverTarget(type), null)` in block context, and `(owner, id, null, part)` in part context;
    - Normal calls `clear(owner)`;
    - folding both hoverable groups calls `clear`, and unfolding one forces again;
    - unmounting calls `clear`;
    - with no `StageHoverKey` provided (the class editor), nothing throws.

  `admin/e2e/tests/hover-preview.spec.ts`. Uses `openDesignPage`, as `typeface.spec.ts` does, and a `stage()` frame locator:
  - `forces the selected Button's look`: select `ctabutn0001`, open Style, click `style-state-hover-colors`. The stage's `.thallo-block-button__link` inside `[data-thallo-block="ctabutn0001"]` has `data-thallo-hover`. Click Normal; it is gone.
  - `reaches every link of a Links block and none of a nested one`: select `hovlinks0001`, and in its Link part section click Hover. All three `[data-thallo-block="hovlinks0001"] .thallo-block-links__link` carry the attribute, and `[data-thallo-block="hovlinks0002"] .thallo-block-links__link` does not.
  - `reaches every link of a Social links row`: select `hovsocial001`, Icon section, Hover. Both links carry it.
  - `the force survives a parent's fragment patch` (Review Focus 3): with Hover on for `ctabutn0001`, edit the CTA's title in its inspector and wait for the stage's `applies()` count to grow. The Button still carries the attribute.
  - `the force survives a full stage reload`: with Hover on for `hovlinks0001`, trigger the appearance reload path, as `appearance-page.spec.ts` triggers one (or `stage.evaluate(() => location.reload())`), and wait for the bridge's `stage-state`. The three links carry the attribute again.
  - `clears when the panel closes`: with Hover on, click the Layout tab, and no element in the stage carries `data-thallo-hover`. Back on Style, collapse the Colours group, and still none.
  - `clears on a new selection`: Hover on for `ctabutn0001`, then select `head0000000a`. No attribute anywhere.
- [ ] **Step 4: Run and watch them fail.** `pnpm vitest run src/__tests__/stage-hover.spec.ts`. Then rebuild the fixtures and `cd e2e && pnpm exec playwright test tests/hover-preview.spec.ts`. Expected: FAIL.
- [ ] **Step 5: Implement the stage side.** In `preview-bridge.js`, beside the typography lookup:
  ```js
  // ── Forced hover (hover state spec §6.3) ────────────────────────────────────
  // While the inspector's Hover is on, every element the block owns for the target or part shows
  // its hover look: the theme's hover rules and the utilities match [data-thallo-hover]. Every match,
  // not the first — a Links block's links are many — and only the block's own: an element whose
  // nearest block is a nested block belongs to that block. A part drawn by children (the Social
  // links' Icon) is the marker of each direct child block. Remembered, so an in-place swap
  // re-applies it; a reload forgets it and the parent sends it again.
  var forced = null
  function forcedElements(f) {
    var w = findBlock(f.id)
    var name = f.part || f.target
    if (!w || !name || !/^[a-z][a-z0-9_-]*$/.test(name)) return []
    var els = w.querySelectorAll('.thallo-stage-target--' + name + ', .thallo-stage-part--' + name)
    var out = []
    for (var i = 0; i < els.length; i++) {
      var owner = wrapperFor(els[i])
      if (owner === w || (f.part && owner && owner !== w && wrapperFor(owner.parentElement) === w)) out.push(els[i])
    }
    return out
  }
  function applyForcedHover() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-thallo-hover]'), function (el) {
      el.removeAttribute('data-thallo-hover')
    })
    if (forced) forcedElements(forced).forEach(function (el) { el.setAttribute('data-thallo-hover', '') })
  }
  function onForceHover(data) {
    forced = typeof data.id === 'string' && data.id !== ''
      ? { id: data.id, target: typeof data.target === 'string' ? data.target : null, part: typeof data.part === 'string' ? data.part : null }
      : null
    applyForcedHover()
  }
  ```
  - Dispatcher: `if (data.type === 'thallo:force-hover') onForceHover(data)`.
  - Add `applyForcedHover()` right after `markEmptySlots()` at lines 1278 and 1354.
  - The child-part rule applies only when `f.part` is set and the block's part is drawn by children. An own part's elements already have the block as owner, so the second disjunct never adds a nested block's own part. Check by test: the nested Links block in the e2e is a grandchild through the Container, so `wrapperFor(owner.parentElement)` is the Container, not the Links block, and is excluded.
- [ ] **Step 6: Implement the admin side.**
  - `useCanvasBridge.ts`:
    ```ts
    forceHover(id: string | null, target: string | null, part: string | null): void {
      post({ type: 'thallo:force-hover', id, target, part })
    },
    ```
  - `stageHover.ts`:
    ```ts
    // The stage's forced hover (hover state spec §6.3): the Style tab asks while its Hover is on; only
    // the asker clears, since a block's tab and its parts' tabs are separate. The stage editor holds
    // the force and sends it again whenever the stage reports ready — a reload forgets it.
    import type { InjectionKey } from 'vue'
    import type { BlockType } from '@/queries/blockTypes'

    export interface StageHover {
      force(owner: symbol, id: string, target: string | null, part: string | null): void
      clear(owner: symbol): void
    }
    export const StageHoverKey: InjectionKey<StageHover> = Symbol('thallo-stage-hover')

    export function hoverTarget(type: BlockType | null): string {
      const map = (type?.style_targets as { map?: Record<string, unknown> } | null | undefined)?.map
      const target = map?.hover ?? map?.['hover.colors.surface'] ?? map?.['hover.colors.text']
      return typeof target === 'string' ? target : 'root'
    }

    export function createStageHover(send: (id: string | null, target: string | null, part: string | null) => void) {
      let active: { owner: symbol; id: string; target: string | null; part: string | null } | null = null
      return {
        force(owner: symbol, id: string, target: string | null, part: string | null) {
          active = { owner, id, target, part }
          send(id, target, part)
        },
        clear(owner: symbol) {
          if (active?.owner !== owner) return
          active = null
          send(null, null, null)
        },
        clearAny() {
          if (active === null) return
          active = null
          send(null, null, null)
        },
        resend() {
          if (active) send(active.id, active.target, active.part)
        },
      }
    }
    ```
  - `useStageEditor.ts`:
    - `const stageHover = createStageHover((id, t, p) => bridge.forceHover(id, t, p))`;
    - `provide(StageHoverKey, stageHover)`;
    - in the existing `bridge.onStageState` callback, `stageHover.resend()`;
    - `watch(selected, () => stageHover.clearAny())`;
    - on scope dispose, `stageHover.clearAny()`.
  - `StyleTab.vue`:
    ```ts
    const stageHover = inject(StageHoverKey, null)
    const owner = Symbol('style-tab')
    const hoverShown = computed(() =>
      hoverState.value === 'hover' && groups.value.some((g) => g.hasHover && !isFolded(g.key)))
    watch(
      () => [hoverShown.value, props.block.id] as const,
      ([shown, id]) => {
        if (stageHover === null || multi.value) return
        if (!shown) return stageHover.clear(owner)
        const context = props.context ?? 'block'
        if (context === 'part') stageHover.force(owner, id, null, props.part ?? null)
        else if (context === 'block') stageHover.force(owner, id, hoverTarget(props.blockType), null)
      },
      { immediate: true },
    )
    onBeforeUnmount(() => stageHover?.clear(owner))
    ```
- [ ] **Step 7: Run.** `pnpm vitest run src/__tests__/stage-hover.spec.ts src/__tests__/style-tab-hover.spec.ts`. Then `cd e2e && pnpm exec playwright test tests/hover-preview.spec.ts tests/typeface.spec.ts tests/motion-play.spec.ts tests/inspector-content.spec.ts`. Then `pnpm type-check` and `pnpm exec oxfmt` on the touched files. Expected: PASS.
- [ ] **Step 8: Commit** `feat(builder): the stage previews the hover look while Hover is on — every owned element, kept across patches and reloads`.

## Task 10: docs, upgrade notes, and the release gates

**Files:**
- Modify: `docs/reference/05-style-settings.md`. A "Hover" section:
  - the four paths and `opacity`;
  - the opt-in rule, per target;
  - what beats what (spec §3.3–3.4), including the full-strength hover background over a surface opacity;
  - pointer, focus and touch (spec §4.1, including the hybrid-device note);
  - the empty reset;
  - the three-branch pattern and `[data-thallo-hover]` for themes (spec §4.3).
- Modify: `docs/reference/04-block-library.md`: the Button, Links, Social links, Social link and File rows' style columns.
- Modify: `CHANGELOG.md` `[Unreleased]`. The bullets from Tasks 1, 5 and 6 are already there; add `### Upgrade Notes`:
  - run `php glueful thallo:provision` so existing workspaces' Button, Links, Social links, Social link and File get their Hover and Opacity settings and File its Link section;
  - a theme overriding `blocks/file.twig` adds `{{ style_classes('link') }}` to `thallo-block-file__link`;
  - a custom theme's hover rules keep working on a pointer; to be shown by the stage's Hover preview and not stick after a tap on phones, write each in the three branches (link to the reference section);
  - on phones, a tap no longer leaves the default theme's hover look on these elements.
- Modify: `docs/reference/08-changelog.md` with `php scripts/sync-docs-changelog`.
- Test: `vendor/bin/phpunit tests/Unit/Docs`

- [ ] **Step 1: Write the docs** in the reference's voice: second person, short sections, tables for the paths.
- [ ] **Step 2: Sync and test.** `php scripts/sync-docs-changelog && vendor/bin/phpunit tests/Unit/Docs`. Expected: PASS.
- [ ] **Step 3: Shard check.** Every new integration test lives under an existing top-level entry (`tests/Integration/Content`, `Http`, `Render`). Run the shard coverage guard from `ci.yml` locally. Expected: no output.
- [ ] **Step 4: The full gates, in order, never concurrently:**
  1. `vendor/bin/phpcs; echo "phpcs=$?"` (must print `phpcs=0`);
  2. `composer boundaries`;
  3. `COMPOSER_PROCESS_TIMEOUT=0 composer test`, in shards, unprefixed;
  4. `cd admin && pnpm type-check && pnpm lint && pnpm fmt:check && pnpm test`;
  5. rebuild the e2e fixtures, then `pnpm --dir e2e test`;
  6. build the text-style and hover fixtures, then `cd tools/runtime-browser && npx playwright test`.

  Expected: all green.
- [ ] **Step 5: Commit** `docs(style): the hover state, and its upgrade notes`.

---

## Self-review (run while writing; kept for the reviewer)

Spec coverage:

| Spec section | Task(s) |
|---|---|
| §1 success criteria | 5 (row/link cascade), 6 (focus, touch, pointer = forced), 9 (stage), 5 Review Focus 5 (unchanged render) |
| §2.1 hover paths, storage | 1 |
| §2.2 per-target rule, group vs individual, order | 1 (fixtures), 2 (registry, save refusal) |
| §2.2.1 `style_paths`, admin consumers, multi-select, class editor, shared cases 1–6 | 4, 7 (and the inventory above) |
| §2.3 not responsive | 1, 3, 8 |
| §2.4 opacity | 1, 3, 5 |
| §2.5 settings version | 1 |
| §3.1–3.2 cascade, parent/child | 5 |
| §3.3–3.4 against the theme and the resting utility | 6 (browser), 10 (docs) |
| §4.1 utilities, focus, forced, empty reset, hybrid note | 3, 6, 10 |
| §4.2 transitions | 6 |
| §4.3 theme rules, touch correction, custom themes | 6, 10 |
| §5 blocks, File part, provision | 5, 10 |
| §6.1–6.2 inspector, switch | 7, 8 |
| §6.3 forced preview, every owned element, children parts, reload, clearing | 9 |
| §7 docs | 10 |
| §8 testing, including reset tests over a class and a parent value | 2, 3, 5, 6, 7, 8, 9 |

Type consistency: `effectivePaths` / `expandDeclaration` / `HOVER_OF` (Task 7) are used in Tasks 8 and 9 with those names. `StageHover.force(owner, id, target, part)` / `clear(owner)` (Task 9) match Shared contracts. `createStageHover` adds `clearAny` and `resend` for the stage editor only. `BlockTypeStylePaths::for(array $row)` (Task 4) is used only there. `StyleTargets::effective` (Task 1) is used in Tasks 2 and 4.

Placeholders: none. The orders inside `expansion.json`'s `block` lists follow the final table, and Step 4 of Task 1 tells the executor to fix them by it. That is a ruling point, not an open item.
