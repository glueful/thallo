# The hover state — Implementation Plan

> Amended 2026-10-07 after plan review:
> - the schema and its compilation land in one commit (Task 1, which absorbs the former compiler task; tasks renumbered), and the shared-fixture "both runtimes" check moves to Task 6, which creates the admin spec it reads;
> - the published `style_paths` are normalised to schema order (`StyleTargets::stylePaths`, Tasks 1 and 3);
> - the forced preview names every effective hover target and a part's declared scope (Task 8), and the parent-patch proof keeps the Button selected;
> - Task 5 proves resets under the forced preview too, and override parity on every element.

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
  - `StyleTargets::stylePaths(StyleCapabilities)` is the one published form: effective, and normalised to schema order (`StyleSchema::ordered`). `fromDeclaration` keeps declaration order, so without it `['hover', 'colors']` and `['colors', 'hover']` would publish different lists.
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
- **The parent-patch proof uses the e2e world.** The harness's stage is a captured page, so `World.fragments` lets a proof answer an accepted apply with a fragment it built from the stage's own DOM. The Button stays selected, and the edit is made in its open Hover panel.
- **Browser fixtures.**
  - A new builder, `scripts/build-hover-fixtures`, copies `scripts/build-text-style-fixtures`' skeleton (public and stage pages, rolled back) into `tools/runtime-browser/fixtures/hover/`.
  - It gets its own CI step in `runtime-browser.yml`.
  - The e2e page gains three blocks after the last block of `scripts/build-builder-proof-fixtures`' body. Proofs address blocks by id, and Task 8 checks `inspector-content.spec.ts` does too before relying on that.
- **One commit for the schema and its compilation.** `StyleCompiler::rules()` enumerates every schema property, so the definitions, their class names and their utilities land together (Task 1).
- **What a force names.**
  - A block's tab forces every target an effective hover path lands on (`hoverTargets`): the path's own mapping, else the `hover` group's, else `root`. Its one switch governs them all, so a split across targets is forced on all of them.
  - A part's tab forces the part with its declared scope (`own`, or `children` for `children: true`), carried in the message. The bridge never infers the scope.
- **The stage's force state.**
  - `editor/stage/stageHover.ts` provides a `StageHover` (`force(owner, request)`, `clear(owner)`). Only the owner that set a force clears it, because a part tab and the block's tab are separate `StyleTab` instances.
  - `useStageEditor` provides it and re-sends the active force from its existing `bridge.onStageState` callback.
  - The bridge re-applies its remembered force after each `markEmptySlots()` call that follows an in-place swap (`preview-bridge.js:1278`, `:1354`).

## Capability consumer inventory

Every place that turns a capability declaration into paths. All of them are covered by a task.

| Consumer | Today | After | Task |
|---|---|---|---|
| `StyleCapabilities::fromDeclaration` (`packages/thallo-contracts/src/Style/StyleCapabilities.php:33`) | group expansion | + final hover pass | 1 |
| `StyleTargets::fromDeclaration` map / parts (`StyleTargets.php:47`) | group → target | + second-pass hover mapping, wrong-target error; parts via the line above | 1 |
| `StyleTargets::validateAgainst` (`:284`) | every cap needs a target | dropped hover paths exempt | 1 |
| `StyleTargets::stylePaths` (new) | — | the published form, schema-ordered; used by the payload and the fixtures | 1, 3 |
| `StyleCompiler::rules` (`packages/thallo-render/src/Style/StyleCompiler.php:268`) | every schema property | + `opacity`; hover paths compiled by `hoverRules()` | 1 |
| `EngineBlockStyleRegistry::capabilitiesFor` (`core/src/Content/Style/EngineBlockStyleRegistry.php:26`) | raw caps | effective caps | 2 |
| `FieldValidator` (`core/src/Content/Validation/FieldValidator.php:627`) | registry | unchanged (gets effective) | 2 (test) |
| `SettingsValidator` parts (`core/src/Content/Style/SettingsValidator.php:154`) | `partCapabilities` | unchanged (gets the hover pass) | 2 (test) |
| `StyleClassJobRunner` (`…/Classes/StyleClassJobRunner.php:151`), `StyleClassUsage` (`…/Classes/StyleClassUsage.php:88`) | registry | unchanged (gets effective) | 2 (test) |
| `BlockTypeRepository` save check (`core/src/Content/Blocks/BlockTypeRepository.php:118`) | caps + targets + `validateAgainst` | unchanged; wrong-target hover now refused | 2 (test) |
| `BlockTypeController::styleRefusal` / `CustomBlockStyle` | admin-made types | unchanged (no hover offered) — ruling | — |
| `StyleClassController` (`StyleCapabilities::all()`) | every path | now includes hover paths | 2 (test) |
| `RegionStyle`, `PageStyleCapabilities`, `LayoutValidator` | fixed lists without hover | unchanged | — |
| `BlockStyleEmitter` (`packages/thallo-render/src/Style/BlockStyleEmitter.php:48,53`) | per target / part | unchanged | 4 (test) |
| `RenderContextExtension::parentStyleClasses` | part filter | unchanged | 4 (test) |
| `BlockTypeController` payload | raw rows | + `style_paths` | 3 |
| Admin `BlockType` type (`admin/src/queries/blockTypes.ts:17`) and `schema.d.ts` | — | + `style_paths` (generated + hand type) | 3 |
| Admin `StyleTab.pathsOf` (`admin/src/editor/inspector/StyleTab.vue:145`) | local expansion | `effectivePaths()` | 6 |
| Admin `LayoutTab.pathsOf` (`LayoutTab.vue:106`) | local expansion | `effectivePaths()` | 6 |
| Admin `BlockInspector.declaredPaths` (`BlockInspector.vue:160`) | local expansion | `effectivePaths()` | 6 |
| Admin `BlockInspector.parts` synthetic part type (`BlockInspector.vue:130`) | `style_capabilities: spec.capabilities` | `style_paths: {block: server part paths}` | 6 |
| Admin `useStageEditor` lift and detach (`useStageEditor.ts:2138`, `:2206`) through `capabilityPaths` (`style/detach.ts:14`) | local expansion | `effectivePaths()` | 6 |
| Admin `StyleClassEditor` synthetic type (`StyleClassEditor.vue:30`) | every group | unchanged (local expansion with hover pass) | 6 (test) |
| Admin `RegionStyleEditor` synthetic type (`RegionStyleEditor.vue:28`) | region caps | unchanged (local expansion) | 6 (test) |
| Admin `LayoutTab.parentArranges` (`LayoutTab.vue:332`) | raw names `layout.display` / `layout` | unchanged (not an expansion) | — |
| Admin `stageTypography.typographyTarget`, `layoutContext.ts` | read `style_targets.map` | unchanged; `hoverTargets()` and `partScope()` added beside it (every effective hover target; a part's declared scope) | 8 |

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
- **Forced-preview attribute:** `data-thallo-hover` (never `thallo-canvas-hover`). **Bridge message:** `thallo:force-hover {id, targets, part, scope}`, with `scope` either `own` or `children`; `{id: null}` clears.
- **Blocks:**
  - Button `control`: `opacity`, `hover`;
  - Social link `icon` part: `opacity`, `hover`;
  - Social links `icon` part: `opacity`, `hover`;
  - Links `link` part: `hover`;
  - File: new `link` part with `colors`, `radius`, `typography.size`, the four paddings, `opacity`, `hover`.
- **Copy:** `Normal` and `Hover` (the switch); field labels `Opacity`, and `Text colour`, `Background`, `Border colour`, `Opacity` in Hover. Sentence case.
- **Changelog:** a bullet rides in the commit of the change, under `## [Unreleased]`. Upgrade Notes are written in Task 9.
- **Commits:** no `Co-Authored-By` trailer, never push. MAMP PHP first on PATH: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`.
- **Gates:**
  - `vendor/bin/phpcs; echo "phpcs=$?"` must print `phpcs=0`.
  - `COMPOSER_PROCESS_TIMEOUT=0 composer test`, in shards, unprefixed, never concurrently.
  - Admin: `pnpm type-check`, `pnpm lint`, `pnpm fmt:check` (format touched files with `pnpm exec oxfmt <files>`), `pnpm test`.
  - e2e after `CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`.
  - `tools/runtime-browser`: `npx playwright test`.

## Review Focus

1. **A block whose `colors` and `hover` sit on different targets**, such as a custom code-declared type, or a future Card with `colors.text` on `title`. The Style tab must not offer a hover colour that would be saved and then refused or silently ignored. Pinned in Task 1 (fixture `different-targets`) and Task 6 (`style-capabilities.spec.ts` runs the same fixture through `StyleTab`).
2. **A style class carrying `hover.colors.surface`, worn by a Heading (which has no `hover`).** The class saves (classes are not target-bound), and the Heading renders without the hover class and without error. Pinned in Task 4 (`testAClassHoverValueOnABlockWithoutHoverEmitsNothing`).
3. **A Button inside a Call to action's slot, still selected with its Hover panel open, while an accepted apply replaces the parent's fragment.** The force must survive the parent's swap, which replaces the Button's DOM too, without the selection moving. Pinned in Task 8 (e2e `the force survives a parent's fragment patch`).
4. **Tabbing through a Social links row whose row sets a hover colour.** Each link shows the colour on `:focus-visible`, and the theme's focus ring stays. Pinned in Task 5 (`keyboard focus shows the authored hover colour and keeps the focus ring`).
5. **An old document with no hover values, and a page cached before the upgrade.** It renders byte-identically except for the compiled artifact's name (a new hash). Pinned in Task 4 (`testABlockWithoutHoverValuesRendersTheSameClasses`).

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

    /** The published form: effective block paths and each part's, in schema order (spec §2.2.1). */
    public function stylePaths(StyleCapabilities $caps): array; // array{block: list<string>, parts: array<string, list<string>>}
}

// StyleSchema also gains:
/** @param list<string> $paths @return list<string> the same paths, in table order */
public static function ordered(array $paths): array;
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
export interface ForceHoverRequest {
  id: string
  targets: string[]        // a block's tab: every target an effective hover path lands on
  part: string | null      // a part's tab: the part
  scope: 'own' | 'children' // a part drawn by child blocks is 'children'
}
export interface StageHover {
  force(owner: symbol, request: ForceHoverRequest): void
  clear(owner: symbol): void
}
export const StageHoverKey: InjectionKey<StageHover>
export function hoverTargets(type: BlockType | null): string[]
export function partScope(type: BlockType | null, part: string): 'own' | 'children'
export function createStageHover(send: (request: ForceHoverRequest | null) => void): StageHover & { clearAny(): void; resend(): void }
```

```js
// preview-bridge.js — parent → stage
{ type: 'thallo:force-hover', id: string | null, targets: string[], part: string | null, scope: 'own' | 'children' }
```

---

## Task 1: the hover and opacity properties, the target-aware expansion, and their compilation

One commit: the compiler enumerates every schema property, so a property without its class name and
utilities would break stylesheet compilation in between.

**Files:**
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php` (VERSION 15, `HOVER`, `restingPathOf`, five definitions appended after `footer.divider_style`)
- Modify: `packages/thallo-contracts/src/Style/StyleCapabilities.php` (final hover pass, `filter`)
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php` also gains `ordered()`
- Modify: `packages/thallo-contracts/src/Style/StyleTargets.php` (two-pass map, wrong-target error, `effective`, `stylePaths`, `validateAgainst` exemption)
- Modify: `packages/thallo-render/src/Style/ClassNames.php` (stems), `packages/thallo-render/src/Style/StyleCompiler.php` (VERSION 20 with a log line; `opacity`; hover rules), `tests/fixtures/style/compiled-default-artifact.json` (re-pin)
- Create: `packages/thallo-contracts/style-capability-fixtures/v1/README.md`, `expansion.json`, `multi-select.json`
- Create: `packages/thallo-contracts/style-schema/v1.json` (the snapshot)
- Test: `tests/Unit/Contracts/StyleSchemaTest.php` (VERSION 15, path list), `tests/Unit/Contracts/StyleCapabilityFixturesTest.php` (new), `tests/Unit/Contracts/StyleSchemaSnapshotTest.php` (new), `tests/Unit/Render/StyleCompilerTest.php`

**Interfaces:**
- **Produces:**
  - `StyleSchema::HOVER`, `StyleSchema::restingPathOf()`, `StyleSchema::ordered()`;
  - `StyleCapabilities::filter()`;
  - `StyleTargets::effective()`, `StyleTargets::stylePaths()` (schema-ordered);
  - the class names and compiled rules of Global Constraints;
  - the fixture files (consumed by Tasks 3 and 6) and the schema snapshot (consumed by Task 6).

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
        "name": "order: block paths are published in schema order, not declaration order",
        "declaration": {
          "style_capabilities": ["opacity", "hover", "colors.text"],
          "style_targets": {"targets": {"root": {"kind": "box"}}, "map": {"opacity": "root", "hover": "root", "colors.text": "root"}}
        },
        "expect": {"block": ["colors.text", "opacity", "hover.colors.text", "hover.opacity"], "parts": {}}
      },
      {
        "name": "order: part paths are published in schema order",
        "declaration": {
          "style_capabilities": [],
          "style_targets": {
            "targets": {"root": {"kind": "box"}},
            "map": {},
            "parts": {
              "a": {"capabilities": ["hover", "opacity", "colors.text"]},
              "b": {"capabilities": ["colors.text", "opacity", "hover"]}
            }
          }
        },
        "expect": {"block": [], "parts": {
          "a": ["colors.text", "opacity", "hover.colors.text", "hover.opacity"],
          "b": ["colors.text", "opacity", "hover.colors.text", "hover.opacity"]
        }}
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

  `multi-select.json`. Sibling blocks' `style_paths` and the rows the Style tab shows for them together (Task 6 reads it):
  ```json
  {
    "cases": [
      {"name": "Button + Button", "slugs": ["button", "button"], "expect_hover": ["hover.colors.text", "hover.colors.surface", "hover.colors.border", "hover.opacity"]},
      {"name": "Button + Links", "slugs": ["button", "links"], "expect_hover": []},
      {"name": "Button + Social link", "slugs": ["button", "social_link"], "expect_hover": []}
    ]
  }
  ```
  `slugs` refer to the shipped starters. Task 3's test writes their real `style_paths` into `packages/thallo-contracts/style-capability-fixtures/v1/starters.json`, which is committed and checked for staleness, so the admin test reads real data without a server.

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
          // assertSame on lists: the order is part of the contract (schema order).
          self::assertSame($case['expect'], $targets->stylePaths($caps));
      }

      public function testTheTwoOrderCasesAreEqual(): void
      {
          $doc = json_decode((string) file_get_contents(self::FIXTURES . '/expansion.json'), true, 512, JSON_THROW_ON_ERROR);
          $by = array_column($doc['cases'], 'expect', 'name');
          self::assertSame($by['order: hover listed first'], $by['order: hover listed last']);
      }
  }
  ```
  The check that the admin reads the same folder (`testBothRuntimesReadTheSameFixtureFiles`) is added in Task 6, which creates the admin spec it reads.

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

  `StyleCompilerTest`:
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

- [ ] **Step 3: Run them and watch them fail.** `vendor/bin/phpunit tests/Unit/Contracts/StyleCapabilityFixturesTest.php tests/Unit/Contracts/StyleSchemaTest.php tests/Unit/Contracts/StyleSchemaSnapshotTest.php tests/Unit/Render/StyleCompilerTest.php`. Expected: FAIL, with unknown capability "hover", VERSION 14 ≠ 15, a missing snapshot, and no hover utilities.

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
  And the published form, normalised to schema order (`fromDeclaration` keeps declaration order, so
  `['hover', 'colors']` and `['colors', 'hover']` would otherwise differ):
  ```php
  /**
   * What the block and each part offer, as published (hover state spec §2.2.1): effective, and in
   * schema table order whatever order the declaration used.
   *
   * @return array{block: list<string>, parts: array<string, list<string>>}
   */
  public function stylePaths(StyleCapabilities $caps): array
  {
      $parts = [];
      foreach ($this->parts() as $part) {
          $parts[$part] = StyleSchema::ordered($this->partCapabilities($part)->paths());
      }
      return ['block' => StyleSchema::ordered($this->effective($caps)->paths()), 'parts' => $parts];
  }
  ```
  `StyleSchema`:
  ```php
  /** @param list<string> $paths @return list<string> the same paths, in table order */
  public static function ordered(array $paths): array
  {
      $set = array_flip($paths);
      return array_values(array_filter(array_keys(self::properties()), static fn (string $p): bool => isset($set[$p])));
  }
  ```
  In `validateAgainst`, inside the loop, before `$errors[] = "capability {$path} has no target"`:
  ```php
  if (StyleSchema::restingPathOf($path) !== null) {
      continue; // dropped by the target-aware rule (hover state spec §2.2), not missing
  }
  ```

- [ ] **Step 6b: Implement the compilation.**
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
- [ ] **Step 6c: Re-pin.** Run the test once and copy the reported sha256 into `tests/fixtures/style/compiled-default-artifact.json` with `version: 20`.

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

- [ ] **Step 8: Run the tests.** The Step 3 command, then `vendor/bin/phpunit tests/Unit/Contracts tests/Unit/Render`. Then compile a real stylesheet end to end, `vendor/bin/phpunit tests/Integration/Render/StyleSchemaEndpointTest.php tests/Integration/Render/BlocksRenderingTest.php`, to prove compilation is usable at this commit. Expected: PASS.

- [ ] **Step 9: Commit** `feat(style): hover and opacity — properties, the target-aware expansion, and their utilities`, with a CHANGELOG `[Unreleased]` bullet: settings version 15 adds `opacity` and the hover state.

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

## Task 3: `style_paths` on the block-type payload, its OpenAPI schema and the admin types

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
  - `testEveryExpansionFixtureMatchesThePayload`: for each `expansion.json` case without `error`, it creates a type with that declaration and asserts with `assertSame` (order included) that `GET /v1/admin/block-types/{slug}`'s `data.block_type.style_paths` equals `expect`. The two `order:` cases prove that the payload, not only the contract, is in schema order.
  - `testAPayloadIsInSchemaOrderWhateverTheDeclarationOrder`: two types, one declared `['hover', 'colors', 'opacity']` and one `['opacity', 'colors', 'hover']` (same map), give identical `style_paths.block`, and that list equals `StyleSchema::ordered()` of itself.
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
          // The one published form (StyleTargets::stylePaths): effective and in schema order. A type
          // with no targets declaration has one implicit target and no parts.
          return $targets?->stylePaths($caps) ?? ['block' => StyleSchema::ordered($caps->paths()), 'parts' => []];
      }
  }
  ```
  In the controller, a private `withStylePaths(array $row): array` returns `$row + ['style_paths' => BlockTypeStylePaths::for($row)]`. Apply it to `$listed` in `index` (`array_map`) and to the row in `show`, `store` and `update`. `BlockTypeItemData` gains:
  ```php
  /** @var array{block: list<string>, parts: array<string, list<string>>}|null What the type offers, expanded (hover state spec §2.2.1). */
  public readonly ?array $style_paths = null,
  ```
- [ ] **Step 4: Run, then record the starters snapshot.** `THALLO_RECORD_STYLE_PATHS=1 vendor/bin/phpunit --filter testTheStartersSnapshotIsCurrent tests/Integration/Http/BlockTypeStylePathsTest.php`, then the whole file without the variable. Expected: PASS. The snapshot changes again in Task 4 (the blocks gain hover), where it is re-recorded.
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

## Task 4: the five blocks gain hover and opacity; File gains its Link part

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (Button, Links, Social links, Social link, File)
- Modify: `packages/thallo-render/themes/default/templates/blocks/file.twig` (`{{ style_classes('link') }}`)
- Modify: `packages/thallo-render/fragments-verified.json` (re-record)
- Modify: `packages/thallo-contracts/style-capability-fixtures/v1/starters.json` (re-record)
- Test: `tests/Integration/Render/HoverStyleTest.php` (new, under `tests/Integration/Render`), `tests/Integration/Render/FooterAndSocialStyleTest.php` (extend)

**Interfaces:**
- **Consumes:** Tasks 1–3.
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

## Task 5: the default theme's hover rules — three branches — and the browser proofs

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (lines 643, 666–668, 679, 969–970, 1081–1082, 1348–1361)
- Create: `scripts/build-hover-fixtures` (from `scripts/build-text-style-fixtures`: same bootstrap, sync, transaction and pages; its own body and output folder `tools/runtime-browser/fixtures/hover/`)
- Create: `tools/runtime-browser/tests/hover.spec.js`
- Modify: `.github/workflows/runtime-browser.yml` (path filter `scripts/build-hover-fixtures`; a step "Build the hover fixtures" after the text style one)

**Interfaces:**
- **Consumes:** Tasks 1 and 4.
- **Produces:** the theme rules in the three-branch form of spec §4.3.

- [ ] **Step 1: Write the fixture builder.** The body, in document order, ids in comments:
  1. Buttons, one per variant (`solid`, `outline`, `soft`, `subtle`, `ghost`, `link`), no settings: `btnplain*`.
  2. Buttons, one per variant again, each with `hover.colors.text` `color.accent` only and nothing else: `btnhov*` (`btnhovsolid`, `btnhovoutli`, `btnhovsoft1`, `btnhovsubtl`, `btnhovghost`, `btnhovlink1`).
  3. A solid Button with `colors.surface` `color.ink`, `hover.colors.surface` reset, wearing a class `hoverclass01` whose style is `{hover: {colors: {surface: color.accent}}}`: `btnreset001`.
  4. A Links block, no settings, with two items; a Links block with `parts.link.hover.colors.text` `color.accent`.
  5. A File block, no settings, with a public blob (seeded as `build-text-style-fixtures` seeds images); a File block with `parts.link.hover.colors.text` `color.accent` only (the theme's hover background stays the theme's); a File block with `parts.link.hover.colors.surface` `color.accent`.
  6. A Social links row with two links, no settings; a Social links row with `parts.icon.hover.colors.surface` `color.accent` only (the theme's ink stays the theme's).
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
  - `pointer hover and forced preview match with one authored property`, for **every** element with exactly one authored hover property: the six `btnhov*` Buttons, the Links link with the hover text colour, the File link with the hover text colour, the File link with the hover background, and the first link of the row with the hover background. For each:
    - `pointer` deep-equals `forced`;
    - the authored property is the accent. Read `--t-color-accent` once through a probe element, so both are computed colours;
    - one theme-owned property is still the theme's hovered value, compared with the matching plain element's `pointer`:
      - Buttons: `transform`, and `backgroundColor` for ghost, soft and subtle;
      - Links: `backgroundColor`;
      - File with text: `backgroundColor` (the theme's `--surface`);
      - File with background: `color`;
      - Social link with background: `color` (the theme's `--ink` through the wrapper).
  - `an authored hover colour beats the resting utility and the theme`: the hovered Links link with the part setting shows the accent, not the theme's `--ink`. The hovered File link with the part setting shows the accent background, not `--surface`.
  - `keyboard focus shows the authored hover colour and keeps the focus ring` (Review Focus 4): pressing Tab onto the second row's first Social link gives the accent colour, and its `outlineStyle` is not `none`. For the plain File link, focus shows `--surface` (the theme's existing focus branch).
  - `a reset keeps the resting colour, hovered and forced`: for `btnreset001` and the authored row's second link, `pointerAndForced()` gives `pointer` and `forced` equal to each other. `btnreset001`'s `backgroundColor` is `--t-color-ink`'s, from the resting utility, not the class's accent. The link's `color` is `color.muted`, not the row's accent.
  - `a tap leaves nothing on a touch-primary device`: in `test.describe` with `test.use({ ...devices['Pixel 7'] })` (`isMobile`, `hasTouch`; first `expect(await page.evaluate(() => matchMedia('(hover: none)').matches)).toBe(true)`), tapping each plain element and the authored ones leaves `look` equal to `rest`. Navigation is prevented by `page.addInitScript(() => document.addEventListener('click', (e) => e.preventDefault(), true))`, so the tap stays on the page.

  Run the whole file against `public.html`, and the two "match" tests against `stage.html` too (as `text-style.spec.js` iterates `PAGES`).
- [ ] **Step 4: Run and watch them fail.** `cd tools/runtime-browser && npx playwright test tests/hover.spec.js`. Expected:
  - "no declarations" FAILs for the Buttons (forced shows no tint and no lift);
  - "touch" FAILs for the theme's rules (the ghost tint sticks after the tap);
  - the authored-value tests pass (Task 1).
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

## Task 6: the admin reads `style_paths` everywhere it expands capabilities

**Files:**
- Create: `admin/src/style/capabilities.ts`
- Modify: `admin/src/style/schema.ts` (the five rows after the footer rows, in PHP order)
- Modify: `admin/src/editor/inspector/StyleTab.vue` (`pathsOf` → `effectivePaths`; drop the local loop)
- Modify: `admin/src/editor/inspector/LayoutTab.vue` (`pathsOf` → `effectivePaths`)
- Modify: `admin/src/editor/inspector/BlockInspector.vue` (`declaredPaths` → `effectivePaths`; the part's synthetic type carries `style_paths: { block: props.blockType?.style_paths?.parts?.[name] ?? [...expandDeclaration(spec.capabilities)], parts: {} }`)
- Modify: `admin/src/editor/stage/useStageEditor.ts` (both `capabilityPaths(type?.style_capabilities)` → `effectivePaths(type)`)
- Modify: `admin/src/style/detach.ts` (`capabilityPaths` delegates to `expandDeclaration` and is kept for its fixture test)
- Test: `admin/src/__tests__/style-capabilities.spec.ts` (new), `admin/src/__tests__/style-schema-parity.spec.ts` (new), `tests/Unit/Contracts/StyleCapabilityFixturesTest.php` (one method added)

**Interfaces:**
- **Consumes:** `expansion.json`, `multi-select.json` and `starters.json` (Tasks 1, 3, 4); `BlockType.style_paths` (Task 3).
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
  - For each `expansion.json` case with `expect`, mount `StyleTab` (as `style-tab-backdrop.spec.ts` mounts it) with a type `{ …, style_capabilities: decl.style_capabilities, style_targets: decl.style_targets, style_paths: expect }` and the schema built from the snapshot. Assert `effectivePaths(type)` equals `new Set(expect.block)`, and that the rendered row paths (`data-test` of each field) are exactly the non-hover paths of `expect.block` that `pathsForTab` assigns to Style. Task 7 adds to this test that switching to Hover renders exactly the `hover.*` paths of `expect.block`.
  - For each part in `expect.parts`, `BlockInspector`'s computed part type for `name` gives `effectivePaths` equal to `expect.parts[name]`.
  - For each `multi-select.json` case, the intersection `StyleTab` computes (`allowed`, exposed for the test through the rendered rows plus `effectivePaths` of each type in `starters.json`) contains exactly `expect_hover` among hover paths. Task 7 adds the rendered Hover rows to this test.
  - A synthetic style-class type gives `effectivePaths` containing every hover path. A synthetic region type with `RegionStyle` caps gives none.
  - `expandDeclaration(['hover'])` is empty; `expandDeclaration(['hover', 'colors.text'])` is `{'colors.text', 'hover.colors.text'}`.
  - The file reads the folder `style-capability-fixtures/v1`.

  And, now that the admin spec exists, the PHP side of the shared-fixture contract, added to `StyleCapabilityFixturesTest`:
  ```php
  public function testBothRuntimesReadTheSameFixtureFiles(): void
  {
      $spec = (string) file_get_contents(__DIR__ . '/../../../admin/src/__tests__/style-capabilities.spec.ts');
      self::assertStringContainsString('style-capability-fixtures/v1', $spec, 'the TypeScript spec reads the same folder');
  }
  ```
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
- [ ] **Step 4: Run.** The Step 2 command, then `pnpm test`, `pnpm type-check`, and `pnpm exec oxfmt <touched files>`. Then `vendor/bin/phpunit tests/Unit/Contracts/StyleCapabilityFixturesTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(admin): every capability consumer reads the server's style_paths; the schema mirror has a parity test`.

## Task 7: the Normal / Hover switch

**Files:**
- Modify: `admin/src/editor/inspector/StyleTab.vue`
- Modify: `admin/src/editor/inspector/choiceLabels.ts` (if opacity choices need `%` labels: `'100' → '100%'` … for `opacity` and `hover.opacity`)
- Test: `admin/src/__tests__/style-tab-hover.spec.ts` (new), plus the hover assertions left from Task 6 in `style-capabilities.spec.ts`

**Interfaces:**
- **Consumes:** `effectivePaths`, `HOVER_OF` (Task 6).
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

## Task 8: the stage's forced hover preview

**Files:**
- Create: `admin/src/editor/stage/stageHover.ts`
- Modify: `admin/src/composables/useCanvasBridge.ts` (`forceHover(request | null)`)
- Modify: `admin/src/editor/stage/useStageEditor.ts` (provide `StageHoverKey`; re-send on `onStageState`; clear on selection change and dispose)
- Modify: `admin/src/editor/inspector/StyleTab.vue` (inject `StageHoverKey`; force while Hover is on and a hoverable group is unfolded; clear on Normal, fold and unmount)
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js` (`onForceHover`, `forcedElements`, `applyForcedHover`, the dispatcher entry, re-apply after `markEmptySlots()` at the two in-place swaps)
- Modify: `admin/e2e/helpers.ts` (`World.fragments`: an accepted apply may answer with fragments)
- Modify: `scripts/build-builder-proof-fixtures` (three blocks after `prose0000001`)
- Test: `admin/src/__tests__/stage-hover.spec.ts` (new), `admin/src/__tests__/preview-bridge-hover.spec.ts` (new: the real bridge asset in jsdom, as `preview-bridge-dom.spec.ts` drives it), `admin/e2e/tests/hover-preview.spec.ts` (new)

**Interfaces:**
- **Consumes:** `hoverState` (Task 7); `effectivePaths`, `HOVER_OF` (Task 6); the theme's and utilities' `[data-thallo-hover]` (Tasks 1, 5).
- **Produces:** `ForceHoverRequest`, `StageHover`, `StageHoverKey`, `hoverTargets`, `partScope`, `createStageHover`, and the bridge message of Shared contracts.

The force names **what the inspector governs**, never a guess:
- A block's Style tab forces **every** target an effective hover path lands on (`hoverTargets`), since its one switch governs all of them. Each hover path's target is its explicit mapping, else the `hover` group's, else `root` for a type with no targets declaration.
- A part's tab forces that part with its **declared scope**: `children` when the part is declared `children: true` (drawn by child blocks), `own` otherwise. The bridge never infers the scope.

- [ ] **Step 1: Check the e2e premise.** `grep -n "nth(\|prose0000001" admin/e2e/tests/inspector-content.spec.ts`. The proof must find its blocks by id. If it indexes, ledger a ruling and insert the new blocks before `prose0000001` instead, re-checking the specs that count root blocks (`grep -rn "toHaveCount" admin/e2e/tests | grep -i block`).
- [ ] **Step 2: Add the e2e fixture blocks** after `prose0000001`:
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

  `preview-bridge-hover.spec.ts`. It evaluates the bridge once and greets it, exactly as `preview-bridge-dom.spec.ts`'s `beforeAll` does, and sends messages with that spec's `sendToBridge` shape. Fixtures are built per test with unique ids:
  - **Own part vs a direct child carrying the same part name.** Block `hvA` holds its own `<a class="thallo-stage-part--icon">`, a direct child block `hvB` with its own `.thallo-stage-part--icon`, and inside `hvB` a grandchild block `hvC` with one too.
    - `{id: 'hvA', targets: [], part: 'icon', scope: 'own'}` marks only `hvA`'s own element, not `hvB`'s or `hvC`'s.
    - `scope: 'children'` marks only `hvB`'s, not `hvA`'s own and not `hvC`'s.
  - **Repeated owned elements.** Block `hvL` with three `.thallo-stage-part--link` and a nested block `hvL2` with one: `part: 'link', scope: 'own'` marks all three of `hvL`'s and not `hvL2`'s.
  - **Two targets.** Block `hvT` with `.thallo-stage-target--root` on its wrapper's first child and a `.thallo-stage-target--title` inside, plus a nested block `hvT2` with its own `.thallo-stage-target--title`. `{targets: ['root', 'title'], part: null, scope: 'own'}` marks both of `hvT`'s and not `hvT2`'s.
  - **Replace and clear.** A second force removes the first force's attributes. `{id: null}` removes every `data-thallo-hover`.
  - **Re-applied after an in-place swap.** With a force on `hvL`, send a valid `thallo:fragments` swap for `hvL` (built with the helper the existing fragments tests in `preview-bridge-dom.spec.ts` use, with the same pair handshake). The three links are new nodes (`!==` the old ones) and carry the attribute.
  - **Junk is ignored.** A target name failing `/^[a-z][a-z0-9_-]*$/`, or a scope other than `own` / `children`, marks nothing and throws nothing.

  `stage-hover.spec.ts` (vitest):
  - **`hoverTargets`** (types given `style_paths` and `style_targets`):
    - Button-like: `map` `{colors: 'control', opacity: 'control', hover: 'control'}`, effective hover paths on `control` → `['control']`;
    - explicit mappings without the shorthand: `map` `{'colors.border': 'root', 'hover.colors.border': 'root', opacity: 'media', 'hover.opacity': 'media'}` → `['root', 'media']`;
    - border only: `{'colors.border': 'frame', 'hover.colors.border': 'frame'}` → `['frame']`;
    - two hover targets through the group and explicit paths: `{'colors.surface': 'root', 'colors.text': 'title', hover: 'root', 'hover.colors.text': 'title'}` with effective `hover.colors.surface` and `hover.colors.text` → `['root', 'title']`;
    - no targets declaration → `['root']`;
    - no effective hover path → `[]`.
  - **`partScope`**: a part declared `children: true` → `'children'`; otherwise `'own'`.
  - **`createStageHover`**, with a fake `send`:
    - `force(a, r1)` sends `r1`;
    - `clear(b)` sends nothing, `clear(a)` sends `null`;
    - `force(a, r1)` then `force(b, r2)`: `clear(a)` sends nothing;
    - `resend()` after `force(a, r1)` sends `r1` again; after `clear(a)`, nothing;
    - `clearAny()` clears whoever owns it.
  - **StyleTab** (with a provided fake `StageHover`):
    - Hover in block context forces `{id, targets: hoverTargets(type), part: null, scope: 'own'}`;
    - in part context, `{id, targets: [], part, scope: partScope(blockType, part)}` (the part's tab receives the block type through a new optional `partScope` prop set by `BlockInspector`);
    - Normal clears;
    - folding every hoverable group clears, and unfolding one forces again;
    - unmounting clears;
    - with no `StageHoverKey` provided (the class editor), nothing throws;
    - in multi-select nothing is forced.

  `admin/e2e/tests/hover-preview.spec.ts`. Uses `openDesignPage` and a `stage()` frame locator, as `typeface.spec.ts` does:
  - `forces the selected Button's look`: select `ctabutn0001`, open Style, click `style-state-hover-colors`. `[data-thallo-block="ctabutn0001"] .thallo-block-button__link` has `data-thallo-hover`. Click Normal; it is gone.
  - `reaches every link of a Links block and none of a nested one`: select `hovlinks0001`, and in its Link part section click Hover. All three `[data-thallo-block="hovlinks0001"] .thallo-block-links__link` carry the attribute, and `[data-thallo-block="hovlinks0002"] .thallo-block-links__link` does not.
  - `reaches every link of a Social links row`: select `hovsocial001`, Icon section, Hover. Both links carry it (the `children` scope).
  - `the force survives a parent's fragment patch` (Review Focus 3). Selection stays on the Button throughout:
    1. Open the page with `World.fragments`, a function the test controls.
    2. Select `ctabutn0001`, open Style, and switch Colours to Hover.
    3. Read the CTA wrapper's `outerHTML` from the stage, `[data-thallo-block="ctaa00000001"]`, and set `data-proof-swap="1"` on its `.thallo-block-button__link`. Make `World.fragments` answer the next apply with `{ ctaa00000001: <that html> }`.
    4. In the still-open Hover panel, pick a hover text colour. That is an edit to the Button, so the selection does not move, and it produces an apply.
    5. Wait for `applies()` to grow.
    6. Assert that `__thalloBuilder.snapshot().selection` is still `ctabutn0001`.
    7. Assert that the stage's Button link carries `data-proof-swap="1"`, proving the parent's fragment really replaced the child's DOM, and carries `data-thallo-hover`, proving it was restored.

    If the stage refuses the swap (its pair guard), it falls back to a reload. The swap marker is then absent and the test fails, as it should. Fix the world's epoch and baseline to the mint fixture's pair, and ledger it.
  - `the force survives a full stage reload`: with Hover on for `hovlinks0001`'s Link part, `stage.evaluate(() => location.reload())`. Wait until the bridge has re-announced itself: the three links carry the attribute again (`expect.poll`), sent by the admin on `stage-state`.
  - `clears when the panel closes`:
    - With Hover on, click the Layout tab. No element in the stage carries `data-thallo-hover`.
    - Back on Style (the tab remounts in Normal), switch to Hover again and collapse the Colours and Effects groups. Still none.
  - `clears on a new selection`: Hover on for `ctabutn0001`, then select `head0000000a`. No attribute anywhere.
- [ ] **Step 4: Run and watch them fail.** `cd admin && pnpm vitest run src/__tests__/stage-hover.spec.ts src/__tests__/preview-bridge-hover.spec.ts`. Then rebuild the fixtures and `cd e2e && pnpm exec playwright test tests/hover-preview.spec.ts`. Expected: FAIL.
- [ ] **Step 5: Implement the stage side.** In `preview-bridge.js`, beside the typography lookup:
  ```js
  // ── Forced hover (hover state spec §6.3) ────────────────────────────────────
  // While the inspector's Hover is on, every element the block owns for the forced targets or part
  // shows its hover look: the theme's hover rules and the utilities match [data-thallo-hover]. Every
  // match, not the first (a Links block's links are many). The parent says whose elements: `own`
  // — the block's own, never a nested block's — or `children` — a part drawn by the block's direct
  // child blocks (the Social links' Icon). Remembered, so an in-place swap re-applies it; a reload
  // forgets it and the parent sends it again.
  var NAME = /^[a-z][a-z0-9_-]*$/
  var forced = null
  function forcedElements(f) {
    var w = findBlock(f.id)
    if (!w) return []
    var selectors = f.part
      ? ['.thallo-stage-part--' + f.part]
      : f.targets.map(function (t) { return '.thallo-stage-target--' + t })
    if (selectors.length === 0) return []
    var els = w.querySelectorAll(selectors.join(', '))
    var out = []
    for (var i = 0; i < els.length; i++) {
      var owner = wrapperFor(els[i])
      var mine = f.scope === 'own'
        ? owner === w
        : owner !== null && owner !== w && wrapperFor(owner.parentElement) === w
      if (mine) out.push(els[i])
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
    var targets = Array.isArray(data.targets) ? data.targets.filter(function (t) { return typeof t === 'string' && NAME.test(t) }) : []
    var part = typeof data.part === 'string' && NAME.test(data.part) ? data.part : null
    var valid = typeof data.id === 'string' && data.id !== ''
      && (data.scope === 'own' || data.scope === 'children')
      && (part !== null || targets.length > 0)
    forced = valid ? { id: data.id, targets: targets, part: part, scope: data.scope } : null
    applyForcedHover()
  }
  ```
  Dispatcher: `if (data.type === 'thallo:force-hover') onForceHover(data)`. Add `applyForcedHover()` right after `markEmptySlots()` at lines 1278 and 1354.
- [ ] **Step 6: Implement the admin side.**
  - `useCanvasBridge.ts`:
    ```ts
    forceHover(request: ForceHoverRequest | null): void {
      post(request === null
        ? { type: 'thallo:force-hover', id: null, targets: [], part: null, scope: 'own' }
        : { type: 'thallo:force-hover', ...request })
    },
    ```
  - `stageHover.ts`:
    ```ts
    // The stage's forced hover (hover state spec §6.3): the Style tab asks while its Hover is on; only
    // the asker clears, since a block's tab and its parts' tabs are separate. The stage editor holds
    // the force and sends it again whenever the stage reports ready, because a reload forgets it.
    import type { InjectionKey } from 'vue'
    import type { BlockType } from '@/queries/blockTypes'
    import { effectivePaths, HOVER_OF } from '@/style/capabilities'

    export interface ForceHoverRequest {
      id: string
      targets: string[]
      part: string | null
      scope: 'own' | 'children'
    }
    export interface StageHover {
      force(owner: symbol, request: ForceHoverRequest): void
      clear(owner: symbol): void
    }
    export const StageHoverKey: InjectionKey<StageHover> = Symbol('thallo-stage-hover')

    /** Every target an effective hover path lands on: its own mapping, else the `hover` group's, else root. */
    export function hoverTargets(type: BlockType | null): string[] {
      const map = (type?.style_targets as { map?: Record<string, unknown> } | null | undefined)?.map
      const out: string[] = []
      for (const path of effectivePaths(type)) {
        if (HOVER_OF[path] === undefined) continue
        const mapped = map?.[path] ?? map?.hover
        const target = typeof mapped === 'string' ? mapped : 'root'
        if (!out.includes(target)) out.push(target)
      }
      return out
    }

    /** Whose elements a part's force reaches: the block's own, or its direct children's (`children: true`). */
    export function partScope(type: BlockType | null, part: string): 'own' | 'children' {
      const parts = (type?.style_targets as { parts?: Record<string, { children?: boolean }> } | null | undefined)?.parts
      return parts?.[part]?.children === true ? 'children' : 'own'
    }

    export function createStageHover(send: (request: ForceHoverRequest | null) => void) {
      let active: { owner: symbol; request: ForceHoverRequest } | null = null
      return {
        force(owner: symbol, request: ForceHoverRequest) {
          active = { owner, request }
          send(request)
        },
        clear(owner: symbol) {
          if (active?.owner !== owner) return
          active = null
          send(null)
        },
        clearAny() {
          if (active === null) return
          active = null
          send(null)
        },
        resend() {
          if (active) send(active.request)
        },
      }
    }
    ```
  - `useStageEditor.ts`:
    - `const stageHover = createStageHover((r) => bridge.forceHover(r))`;
    - `provide(StageHoverKey, stageHover)`;
    - `stageHover.resend()` inside the existing `bridge.onStageState` callback;
    - `watch(selected, () => stageHover.clearAny())`;
    - `onScopeDispose(() => stageHover.clearAny())`.
  - `BlockInspector.vue`: pass `:part-scope="partScope(blockType, part.name)"` to each part's `StyleTab`.
  - `StyleTab.vue` gains the prop `partScope?: 'own' | 'children'`, and:
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
        if (context === 'part' && props.part) {
          stageHover.force(owner, { id, targets: [], part: props.part, scope: props.partScope ?? 'own' })
        } else if (context === 'block') {
          const targets = hoverTargets(props.blockType)
          if (targets.length > 0) stageHover.force(owner, { id, targets, part: null, scope: 'own' })
        }
      },
      { immediate: true },
    )
    onBeforeUnmount(() => stageHover?.clear(owner))
    ```
  - `admin/e2e/helpers.ts`:
    - `World` gains `/** An accepted apply answers with these fragments (null: none, the default). */ fragments?: () => Record<string, string> | null`;
    - the apply route uses `fragments: world.fragments?.() ?? null`.
- [ ] **Step 7: Run.** `pnpm vitest run src/__tests__/stage-hover.spec.ts src/__tests__/preview-bridge-hover.spec.ts src/__tests__/preview-bridge-dom.spec.ts src/__tests__/style-tab-hover.spec.ts`. Then `cd e2e && pnpm exec playwright test tests/hover-preview.spec.ts tests/typeface.spec.ts tests/motion-play.spec.ts tests/inspector-content.spec.ts tests/cancel.spec.ts`. Then `pnpm type-check` and `pnpm exec oxfmt` on the touched files. Expected: PASS.
- [ ] **Step 8: Commit** `feat(builder): the stage previews the hover look while Hover is on — every governed target, the declared part scope, kept across patches and reloads`.

## Task 9: docs, upgrade notes, and the release gates

**Files:**
- Modify: `docs/reference/05-style-settings.md`. A "Hover" section:
  - the four paths and `opacity`;
  - the opt-in rule, per target;
  - what beats what (spec §3.3–3.4), including the full-strength hover background over a surface opacity;
  - pointer, focus and touch (spec §4.1, including the hybrid-device note);
  - the empty reset;
  - the three-branch pattern and `[data-thallo-hover]` for themes (spec §4.3).
- Modify: `docs/reference/04-block-library.md`: the Button, Links, Social links, Social link and File rows' style columns.
- Modify: `CHANGELOG.md` `[Unreleased]`. The bullets from Tasks 1, 4 and 5 are already there; add `### Upgrade Notes`:
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
| §1 success criteria | 4 (row/link cascade, unchanged render — Review Focus 5), 5 (focus, touch, pointer = forced), 8 (stage) |
| §2.1 hover paths, storage | 1 |
| §2.2 per-target rule, group vs individual, order | 1 (fixtures, schema order), 2 (registry, save refusal) |
| §2.2.1 `style_paths`, admin consumers, multi-select, class editor, shared cases 1–6 | 1, 3, 6 (and the inventory above) |
| §2.3 not responsive | 1, 7 |
| §2.4 opacity | 1, 4 |
| §2.5 settings version | 1 |
| §3.1–3.2 cascade, parent/child | 4 |
| §3.3–3.4 against the theme and the resting utility | 5 (browser), 9 (docs) |
| §4.1 utilities, focus, forced, empty reset, hybrid note | 1, 5, 9 |
| §4.2 transitions | 5 |
| §4.3 theme rules, touch correction, custom themes | 5, 9 |
| §5 blocks, File part, provision | 4, 9 |
| §6.1–6.2 inspector, switch | 6, 7 |
| §6.3 forced preview: every governed target, every owned element, declared part scope, reload, clearing | 8 |
| §7 docs | 9 |
| §8 testing, including reset tests over a class and a parent value, pointer and forced | 2, 1, 4, 5, 6, 7, 8 |

Plan-review corrections, where each landed:

| Correction | Where |
|---|---|
| Schema and compilation atomic; no forward test dependency | Task 1 (merged), Task 6 (`testBothRuntimesReadTheSameFixtureFiles`) |
| Explicit ownership scope for forced parts | Task 8 (`partScope`, message `scope`, bridge `own` / `children`, the own-part-plus-child fixture in `preview-bridge-hover.spec.ts`) |
| Every effective hover target | Task 8 (`hoverTargets`; explicit mappings without the shorthand, border-only, opacity on another target, two targets) |
| Selection unchanged during the parent-patch proof | Task 8 (`World.fragments`, the edit made in the Button's own Hover panel, selection and swap marker asserted) |
| `style_paths` in schema order | Task 1 (`StyleTargets::stylePaths`, `StyleSchema::ordered`, two `order:` fixture cases), Task 3 (payload asserted with order) |
| Resets under forced preview; parity beyond one Button | Task 5 |

Type consistency:
- `effectivePaths`, `expandDeclaration` and `HOVER_OF` (Task 6) are used in Tasks 7 and 8 with those names.
- `ForceHoverRequest`, `StageHover.force(owner, request)` / `clear(owner)`, `hoverTargets`, `partScope` and `createStageHover` (Task 8) match Shared contracts. `createStageHover` adds `clearAny` and `resend` for the stage editor only.
- `BlockTypeStylePaths::for(array $row)` (Task 3) delegates to `StyleTargets::stylePaths` (Task 1).
- `StyleTargets::effective` (Task 1) is used in Tasks 2 and 3.

Placeholders: none. The orders inside `expansion.json`'s lists are schema order, asserted with `assertSame`. If the final table orders a list differently from what the fixture states, the executor corrects the fixture to the table and ledgers it.
