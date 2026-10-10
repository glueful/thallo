# Brand colour list (custom palette revision 5) — Implementation Plan

> Amended 2026-10-10 after plan review:
> - **A list edited from an older revision is refused (409), whatever it changes.**
>   - The stored list carries a `revision`, and a submitted list names its `base`. This is checked under the palette row (ruling 3, Task 4).
>   - It covers concurrent renames, re-colours, reorders, adds and Clears, not only adds.
> - **The editor takes a save's assigned ids and revision from the Save response** (ruling 11, Task 7).
>   - `useSettingsForm` gains `adopt()` and `saved(sent)`, so an edit made before the refetch saves cleanly and stays marked unsaved.
>   - A colour that leaves the stored list (Clear, a replacement completing) is dropped from the rows.
> - **Migration 048 reserves ids 1–3 on every workspace that used the palette** (an old key, a `palette_state` row or a `palette_jobs` row), named from the audit log where it can tell (ruling 12, Task 3).
>   - The revision-4 Clear deleted a slot's row, so a cleared slot left no setting behind.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three fixed brand slots with a list the author adds to, up to a deployment
limit (`theme.brand_colors.max`), each colour keeping a permanent id. The brand utilities move
out of the shared per-theme stylesheet into a per-workspace colours stylesheet.

**Architecture:**
- **Contract.** `Palette` (thallo-contracts) carries an ordered `id => BrandSlot` map, the removed ids' names, and the limit.
  - Everything that looped over `Palette::SLOTS` iterates `configured()` instead.
  - `slotOf()` accepts any id from 1 to 9999.
  - The colour vocabulary becomes a fixed list plus the `brand-N` pattern (`Vocabulary::isBrandColor()`). Validators accept the pattern through `isBaseline()`.
- **Colours stylesheet.**
  - `StyleCompiler` (v25) no longer emits brand utilities.
  - A new `ColorsArtifact` builds them for a workspace's configured ids from the compiler's own property table, and `ColorsArtifacts` publishes it per workspace, like `FontsArtifacts`.
  - `theme_colors_style()` links it, so no theme template changes.
- **Storage.**
  - One general setting, `theme_brand_colors` (`{"colors":[{id,name,hex}],"removed":[{id,name}]}`), parsed and encoded by `BrandColors`.
  - Saves resolve the submitted list against the held palette under the palette row: new ids, the limit, no omissions, no edits to a colour being replaced.
  - Clear and the Replace job move a colour to `removed`.
  - Migration 048 converts the revision-4 keys.
- **HTTP.**
  - Routes take `{id}`.
  - Preview claims carry a list.
  - The style schema adds `limit`, `order`, `removed` slots, `can_manage`, and the configured brand names in `vocabulary.domains.color`.
- **Admin.**
  - Appearance edits a list of rows: add, remove unsaved, drag to reorder, the count against the limit.
  - The colour pickers group brand colours, put their text colours behind a disclosure, link to `/appearance?tab=colours`, and name unavailable colours.

**Tech Stack:** PHP 8.4 (Glueful, PHPUnit), PostgreSQL, Twig, Vue 3 + Nuxt UI 4 (vitest, oxfmt, `pnpm type-check`), `vue-draggable-plus` (already a dependency), Playwright (`admin/e2e`, `tools/runtime-browser`).

**Spec:** `docs/internal/superpowers/specs/2026-10-09-custom-palette-design.md`, revision 5 (`4c43251d`), §1, §2, §2.3, §3.1, §3.2, §3.4, §4 (routes), §5.1, §5.2, §5.3, §7, §8, §9. The revision-4 machinery (fence, normaliser, usage, Replace job, restore basis, history ledger) is in place and keeps working. This plan changes only what assumes three slots.

**Depends on:** the Appearance tabs plan (`docs/internal/plans/2026-10-10-appearance-tabs-plan.md`). Task 7 edits its `TAB_KEYS`, and Task 8's link targets `?tab=colours`.

## Rulings made while planning (from the code)

1. **The limit lives on the `Palette` value** (`$palette->limit`), set by `PaletteSettings` from config.
   - `PaletteMutations`, the style schema and the render all already read the palette, so the limit reaches them with no new wiring.
   - `configured()` is empty at limit 0. The raw `brands` and `removed` maps stay for writers, so turning the limit back up restores the colours.
   - **Cost if wrong:** one more constructor argument where the limit is needed.
2. **A save over the limit is refused only when it adds a colour.**
   - Spec §2.3 says both "a save that would leave more colours than the limit is a 422" and "lowering the limit removes nothing; existing colours stay editable". With 5 colours and a limit of 3, a rename must succeed.
   - So the rule is: a save that adds a colour must leave at most `limit` colours.
3. **A save from a stale list is refused with a 409, never merged.** This is decided after plan review.
   - The stored list carries a `revision`, an integer that every write increments: a list save, Clear, and the Replace job's completion. The migration and an unset list start at 0.
   - A submitted list names the revision it was edited from (`base`). Under the palette row, `base` must equal the stored revision, else 409 `conflict`: "Brand colours changed since you opened this page — reload to see the latest".
   - This covers every concurrent change to existing rows, not only additions:
     - A renames Gold to Amber, and B re-colours Rose from an older form: B is refused, so Amber is never reverted.
     - The same holds for a concurrent re-colour, a reorder, an add, a Clear, or a completed replacement.
   - Ids stay distinct (spec §8, "two concurrent adds get distinct ids": the second save is refused).
   - The palette generation is not used for this. It also moves on neutral edits and job steps, which would refuse saves that touched nothing of each other's.
   - **Cost if wrong:** a reload when two people edit brand colours at the same moment.
4. **There is no general-settings import to apply the import rule to.**
   - `ContentImporter` imports content only, and the starter `SettingKind` writes `site_name`, `default_locale` and `listing_types`. Brand references inside imported content already go through the fence.
   - No code is written for §2.3's "Imports" bullet, and the ledger notes it.
   - **Cost if wrong:** a future settings importer must merge by id (`BrandColors::parse` + `encode` make that a few lines).
5. **The colours stylesheet is linked by `theme_colors_style()`, not by a new Twig function.**
   - Every theme layout already calls it after the compiled per-theme stylesheet (it already links the fonts stylesheet for layouts that predate it), so cloned and third-party themes get the link with no template edit.
6. **Colour properties are not responsive** (`StyleSchema`: `colors.*`, `aside.surface`, `marker.*`, `footer.divider_color` all have `responsive: false`).
   - So a later stylesheet's colour utilities never fight the per-theme artifact's breakpoint order.
   - The one ordering dependency is `colors.surface_opacity`, a modifier that must follow the surface utility. The colours stylesheet re-emits the opacity rules after its own surface rules.
7. **The render-cache and stage fingerprints need no new segment.**
   - `Palette::fingerprint()` already changes when the configured ids change, and the per-theme compiled hash (already in both fingerprints) carries `StyleCompiler::VERSION`. Together they determine the colours stylesheet's hash.
   - A test pins that adding and clearing a colour change `appearanceFingerprint()`.
8. **The style schema learns whether the reader may manage brand colours (`palette.can_manage`)** through the existing `Thallo\Contracts\Authorization\PermissionRequirementAuthority`, as `GET /fonts` does with `can_manage`.
   - The admin has no client-side permission store.
9. **Preview claims carry ids for unsaved rows.**
   - The admin numbers new rows provisionally from the highest id it has seen (colours, removed, pending), so the contrast rows can be labelled.
   - Those ids never reach storage, because the save assigns real ids.
10. **At limit 0, stored colours are absent from `palette.slots`, but their names stay in `palette.labels`.**
    - A stored reference then reads "Unavailable colour: Gold dark". "(removed)" is said only of a cleared colour, and "Brand 7" only of an id never issued here.
11. **The editor adopts a save's assigned ids from the Save response, not from a refetch.** This is decided after plan review.
    - `useSettingsForm` deliberately ignores refetched settings while the form is dirty. An edit made between Save and the refetch would leave a saved row at `id: null` and a stale revision, so the next save would add the colour twice (or be refused).
    - So the save response is reconciled into the form. Each submitted new row's key is mapped to the id at its position in the returned list, the revision is taken from the response, and newer edits are kept.
    - `useSettingsForm` gains `adopt()`, which writes without marking the form dirty, and `saved(sent)`, which marks it clean only if nothing changed since the payload was sent. The second fixes, for every field, today's loss of an edit's dirty state when it was made while a save was in flight.
    - A colour that leaves the stored list (a Clear, or a replacement completing) is dropped from the rows with `adopt()`.
12. **The migration reserves the revision-4 ids on any workspace that used the palette.** This is decided after plan review.
    - That build's Clear deleted the slot's settings row. So a cleared Brand 3 leaves no trace in settings, while retained content may still name `color.brand-3`.
    - Ids 1–3 that are not configured go to `removed` for any workspace with an old key, a `palette_state` row or a `palette_jobs` row. Fresh installs have none of these and start at `brand-1`.
    - The removed name is the `target_label` of the latest `palette_slot` audit entry for `brand-N`, on a single-site install (`audit_logs` carries no workspace), else "Brand N".
    - **Cost if wrong:** a development install's first new colour is `brand-4` rather than `brand-1`.

## Global Constraints

- Brand tokens: `color.brand-N` and `color.brand-N-contrast`, `N` from 1 to 9999. Pattern `/\Abrand-[1-9][0-9]{0,3}(-contrast)?\z/`. Values `var(--brand-N)` / `var(--brand-N-ink)`, site-controlled. A theme mapping one is ignored, and the Doctor warns.
- Setting `theme_brand_colors`: `{"revision": int, "colors": [{"id": int ≥ 1, "name": string ≤ 32, "hex": "#rrggbb"}, …], "removed": [{"id": int, "name": string}, …]}`.
  - `colors` is in display order. Unset means no colours and revision 0.
  - Every write increments `revision`.
  - A submitted list is `{"base": int, "colors": [{"id"?: int, "name", "hex"}, …]}`.
- Config: `theme.brand_colors.max` from `THALLO_BRAND_COLORS_MAX`, default 3, clamped 0–12. 0 turns brand colours off.
- New ids are one above the highest id ever issued (configured or removed), assigned inside the palette state transaction. Ids are never reused.
- A stale `base` is a 409 `conflict`: "Brand colours changed since you opened this page — reload to see the latest".
- Refusals, each a 422:
  - "This site allows 3 brand colours" (1 → "1 brand colour");
  - "Remove a brand colour with Clear";
  - "Gold dark isn't in the palette";
  - "Brand colours are turned off on this site".
- `StyleCompiler::VERSION` 24 → **25**. `Vocabulary::VERSION` stays **1**. `StyleSchema::VERSION` unchanged.
- Colours stylesheet:
  - file `colors-{hash}.css`, 16 hex chars, hash over the sorted configured ids, `StyleCompiler::VERSION` and `StyleSchema::VERSION`;
  - in `@layer settings`, published before it is linked;
  - retention: newest three plus anything younger than a day, per workspace directory;
  - nothing is linked when no colour is configured.
- Existing sites (no colour configured, family neutral): `themeColorsStyle()` is byte-identical and the rendered HTML is byte-identical, with the compiled-stylesheet URL normalised.
- Routes: `/v1/admin/appearance/palette/brand/{id}` (usage, clear, replace), `{id}` matching `[1-9][0-9]{0,3}`.
- Style schema `palette`:
  - `limit`, `order` (configured and replacing slots in author order), `can_manage`;
  - `slots` (`configured` | `replacing` | `removed`; never-issued ids absent; `reserved`; `replacing`);
  - `swatches`, `labels`, `color_mode`, `generation`, `replacements`.
- Admin copy:
  - "Add colour";
  - "2 of 3";
  - "This site allows 3 brand colours";
  - "Brand colours" (the group header);
  - "Text colours" (the disclosure);
  - "Manage brand colours" (link to `/appearance?tab=colours`, shown only with `content.manage`);
  - "Unavailable colour: Teal (removed)" / "Unavailable colour: Brand 7";
  - "No colour applied".
- PHP gates:
  - `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH` first;
  - phpcs judged by its exit code (warnings fail CI);
  - `composer test:reset-db && composer test:migrate` immediately before Unit+Feature;
  - integration shards run one at a time and attached, never concurrently with another run;
  - every new `tests/Integration` file is listed in exactly one `INTEGRATION_SHARD_*` in `.github/workflows/ci.yml`;
  - the tenancy harness runs one file per process with `THALLO_TENANCY_DEV_LINK=1`.
- Admin gates: `pnpm test`, `pnpm type-check`, `pnpm lint`, `pnpm exec oxfmt --check <touched files>`.
- `docs/openapi.json`: hand-splice changed operations only (regenerate with `CACHE_DRIVER=array` into a scratch copy to read from). Never commit a wholesale regeneration.
- Changelog: edit the existing `[Unreleased]` custom-palette bullets in the same commit as the change (this release has not shipped, so the three-slot wording is rewritten, not contradicted).
- Commits carry no AI attribution lines. Never push. `$SCRATCH` means the session scratchpad: long output goes there.

## Review Focus

1. **A client whose list is stale saves:** someone else renamed, re-coloured, reordered, added or cleared meanwhile. It must get a 409 and change nothing, never revert the other person's edit, drop a colour or reuse an id. Tested in Task 4 (`testAStaleListIsRefusedWhateverChangedMeanwhile`).
2. **Raising the limit back after lowering it to 0 must bring the same colours back**, with the same ids and order. `configured()` is limit-gated and storage is not. Tested in Task 3 (`testLimitZeroHidesColoursAndKeepsThem`).
3. **A block with a brand background and 50% surface opacity** must still mix, even though the brand utility now loads after the opacity rule's original position. Tested in Task 2 (`testOpacityStillModifiesABrandSurface`).
4. **A restored version naming a cleared id, or an imported id never issued here,** shows "(removed)" or "Brand 7" and renders no colour. The id is never confused with a later colour. Tested in Task 3 (`testANewColourNeverTakesAClearedId`) and Task 8 (picker wording).
5. **The pickers and the colours stylesheet for an id above 3** (`brand-12`): validator, normaliser, `token_class()`, stylesheet and picker must all treat it like `brand-1`. Tested in Task 1 (normaliser, `slotOf`), Task 2 (validator, `token_class`, stylesheet) and Task 8 (picker).
6. **Save, then edit before the refetch arrives.** The next save must send the assigned id and the new revision, and the edit must stay marked unsaved. Tested in Task 7 (`'adopts the assigned ids from the save response…'`).
7. **A development workspace whose Brand 3 was cleared under revision 4** must never hand id 3 to a new colour, so a restored old reference stays unavailable. Tested in Task 3 (`testAClearedRevisionFourSlotIsNeverReissued`).

---

### Task 1: The palette contract takes any id

**Files:**
- Modify: `packages/thallo-contracts/src/Style/Palette.php`
- Modify: `packages/thallo-render/src/Theme/ThemeColors.php` (`paletteCss`, ~L156–182)
- Modify: `packages/thallo-render/src/Theme/EffectivePalette.php` (`of` ~L52–59, `contrastRows` ~L81–86)
- Modify: `core/src/Content/Palette/PaletteNormalizer.php` (~L51–67: the two "Brand {$slot}" messages)
- Test: `tests/Unit/Contracts/PaletteTest.php`, `tests/Unit/Render/EffectivePaletteTest.php`, `tests/Unit/Content/Palette/PaletteNormalizerTest.php`

**Interfaces:**
- Produces (contracts, `Thallo\Contracts\Style\Palette`):
  - `const MAX_ID = 9999; const DEFAULT_LIMIT = 3; const LIMIT_CEILING = 12;`
  - `const SLOTS` **stays for now** (Task 3 deletes it with its last users: `PaletteSettings`, `PaletteMutations`, `GeneralSettingsController`).
  - `public readonly array $brands` — `array<int,BrandSlot>`, id => colour, author order, raw (ignores the limit)
  - `public readonly array $removed` — `array<int,string>`, cleared id => its name
  - `public readonly int $limit`
  - `__construct(?array $customNeutral = null, ?string $darkBase = null, array $brands = [], array $removed = [], int $limit = self::DEFAULT_LIMIT)` (null entries in `$brands` are dropped)
  - `configured(): array<int,BrandSlot>` (`[]` at limit 0), `ids(): list<int>`, `brand(int): ?BrandSlot`, `isConfigured(int): bool`, `isRemoved(int): bool`, `labelOf(int): string`, `highestIssued(): int`
  - `static slotOf(string $token): ?int` (1–9999), `static isContrastToken`, `isUnavailable`, `isEmpty`, `fingerprint`
  - `withBrands()` is **deleted** (no production caller).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Contracts/PaletteTest.php` (keep its existing tests, adjusting any constructor
call that passes `[1 => …, 2 => null, 3 => null]` to pass only configured ids):

```php
public function testAnyIdUpTo9999IsABrandSlot(): void
{
    self::assertSame(12, Palette::slotOf('color.brand-12'));
    self::assertSame(9999, Palette::slotOf('color.brand-9999-contrast'));
    self::assertNull(Palette::slotOf('color.brand-0'));
    self::assertNull(Palette::slotOf('color.brand-10000'));
    self::assertNull(Palette::slotOf('color.brand-01'));
    self::assertTrue(Palette::isContrastToken('color.brand-12-contrast'));
}

public function testColoursKeepTheAuthorsOrderAndRemovedIdsKeepTheirNames(): void
{
    $p = new Palette(null, null, [7 => new BrandSlot('Rose', '#c98a8a'), 2 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal']);
    self::assertSame([7, 2], $p->ids());
    self::assertSame('Rose', $p->labelOf(7));
    self::assertSame('Teal', $p->labelOf(3));
    self::assertSame('Brand 9', $p->labelOf(9));
    self::assertTrue($p->isRemoved(3));
    self::assertFalse($p->isRemoved(7));
    self::assertSame(7, $p->highestIssued());
    self::assertTrue($p->isUnavailable('color.brand-3'));
    self::assertFalse($p->isUnavailable('color.brand-7-contrast'));
}

public function testLimitZeroConfiguresNothingButKeepsTheStoredColours(): void
{
    $p = new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a')], [], 0);
    self::assertSame([], $p->configured());
    self::assertFalse($p->isConfigured(1));
    self::assertTrue($p->isUnavailable('color.brand-1'));
    self::assertSame(['Gold'], array_map(static fn (BrandSlot $b): string => $b->name, $p->brands));
    self::assertTrue($p->isEmpty());
    self::assertSame('', $p->fingerprint());
}

public function testColoursAboveALoweredLimitStillApply(): void
{
    // lowering the limit removes nothing (§2.3): the four made under a higher limit keep rendering
    $four = [];
    foreach ([1, 2, 3, 4] as $id) {
        $four[$id] = new BrandSlot("C{$id}", '#123456');
    }
    self::assertSame([1, 2, 3, 4], (new Palette(null, null, $four, [], 3))->ids());
}

public function testTheFingerprintFollowsOrderAndValuesButNotRemovedNames(): void
{
    $a = new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
    $b = new Palette(null, null, [2 => new BrandSlot('Rose', '#c98a8a'), 1 => new BrandSlot('Gold', '#8a6a2a')]);
    $c = new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')], [3 => 'Teal']);
    self::assertNotSame($a->fingerprint(), $b->fingerprint());
    self::assertSame($a->fingerprint(), $c->fingerprint());
    self::assertSame('', Palette::empty()->fingerprint());
}
```

Add to `tests/Unit/Render/EffectivePaletteTest.php`:

```php
public function testEveryConfiguredIdGetsValuesAndContrastRowsInOrder(): void
{
    $palette = new Palette(null, null, [12 => new BrandSlot('Teal', '#0f766e'), 4 => new BrandSlot('Gold', '#8a6a2a')]);
    $effective = EffectivePalette::of('blue', 'slate', 'plain', $palette);
    self::assertSame('#0f766e', $effective->values('light')['brand-12']);
    self::assertArrayHasKey('brand-4-contrast', $effective->values('dark'));
    $fgs = array_values(array_unique(array_map(
        static fn (array $r): string => $r['fg'],
        array_filter($effective->contrastRows(), static fn (array $r): bool => str_starts_with($r['fg'], 'brand-')),
    )));
    self::assertSame(['brand-12', 'brand-12-contrast', 'brand-4', 'brand-4-contrast'], $fgs);
}
```

Add a case to `tests/Unit/Content/Palette/PaletteNormalizerTest.php`. Follow the file's existing
construction of the normaliser and its basis helper. A palette with `[3 => 'Teal']` removed and
nothing configured, normalising a heading that names `color.brand-3` with no basis, gives the error
`"Teal isn't in the palette"`. The same with `color.brand-12` (never issued) gives
`"Brand 12 isn't in the palette"`. With a basis holding `color.brand-12` at that location, it is
accepted unchanged.

Add one case to `packages/thallo-render`'s `ThemeColors` test (`tests/Integration/Render/PaletteCssTest.php`):
a palette with ids `[5, 2]` emits `--brand-5` before `--brand-2` in both blocks, and none for a
removed id.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/Contracts/PaletteTest.php tests/Unit/Render/EffectivePaletteTest.php tests/Unit/Content/Palette/PaletteNormalizerTest.php tests/Integration/Render/PaletteCssTest.php`
Expected: FAIL. `slotOf('color.brand-12')` returns null, and `ids()`, `labelOf()`, `isRemoved()`,
`highestIssued()` and `configured()` are undefined. The constructor rejects the fourth and fifth
arguments.

- [ ] **Step 3: Implement**

`packages/thallo-contracts/src/Style/Palette.php`. Replace the class body with:

```php
/**
 * The site's own colours beyond the families (custom palette spec §2): a Custom neutral, the
 * dark-mode base family it uses, and the brand colours — a list the author adds to, each with a
 * permanent id (`brand-4`) that is never reused. Stored references name the id token
 * (`color.brand-4`), never the author's label; a reference to an id that is not configured —
 * removed, never issued, or any id while the limit is 0 — is valid data that renders no colour (§3.2).
 */
final class Palette
{
    /** Retired with the fixed slots: PaletteSettings, PaletteMutations and the settings controller still read it until the list setting lands. */
    public const SLOTS = [1, 2, 3];
    public const NEUTRAL_KEYS = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line'];
    /** Brand ids run from 1 to this: `brand-1` … `brand-9999` (§3.1). */
    public const MAX_ID = 9999;
    /** How many brand colours a workspace may have when the deployment says nothing (§1). */
    public const DEFAULT_LIMIT = 3;
    /** The most a deployment may allow: each colour costs every picker a choice and the colours stylesheet its utilities. */
    public const LIMIT_CEILING = 12;

    /** @var array<int,BrandSlot> id => colour, in the author's order, whatever the limit */
    public readonly array $brands;

    /**
     * @param array<string,string>|null $customNeutral NEUTRAL_KEYS => #rrggbb, null when unset
     * @param array<int,BrandSlot|null> $brands id => colour in the author's order; nulls are dropped
     * @param array<int,string> $removed cleared ids => the name each had
     * @param int $limit the deployment's limit (0 turns brand colours off)
     */
    public function __construct(
        public readonly ?array $customNeutral = null,
        public readonly ?string $darkBase = null,
        array $brands = [],
        public readonly array $removed = [],
        public readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->brands = array_filter($brands, static fn (?BrandSlot $b): bool => $b !== null);
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @return array<int,BrandSlot> the colours that apply: none while the limit is 0 */
    public function configured(): array
    {
        return $this->limit > 0 ? $this->brands : [];
    }

    /** @return list<int> the configured ids in the author's order */
    public function ids(): array
    {
        return array_keys($this->configured());
    }

    public function brand(int $slot): ?BrandSlot
    {
        return $this->configured()[$slot] ?? null;
    }

    public function isConfigured(int $slot): bool
    {
        return $this->brand($slot) !== null;
    }

    public function isRemoved(int $slot): bool
    {
        return isset($this->removed[$slot]) && !isset($this->brands[$slot]);
    }

    /** The name an id is known by: its colour's, the name it had when cleared, else "Brand N". */
    public function labelOf(int $slot): string
    {
        return $this->brands[$slot]->name ?? $this->removed[$slot] ?? "Brand {$slot}";
    }

    /** The highest id this workspace has issued, configured or removed; 0 for none. */
    public function highestIssued(): int
    {
        return max([0, ...array_keys($this->brands), ...array_keys($this->removed)]);
    }

    /** The id of color.brand-N / color.brand-N-contrast (N from 1 to 9999), else null. */
    public static function slotOf(string $token): ?int
    {
        return preg_match('/\Acolor\.brand-([1-9][0-9]{0,3})(?:-contrast)?\z/', $token, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isContrastToken(string $token): bool
    {
        return self::slotOf($token) !== null && str_ends_with($token, '-contrast');
    }

    /** True for a brand token whose id is not configured. */
    public function isUnavailable(string $token): bool
    {
        $slot = self::slotOf($token);
        return $slot !== null && !$this->isConfigured($slot);
    }

    /** Nothing applies: an existing site before its author opts in. */
    public function isEmpty(): bool
    {
        return $this->customNeutral === null && $this->darkBase === null && $this->configured() === [];
    }

    /** '' when empty (existing fingerprints stay unchanged), else sha1 of the canonical JSON. */
    public function fingerprint(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $brands = [];
        foreach ($this->configured() as $id => $brand) {
            $brands[] = [$id, $brand->name, $brand->hex];
        }
        return sha1((string) json_encode([$this->customNeutral, $this->darkBase, $brands]));
    }
}
```

`ThemeColors::paletteCss`:
- replace `if (!$custom && array_filter($palette->brands) === [])` with `if (!$custom && $palette->configured() === [])`;
- replace the `foreach (Palette::SLOTS as $slot) { $brand = $palette->brand($slot); if ($brand === null) { continue; } …` loop with `foreach ($palette->configured() as $slot => $brand) { … }` (body otherwise unchanged);
- update the docblock: "each configured brand colour emits its fill and ink in both modes, in the author's order; a removed or never-issued id emits nothing".

`EffectivePalette::of`: the same loop change (`foreach ($palette->configured() as $slot => $brand)`).
`contrastRows`: `foreach ($this->palette->ids() as $slot) { $pairs[] = ["brand-{$slot}", 'background']; $pairs[] = ["brand-{$slot}-contrast", "brand-{$slot}"]; }`.

`PaletteNormalizer` (~L51–67): the two messages use the palette's label instead of
`"Brand {$slot}"`: `"Text on {$palette->labelOf($slot)} has no replacement…"` (keep the rest of that
sentence) and `"{$palette->labelOf($slot)} isn't in the palette"`. Use whichever variable holds the
palette in that method (the snapshot's `->palette`).

Then `grep -rn "withBrands" core packages tests --include='*.php'`, and replace each test use with
the constructor.

- [ ] **Step 4: Run them to see them pass, then the palette suites**

Run: `vendor/bin/phpunit tests/Unit/Contracts tests/Unit/Render tests/Unit/Content/Palette tests/Integration/Render/PaletteCssTest.php tests/Integration/Render/ThemeColorsStyleTest.php tests/Integration/Render/PagePaletteAvailabilityTest.php > "$SCRATCH/t1.log" 2>&1; tail -5 "$SCRATCH/t1.log"`
Expected: OK. A failure that pins the old three-key constructor shape is fixed in the test (pass
only configured ids). Ledger each one.

- [ ] **Step 5: phpcs and commit**

Run: `vendor/bin/phpcs packages/thallo-contracts/src/Style/Palette.php packages/thallo-render/src/Theme core/src/Content/Palette/PaletteNormalizer.php; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add packages/thallo-contracts/src/Style/Palette.php packages/thallo-render/src/Theme core/src/Content/Palette/PaletteNormalizer.php tests
git commit -m "feat(palette): the palette contract takes any brand id from 1 to 9999 — ordered colours, removed ids keep their names, a limit (0 configures nothing)"
```

### Task 2: Brand names become a family; their utilities move to the colours stylesheet

**Files:**
- Modify: `packages/thallo-contracts/src/Style/Vocabulary.php` (`DOMAINS['color']`, delete `SITE_CONTROLLED`, add `isBrandColor` / `siteControlled`, `isBaseline`)
- Modify: `packages/thallo-render/src/Style/ThemeVocabulary.php` (~L58–60)
- Modify: `packages/thallo-render/src/Style/StyleCompiler.php` (VERSION 25, new `colorUtilities()`)
- Create: `packages/thallo-render/src/Style/ColorsArtifact.php`, `packages/thallo-render/src/Style/ColorsArtifacts.php`
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (constructor, new `colorsStylesheetUrl()`, `themeColorsStyle()` ~L1085, `tokenClass()` ~L810)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (register `ColorsArtifacts` beside `FontsArtifacts` ~L183 and ~L661; pass to the extension ~L849 and `RenderController`)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (`asset()` ~L935, `previewAsset()` ~L1016)
- Modify: `core/src/Content/Validation/FieldValidator.php` (`checkToken` ~L491)
- Modify: `core/src/Content/Palette/PaletteReplaceService.php` (`assertDestination` ~L154)
- Test: `tests/Unit/Render/StyleCompilerTest.php`, `tests/Unit/Render/ThemeVocabularyTest.php`, `tests/Unit/Render/ColorsArtifactTest.php` (new), `tests/Integration/Render/ColorsStylesheetTest.php` (new), `tests/Unit/Contracts/VocabularyTest.php` (or the file pinning `Vocabulary::names('color')`; find it with `grep -rln "names('color')" tests`)

**Interfaces:**
- Consumes: `Palette::ids()`, `Palette::configured()` (Task 1).
- Produces:
  - `Vocabulary::BRAND_COLOR` (the pattern), `Vocabulary::isBrandColor(string $name): bool`, `Vocabulary::siteControlled(string $token): ?string`. `isBaseline()` is true for brand tokens of the colour domain.
  - `StyleCompiler::VERSION = 25`; `StyleCompiler::colorUtilities(list<string> $names): string`
  - `ColorsArtifact::compile(list<int> $ids): string`, `ColorsArtifact::hash(list<int> $ids): string`
  - `ColorsArtifacts` (`forIds(list<int>): array{hash: string, css: string}`, `read(string $hash): ?string`, `static fileName(string): string`, `static hashFromFileName(string): ?string`)
  - `RenderContextExtension::colorsStylesheetUrl(?Palette $palette = null): ?string`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Render/ColorsArtifactTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Style\ColorsArtifact;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeVocabulary;

/**
 * The workspace's colours stylesheet (custom palette spec §3.4): the compiler's colour utilities for
 * each configured brand id, from the same property table, in @layer settings; its hash depends only
 * on which ids are configured.
 */
final class ColorsArtifactTest extends TestCase
{
    public function testItCarriesEveryColourUtilityTheCompilerWritesForAColourName(): void
    {
        // The drift guard: what the compiler writes for `accent`, renamed, is what the stylesheet
        // writes for a brand id — every resting and hover rule, nothing missing.
        $vocabulary = ThemeVocabulary::fromThemeJson(
            json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/theme.json'), true),
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default',
        );
        $compiled = StyleCompiler::compile($vocabulary);
        $accent = array_filter(
            explode("\n", StyleCompiler::colorUtilities(['accent'])),
            static fn (string $line): bool => str_contains($line, 'color-accent') && !str_contains($line, 'contrast'),
        );
        self::assertNotEmpty($accent);
        foreach ($accent as $line) {
            self::assertStringContainsString($line, $compiled, 'compile() writes this accent rule too');
        }
        $brand = StyleCompiler::colorUtilities(['brand-12']);
        self::assertSame(
            count($accent),
            count(array_filter(explode("\n", $brand), static fn (string $l): bool => str_contains($l, 'color-brand-12'))),
        );
    }

    public function testItDefinesEachIdsVariablesAndUtilitiesInTheSettingsLayer(): void
    {
        $css = ColorsArtifact::compile([4, 12]);
        self::assertStringStartsWith('@layer settings {', $css);
        self::assertStringContainsString('--t-color-brand-4: var(--brand-4);', $css);
        self::assertStringContainsString('--t-color-brand-12-contrast: var(--brand-12-ink);', $css);
        self::assertStringContainsString('.t-surface-color-brand-12', $css); // background utility
        self::assertStringNotContainsString('brand-1-', $css);
    }

    public function testOpacityStillModifiesABrandSurface(): void
    {
        // A modifier must follow the utility it modifies: the opacity rules come after the brand
        // surface rules inside this stylesheet, which loads after the per-theme one.
        $css = ColorsArtifact::compile([4]);
        $surface = strpos($css, 'background: var(--t-color-brand-4)');
        $opacity = strpos($css, 'color-mix(in srgb, var(--t-surface');
        self::assertIsInt($surface);
        self::assertIsInt($opacity);
        self::assertGreaterThan($surface, $opacity);
    }

    public function testTheHashDependsOnlyOnWhichIdsAreConfigured(): void
    {
        self::assertSame(ColorsArtifact::hash([2, 4]), ColorsArtifact::hash([4, 2]));
        self::assertNotSame(ColorsArtifact::hash([2, 4]), ColorsArtifact::hash([2, 4, 5]));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', ColorsArtifact::hash([1]));
        self::assertSame('', ColorsArtifact::compile([]));
    }
}
```

Check the utility class stem. `ClassNames::for('colors.surface', 'color.brand-12')` gives
`t-<stem>-color-brand-12`. Assert on whatever stem `ClassNames::STEMS['colors.surface']` holds;
the test above assumes `surface`. Adjust the literal in the same step if it differs.

In `tests/Unit/Render/StyleCompilerTest.php`, replace the revision-4 assertions that brand-1…3
utilities are in the compiled artifact with:

```php
public function testThePerThemeArtifactCarriesNoBrandUtilities(): void
{
    $css = StyleCompiler::compile($this->defaultVocabulary()); // the file's existing helper; use what it has
    self::assertStringNotContainsString('brand-', $css);
    self::assertSame(25, StyleCompiler::VERSION);
}
```

In the vocabulary test file:

```php
public function testBrandColoursAreAFamilyNotAList(): void
{
    self::assertNotContains('brand-1', Vocabulary::names('color'));
    self::assertTrue(Vocabulary::isBaseline('color.brand-12'));
    self::assertTrue(Vocabulary::isBaseline('color.brand-12-contrast'));
    self::assertFalse(Vocabulary::isBaseline('color.brand-0'));
    self::assertFalse(Vocabulary::isBaseline('spacing.brand-1'));
    self::assertSame('var(--brand-12)', Vocabulary::siteControlled('color.brand-12'));
    self::assertSame('var(--brand-12-ink)', Vocabulary::siteControlled('color.brand-12-contrast'));
    self::assertNull(Vocabulary::siteControlled('color.accent'));
}
```

In `tests/Unit/Render/ThemeVocabularyTest.php`, change the existing "a theme mapping a brand token
is ignored" test to map `color.brand-7`, and assert `ignored()` lists it and `values()` has no
`color.brand-7` key.

`tests/Integration/Render/ColorsStylesheetTest.php` (new; add it to an `INTEGRATION_SHARD_*` next
to `PaletteCssTest`). Build on `PagePaletteAvailabilityTest`'s page-render idiom (copy its setup
for a published page with a heading and its `render()` helper):
- **`testAPageLinksTheColoursStylesheetOnlyWhenAColourIsConfigured`:**
  - with no colour configured, the HTML has no `colors-` link;
  - after `configure(1, 'Gold', '#8a6a2a')`, the HTML has one `<link rel="stylesheet" href="/theme-assets/colors-<16 hex>.css">`, after the compiled `settings-` stylesheet link and before the `<style>` that `theme_colors_style()` writes;
  - fetching that path through the asset route returns 200, `text/css`, and contains `t-` utilities for `brand-1`.
- **`testAClassForABrandIdAboveThreeIsWritten`:** a heading with text colour `color.brand-12`, where 12 is configured, renders its utility class. With 12 unconfigured, there is no class. Also, `token_class('colors.text', 'color.brand-12')` returns `''` when 12 is unconfigured and the class when it is.
- **`testTheValidatorAcceptsAnyBrandId`:** saving a draft whose heading names `color.brand-12` passes `SettingsValidator` (the fence then judges it). A `FieldValidator` token field with `color.brand-12` passes `checkToken`.
- **`testTwoWorkspacesGetTheirOwnColoursStylesheet`:** only if the file can reuse `FontsArtifactsTenancyTest`'s two-workspace setup (find it with `grep -rln "FontsArtifacts" tests`). Otherwise put this test in a copy of that file named `ColorsArtifactsTenancyTest.php` and run it in the tenancy harness. Two workspaces with different configured ids get different `colors-*.css` files in different directories, and the per-theme `settings-*.css` hash is the same for both.
- **`testAddingOrClearingAColourChangesTheStageFingerprint`:** `appearanceFingerprint()` differs after `configure(1, …)` and again after `clear(1)`. Re-colouring changes the palette fingerprint too, so assert only that add and clear change it.

The fixture's `configure()` still writes `theme_brand_N` until Task 3. Use ids 1–3 in this
task's integration tests where the fixture needs them, and `color.brand-12` only through
`Vocabulary`, `token_class()` with a hand-built `Palette`, or `ColorsArtifact`. Task 3 adds a
`brand-12` page test once ids are unbounded in storage.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/Render/ColorsArtifactTest.php tests/Unit/Render/StyleCompilerTest.php tests/Unit/Render/ThemeVocabularyTest.php tests/Unit/Contracts tests/Integration/Render/ColorsStylesheetTest.php`
Expected: FAIL. `ColorsArtifact` is not found, `colorUtilities()` is undefined, the compiled artifact
still carries `brand-1` utilities, `isBaseline('color.brand-12')` is false, and no colours link is
present.

- [ ] **Step 3: Implement the vocabulary**

`Vocabulary.php`:
- Remove the six `brand-*` names from `DOMAINS['color']`.
- Delete `SITE_CONTROLLED`.
- Add:

```php
    /** A brand colour's name (custom palette spec §3.1): `brand-N` or `brand-N-contrast`, N from 1 to 9999. */
    public const BRAND_COLOR = '/\Abrand-[1-9][0-9]{0,3}(-contrast)?\z/';

    /** Whether a colour name is a brand colour's: a family of names, not a list. */
    public static function isBrandColor(string $name): bool
    {
        return preg_match(self::BRAND_COLOR, $name) === 1;
    }

    /**
     * The value a brand token always has (custom palette spec §3.1): the variables
     * themeColorsStyle() emits from the site's settings. Null for any other token. A theme's
     * mapping for a brand token is ignored, so no theme can bypass the site's hex, its swatches
     * or its contrast checks.
     */
    public static function siteControlled(string $token): ?string
    {
        if (!str_starts_with($token, 'color.') || !self::isBrandColor($name = substr($token, 6))) {
            return null;
        }
        return str_ends_with($name, '-contrast')
            ? 'var(--' . substr($name, 0, -strlen('-contrast')) . '-ink)'
            : "var(--{$name})";
    }
```

`isBaseline()` ends with:

```php
        return in_array($name, self::DOMAINS[$domain], true) || ($domain === 'color' && self::isBrandColor($name));
```

Update the class docblock line "A document may reference baseline names only" to add "— brand
colours by their family's pattern".

`ThemeVocabulary::fromThemeJson`, replacing L58–60:

```php
        // The brand colours are the site's (custom palette spec §3.1): a theme's mapping is ignored.
        $ignored = array_values(array_filter(
            array_map('strval', array_keys($vocabulary)),
            static fn (string $token): bool => Vocabulary::siteControlled($token) !== null,
        ));
        foreach ($ignored as $token) {
            unset($vocabulary[$token]);
        }
```

The `values()` / `Vocabulary::all()` loop no longer lists brand tokens, so nothing else changes
there.

`FieldValidator::checkToken`, replacing the `in_array` test:

```php
        if ($name === null || !Vocabulary::isBaseline($value['value'])) {
```

`RenderContextExtension::tokenClass`, replacing the `$valid` token branch:

```php
        $valid = $def->tokenDomain !== null
            ? str_starts_with($value, $def->tokenDomain . '.') && Vocabulary::isBaseline($value)
            : in_array($value, $def->choices ?? [], true);
```

`PaletteReplaceService::assertDestination`, replacing the `$names` lines:

```php
        if (Vocabulary::domain($token) !== 'color' || !Vocabulary::isBaseline($token)) {
            throw new \InvalidArgumentException("{$field}: {$token} is not a colour");
        }
```

Its later `"Brand {$destination} is not configured"` / `"is being replaced"` messages use
`$read->palette->labelOf($destination)`.

- [ ] **Step 4: Implement the compiler and the colours stylesheet**

`StyleCompiler`: add `// 25: brand colours leave the per-theme artifact for each workspace's colours
stylesheet (custom palette spec §3.4).` and `public const VERSION = 25;`. Add after `variable()`:

```php
    /**
     * The colour utilities for some colour names (custom palette spec §3.4): each colour property's
     * resting rule and each colour hover rule, from the same property table and declarations
     * compile() writes — so the colours stylesheet and the per-theme artifact cannot drift — then
     * the surface opacity rules again, because a modifier must follow the utility it modifies.
     * Colour properties are not responsive: one breakpoint is all there is.
     *
     * @param list<string> $names colour names, e.g. `brand-4`
     */
    public static function colorUtilities(array $names): string
    {
        $tokens = array_map(static fn (string $name): string => "color.{$name}", $names);
        $out = '';
        foreach (StyleSchema::properties() as $path => $def) {
            if ($def->tokenDomain !== 'color' || $def->responsive || StyleSchema::restingPathOf($path) !== null) {
                continue;
            }
            foreach ($tokens as $value) {
                $out .= ClassNames::selector(ClassNames::for($path, $value)) . ' { ' . self::declarations($path, $value) . " }\n";
            }
        }
        foreach (StyleSchema::property('colors.surface_opacity')?->choices ?? [] as $value) {
            $out .= ClassNames::selector(ClassNames::for('colors.surface_opacity', $value))
                . ' { ' . self::declarations('colors.surface_opacity', $value) . " }\n";
        }
        $pointer = '';
        $other = '';
        foreach (StyleSchema::HOVER as $hover => $resting) {
            if (StyleSchema::property($hover)?->tokenDomain !== 'color') {
                continue;
            }
            foreach ($tokens as $value) {
                $selector = ClassNames::selector(ClassNames::for($hover, $value));
                $declarations = self::declarations($resting, $value);
                $pointer .= "{$selector}:hover { {$declarations} }\n";
                $other .= "{$selector}:focus-visible, {$selector}[data-thallo-hover] { {$declarations} }\n";
            }
        }
        return $out . "@media (hover: hover) {\n{$pointer}}\n{$other}";
    }
```

If a property line exceeds phpcs's limit, wrap it the way the surrounding methods do.

`packages/thallo-render/src/Style/ColorsArtifact.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\Vocabulary;

/**
 * A workspace's colours stylesheet (custom palette spec §3.4): for each configured brand id, its two
 * variables and the compiler's colour utilities for `brand-N` and `brand-N-contrast`, in
 * `@layer settings`. Values stay in variables, so the bytes depend only on which ids are configured:
 * renaming or re-colouring changes nothing here, adding or clearing a colour makes a new stylesheet.
 */
final class ColorsArtifact
{
    /** @param list<int> $ids */
    public static function compile(array $ids): string
    {
        $ids = self::sorted($ids);
        if ($ids === []) {
            return '';
        }
        $vars = '';
        $names = [];
        foreach ($ids as $id) {
            foreach (["brand-{$id}", "brand-{$id}-contrast"] as $name) {
                $vars .= '  ' . StyleCompiler::variable("color.{$name}") . ': '
                    . Vocabulary::siteControlled("color.{$name}") . ";\n";
                $names[] = $name;
            }
        }
        return "@layer settings {\n:root {\n{$vars}}\n" . StyleCompiler::colorUtilities($names) . "}\n";
    }

    /** 16 hex characters over the sorted ids and the versions that decide the bytes. */
    public static function hash(array $ids): string
    {
        return substr(hash('sha256', (string) json_encode([
            'ids' => self::sorted($ids),
            'compiler' => StyleCompiler::VERSION,
            'settings' => StyleSchema::VERSION,
        ])), 0, 16);
    }

    /** @param list<int> $ids @return list<int> */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }
}
```

`packages/thallo-render/src/Style/ColorsArtifacts.php`: a copy of `FontsArtifacts` with these
changes:
- the docblock names the colours stylesheet (§3.4), `storage/cache/style/colors`, and `colors-{hash}.css`;
- `forSnapshot(FontLibrarySnapshotView $snapshot)` becomes `forIds(array $ids): array`, which computes `$css = ColorsArtifact::compile($ids)` and `$hash = ColorsArtifact::hash($ids)` (the hash is not over the bytes), and returns `['hash' => $hash, 'css' => $css]` without publishing when `$css === ''`;
- `fileName()` returns `"colors-{$hash}.css"`, and `hashFromFileName()` matches `/\Acolors-([0-9a-f]{16})\.css\z/`;
- the exception messages say "colours";
- prune globs `colors-*.css`.

`RenderServiceProvider`:
- Register `ColorsArtifacts::class => ['shared' => true, 'factory' => [self::class, 'makeColorsArtifacts']]` beside `FontsArtifacts`.
- `makeColorsArtifacts()` copies `makeFontsArtifacts()` with base `storage/cache/style/colors` and segment name `'colors'`.
- Pass `colorsArtifacts: $container->get(ColorsArtifacts::class)` to the extension (beside `fontsArtifacts:` ~L849) and to `RenderController` (find its factory's `fontsArtifacts` argument and add the colours one after it).

`RenderContextExtension`:
- Constructor: add after `$paletteRequest`:

```php
        /** The workspace's colours stylesheets (custom palette spec §3.4): null → none is linked. */
        private readonly ?\Thallo\Render\Style\ColorsArtifacts $colorsArtifacts = null,
```

- Method (after `fontsStylesheetUrl()`):

```php
    /**
     * The colours stylesheet for this render's configured brand ids (custom palette spec §3.4),
     * published before it is returned; null when no colour is configured (nothing to link).
     */
    public function colorsStylesheetUrl(?\Thallo\Contracts\Style\Palette $palette = null): ?string
    {
        if ($this->colorsArtifacts === null) {
            return null;
        }
        $artifact = $this->colorsArtifacts->forIds(($palette ?? $this->palette())->ids());
        if ($artifact['css'] === '') {
            return null;
        }
        return ($this->assetBase ?? '/theme-assets') . '/' . \Thallo\Render\Style\ColorsArtifacts::fileName($artifact['hash']);
    }
```

- `themeColorsStyle()`: after the fonts-link block and before `return`, link the colours
  stylesheet first in the returned HTML, using the `$palette` this method already resolved (the
  preview's, when a preview claims one):

```php
        // The brand utilities (custom palette spec §3.4): every theme layout calls this after the
        // compiled stylesheet, so the colours stylesheet needs no template of its own.
        $colors = $this->colorsStylesheetUrl($palette);
        if ($colors !== null) {
            $html = '<link rel="stylesheet" href="' . htmlspecialchars($colors, ENT_QUOTES, 'UTF-8') . '">' . $html;
        }
```

Check the order: the fonts link is prepended before this block runs, so the colours link ends up
first. That is fine, since both are in `@layer settings` and neither depends on the other.

`RenderController::asset()`:
- Add `$colors = \Thallo\Render\Style\ColorsArtifacts::hashFromFileName($path);` to the hash checks and to the `if (… || $colors !== null)` condition.
- Add `$colors !== null => $this->colorsArtifacts?->read($colors),` to the `match` before `default`.
- Mirror the fonts on-demand publish: `if ($css === null && $colors !== null && $this->colorsArtifacts !== null) { $this->extension->colorsStylesheetUrl(); $css = $this->colorsArtifacts->read($colors); }`.
- Add the constructor parameter `private readonly ?\Thallo\Render\Style\ColorsArtifacts $colorsArtifacts = null,` after `$fontsArtifacts`.

`previewAsset()`:
- Add the same `hashFromFileName` check.
- For a colours hash, publish the current palette's stylesheet through `$this->extension->colorsStylesheetUrl()`, then `read()` it.

- [ ] **Step 5: Run the tests to see them pass, then the render and palette suites**

Run: `vendor/bin/phpunit tests/Unit/Render tests/Unit/Contracts tests/Unit/Setup/DoctorTest.php tests/Integration/Render tests/Integration/Content/Palette > "$SCRATCH/t2.log" 2>&1; tail -8 "$SCRATCH/t2.log"`
Expected: OK. Fix any test that pinned the static `brand-1`…`brand-3` vocabulary list or the
compiled artifact's brand utilities, and ledger it. `DoctorTest`'s brand-mapping warning should
pass unchanged, because `ignored()` still lists the mapped token.

- [ ] **Step 6: The pre-palette HTML snapshot**

The revision-4 byte-identity test (find it with `grep -rln "pre-palette\|byte-identical" tests/Integration/Render`)
normalises the compiled-stylesheet URL. Run it.
Expected: PASS. No colour is configured there, so nothing new is linked.

- [ ] **Step 7: phpcs, shard listing, commit**

Add `tests/Integration/Render/ColorsStylesheetTest.php` (and `ColorsArtifactsTenancyTest.php` if
you made it) to one `INTEGRATION_SHARD_*` in `.github/workflows/ci.yml`, next to `PaletteCssTest`.

Run: `vendor/bin/phpcs packages/thallo-contracts/src/Style packages/thallo-render/src core/src/Content/Validation/FieldValidator.php core/src/Content/Palette/PaletteReplaceService.php; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add packages core/src/Content/Validation/FieldValidator.php core/src/Content/Palette/PaletteReplaceService.php tests .github/workflows/ci.yml
git commit -m "feat(palette): brand colour names become a family (brand-1 … brand-9999); their utilities leave the per-theme stylesheet (compiler v25) for each workspace's colours stylesheet, linked by theme_colors_style()"
```

### Task 3: One list setting, a deployment limit, and Clear moving a colour to removed

**Files:**
- Modify: `core/config/theme.php`
- Create: `core/src/Settings/BrandColors.php`
- Modify: `core/src/Settings/PaletteSettings.php` (`palette()`, constructor, `limitFrom()`, `preview()` uses the list; the old per-slot `validate` branch is kept until Task 4)
- Modify: `core/src/Settings/GeneralSettings.php` (`DEFS` L58–63, `save()` `$clearable` L273–280)
- Modify: `core/src/Providers/CoreServiceProvider.php` (the two `PaletteSettings` registrations ~L1412–1422)
- Modify: `core/src/Content/Palette/PaletteMutations.php` (`clear()`)
- Modify: `core/src/Content/Palette/PaletteReplaceRunner.php` (`complete()` ~L237, the "Brand {$slot}" labels ~L178, L284, L303, L311)
- Create: `core/database/migrations/048_ConvertBrandSlotsToList.php`
- Modify: `tests/Support/Palette/PaletteFixtures.php` (`configure`, `clear`)
- Modify: `packages/thallo-contracts/src/Style/Palette.php` (delete `SLOTS`)
- Test: `tests/Unit/Settings/BrandColorsTest.php` (new), `tests/Integration/Settings/PaletteSettingsTest.php`, `tests/Integration/Content/Palette/PaletteMutationsTest.php`, `tests/Integration/Content/Palette/PaletteReplaceTest.php`, `tests/Integration/Migrations/BrandSlotsToListTest.php` (new)

**Interfaces:**
- Consumes: `Palette` (Task 1).
- Produces:
  - `BrandColors::parse(string $json): array{0: array<int,BrandSlot>, 1: array<int,string>, 2: int}` (colours, removed names, revision)
  - `BrandColors::encode(array<int,BrandSlot> $colors, array<int,string> $removed, int $revision): string`
  - `BrandColors::cleared(string $stored, int $id): string` (the stored JSON with `$id` moved to `removed` and the revision incremented; unchanged when `$id` is not a colour)
  - `PaletteSettings::__construct(GeneralSettings $settings, int $brandLimit = Palette::DEFAULT_LIMIT)`
  - `PaletteSettings::limitFrom(mixed $raw): int`
  - `PaletteSettings::palette()` reads `theme_brand_colors` and carries the limit
  - The setting key `theme_brand_colors`. The fixture `configure(int $id, string $name, string $hex)` appends or updates by id. The fixture `clear(int $id)` moves the colour to removed.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Settings/BrandColorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\PaletteSettings;

/** The stored brand colour list (custom palette spec §2, §2.3): parsed leniently, encoded canonically. */
final class BrandColorsTest extends TestCase
{
    public function testItRoundTripsColoursInOrderRemovedNamesAndTheRevision(): void
    {
        $json = BrandColors::encode(
            [7 => new BrandSlot('Rose', '#c98a8a'), 2 => new BrandSlot('Gold', '#8a6a2a')],
            [3 => 'Teal'],
            5,
        );
        self::assertSame(
            '{"revision":5,"colors":[{"id":7,"name":"Rose","hex":"#c98a8a"},{"id":2,"name":"Gold","hex":"#8a6a2a"}],'
            . '"removed":[{"id":3,"name":"Teal"}]}',
            $json,
        );
        [$colors, $removed, $revision] = BrandColors::parse($json);
        self::assertSame([7, 2], array_keys($colors));
        self::assertSame([3 => 'Teal'], $removed);
        self::assertSame(5, $revision);
        self::assertSame(0, BrandColors::parse('{"colors":[]}')[2]);
    }

    public function testAnEntryThatNoLongerParsesReadsAsUnsetAndTheRestStay(): void
    {
        [$colors, $removed] = BrandColors::parse(
            '{"colors":[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":0,"name":"Bad","hex":"#000"},'
            . '{"id":2,"name":"","hex":"#123456"},{"id":4,"name":"Rose","hex":"red"},{"id":1,"name":"Dup","hex":"#111111"}],'
            . '"removed":[{"id":1,"name":"Shadowed"},{"id":5,"name":"Teal"},{"id":"x","name":"No"}]}',
        );
        self::assertSame([1], array_keys($colors));
        self::assertSame('Gold', $colors[1]->name);
        self::assertSame([5 => 'Teal'], $removed);
        self::assertSame([[], [], 0], BrandColors::parse('not json'));
        self::assertSame([[], [], 0], BrandColors::parse(''));
    }

    public function testClearingMovesTheColourToRemovedKeepingItsNameAndBumpsTheRevision(): void
    {
        $stored = BrandColors::encode([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')], [], 4);
        [$colors, $removed, $revision] = BrandColors::parse(BrandColors::cleared($stored, 1));
        self::assertSame([2], array_keys($colors));
        self::assertSame([1 => 'Gold'], $removed);
        self::assertSame(5, $revision);
        self::assertSame($stored, BrandColors::cleared($stored, 9)); // not a colour: nothing written changes
    }

    public function testTheLimitIsClampedAndJunkReadsAsTheDefault(): void
    {
        self::assertSame(3, PaletteSettings::limitFrom(null));
        self::assertSame(3, PaletteSettings::limitFrom('lots'));
        self::assertSame(0, PaletteSettings::limitFrom('0'));
        self::assertSame(12, PaletteSettings::limitFrom(40));
        self::assertSame(0, PaletteSettings::limitFrom(-2));
        self::assertSame(5, PaletteSettings::limitFrom('5'));
    }
}
```

Add to `tests/Integration/Settings/PaletteSettingsTest.php` (and rewrite its `theme_brand_N` set-ups
to `theme_brand_colors` via `BrandColors::encode`):

```php
public function testThePaletteReadsTheListWithItsRemovedNamesAndTheLimit(): void
{
    $this->settings()->save(['theme_brand_colors' => BrandColors::encode(
        [4 => new BrandSlot('Gold', '#8a6a2a'), 1 => new BrandSlot('Rose', '#c98a8a')],
        [2 => 'Teal'],
        1,
    )]);
    $palette = $this->container()->get(PaletteSettings::class)->palette();
    self::assertSame([4, 1], $palette->ids());
    self::assertSame('Teal', $palette->labelOf(2));
    self::assertSame(3, $palette->limit);
}

public function testLimitZeroHidesColoursAndKeepsThem(): void
{
    $this->settings()->save(['theme_brand_colors' => BrandColors::encode([4 => new BrandSlot('Gold', '#8a6a2a')], [], 1)]);
    $off = new PaletteSettings($this->settings(), 0);
    self::assertSame([], $off->palette()->configured());
    $on = new PaletteSettings($this->settings(), 3);
    self::assertSame([4], $on->palette()->ids());
}
```

(`$this->settings()` is the test's `GeneralSettings`. Use whatever accessor the file already has.)

Add to `tests/Integration/Content/Palette/PaletteMutationsTest.php`:

```php
public function testANewColourNeverTakesAClearedId(): void
{
    $this->configure(3, 'Teal', '#0f766e');
    $this->container()->get(PaletteMutations::class)->clear(3, null);
    $palette = $this->container()->get(PaletteSettings::class)->palette();
    self::assertFalse($palette->isConfigured(3));
    self::assertTrue($palette->isRemoved(3));
    self::assertSame('Teal', $palette->labelOf(3));
    self::assertSame(3, $palette->highestIssued());
}
```

Change `testClearWithNoUsageClearsBumpsAndFiresAfterCommit` (and the other clear tests) to assert
`isRemoved($slot)` where they asserted that the slot's setting is `''`.

In `PaletteReplaceTest`, the completion test asserts the source slot is removed with its name
(`isRemoved(1)`, `labelOf(1) === 'Gold'`) instead of unset.

`tests/Integration/Migrations/BrandSlotsToListTest.php` (new; add it to the shard that holds
`RegionsSchemaStampRepairTest`):

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Migrations;

use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The revision-4 build's three brand keys exist only on development installs: migration 048
 * converts them into the list, keeping ids 1–3, and deletes them (custom palette spec §7). Its Clear
 * deleted a slot's row, so on a workspace that used the palette every id 1–3 not configured is
 * reserved — content may still name it, and it must never become a different colour.
 */
final class BrandSlotsToListTest extends AppTestCase
{
    private function migrate(): void
    {
        require_once dirname(__DIR__, 3) . '/core/database/migrations/048_ConvertBrandSlotsToList.php';
        (new \ConvertBrandSlotsToList())->up($this->connection()->getSchemaBuilder());
    }

    private function put(string $key, string $value): void
    {
        $this->connection()->table('settings')->insert(['key' => $key, 'value' => $value]);
    }

    private function value(string $key): ?string
    {
        $row = $this->connection()->table('settings')->where(['key' => $key])->first();
        return $row === null ? null : (string) $row['value'];
    }

    public function testItKeepsIdsOneToThreeReservesTheRestAndDeletesTheOldKeys(): void
    {
        $this->put('theme_brand_1', '{"name":"Gold","hex":"#8a6a2a"}');
        $this->put('theme_brand_2', 'not json');
        $this->migrate();

        [$colors, $removed, $revision] = BrandColors::parse((string) $this->value('theme_brand_colors'));
        self::assertSame([1], array_keys($colors));
        self::assertSame([2 => 'Brand 2', 3 => 'Brand 3'], $removed);
        self::assertSame(0, $revision);
        foreach ([1, 2, 3] as $n) {
            self::assertNull($this->value("theme_brand_{$n}"));
        }
    }

    public function testAClearedRevisionFourSlotIsNeverReissued(): void
    {
        // Brand 3 was configured and cleared under revision 4: its row is gone, the palette row and
        // the audit entry remain, and a retained version still names it.
        $this->state()->snapshot(); // creates the palette_state row, as any palette use did
        $this->connection()->table('audit_logs')->insert([
            'uuid' => 'aud000000001', 'occurred_at' => '2026-10-09 10:00:00', 'action' => 'palette.brand.cleared',
            'category' => 'content', 'target_type' => 'palette_slot', 'target_uuid' => 'brand-3',
            'target_label' => 'Teal',
        ]);
        $this->migrate();
        $palette = $this->container()->get(\Thallo\Core\Settings\PaletteSettings::class)->palette();
        self::assertSame('Teal', $palette->labelOf(3));
        self::assertTrue($palette->isRemoved(3));
        self::assertSame(3, $palette->highestIssued());

        // A colour added after the migration takes 4, and an old reference to 3 stays unavailable.
        $this->container()->get(\Thallo\Core\Http\Controllers\GeneralSettingsController::class)->update(
            new \Thallo\Core\Http\DTOs\UpdateGeneralSettingsData(
                theme_brand_colors: '{"base":0,"colors":[{"name":"Sky","hex":"#38bdf8"}]}',
            ),
        );
        $after = $this->container()->get(\Thallo\Core\Settings\PaletteSettings::class)->palette();
        self::assertSame([4], $after->ids());
        self::assertTrue($after->isUnavailable('color.brand-3'));
    }

    public function testAFreshInstallReservesNothing(): void
    {
        $this->connection()->table('palette_state')->delete();
        $this->migrate();
        self::assertNull($this->value('theme_brand_colors'));
    }

    public function testItNeverOverwritesAList(): void
    {
        $list = '{"revision":2,"colors":[{"id":9,"name":"Rose","hex":"#c98a8a"}],"removed":[]}';
        $this->put('theme_brand_colors', $list);
        $this->put('theme_brand_1', '{"name":"Gold","hex":"#8a6a2a"}');
        $this->migrate();
        self::assertSame($list, $this->value('theme_brand_colors'));
        self::assertNull($this->value('theme_brand_1'));
    }
}
```

The second test uses `PaletteFixtures` (`state()`). Add `use PaletteFixtures;` and the
`audit_logs` cleanup the audit tests use (`grep -rln "audit_logs" tests/Integration`). If
`audit_logs` is absent in the test database (the audit extension disabled), the migration skips
names, and the test asserts `'Brand 3'` instead. Gate that on `hasTable('audit_logs')`.
The second test's settings save depends on Task 4. In this task, end the test at the
`highestIssued()` assertion; Task 4 adds the save and the two assertions after it (Task 4, Step 1
says so).

If the `settings` table carries `tenant_uuid` in the test DB and inserts need it, set it the way
other settings tests insert raw rows. The `settings` wipe between tests comes from `AppTestCase`.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/Settings/BrandColorsTest.php tests/Integration/Settings/PaletteSettingsTest.php tests/Integration/Migrations/BrandSlotsToListTest.php tests/Integration/Content/Palette/PaletteMutationsTest.php`
Expected: FAIL. `BrandColors` and `limitFrom` are undefined, the migration file is missing, and
`palette()` ignores `theme_brand_colors`.

- [ ] **Step 3: Implement**

`core/config/theme.php`, add after `color_mode`:

```php
    // Brand colours (custom palette spec §1): how many a workspace may have at once — a budget the
    // operator sets, not a site setting. Clamped to 0–12; 0 turns brand colours off.
    'brand_colors' => [
        'max' => env('THALLO_BRAND_COLORS_MAX', 3),
    ],
```

`core/src/Settings/BrandColors.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;

/**
 * The stored brand colour list (custom palette spec §2, §2.3): `theme_brand_colors`, as
 * `{"colors":[{id,name,hex}…],"removed":[{id,name}…]}` — colours in the author's order, removed ids
 * with the names they had. A stored entry that no longer parses (hand-edited, imported) reads as
 * unset; the rest stay.
 */
final class BrandColors
{
    /** @return array{0: array<int,BrandSlot>, 1: array<int,string>, 2: int} colours, removed names, revision */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [[], [], 0];
        }
        $colors = [];
        foreach (is_array($data['colors'] ?? null) ? $data['colors'] : [] as $row) {
            $id = is_array($row) ? self::id($row['id'] ?? null) : null;
            $slot = $id === null ? null : PaletteSettings::parseBrand((string) json_encode($row));
            if ($slot !== null && !isset($colors[$id])) {
                $colors[$id] = $slot;
            }
        }
        $removed = [];
        foreach (is_array($data['removed'] ?? null) ? $data['removed'] : [] as $row) {
            $id = is_array($row) ? self::id($row['id'] ?? null) : null;
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
            if ($id !== null && $name !== '' && !isset($colors[$id])) {
                $removed[$id] = mb_substr($name, 0, PaletteSettings::NAME_MAX);
            }
        }
        $revision = is_int($data['revision'] ?? null) && $data['revision'] >= 0 ? $data['revision'] : 0;
        return [$colors, $removed, $revision];
    }

    /**
     * @param array<int,BrandSlot> $colors
     * @param array<int,string> $removed
     * @param int $revision what a submitted list must name as its base to be saved over this one
     */
    public static function encode(array $colors, array $removed, int $revision): string
    {
        $out = ['revision' => $revision, 'colors' => [], 'removed' => []];
        foreach ($colors as $id => $slot) {
            $out['colors'][] = ['id' => $id] + $slot->toArray();
        }
        ksort($removed);
        foreach ($removed as $id => $name) {
            $out['removed'][] = ['id' => $id, 'name' => $name];
        }
        return (string) json_encode($out);
    }

    /**
     * The stored list with `$id` moved from the colours to the removed, keeping its name (§2.3), at
     * the next revision — so an editor holding the list from before is refused, not obeyed. Unchanged
     * when `$id` is not a colour.
     */
    public static function cleared(string $stored, int $id): string
    {
        [$colors, $removed, $revision] = self::parse($stored);
        if (!isset($colors[$id])) {
            return $stored;
        }
        $removed[$id] = $colors[$id]->name;
        unset($colors[$id]);
        return self::encode($colors, $removed, $revision + 1);
    }

    /** An id from 1 to Palette::MAX_ID, else null. */
    public static function id(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= Palette::MAX_ID ? $value : null;
    }
}
```

`PaletteSettings`:
- Constructor: `public function __construct(private readonly GeneralSettings $settings, private readonly int $brandLimit = Palette::DEFAULT_LIMIT)`.
- Add:

```php
    /** The deployment's limit (custom palette spec §1): 0–12, the default for anything not a number. */
    public static function limitFrom(mixed $raw): int
    {
        if (!is_int($raw) && !(is_string($raw) && is_numeric(trim($raw)))) {
            return Palette::DEFAULT_LIMIT;
        }
        return max(0, min(Palette::LIMIT_CEILING, (int) $raw));
    }
```

- `palette()`:

```php
    public function palette(): Palette
    {
        [$colors, $removed] = BrandColors::parse($this->settings->stored('theme_brand_colors'));
        $base = $this->settings->stored('theme_dark_base');
        return new Palette(
            self::parseNeutral($this->settings->stored('theme_neutral_custom')),
            ThemeColors::normalizeNeutral($base) === null ? null : $base,
            $colors,
            $removed,
            $this->brandLimit,
        );
    }
```

- `preview()`: keep the per-slot claim loop until Task 5, but build the result with
  `new Palette($neutral, $base, $brands, $saved->removed, $saved->limit)`. Here `$brands` starts
  from `$saved->brands`, and a claim slot id is accepted when `BrandColors::id((int) $slot) !== null`
  instead of `in_array($slot, Palette::SLOTS)`.
- `validate()`: leave the `theme_brand_N` branch, but loop `foreach ([1, 2, 3] as $slot)` with a
  comment `// the revision-4 keys: removed with the list's save (next task)`. Task 4 deletes it.

`GeneralSettings`:
- `DEFS`: replace the three `theme_brand_N` lines with `'theme_brand_colors' => ['thallo.theme.brand_colors', 'string', ''],` and the comment "The brand colour list (custom palette spec §2.3): JSON, '' when unset; read by PaletteSettings."
- `save()` `$clearable`: drop the three brand keys. The list is never cleared by `''`, because Clear moves one colour.

`CoreServiceProvider`:
- Replace both `PaletteSettings` registrations' `'class'`/`'autowire'` with `'factory' => [self::class, 'makePaletteSettings']`.
- Make the `PaletteProvider` entry resolve the same instance: `'factory' => static fn (ContainerInterface $c) => $c->get(\Thallo\Core\Settings\PaletteSettings::class)`. Use the closure form only if the container supports closures in `'factory'`; otherwise add `makePaletteProvider()` returning `$container->get(PaletteSettings::class)`.
- Add:

```php
    public static function makePaletteSettings(ContainerInterface $container): \Thallo\Core\Settings\PaletteSettings
    {
        return new \Thallo\Core\Settings\PaletteSettings(
            $container->get(\Thallo\Core\Settings\GeneralSettings::class),
            \Thallo\Core\Settings\PaletteSettings::limitFrom(
                config($container->get(ApplicationContext::class), 'theme.brand_colors.max', \Thallo\Contracts\Style\Palette::DEFAULT_LIMIT),
            ),
        );
    }
```

`PaletteMutations::clear()`:
- Replace `$this->settings->save(['theme_brand_' . $slot => '']);` with `$this->settings->save(['theme_brand_colors' => BrandColors::cleared($this->settings->stored('theme_brand_colors'), $slot)]);`. `lock()` has just cleared the store's read cache, so this is the committed list.
- Its "save" method's `foreach (Palette::SLOTS …)` stays until Task 4, rewritten as `foreach ([1, 2, 3] as $slot)` with the same comment as `validate()`.

`PaletteReplaceRunner::complete()`:
- The same replacement for `theme_brand_ . $job->slot`.
- Every `?? "Brand {$x}"` fallback becomes `$held->palette->labelOf($x)` (or the snapshot variable in scope at each site; `labelOf` also covers a slot already moved to removed).

`GeneralSettingsController::update()`: change `foreach ([1, 2, 3] as $slot)` (unchanged until Task 4). Then delete `Palette::SLOTS` from the contract once `grep -rn "Palette::SLOTS" core packages` is empty. It is empty after the two rewrites above.

`core/database/migrations/048_ConvertBrandSlotsToList.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Converts the revision-4 build's fixed brand keys (`theme_brand_1` … `theme_brand_3`, each
 * `{"name","hex"}`) into the brand colour list (`theme_brand_colors`, custom palette spec §2.3, §7),
 * keeping ids 1–3, and deletes them. That build never shipped: the keys exist only on development
 * installs. Its Clear deleted a slot's row, so a cleared slot leaves no setting behind while content
 * may still name it: on every workspace that used the palette — an old key, a palette_state row or a
 * palette_jobs row — each id 1–3 not configured is reserved as removed, named from its last audit
 * entry where the audit log can say whose it was (a single-site install), else "Brand N". A fresh
 * install reserves nothing. A list already present is never overwritten.
 */
final class ConvertBrandSlotsToList implements MigrationInterface
{
    private const OLD = ['theme_brand_1' => 1, 'theme_brand_2' => 2, 'theme_brand_3' => 3];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('settings')) {
            return;
        }
        $pdo = $schema->getConnection()->getPDO();
        $tenanted = $schema->hasColumn('settings', 'tenant_uuid');
        $tenantOf = static fn (string $table): string => $schema->hasColumn($table, 'tenant_uuid')
            ? 'tenant_uuid'
            : 'NULL AS tenant_uuid';

        /** @var array<string, array{tenant: ?string, keys: array<string,string>}> $groups */
        $groups = [];
        $add = static function (?string $tenant) use (&$groups): void {
            $groups[$tenant ?? ''] ??= ['tenant' => $tenant, 'keys' => []];
        };
        $rows = $pdo->query(
            'SELECT ' . $tenantOf('settings') . ', key, value FROM settings '
            . "WHERE key IN ('theme_brand_1', 'theme_brand_2', 'theme_brand_3', 'theme_brand_colors')",
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $tenant = $row['tenant_uuid'] === null ? null : (string) $row['tenant_uuid'];
            $add($tenant);
            $groups[$tenant ?? '']['keys'][(string) $row['key']] = (string) $row['value'];
        }
        foreach (['palette_state', 'palette_jobs'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            foreach ($pdo->query('SELECT DISTINCT ' . $tenantOf($table) . " FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $add($row['tenant_uuid'] === null ? null : (string) $row['tenant_uuid']);
            }
        }

        // The audit log carries no workspace: its names are trusted only on a single-site install.
        $audited = [];
        if (!$tenanted && $schema->hasTable('audit_logs')) {
            $names = $pdo->query(
                "SELECT target_uuid, target_label FROM audit_logs WHERE target_type = 'palette_slot' "
                . "AND target_uuid IN ('brand-1', 'brand-2', 'brand-3') AND target_label IS NOT NULL "
                . 'ORDER BY occurred_at, id',
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($names as $row) {
                $audited[(int) substr((string) $row['target_uuid'], 6)] = mb_substr(trim((string) $row['target_label']), 0, 32);
            }
        }

        foreach ($groups as $group) {
            $keys = $group['keys'];
            $where = $tenanted ? ($group['tenant'] === null ? 'tenant_uuid IS NULL' : 'tenant_uuid = :t') : 'TRUE';
            $bind = $tenanted && $group['tenant'] !== null ? ['t' => $group['tenant']] : [];
            if (!isset($keys['theme_brand_colors'])) {
                $colors = [];
                $removed = [];
                foreach (self::OLD as $key => $id) {
                    $data = json_decode($keys[$key] ?? '', true);
                    $name = is_array($data) && is_string($data['name'] ?? null) ? trim($data['name']) : '';
                    $hex = is_array($data) && is_string($data['hex'] ?? null) ? strtolower(trim($data['hex'])) : '';
                    if ($name !== '' && preg_match('/\A#[0-9a-f]{6}\z/', $hex) === 1) {
                        $colors[] = ['id' => $id, 'name' => mb_substr($name, 0, 32), 'hex' => $hex];
                    } else {
                        $removed[] = ['id' => $id, 'name' => ($audited[$id] ?? '') !== '' ? $audited[$id] : "Brand {$id}"];
                    }
                }
                $insert = $pdo->prepare(
                    'INSERT INTO settings (' . ($tenanted ? 'tenant_uuid, ' : '') . 'key, value, updated_at) VALUES ('
                    . ($tenanted ? ':tenant, ' : '') . "'theme_brand_colors', :value, CURRENT_TIMESTAMP)",
                );
                $insert->execute(($tenanted ? ['tenant' => $group['tenant']] : [])
                    + ['value' => json_encode(['revision' => 0, 'colors' => $colors, 'removed' => $removed])]);
            }
            $delete = $pdo->prepare(
                "DELETE FROM settings WHERE {$where} AND key IN ('theme_brand_1', 'theme_brand_2', 'theme_brand_3')",
            );
            $delete->execute($bind);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Nothing: the revision-4 keys never shipped.
    }

    public function getDescription(): string
    {
        return 'Brand colours: the three fixed keys become the brand colour list (ids 1–3 kept or reserved)';
    }
}
```

The encoded JSON matches `BrandColors::encode()`'s key order (`revision`, `colors`, `removed`), so a
migrated value round-trips byte-for-byte.

If the RawPdo audit (`core/src/Content/Starter/RawPdoWriteAudit.php`) or the tenancy lint
(`tests/Unit/Tenancy/RawPdoScopingLintTest.php`) scans `core/database/migrations`, they flag this
file. In that case, classify it beside migration 042 the way 042 is classified.

`tests/Support/Palette/PaletteFixtures.php`:

```php
    protected function configure(int $slot, string $name, string $hex): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->clearStoreCache();
        [$colors, $removed, $revision] = BrandColors::parse($settings->stored('theme_brand_colors'));
        $colors[$slot] = new BrandSlot($name, $hex);
        unset($removed[$slot]);
        $settings->save(['theme_brand_colors' => BrandColors::encode($colors, $removed, $revision + 1)]);
    }

    protected function clear(int $slot): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($slot): void {
            $this->state()->lock();
            $this->state()->bump();
            $settings = $this->container()->get(GeneralSettings::class);
            $settings->save([
                'theme_brand_colors' => BrandColors::cleared($settings->stored('theme_brand_colors'), $slot),
            ]);
        });
    }
```

(Add the `use` lines for `BrandColors` and `BrandSlot`.)

Then rewrite the direct `theme_brand_N` uses in tests. They are listed by
`grep -rln "theme_brand_" tests`: `PagePaletteAvailabilityTest`, `StyleSchemaEndpointTest`,
`GeneralSettingsAppearanceTest`, `EntryWritersFenceTest`, `PaletteStateTest`, `PaletteReplaceTest`,
`PaletteMutationsTest`, `PaletteReplaceTenancyTest`, `PaletteReplaceConcurrencyTest`,
`PaletteApiTest` and `PalettePreviewTest`. Each one switches to `configure()`/`clear()` or to a
`theme_brand_colors` value built with `BrandColors::encode()`. The settings-save tests that go
through `GeneralSettingsController` with `theme_brand_N` stay red until Task 4. Mark them
`$this->markTestIncomplete('Task 4: the list save')` in this task and record that in the ledger;
Task 4 removes every such mark.

Add a page test to `ColorsStylesheetTest` now that ids are unbounded:
`testAPageUsingBrandTwelvePaintsItsClass`. `configure(12, 'Teal', '#0f766e')`, a heading with text
`color.brand-12` renders `t-…-color-brand-12`, and the linked colours stylesheet contains that class.

- [ ] **Step 4: Run them to see them pass, then the palette and settings suites**

Run: `composer test:reset-db && composer test:migrate && vendor/bin/phpunit tests/Unit/Settings tests/Integration/Settings tests/Integration/Migrations/BrandSlotsToListTest.php tests/Integration/Content/Palette tests/Integration/Render tests/Integration/Http/PaletteApiTest.php tests/Integration/Http/PalettePreviewTest.php > "$SCRATCH/t3.log" 2>&1; tail -8 "$SCRATCH/t3.log"`
Expected: OK, apart from the incomplete tests marked for Task 4.

- [ ] **Step 5: phpcs, shard listing, commit**

Add `BrandSlotsToListTest.php` to the shard that holds `RegionsSchemaStampRepairTest.php`.

Run: `vendor/bin/phpcs core/src/Settings core/src/Content/Palette core/src/Providers/CoreServiceProvider.php core/database/migrations/048_ConvertBrandSlotsToList.php core/config/theme.php packages/thallo-contracts/src/Style/Palette.php tests/Support/Palette; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add core packages/thallo-contracts tests .github/workflows/ci.yml
git commit -m "feat(palette): brand colours are one list setting (theme_brand_colors) with removed ids kept by name, under a deployment limit (THALLO_BRAND_COLORS_MAX, 0–12); Clear and Replace move a colour to removed; migration 048 converts the revision-4 keys"
```

### Task 4: Saving the list — new ids, the limit, no omissions

**Files:**
- Create: `core/src/Content/Palette/BrandColorsRefused.php`
- Modify: `core/src/Settings/BrandColors.php` (add `applied()`)
- Modify: `core/src/Settings/PaletteSettings.php` (`parseSubmitted()`, `validate()`)
- Modify: `core/src/Http/DTOs/UpdateGeneralSettingsData.php` (L79–87)
- Modify: `core/src/Content/Palette/PaletteMutations.php` (`save()`)
- Modify: `core/src/Http/Controllers/GeneralSettingsController.php` (`update()` ~L143–175)
- Test: `tests/Unit/Settings/BrandColorsTest.php`, `tests/Integration/Content/GeneralSettingsAppearanceTest.php`, `tests/Integration/Content/Palette/PaletteMutationsTest.php`

**Interfaces:**
- Consumes: `BrandColors` (Task 3), `Palette::highestIssued()`, `labelOf()`, `limit` (Task 1).
- Produces:
  - `PaletteSettings::parseSubmitted(string $json): ?array{base: int, rows: list<array{id: ?int, name: string, hex: string}>}`
  - `BrandColors::applied(Palette $held, int $revision, int $base, list<array{id: ?int, name: string, hex: string}> $rows, \Closure(int): bool $replacing): string`, which returns the value to store at `$revision + 1` and throws `BrandColorsRefused` or `PaletteConflict`
  - `UpdateGeneralSettingsData::$theme_brand_colors` (`?string`): the submitted `{"base": int, "colors":[{"id"?: int, "name", "hex"}, …]}`; any `removed` sent is ignored
  - 422 `{"theme_brand_colors": "<message>"}` for each refusal. 409 `conflict` for a stale `base` or a colour being replaced.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Settings/BrandColorsTest.php`:

```php
private static function held(array $colors, array $removed = [], int $limit = 3): Palette
{
    return new Palette(null, null, $colors, $removed, $limit);
}

private static function row(?int $id, string $name, string $hex = '#123456'): array
{
    return ['id' => $id, 'name' => $name, 'hex' => $hex];
}

/** Applied from a list edited at the stored revision (0), unless a test says otherwise. */
private static function apply(Palette $held, array $rows, ?\Closure $replacing = null, int $base = 0, int $revision = 0): string
{
    return BrandColors::applied($held, $revision, $base, $rows, $replacing ?? static fn (): bool => false);
}

public function testNewColoursTakeIdsAboveEveryIdEverIssued(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal']);
    [$colors, $removed] = BrandColors::parse(self::apply(
        $held,
        [self::row(null, 'Rose'), self::row(1, 'Gold', '#8a6a2a'), self::row(null, 'Sky')],
    ));
    self::assertSame([4, 1, 5], array_keys($colors));
    self::assertSame([3 => 'Teal'], $removed);
}

public function testRenameRecolourAndReorderKeepIds(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
    [$colors] = BrandColors::parse(self::apply(
        $held,
        [self::row(2, 'Blush', '#d9a0a0'), self::row(1, 'Gold', '#8a6a2a')],
    ));
    self::assertSame([2, 1], array_keys($colors));
    self::assertSame('Blush', $colors[2]->name);
}

public function testASaveThatOmitsAColourIsRefused(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Teal', '#0f766e')]);
    $this->expectException(BrandColorsRefused::class);
    $this->expectExceptionMessage('Remove a brand colour with Clear: Teal is missing from this save');
    self::apply($held, [self::row(1, 'Gold', '#8a6a2a')]);
}

public function testAnIdThatIsNotConfiguredIsRefusedByItsName(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal']);
    $this->expectExceptionMessage("Teal isn't in the palette");
    self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(3, 'Teal')]);
}

public function testAddingPastTheLimitIsRefusedButEditingAboveItIsNot(): void
{
    $four = [];
    foreach ([1, 2, 3, 4] as $id) {
        $four[$id] = new BrandSlot("C{$id}", '#123456');
    }
    // the limit was lowered to 3 after four were made: they stay editable
    $held = self::held($four);
    $rows = array_map(static fn (int $id): array => self::row($id, "C{$id}"), [1, 2, 3, 4]);
    $rows[0]['name'] = 'Renamed';
    self::assertStringContainsString('Renamed', self::apply($held, $rows));
    // …but nothing can be added until the count is under the limit
    $this->expectException(BrandColorsRefused::class);
    $this->expectExceptionMessage('This site allows 3 brand colours');
    self::apply($held, [...$rows, self::row(null, 'New')]);
}

public function testTheLimitMessageSaysOneColour(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [], 1);
    $this->expectExceptionMessage('This site allows 1 brand colour');
    self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(null, 'New')]);
}

public function testLimitZeroRefusesAnySave(): void
{
    $this->expectExceptionMessage('Brand colours are turned off on this site');
    self::apply(self::held([], [], 0), []);
}

public function testAColourBeingReplacedCannotChangeButMayMove(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
    $replacing = static fn (int $id): bool => $id === 1;
    // moving it is a label-order change only
    self::apply($held, [self::row(2, 'Rose', '#c98a8a'), self::row(1, 'Gold', '#8a6a2a')], $replacing);
    $this->expectException(PaletteConflict::class);
    $this->expectExceptionMessage('Gold is being replaced');
    self::apply($held, [self::row(1, 'Gold', '#000000'), self::row(2, 'Rose', '#c98a8a')], $replacing);
}

public function testAListEditedFromAnOlderRevisionIsRefused(): void
{
    // A renamed Gold to Amber (revision 4 → 5); B, holding revision 4, re-colours Rose and would
    // send Gold back. Whatever changed — a rename, a re-colour, a reorder, an add, a Clear — the
    // stale list is refused and nothing it carries is written.
    $held = self::held([1 => new BrandSlot('Amber', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
    $this->expectException(PaletteConflict::class);
    $this->expectExceptionMessage('Brand colours changed since you opened this page');
    self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(2, 'Rose', '#000000')], null, 4, 5);
}

public function testTheStoredValueIsAtTheNextRevision(): void
{
    $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')]);
    self::assertSame(8, BrandColors::parse(self::apply($held, [self::row(1, 'Gold', '#8a6a2a')], null, 7, 7))[2]);
}

public function testSubmittedListsAreParsedStrictly(): void
{
    self::assertSame(
        ['base' => 3, 'rows' => [
            ['id' => 2, 'name' => 'Gold', 'hex' => '#8a6a2a'],
            ['id' => null, 'name' => 'Rose', 'hex' => '#aabbcc'],
        ]],
        PaletteSettings::parseSubmitted(
            '{"base":3,"colors":[{"id":2,"name":" Gold ","hex":"#8A6A2A"},{"name":"Rose","hex":"#abc"}]}',
        ),
    );
    foreach ([
        '', 'nope', '{"base":0,"colors":"x"}', '{"base":0,"colors":[{"id":2,"name":"","hex":"#123456"}]}',
        '{"base":0,"colors":[{"id":0,"name":"A","hex":"#123456"}]}',
        '{"base":0,"colors":[{"id":2,"name":"A","hex":"#123456"},{"id":2,"name":"B","hex":"#123456"}]}',
        '{"base":0,"colors":[{"id":"2","name":"A","hex":"#123456"}]}',
        '{"colors":[]}', '{"base":-1,"colors":[]}', '{"base":"1","colors":[]}',
    ] as $bad) {
        self::assertNull(PaletteSettings::parseSubmitted($bad), $bad);
    }
}
```

(Add `use Thallo\Core\Content\Palette\BrandColorsRefused;` and
`use Thallo\Core\Content\Palette\PaletteConflict;`.)

Add to `tests/Integration/Content/GeneralSettingsAppearanceTest.php` (replacing its three
`theme_brand_N` cases):

```php
public function testBrandColoursAreSavedAsAListAndGetPermanentIds(): void
{
    $controller = $this->container()->get(GeneralSettingsController::class);
    $res = $controller->update(new UpdateGeneralSettingsData(
        theme_brand_colors: '{"base":0,"colors":[{"name":"Gold dark","hex":"#8A6A2A"},{"name":"Rose","hex":"#c98a8a"}]}',
    ));
    self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
    $palette = $this->container()->get(PaletteSettings::class)->palette();
    self::assertSame([1, 2], $palette->ids());
    self::assertSame('#8a6a2a', $palette->brand(1)?->hex);
    // The response carries the stored list: the ids it gave and the revision to edit from.
    $stored = json_decode((string) $res->getContent(), true)['data']['settings']['theme_brand_colors'];
    self::assertSame(1, BrandColors::parse($stored)[2]);

    foreach ([
        ['{"base":1,"colors":[{"id":1,"name":"Gold dark","hex":"#8a6a2a"}]}', 'Remove a brand colour with Clear'],
        ['{"base":1,"colors":[{"id":1,"name":"Gold dark","hex":"#8a6a2a"},{"id":2,"name":"Rose","hex":"#c98a8a"},'
            . '{"name":"A","hex":"#111111"},{"name":"B","hex":"#222222"}]}', 'This site allows 3 brand colours'],
        ['{"base":1,"colors":[{"id":1,"name":"","hex":"#8a6a2a"}]}', 'a name (1–32 characters) and a hex colour'],
        ['', 'clear a brand colour with Clear'],
    ] as [$body, $message]) {
        $refused = $controller->update(new UpdateGeneralSettingsData(theme_brand_colors: $body));
        self::assertSame(422, $refused->getStatusCode(), $body);
        self::assertStringContainsString($message, (string) $refused->getContent(), $body);
    }
    self::assertSame([1, 2], $this->container()->get(PaletteSettings::class)->palette()->ids());
}

/** @return list<array{string, \Closure(): void}> what someone else may have done meanwhile */
private function otherEdits(GeneralSettingsController $controller): array
{
    $save = fn (string $colors) => $controller->update(new UpdateGeneralSettingsData(
        theme_brand_colors: '{"base":1,"colors":' . $colors . '}',
    ));
    return [
        ['a rename', fn () => $save('[{"id":1,"name":"Amber","hex":"#8a6a2a"},{"id":2,"name":"Rose","hex":"#c98a8a"}]')],
        ['a re-colour', fn () => $save('[{"id":1,"name":"Gold","hex":"#000000"},{"id":2,"name":"Rose","hex":"#c98a8a"}]')],
        ['a reorder', fn () => $save('[{"id":2,"name":"Rose","hex":"#c98a8a"},{"id":1,"name":"Gold","hex":"#8a6a2a"}]')],
        ['an add', fn () => $save('[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":2,"name":"Rose","hex":"#c98a8a"},'
            . '{"name":"Teal","hex":"#0f766e"}]')],
        ['a Clear', fn () => $this->container()->get(PaletteMutations::class)->clear(2, null)],
    ];
}

public function testAStaleListIsRefusedWhateverChangedMeanwhile(): void
{
    $controller = $this->container()->get(GeneralSettingsController::class);
    foreach ($this->otherEdits($controller) as [$what, $edit]) {
        $this->container()->get(GeneralSettings::class)->save(['theme_brand_colors' => BrandColors::encode(
            [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')],
            [],
            1,
        )]);
        $edit(); // A, from revision 1
        $before = $this->container()->get(GeneralSettings::class)->storedValue('theme_brand_colors');
        // B, still holding revision 1, re-colours Gold
        $b = $controller->update(new UpdateGeneralSettingsData(
            theme_brand_colors: '{"base":1,"colors":[{"id":1,"name":"Gold","hex":"#123456"},{"id":2,"name":"Rose","hex":"#c98a8a"}]}',
        ));
        self::assertSame(409, $b->getStatusCode(), $what);
        self::assertStringContainsString('Brand colours changed since you opened this page', (string) $b->getContent(), $what);
        self::assertSame($before, $this->container()->get(GeneralSettings::class)->storedValue('theme_brand_colors'), $what);
    }
}

public function testAddingAndClearingThenAddingNeverReusesAnId(): void
{
    $controller = $this->container()->get(GeneralSettingsController::class);
    $controller->update(new UpdateGeneralSettingsData(
        theme_brand_colors: '{"base":0,"colors":[{"name":"A","hex":"#111111"},{"name":"B","hex":"#222222"},{"name":"C","hex":"#333333"}]}',
    ));
    $this->container()->get(PaletteMutations::class)->clear(3, null); // revision 1 → 2
    $controller->update(new UpdateGeneralSettingsData(
        theme_brand_colors: '{"base":2,"colors":[{"id":1,"name":"A","hex":"#111111"},{"id":2,"name":"B","hex":"#222222"},{"name":"D","hex":"#444444"}]}',
    ));
    self::assertSame([1, 2, 4], $this->container()->get(PaletteSettings::class)->palette()->ids());
}
```

Also finish `BrandSlotsToListTest::testAClearedRevisionFourSlotIsNeverReissued` with its settings
save and the two assertions after it, as written in Task 3.

Also remove every `markTestIncomplete('Task 4: …')` left by Task 3, rewriting those tests'
bodies to send `theme_brand_colors`. `PaletteMutationsTest::testRenamingTheSourceOfAJobIsAConflictButRenamingAReservedSlotIsNot`
goes through `PaletteMutations::save(['theme_brand_colors' => <submitted JSON with the current "base">], null)`.
Every test that posts a list sends `"base"`: the stored revision, which is 0 for an unset list, or
whatever `BrandColors::parse(...)[2]` reads after the fixture's writes.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/Settings/BrandColorsTest.php tests/Integration/Content/GeneralSettingsAppearanceTest.php tests/Integration/Content/Palette/PaletteMutationsTest.php`
Expected: FAIL. `BrandColors::applied`, `parseSubmitted` and `BrandColorsRefused` are undefined,
and the DTO has no `theme_brand_colors`.

- [ ] **Step 3: Implement**

`core/src/Content/Palette/BrandColorsRefused.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** A brand colour list the palette refuses (custom palette spec §2.3): a 422 on `theme_brand_colors`. */
final class BrandColorsRefused extends \DomainException
{
}
```

`BrandColors::applied()`:

```php
    /**
     * A submitted list (custom palette spec §2.3) applied to the palette held under its row, as the
     * value to store. New colours (no id) take the ids above the highest the workspace has ever
     * issued, in the order submitted; configured colours may be renamed, re-coloured and reordered;
     * a colour may leave only through Clear, so a list that omits one is refused; an id that is not
     * configured is refused by its name; a save that adds a colour must stay within the limit,
     * while colours above a lowered limit stay editable. A colour being replaced may move but not
     * change. A list edited from an older revision is refused whatever it changes, so a save never
     * reverts what someone else saved meanwhile (plan ruling 3).
     *
     * @param int $revision the stored list's revision, read under the palette row
     * @param int $base the revision the submitted list was edited from
     * @param list<array{id: ?int, name: string, hex: string}> $rows
     * @param \Closure(int): bool $replacing whether a running replacement replaces the id
     * @return string the value to store, at `$revision + 1`
     * @throws BrandColorsRefused
     * @throws PaletteConflict
     */
    public static function applied(Palette $held, int $revision, int $base, array $rows, \Closure $replacing): string
    {
        if ($base !== $revision) {
            throw new PaletteConflict('Brand colours changed since you opened this page — reload to see the latest');
        }
        if ($held->limit === 0) {
            throw new BrandColorsRefused('Brand colours are turned off on this site');
        }
        $sent = array_values(array_filter(array_column($rows, 'id'), 'is_int'));
        foreach ($held->brands as $id => $brand) {
            if (!in_array($id, $sent, true)) {
                throw new BrandColorsRefused(
                    "Remove a brand colour with Clear: {$brand->name} is missing from this save",
                );
            }
        }
        foreach ($sent as $id) {
            if (!isset($held->brands[$id])) {
                throw new BrandColorsRefused("{$held->labelOf($id)} isn't in the palette");
            }
        }
        if (count($rows) > count($sent) && count($rows) > $held->limit) {
            $unit = $held->limit === 1 ? 'brand colour' : 'brand colours';
            throw new BrandColorsRefused("This site allows {$held->limit} {$unit}");
        }
        $next = $held->highestIssued();
        $colors = [];
        foreach ($rows as $row) {
            $slot = new BrandSlot($row['name'], $row['hex']);
            if ($row['id'] === null) {
                if (++$next > Palette::MAX_ID) {
                    throw new BrandColorsRefused('No brand colour ids are left on this site');
                }
                $colors[$next] = $slot;
                continue;
            }
            $current = $held->brands[$row['id']];
            if ($current->toArray() !== $slot->toArray() && $replacing($row['id'])) {
                throw new PaletteConflict("{$current->name} is being replaced");
            }
            $colors[$row['id']] = $slot;
        }
        return self::encode($colors, $held->removed, $revision + 1);
    }
```

(Add `use Thallo\Core\Content\Palette\BrandColorsRefused;` and
`use Thallo\Core\Content\Palette\PaletteConflict;`.)

`PaletteSettings::parseSubmitted()`:

```php
    /**
     * A submitted brand colour list (custom palette spec §2.3) and the revision it was edited from,
     * normalised — names trimmed, hex lower-case — or null when it names no base revision or is not a
     * list of valid colours with distinct ids. A missing or null id is a new colour; `removed` is the
     * server's and is ignored.
     *
     * @return array{base: int, rows: list<array{id: ?int, name: string, hex: string}>}|null
     */
    public static function parseSubmitted(string $json): ?array
    {
        $data = json_decode($json, true);
        if (
            !is_array($data) || !is_int($data['base'] ?? null) || $data['base'] < 0
            || !is_array($data['colors'] ?? null) || !array_is_list($data['colors'])
        ) {
            return null;
        }
        $rows = [];
        $ids = [];
        foreach ($data['colors'] as $row) {
            if (!is_array($row)) {
                return null;
            }
            $id = $row['id'] ?? null;
            if ($id !== null && (BrandColors::id($id) === null || in_array($id, $ids, true))) {
                return null;
            }
            $slot = self::parseBrand((string) json_encode($row));
            if ($slot === null) {
                return null;
            }
            if ($id !== null) {
                $ids[] = $id;
            }
            $rows[] = ['id' => $id, 'name' => $slot->name, 'hex' => $slot->hex];
        }
        return ['base' => $data['base'], 'rows' => $rows];
    }
```

`PaletteSettings::validate()`: replace the revision-4 per-slot loop with:

```php
        $list = $input->theme_brand_colors;
        if ($list === '') {
            $errors['theme_brand_colors'] = 'clear a brand colour with Clear, which checks where it is used';
        } elseif ($list !== null && self::parseSubmitted($list) === null) {
            $errors['theme_brand_colors'] = 'a list of brand colours, each a name (1–'
                . self::NAME_MAX . ' characters) and a hex colour, and the revision it was edited from';
        }
```

Delete `encodeBrand()` once `grep -rn encodeBrand core` shows no caller.

`UpdateGeneralSettingsData`: replace the three `theme_brand_N` properties with:

```php
        /**
         * The brand colour list (custom palette spec §2.3): `{"base": <revision>, "colors":[{"id"?,
         * "name", "hex"}, …]}` in display order; a colour without an id is new; `base` is the stored
         * revision the list was edited from (a stale one is a 409). Removing one is Clear's.
         */
        #[Rule('string')]
        public readonly ?string $theme_brand_colors = null,
```

(Keep the DTO's existing attribute and docblock style.)

`PaletteMutations::save()`: replace the revision-4 slot loop with:

```php
            if (array_key_exists('theme_brand_colors', $pairs)) {
                $submitted = PaletteSettings::parseSubmitted($pairs['theme_brand_colors'])
                    ?? throw new BrandColorsRefused('a list of brand colours is required');
                // lock() cleared the store's read cache: this is the committed list and its revision.
                [, , $revision] = BrandColors::parse($this->settings->stored('theme_brand_colors'));
                $pairs['theme_brand_colors'] = BrandColors::applied(
                    $held->palette,
                    $revision,
                    $submitted['base'],
                    $submitted['rows'],
                    static fn (int $id): bool => $held->jobReplacing($id) !== null,
                );
            }
```

Update its docblock (`theme_brand_colors` as submitted, resolved here under the row) and add
`@throws BrandColorsRefused`.

`GeneralSettingsController::update()`:
- Replace the `foreach ([1, 2, 3] as $slot)` block with:

```php
        if ($input->theme_brand_colors !== null) {
            $paletteKeys['theme_brand_colors'] = $input->theme_brand_colors; // resolved under the palette row
        }
```

- Add a catch beside the `PaletteConflict` one:

```php
        } catch (\Thallo\Core\Content\Palette\BrandColorsRefused $e) {
            $this->settings->clearStoreCache(); // the transaction rolled the palette keys back
            return Response::validation(['theme_brand_colors' => $e->getMessage()]);
```

- [ ] **Step 4: Run them to see them pass, then the palette suites**

Run: `vendor/bin/phpunit tests/Unit/Settings tests/Integration/Content/GeneralSettingsAppearanceTest.php tests/Integration/Content/Palette tests/Integration/Settings tests/Integration/Http > "$SCRATCH/t4.log" 2>&1; tail -8 "$SCRATCH/t4.log"`
Expected: OK, with no incomplete tests left (`grep -rn "Task 4:" tests` is empty).

- [ ] **Step 5: phpcs and commit**

Run: `vendor/bin/phpcs core/src/Settings core/src/Content/Palette core/src/Http; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add core tests
git commit -m "feat(palette): saving the brand colour list — new colours take the next id ever issued, a list edited from an older revision is refused (409) whatever it changes, a list that omits a colour or adds past the limit is refused (422), a colour being replaced may move but not change (409)"
```

### Task 5: The palette endpoints take an id; preview claims carry a list

**Files:**
- Modify: `core/routes/admin.php` (L202–216)
- Modify: `core/src/Content/Palette/Http/PaletteController.php` (`usage`, `clear`, `replace`, the preview error text ~L149)
- Modify: `core/src/Content/Palette/Http/PalettePreviewData.php` (doc), `core/src/Content/Http/DTOs/MintPreviewData.php` (doc L54–58), `core/src/Content/Http/Controllers/PreviewController.php` (error text ~L146–155)
- Modify: `core/src/Settings/PaletteSettings.php` (`previewClaim` `brands` case, `preview()`)
- Modify: `core/src/Content/Preview/PreviewToken.php` (`brandsClaim` L176–187, doc L136–138)
- Modify: `packages/thallo-contracts/src/Style/PaletteProvider.php`, `packages/thallo-contracts/src/Delivery/PreviewSession.php` (docblocks)
- Test: `tests/Integration/Http/PaletteApiTest.php`, `tests/Integration/Http/PalettePreviewTest.php`, `tests/Unit/Content/PreviewTokenTest.php`, `tests/Integration/Settings/PaletteSettingsTest.php`

**Interfaces:**
- Consumes: `Palette` (Task 1), `BrandColors::id()` (Task 3).
- Produces:
  - Routes `GET /appearance/palette/brand/{id}/usage`, `DELETE /appearance/palette/brand/{id}`, `POST /appearance/palette/brand/{id}/replace`, each with `->where('id', '[1-9][0-9]{0,3}')`. Controller methods `usage(int $id)`, `clear(int $id, ?Request $request = null)`, `replace(ReplaceBrandData $input, int $id, ?Request $request = null)`.
  - Preview claim `palette.brands`: `list<{id: int, name: string, hex: string}>`, the whole pending list in order. Absent means the saved list.

- [ ] **Step 1: Write the failing tests**

`PaletteApiTest`. Follow the file's request idiom:
- **`testTheEndpointsTakeAnyIssuedId`:** `configure(12, 'Teal', '#0f766e')`; `GET …/brand/12/usage` is 200 with `usage.slot === 12`; `DELETE …/brand/12` is 200, and the palette shows 12 removed. `GET …/brand/0/usage` and `…/brand/10000/usage` are 404 (route constraint). `DELETE …/brand/7` (never issued) is 200 with nothing changed, because an unconfigured id is already clear.
- Update the existing tests' URLs where they name `{slot}`. The paths are unchanged, so only a test that hard-codes the route pattern needs edits.

`PalettePreviewTest`:
- **`testAPreviewClaimCarriesTheWholePendingListInOrder`:** mint a preview with `palette: {brands: [{id: 5, name: 'Rose', hex: '#c98a8a'}, {id: 1, name: 'Gold', hex: '#8a6a2a'}]}` while only 1 is saved. The preview render's `theme_colors_style()` carries `--brand-5` before `--brand-1`, and the public render carries only `--brand-1`. A claim with `id: 0`, a duplicate id, or a map instead of a list is a 422.

`PreviewTokenTest`: `brandsClaim` accepts the list form, rejects `{"1": {...}}` (the old map form), and rejects an id outside 1–9999.

`PaletteSettingsTest`:
- **`testPreviewReplacesTheListAndKeepsRemovedNamesAndTheLimit`:** `preview(['brands' => [['id' => 6, 'name' => 'Sky', 'hex' => '#38bdf8']]])->ids() === [6]`, with `removed` and `limit` from the saved palette. Also `preview([])` returns the saved list.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Http/PaletteApiTest.php tests/Integration/Http/PalettePreviewTest.php tests/Unit/Content/PreviewTokenTest.php tests/Integration/Settings/PaletteSettingsTest.php`
Expected: FAIL. The routes are constrained to `[123]` (12 is a 404), the controller refuses ids not
in `[1, 2, 3]`, and the claim validators expect slot keys.

- [ ] **Step 3: Implement**

Routes:
- Each of the three becomes `/appearance/palette/brand/{id}…` with `->where('id', '[1-9][0-9]{0,3}')`.
- Update the comment: "usage, clearing and replacing a brand colour (by its permanent id) need content.manage".

`PaletteController`:
- Rename the `int $slot` parameters to `int $id`.
- Delete the three `in_array($slot, [1, 2, 3], true)` checks (the route constraint does that).
- Keep `$this->mutations === null` / `$this->replace === null` as the only 404 conditions.
- Update the `#[ApiOperation]` docs: "the colour with this id".
- The preview error message becomes `'a palette is neutral_custom (six hex colours), dark_base (a neutral family) and brands (a list of {id, name, hex})'`. Use the same text in `PreviewController`.

`PaletteSettings::previewClaim()`, the `brands` case:

```php
                case 'brands':
                    if (!is_array($value) || !array_is_list($value)) {
                        return null;
                    }
                    $brands = [];
                    foreach ($value as $brand) {
                        $id = is_array($brand) ? BrandColors::id($brand['id'] ?? null) : null;
                        $parsed = $id === null ? null : self::parseBrand((string) json_encode($brand));
                        if ($parsed === null || isset($brands[$id])) {
                            return null;
                        }
                        $brands[$id] = $parsed;
                    }
                    $out[$key] = array_map(
                        static fn (int $id, BrandSlot $b): array => ['id' => $id] + $b->toArray(),
                        array_keys($brands),
                        array_values($brands),
                    );
                    break;
```

`PaletteSettings::preview()`: replace the per-slot loop with:

```php
        $brands = $saved->brands;
        if (is_array($claim['brands'] ?? null)) {
            $brands = [];
            foreach ($claim['brands'] as $brand) {
                $id = is_array($brand) ? BrandColors::id($brand['id'] ?? null) : null;
                $parsed = $id === null ? null : self::parseBrand((string) json_encode($brand));
                if ($parsed !== null) {
                    $brands[$id] = $parsed;
                }
            }
        }
        return new Palette($neutral, $base, $brands, $saved->removed, $saved->limit);
```

`PreviewToken::brandsClaim()`: a list whose every entry has a `BrandColors::id()`-valid int `id`
(the token is in core, so use the helper directly), distinct, and a string `name` and `hex`.
Return false otherwise. Update its docblock (L136–138) and the `MintPreviewData`,
`PalettePreviewData`, `PaletteProvider::preview` and `PreviewSession::$palette` docblocks to "a
list of {id, name, hex} in display order — the whole pending list; absent reads the saved one".

- [ ] **Step 4: Run them to see them pass**

Run: `vendor/bin/phpunit tests/Integration/Http tests/Unit/Content/PreviewTokenTest.php tests/Integration/Settings tests/Integration/Content/PreviewAppearanceTest.php > "$SCRATCH/t5.log" 2>&1; tail -6 "$SCRATCH/t5.log"`
Expected: OK.

- [ ] **Step 5: phpcs and commit**

Run: `vendor/bin/phpcs core/routes/admin.php core/src/Content core/src/Settings packages/thallo-contracts/src; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add core packages/thallo-contracts tests
git commit -m "feat(palette): the brand colour endpoints take the colour's permanent id ([1-9][0-9]{0,3}); preview claims carry the whole pending list in order"
```

### Task 6: The style schema's palette — limit, order, removed colours, who may manage

**Files:**
- Modify: `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php` (`show`, `palette`)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (`makeStyleSchemaController` ~L331)
- Modify: `packages/thallo-render/src/Http/DTOs/StylePaletteData.php` (doc)
- Test: `tests/Integration/Render/StyleSchemaEndpointTest.php`

**Interfaces:**
- Consumes: `Palette::ids()`, `brands`, `removed`, `limit`, `labelOf()` (Tasks 1, 3); `Thallo\Contracts\Authorization\PermissionRequirementAuthority`.
- Produces: the style schema response.
  - `vocabulary.domains.color`: the theme's colour names, then `brand-N`, `brand-N-contrast` for each configured or replacing id in order.
  - `palette.limit: int`, `palette.order: list<string>`, `palette.can_manage: bool`.
  - `palette.slots`:
    - `brand-N` configured or replacing: `{name, hex, state: 'configured'|'replacing', reserved, replacing}`;
    - removed: `{name, state: 'removed'}`;
    - never issued: absent;
    - at limit 0: removed only.
  - `palette.labels`: every theme colour, plus every stored or removed id's name, with "— text" for the contrast token.
  - `show(?Request $request = null)`.

- [ ] **Step 1: Write the failing test**

Add to `StyleSchemaEndpointTest`. Use its `schemaPalette()` helper; for `can_manage` and the
vocabulary, call `show()` with a request carrying a user, following the file's permission tests:

```php
public function testThePaletteListsColoursInOrderWithRemovedOnesAndTheLimit(): void
{
    $this->configure(4, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $this->configure(7, 'Teal', '#0f766e');
    $this->container()->get(PaletteMutations::class)->clear(7, null);
    $this->container()->get(RequestPalette::class)->refresh();
    $data = $this->schema(); // the full response data; add the helper beside schemaPalette() if missing
    $palette = $data['palette'];

    self::assertSame(3, $palette['limit']);
    self::assertSame(['brand-4', 'brand-2'], $palette['order']);
    self::assertSame('configured', $palette['slots']['brand-4']['state']);
    self::assertSame(['name' => 'Teal', 'state' => 'removed'], $palette['slots']['brand-7']);
    self::assertArrayNotHasKey('brand-1', $palette['slots']);
    self::assertSame('Teal', $palette['labels']['color.brand-7']);
    self::assertSame('Gold — text', $palette['labels']['color.brand-4-contrast']);
    $colours = $data['vocabulary']['domains']['color'];
    self::assertSame(
        ['brand-4', 'brand-4-contrast', 'brand-2', 'brand-2-contrast'],
        array_values(array_filter($colours, static fn (string $n): bool => str_starts_with($n, 'brand-'))),
    );
    self::assertSame('accent', $colours[array_search('accent', $colours, true)]); // theme names first
    self::assertLessThan(array_search('brand-4', $colours, true), array_search('black', $colours, true));
}

public function testCanManageFollowsContentManage(): void
{
    // reuse the file's permission-request helpers: one user with content.edit only, one with content.manage
    self::assertFalse($this->schemaAs(['content.edit'])['palette']['can_manage']);
    self::assertTrue($this->schemaAs(['content.manage'])['palette']['can_manage']);
}
```

The limit-0 case is a unit-level check on a directly built controller. Construct
`new StyleSchemaController($themeLocator, $requestPaletteOverAProviderReturning(new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal'], 0)))`
(build the `RequestPalette` the way `makeRequestPalette` does, over a small anonymous
`PaletteProvider`). Assert:
- `palette.limit === 0`, `order === []`;
- `slots` holds only `brand-3` (removed);
- `labels['color.brand-1'] === 'Gold'`;
- no `brand-` names in `vocabulary.domains.color`.

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Render/StyleSchemaEndpointTest.php`
Expected: FAIL. There is no `limit`, `order` or `can_manage`, the slots are keyed 1–3 with
`unset`, and the colour vocabulary has no brand names.

- [ ] **Step 3: Implement**

Constructor: add `private readonly ?\Thallo\Contracts\Authorization\PermissionRequirementAuthority $permissions = null,`
last. `makeStyleSchemaController` passes
`$container->has(PermissionRequirementAuthority::class) ? $container->get(...) : null`.

`show(?Request $request = null)` (`use Symfony\Component\HttpFoundation\Request;`):
- Build `$domains` as today. Then:

```php
        $palette = $this->consistentPalette();
        // The configured brand colours join the colour names, in the author's order (custom palette spec §3.1).
        foreach ($palette['order'] as $name) {
            $domains['color'][] = $name;
            $domains['color'][] = $name . '-contrast';
        }
        $palette['can_manage'] = $request !== null
            && $this->permissions?->allows($request, ['content.manage']) === true;
```

- Pass `'palette' => $palette` in the response. `paletteBlock()` (used after Clear) returns
  `consistentPalette()` with `can_manage => true`: only `content.manage` can call Clear.

`palette()`:

```php
    private function palette(): array
    {
        $palette = $this->palette?->current() ?? Palette::empty();
        $statuses = $this->statuses?->statuses() ?? [];
        $labels = [];
        foreach (self::LABELS as $name => $label) {
            $labels['color.' . $name] = $label;
        }
        foreach (array_keys($palette->brands + $palette->removed) as $id) {
            $labels["color.brand-{$id}"] = $palette->labelOf($id);
            $labels["color.brand-{$id}-contrast"] = $palette->labelOf($id) . ' — text';
        }
        $slots = [];
        $order = [];
        foreach ($palette->configured() as $id => $brand) {
            $replacing = $statuses[$id]['replacing'] ?? null;
            $order[] = "brand-{$id}";
            $slots["brand-{$id}"] = [
                'name' => $brand->name,
                'hex' => $brand->hex,
                'state' => $replacing !== null ? 'replacing' : 'configured',
                'reserved' => (bool) ($statuses[$id]['reserved'] ?? false),
                'replacing' => $replacing === null ? null : [
                    'to' => $replacing['to'],
                    'to_label' => $labels[$replacing['to']] ?? $replacing['to'],
                    'contrast_to' => $replacing['contrast_to'],
                    'contrast_to_label' => $replacing['contrast_to'] === null
                        ? null
                        : ($labels[$replacing['contrast_to']] ?? $replacing['contrast_to']),
                ],
            ];
        }
        foreach ($palette->removed as $id => $name) {
            $slots["brand-{$id}"] ??= ['name' => $name, 'state' => 'removed'];
        }
        $swatches = EffectivePalette::of(
            $this->appearance?->accent() ?? ThemeColors::DEFAULT_ACCENT,
            $this->appearance?->neutral() ?? ThemeColors::DEFAULT_NEUTRAL,
            $this->appearance?->background() ?? 'plain',
            $palette,
        )->swatches();
        return [
            'limit' => $palette->limit,
            'order' => $order,
            'slots' => $slots,
            'swatches' => $swatches,
            'labels' => $labels,
            'color_mode' => $this->colorMode,
        ];
    }
```

Update its docblock and return type, the `#[ApiOperation]` description ("…the workspace's palette:
its limit, the brand colours in order with their states, removed colours by name, swatches, labels
and whether the reader may manage it…"), and `StylePaletteData`'s field docs to match the shape in
the Interfaces block above.

- [ ] **Step 4: Run it to see it pass, then the render suite**

Run: `vendor/bin/phpunit tests/Integration/Render tests/Unit/Contracts/StyleSchemaSnapshotTest.php > "$SCRATCH/t6.log" 2>&1; tail -6 "$SCRATCH/t6.log"`
Expected: OK. If `StyleSchemaSnapshotTest` pins the response shape, update the snapshot in this
step and ledger it.

- [ ] **Step 5: phpcs and commit**

Run: `vendor/bin/phpcs packages/thallo-render/src; echo "phpcs exit $?"`
Expected: `phpcs exit 0`.

```bash
git add packages/thallo-render tests
git commit -m "feat(palette): the style schema's palette carries the limit, the brand colours in order, removed colours by name and whether the reader may manage them; the colour vocabulary lists the configured brand colours after the theme's"
```

### Task 7: Appearance edits a list of brand colours

**Files:**
- Create: `admin/src/pages/appearance/brandColors.ts`
- Modify: `admin/src/composables/useSettingsForm.ts` (`adopt()`, `saved(sent?)`)
- Modify: `admin/src/queries/palette.ts` (`BrandKey` → `BrandEntry`, `PaletteLook.palette.brands`, `fetchPaletteUsage` / `clearBrand` / `replaceBrand` take `id: number`)
- Modify: `admin/src/queries/generalSettings.ts` (L45–54: `theme_brand_colors?: string`)
- Modify: `admin/src/queries/styleSchema.ts` (`palette.limit`, `order`, `can_manage`; `PaletteSlot.state` adds `'removed'`, drops `'unset'`; `hex?: string | null`; `reserved?`/`replacing?` optional for removed)
- Modify: `admin/src/pages/appearance/appearanceTabs.ts` (`TAB_KEYS.colours`: `theme_brand_colors` replaces the three keys)
- Modify: `admin/src/pages/appearance/index.vue` (the brand parts of the script and the Brand colours field)
- Rewrite: `admin/src/pages/appearance/components/BrandColorsField.vue`
- Modify: `admin/src/pages/appearance/components/ClearBrandDialog.vue` (`slot: 1|2|3` → `id: number`; `destinations`, `hasPair`)
- Modify: `admin/src/pages/appearance/components/ContrastChecks.vue` (`label()`)
- Test: `admin/src/__tests__/brandColors.spec.ts` (new), `admin/src/__tests__/useSettingsForm.spec.ts` (new), `admin/src/__tests__/appearance-palette.spec.ts`, `admin/src/__tests__/clear-brand-dialog.spec.ts`, `admin/src/__tests__/appearanceTabs.spec.ts`

**Interfaces:**
- Consumes:
  - the settings shape `theme_brand_colors` with its `revision`, and the save response's `settings` (Task 3, Task 4);
  - the save refusals on `theme_brand_colors` (Task 4);
  - `/brand/{id}` (Task 5);
  - the schema's `palette.limit`, `order`, `slots`, `labels`, `can_manage` (Task 6);
  - the tabs page (tabs plan).
- Produces:

```ts
// brandColors.ts
export interface BrandRow { key: string; id: number | null; name: string; hex: string }
export interface StoredBrandColors { revision: number; colors: Array<{ id: number; name: string; hex: string }>; removed: Array<{ id: number; name: string }> }
export interface Submission { json: string; keys: string[] } // keys: the row behind each sent colour, in order
export function parseStored(json: string | undefined): StoredBrandColors
export function newer(a: StoredBrandColors, b: StoredBrandColors): StoredBrandColors
export function parseDraft(json: string | undefined): BrandRow[]
export function serializeDraft(rows: BrandRow[]): string
export function newRow(): BrandRow
export function validRow(row: BrandRow): { name: string; hex: string } | null
export function submission(rows: BrandRow[], stored: StoredBrandColors): Submission | null
export function adoptIds(rows: BrandRow[], sentKeys: string[], saved: StoredBrandColors): BrandRow[]
export function dropRemoved(rows: BrandRow[], stored: StoredBrandColors): BrandRow[]
export function previewBrands(rows: BrandRow[], stored: StoredBrandColors): BrandEntry[]
```

```ts
// useSettingsForm.ts — gains:
adopt(patch: Partial<Pick<GeneralSettings, K>>): void // writes without marking the form dirty
saved(sent?: Pick<GeneralSettings, K>): void // clean only if the form still equals what was sent
```

```ts
// palette.ts
export interface BrandEntry { id: number; name: string; hex: string }
PaletteLook.palette.brands: BrandEntry[]
```

- `BrandColorsField` props `{ rows: BrandRow[]; palette: Record<string, PaletteSlot>; jobs: PaletteJob[]; limit: number }`, emits `update:rows` and `clear: [id: number]`.
- `ClearBrandDialog` prop `id: number` (was `slot`).

- [ ] **Step 1: Write the failing tests**

`admin/src/__tests__/brandColors.spec.ts`:

```ts
// The Appearance page's brand colour rows (custom palette spec §2.3, §5.1): the stored list read
// into rows, what Save sends, and the list a preview frames.
import { describe, it, expect } from 'vitest'
import {
  adoptIds,
  dropRemoved,
  newer,
  newRow,
  parseDraft,
  parseStored,
  previewBrands,
  serializeDraft,
  submission,
} from '@/pages/appearance/brandColors'

const STORED =
  '{"revision":3,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"},{"id":1,"name":"Rose","hex":"#c98a8a"}],' +
  '"removed":[{"id":7,"name":"Teal"}]}'

describe('brand colour rows', () => {
  it('reads the stored list into rows in order, and ignores junk', () => {
    expect(parseDraft(STORED).map((r) => [r.id, r.name])).toEqual([
      [4, 'Gold'],
      [1, 'Rose'],
    ])
    expect(parseStored('nope')).toEqual({ revision: 0, colors: [], removed: [] })
    expect(parseStored(STORED).revision).toBe(3)
    expect(parseDraft('')).toEqual([])
  })

  it('keeps a row’s key across a round trip, so a drag or an edit never remounts it', () => {
    const rows = [...parseDraft(STORED), newRow()]
    expect(parseDraft(serializeDraft(rows)).map((r) => r.key)).toEqual(rows.map((r) => r.key))
  })

  it('sends nothing when nothing changed, and the whole list when something did', () => {
    const stored = parseStored(STORED)
    expect(submission(parseDraft(STORED), stored)).toBeNull()
    const moved = parseDraft(STORED).reverse()
    expect(JSON.parse(submission(moved, stored)!.json)).toEqual({
      base: 3,
      colors: [
        { id: 1, name: 'Rose', hex: '#c98a8a' },
        { id: 4, name: 'Gold', hex: '#8a6a2a' },
      ],
    })
  })

  it('sends a new row without an id once it is a name and a colour, and leaves an unfinished one out', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38BDF8' }, newRow()]
    const sent = submission(rows, stored)!
    expect(JSON.parse(sent.json).colors.at(-1)).toEqual({ name: 'Sky', hex: '#38bdf8' })
    expect(JSON.parse(sent.json).colors).toHaveLength(3)
    expect(sent.keys).toEqual([rows[0]!.key, rows[1]!.key, rows[2]!.key])
  })

  it('takes the ids a save gave from its response, keeping edits made since', () => {
    const stored = parseStored(STORED)
    const sky = { ...newRow(), name: 'Sky', hex: '#38bdf8' }
    const rows = [...parseDraft(STORED), sky]
    const sent = submission(rows, stored)!
    const saved = parseStored(
      '{"revision":4,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"},{"id":1,"name":"Rose","hex":"#c98a8a"},' +
        '{"id":8,"name":"Sky","hex":"#38bdf8"}],"removed":[{"id":7,"name":"Teal"}]}',
    )
    // edited while the save was in flight: Sky renamed, and another row added
    const now = [...rows.slice(0, 2), { ...sky, name: 'Sky blue' }, newRow()]
    const adopted = adoptIds(now, sent.keys, saved)
    expect(adopted.map((r) => [r.id, r.name])).toEqual([
      [4, 'Gold'],
      [1, 'Rose'],
      [8, 'Sky blue'],
      [null, ''],
    ])
    expect(adopted[2]!.key).toBe(sky.key) // never remounted
    // the next save edits from the saved revision and names Sky by its id
    expect(JSON.parse(submission(adopted, saved)!.json)).toMatchObject({
      base: 4,
      colors: [{ id: 4 }, { id: 1 }, { id: 8, name: 'Sky blue' }],
    })
  })

  it('adopts nothing when the response does not line up with what was sent', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38bdf8' }]
    const sent = submission(rows, stored)!
    expect(adoptIds(rows, sent.keys, stored)).toEqual(rows)
  })

  it('drops a colour that left the stored list, and keeps new rows', () => {
    const cleared = parseStored(
      '{"revision":4,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"}],"removed":[{"id":1,"name":"Rose"},{"id":7,"name":"Teal"}]}',
    )
    const rows = [...parseDraft(STORED), newRow()]
    expect(dropRemoved(rows, cleared).map((r) => r.id)).toEqual([4, null])
  })

  it('prefers the newer of two readings of the stored list', () => {
    const older = parseStored(STORED)
    const later = { ...older, revision: 4 }
    expect(newer(older, later)).toBe(later)
    expect(newer(later, older)).toBe(later)
  })

  it('keeps a saved colour whose fields are mid-edit as it was saved', () => {
    const stored = parseStored(STORED)
    const rows = parseDraft(STORED)
    rows[0]!.name = ''
    expect(submission(rows, stored)).toBeNull()
  })

  it('numbers new rows above every id seen, for the preview only', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38bdf8' }]
    expect(previewBrands(rows, stored).map((b) => b.id)).toEqual([4, 1, 8])
  })
})
```

`admin/src/__tests__/useSettingsForm.spec.ts`:

```ts
// One slice of the general settings on its own page (useSettingsForm): a value the page adopts from
// the server never counts as an edit, and a save marks the form clean only if nothing was edited
// while it was in flight.
import { describe, it, expect } from 'vitest'
import { nextTick, ref } from 'vue'
import { useSettingsForm } from '@/composables/useSettingsForm'
import type { GeneralSettings } from '@/queries/generalSettings'

const settings = (over: Partial<GeneralSettings> = {}) =>
  ({ site_logo: '', theme_radius: 'round', ...over }) as GeneralSettings

describe('useSettingsForm', () => {
  it('adopting a value is not an edit', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, adopt } = useSettingsForm(data, { site_logo: '', theme_radius: 'round' })
    await nextTick()
    adopt({ site_logo: 'blob00000001' })
    await nextTick()
    expect(form.site_logo).toBe('blob00000001')
    expect(dirty.value).toBe(false)
  })

  it('a save marks the form clean only if nothing changed since it was sent', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, payload, saved } = useSettingsForm(data, { site_logo: '', theme_radius: 'round' })
    await nextTick()
    form.theme_radius = 'sharp'
    await nextTick()
    const sent = payload()
    form.site_logo = 'blob00000002' // edited while the save was in flight
    await nextTick()
    saved(sent)
    expect(dirty.value).toBe(true)
    saved(payload())
    expect(dirty.value).toBe(false)
  })

  it('saved() with nothing sent behaves as before', async () => {
    const data = ref<GeneralSettings | undefined>(settings())
    const { form, dirty, saved } = useSettingsForm(data, { site_logo: '', theme_radius: 'round' })
    await nextTick()
    form.theme_radius = 'sharp'
    await nextTick()
    saved()
    expect(dirty.value).toBe(false)
  })
})
```

In `appearance-palette.spec.ts`, rewrite the brand tests for the list (the file mounts the page
with mocks; set the settings fixture's `theme_brand_colors` to `STORED`-like JSON, and the
schema's palette to `{limit: 3, order: ['brand-4', 'brand-1'], slots: {…}, …}`):
- **`'lists the brand colours in order, with the count against the limit'`:** two rows (`brand-row-4`, `brand-row-1`) and the text `2 of 3`.
- **`'Add colour appends an empty row with a plain remove button, and saves it without an id'`:** click `brand-add` and fill the new row's name and hex. Save sends `theme_brand_colors` whose last entry has no `id`. The new row has `brand-row-remove`, not `brand-row-clear`.
- **`'Add is disabled at the limit and says so'`:** with three stored colours, `brand-add` is disabled and `brand-limit` reads `This site allows 3 brand colours`.
- **`'removing an unsaved row sends nothing'`:** add, then remove; Save does not send `theme_brand_colors`.
- **`'reordering sends the new order'`:** emit the draggable's `update:modelValue` with the two rows reversed (the stub pattern `BlockList`'s spec uses for `VueDraggable`). Save sends the ids in the new order.
- **`'Clear on a saved colour opens the dialog for that id'`:** click `brand-row-clear` on id 4. `ClearBrandDialog` receives `id: 4`.
- **`'the section is hidden when the limit is 0'`:** schema `limit: 0`, so no `brand-colors`.
- **`'a refused list shows its message on the Colours tab'`:** save rejects with `ApiError(422, { theme_brand_colors: 'This site allows 3 brand colours' })`. The Colours tab has its error dot, and `brand-colors-error` shows the message.
- **`'the preview carries the pending list, new rows numbered above every id seen'`:** after adding Sky, the last `previewPalette` call's `palette.brands` ends with `{ id: 8, name: 'Sky', … }`.
- **`'adopts the assigned ids from the save response, so an edit before the refetch saves cleanly'`:**
  - The settings query stays on the old value throughout (`settingsData` is not updated, so the refetch is "delayed").
  - Add Sky and save; `saveMock` resolves with settings whose `theme_brand_colors` is revision 4 with Sky as id 8.
  - Before any refetch, rename Sky to "Sky blue". The navbar Save chip still shows unsaved changes.
  - Save again. The second payload's `theme_brand_colors` is `{"base":4,"colors":[…, {"id":8,"name":"Sky blue",…}]}`: no entry without an id, and no second Sky.
- **`'an edit made while a save is in flight stays unsaved'`:**
  - Make `saveMock` return a promise the test resolves later.
  - Click Save, rename a row, then resolve.
  - The Colours tab's unsaved dot and the navbar chip are still shown, and the renamed value is still in the field.
- **`'a cleared colour leaves the rows without marking the form dirty'`:**
  - After `ClearBrandDialog` emits `done` for id 4, set `settingsData` to the stored list with 4 removed and revision 4.
  - Row `brand-row-4` is gone, no unsaved dot appears, and the next save's `base` is 4.
- **`'a stale list’s 409 says to reload'`:**
  - Save rejects with `ApiError('Brand colours changed since you opened this page — reload to see the latest', 409, {}, { error: { details: { conflict: '…' } } })`.
  - The error toast carries that message, and the rows are unchanged.
- Existing replacing and reserved tests: keyed by id 4 (`brand-row-4-progress`, `brand-row-4-reserved`).

In `clear-brand-dialog.spec.ts`:
- mount with `id: 4`;
- destinations exclude `brand-4`, a `removed` slot and a `replacing` slot, and include a configured `brand-12`;
- `hasPair` treats `color.brand-12` as carrying its own text colour (no contrast picker).

In `appearanceTabs.spec.ts`, update the key-ownership test so `theme_brand_colors.colors.1.name`
maps to `colours`.

- [ ] **Step 2: Run them to see them fail**

Run: `cd admin && pnpm vitest run src/__tests__/brandColors.spec.ts src/__tests__/appearance-palette.spec.ts src/__tests__/clear-brand-dialog.spec.ts src/__tests__/appearanceTabs.spec.ts`
Expected: FAIL. `brandColors.ts` is missing, and the page still renders three fixed slots.

- [ ] **Step 3: Implement the helpers and the types**

`admin/src/pages/appearance/brandColors.ts`:

```ts
// The Appearance page's brand colour rows (custom palette spec §2.3, §5.1). The form keeps the
// rows as JSON (useSettingsForm's one string per key): each row its stable key, its id — null
// until the server gives a new colour one — its name and its hex. Save sends the whole list when it
// differs from the stored one; a new row joins once it is a name and a colour.
import { normalizeHex } from '@/style/contrast'
import type { BrandEntry } from '@/queries/palette'

export interface BrandRow {
  key: string
  id: number | null
  name: string
  hex: string
}
export interface StoredBrandColors {
  /** What a list edited from this one names as its base; every write moves it on. */
  revision: number
  colors: Array<{ id: number; name: string; hex: string }>
  removed: Array<{ id: number; name: string }>
}
/** A list to save, with the row behind each colour it sends, in order: how the response's ids are matched back. */
export interface Submission {
  json: string
  keys: string[]
}

const NAME_MAX = 32
let counter = 0

function parse(json: string | undefined): Record<string, unknown> | null {
  if (!json) return null
  try {
    const value: unknown = JSON.parse(json)
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null
  } catch {
    return null
  }
}
const isId = (v: unknown): v is number => Number.isInteger(v) && (v as number) >= 1 && (v as number) <= 9999

export function parseStored(json: string | undefined): StoredBrandColors {
  const data = parse(json)
  const list = (v: unknown) => (Array.isArray(v) ? (v as Array<Record<string, unknown>>) : [])
  const revision = data?.revision
  return {
    revision: Number.isInteger(revision) && (revision as number) >= 0 ? (revision as number) : 0,
    colors: list(data?.colors)
      .filter((c) => isId(c.id) && typeof c.name === 'string' && typeof c.hex === 'string')
      .map((c) => ({ id: c.id as number, name: c.name as string, hex: c.hex as string })),
    removed: list(data?.removed)
      .filter((r) => isId(r.id) && typeof r.name === 'string')
      .map((r) => ({ id: r.id as number, name: r.name as string })),
  }
}

/** The form's rows: stored colours (key `b<id>`) or draft rows carrying their own key. */
export function parseDraft(json: string | undefined): BrandRow[] {
  const data = parse(json)
  if (!Array.isArray(data?.colors)) return []
  return (data.colors as Array<Record<string, unknown>>).map((c) => ({
    key: typeof c.key === 'string' ? c.key : `b${String(c.id)}`,
    id: isId(c.id) ? c.id : null,
    name: typeof c.name === 'string' ? c.name : '',
    hex: typeof c.hex === 'string' ? c.hex : '',
  }))
}

export function serializeDraft(rows: BrandRow[]): string {
  return JSON.stringify({ colors: rows })
}

export function newRow(): BrandRow {
  counter += 1
  return { key: `n${counter}`, id: null, name: '', hex: '' }
}

/** A row as the server would store it, or null while it is not a name and a colour. */
export function validRow(row: BrandRow): { name: string; hex: string } | null {
  const name = row.name.trim()
  const hex = normalizeHex(row.hex)
  return name !== '' && name.length <= NAME_MAX && hex !== null ? { name, hex } : null
}

/** The newer of two readings of the stored list: a save's response can arrive before or after a refetch. */
export function newer(a: StoredBrandColors, b: StoredBrandColors): StoredBrandColors {
  return b.revision > a.revision ? b : a
}

/**
 * What Save sends for the rows, or null for nothing: the list and the revision it was edited from.
 * A saved colour mid-edit goes as it was saved, an unfinished new row stays out, and an unchanged
 * list is not sent at all.
 */
export function submission(rows: BrandRow[], stored: StoredBrandColors): Submission | null {
  const saved = new Map(stored.colors.map((c) => [c.id, c]))
  const colors: Array<{ id?: number; name: string; hex: string }> = []
  const keys: string[] = []
  for (const row of rows) {
    const valid = validRow(row)
    if (row.id === null) {
      if (valid) {
        colors.push(valid)
        keys.push(row.key)
      }
      continue
    }
    const kept = valid ?? saved.get(row.id)
    if (kept) {
      colors.push({ id: row.id, name: kept.name, hex: kept.hex })
      keys.push(row.key)
    }
  }
  const same =
    colors.length === stored.colors.length &&
    colors.every((c, i) => {
      const s = stored.colors[i]!
      return c.id === s.id && c.name === s.name && c.hex === s.hex
    })
  return same ? null : { json: JSON.stringify({ base: stored.revision, colors }), keys }
}

/**
 * The rows with the ids a save gave its new colours, read from the save's own response: the
 * response lists the colours in the order they were sent, so the row behind the i-th sent colour
 * takes the i-th id. Edits made since stay; a response that does not line up adopts nothing (the
 * refetch then decides).
 */
export function adoptIds(rows: BrandRow[], sentKeys: string[], saved: StoredBrandColors): BrandRow[] {
  if (saved.colors.length !== sentKeys.length) return rows
  const ids = new Map(sentKeys.map((key, i) => [key, saved.colors[i]!.id]))
  return rows.map((row) => (row.id === null && ids.has(row.key) ? { ...row, id: ids.get(row.key)! } : row))
}

/** The rows without colours that left the stored list (a Clear, a replacement completing); new rows stay. */
export function dropRemoved(rows: BrandRow[], stored: StoredBrandColors): BrandRow[] {
  const live = new Set(stored.colors.map((c) => c.id))
  return rows.filter((row) => row.id === null || live.has(row.id))
}

/** The pending list for the preview: new finished rows numbered above every id seen (never stored). */
export function previewBrands(rows: BrandRow[], stored: StoredBrandColors): BrandEntry[] {
  let next = Math.max(0, ...stored.colors.map((c) => c.id), ...stored.removed.map((r) => r.id), ...rows.map((r) => r.id ?? 0))
  const out: BrandEntry[] = []
  for (const row of rows) {
    const valid = validRow(row)
    if (valid) out.push({ id: row.id ?? ++next, ...valid })
  }
  return out
}
```

`queries/palette.ts`:
- Delete `BrandKey`. Add `export interface BrandEntry { id: number; name: string; hex: string }` and set `brands: BrandEntry[]` in `PaletteLook`.
- `fetchPaletteUsage(id: number)`, `clearBrand(id: number)`, `replaceBrand(id: number, to: string, contrastTo?: string)`, with URLs `${base()}/brand/${id}…`.

`queries/generalSettings.ts`: replace the three optional brand keys with
`/** The brand colour list (custom palette spec §2.3): JSON {colors, removed}, '' when unset. */ theme_brand_colors?: string`.

`queries/styleSchema.ts`:

```ts
  palette?: {
    /** How many brand colours the deployment allows (0: brand colours are off). */
    limit: number
    /** The configured (and replacing) brand colours, in the author's order. */
    order: string[]
    /** Whether the reader may manage brand colours (content.manage). */
    can_manage?: boolean
    slots: Record<string, PaletteSlot>
    swatches: Record<string, string>
    labels: Record<string, string>
    color_mode?: boolean
    generation: number
    replacements: ReplacementBatch
  }
}

/** One brand colour as the pickers see it (custom palette spec §5.2); a never-issued id is absent. */
export interface PaletteSlot {
  name: string
  hex?: string | null
  state: 'configured' | 'replacing' | 'removed'
  reserved?: boolean
  replacing?: null | { to: string; to_label: string; contrast_to: string | null; contrast_to_label: string | null }
}
```

Then run `pnpm type-check` and fix every consumer the type errors name (`TokenScaleControl` is
rewritten in Task 8; here only make it compile by treating a missing slot as unavailable).

- [ ] **Step 4: Implement the page and the field**

`index.vue`:
- Form defaults: replace `theme_brand_1/2/3: ''` with `theme_brand_colors: ''`.
- Delete `BRAND_KEYS`, `brandField`, `brandDrafts`, `setBrandDrafts`, `validBrand` and `configured` (moved to `brandColors.ts`). Add:

```ts
// ── The brand colours (custom palette spec §2.3, §5.1) ─────────────────────────────────────────
// The stored list as last seen — from the settings query or from a save's own response, whichever
// is newer: the form ignores a refetch while it holds edits, so a save's ids and revision are taken
// from the response directly (plan ruling 11).
const latestBrands = ref(parseStored(data.value?.theme_brand_colors))
watch(
  () => data.value?.theme_brand_colors,
  (json) => {
    latestBrands.value = newer(latestBrands.value, parseStored(json))
  },
)
const storedBrands = computed(() => latestBrands.value)
const brandRows = computed<BrandRow[]>(() => parseDraft(form.theme_brand_colors))
function setBrandRows(next: BrandRow[]): void {
  form.theme_brand_colors = serializeDraft(next)
}
// A colour that left the stored list — a Clear, a replacement completing — leaves the rows too,
// as an adoption, not an edit.
watch(latestBrands, (stored) => {
  const kept = dropRemoved(brandRows.value, stored)
  if (kept.length !== brandRows.value.length) adopt({ theme_brand_colors: serializeDraft(kept) })
})
const brandLimit = computed(() => styleSchema.value?.palette?.limit ?? 3)
/** The message a refused list came back with, shown under the rows until the next save. */
const brandError = ref<string | null>(null)
```

- `pendingPalette.brands`: `previewBrands(brandRows.value, storedBrands.value)`.
- `savedPalette.brands`: `storedBrands.value.colors.map((c) => ({ id: c.id, name: c.name, hex: c.hex }))`.
- `emptyPalette()`: `brands: []`.
- `useSettingsForm` destructuring adds `adopt`.
- `savePayload()`: replace the brand loop with:

```ts
  const brands = submission(brandRows.value, storedBrands.value)
  if (brands === null) delete out.theme_brand_colors
  else out.theme_brand_colors = brands.json
```

- `onSave`: send, then take the response's list and the new colours' ids, and mark the form clean
  only if nothing was edited while the save was in flight:

```ts
async function onSave() {
  errorTabs.value = new Set()
  brandError.value = null
  const sent = payload() // the form as it was when Save was pressed
  const brands = submission(brandRows.value, storedBrands.value)
  try {
    const result = await save.mutateAsync(savePayload())
    const savedBrands = parseStored(result.theme_brand_colors)
    latestBrands.value = newer(latestBrands.value, savedBrands)
    if (brands !== null) {
      // The ids the save gave, into the rows as they are now and into what was sent, so "nothing
      // changed since" compares like with like.
      adopt({ theme_brand_colors: serializeDraft(adoptIds(brandRows.value, brands.keys, savedBrands)) })
      sent.theme_brand_colors = serializeDraft(adoptIds(parseDraft(sent.theme_brand_colors), brands.keys, savedBrands))
    }
    saved(sent)
    appearanceChanges.notify('appearance')
    success('Appearance saved', 'Changes apply on the next page view.')
  } catch (e) {
    brandError.value = (e as { fieldErrors?: Record<string, string> }).fieldErrors?.theme_brand_colors ?? null
    // A refused save opens the first tab, in tab order, holding a field it names (tabs spec §3).
    const fields = (e as { fieldErrors?: Record<string, string> }).fieldErrors ?? {}
    const holding = tabsHolding(Object.keys(fields))
    errorTabs.value = holding
    const first = tabs.value.find((t) => holding.has(t))
    if (first !== undefined) tab.value = first
    notifyError(e, 'Couldn’t save the appearance settings')
  }
}
```

  A stale list's 409 carries its message in the toast (`notifyError` reads the body's message).
  The rows are left as they are, and reloading shows the latest.

- `clearing` becomes `ref<number | null>(null)`, and `onClearBrand(id: number)`.
- `clearingName`: `styleSchema.value?.palette?.labels[\`color.brand-${clearing.value}\`] ?? \`Brand ${clearing.value}\``.
- The template's Brand colours field:

```vue
<UFormField
  v-if="brandLimit > 0"
  label="Brand colours"
  description="Named colours every block's colour picker offers."
  :error="brandError ?? undefined"
>
  <BrandColorsField
    :rows="brandRows"
    :palette="schemaSlots"
    :jobs="jobs"
    :limit="brandLimit"
    @update:rows="setBrandRows"
    @clear="onClearBrand"
  />
  <p v-if="brandError" class="sr-only" data-test="brand-colors-error">{{ brandError }}</p>
</UFormField>
```

(If `UFormField`'s `:error` already renders the text with a test hook, drop the `sr-only` line and
point the test at that element.) `ClearBrandDialog` takes `:id="clearing"`.

`useSettingsForm.ts`: add `adopt` and give `saved` an optional snapshot:

```ts
  /** A value the page takes from the server (a save's ids, a colour that left): never an edit. */
  const adopt = (patch: Partial<Pick<GeneralSettings, K>>): void => {
    syncing = true
    Object.assign(form, patch)
    void nextTick(() => {
      syncing = false
    })
  }
  /**
   * Saved: the form matches the server again, so the post-save refetch may sync — unless it was
   * edited while the save was in flight (`sent` is what was sent), when it stays dirty and keeps
   * the edit.
   */
  const saved = (sent?: Pick<GeneralSettings, K>): void => {
    if (sent !== undefined && keys.some((key) => form[key] !== sent[key])) return
    dirty.value = false
  }

  return { form, dirty, payload, saved, adopt }
```

Update the file's header comment with one line on each. `pages/settings/general/index.vue` calls
`saved()` without an argument and behaves as before.

`BrandColorsField.vue` (rewrite):

```vue
<script setup lang="ts">
// The brand colours (custom palette spec §2.3, §4, §5.1): a list the author adds to, up to the
// deployment's limit, in the order every colour picker offers them. A saved colour is cleared
// through the page's dialog, which checks where it is used; a row not yet saved is simply removed.
// A colour being replaced shows the replacement's progress instead of its fields; one a running
// replacement writes to stays editable but cannot be cleared.
import { computed } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { PaletteJob } from '@/queries/palette'
import type { PaletteSlot } from '@/queries/styleSchema'
import { newRow, type BrandRow } from '../brandColors'
import HexInput from './HexInput.vue'

const props = defineProps<{
  rows: BrandRow[]
  /** The style schema's slots (`palette.slots`). */
  palette: Record<string, PaletteSlot>
  jobs: PaletteJob[]
  /** The deployment's limit. */
  limit: number
}>()
const emit = defineEmits<{ 'update:rows': [rows: BrandRow[]]; clear: [id: number] }>()

const NAME_MAX = 32
const full = computed(() => props.rows.length >= props.limit)
const unit = computed(() => (props.limit === 1 ? 'brand colour' : 'brand colours'))

function slotOf(row: BrandRow): PaletteSlot | undefined {
  return row.id === null ? undefined : props.palette[`brand-${row.id}`]
}
function nameOf(row: BrandRow): string {
  return slotOf(row)?.name || row.name || (row.id === null ? 'New colour' : `Brand ${row.id}`)
}
function set(row: BrandRow, part: 'name' | 'hex', value: string): void {
  emit('update:rows', props.rows.map((r) => (r.key === row.key ? { ...r, [part]: value } : r)))
}
function add(): void {
  if (!full.value) emit('update:rows', [...props.rows, newRow()])
}
function remove(row: BrandRow): void {
  emit('update:rows', props.rows.filter((r) => r.key !== row.key))
}
const replacing = (row: BrandRow) => slotOf(row)?.state === 'replacing'
function progress(row: BrandRow): string {
  const job = props.jobs.find((j) => j.slot === row.id)
  const to = slotOf(row)?.replacing?.to_label ?? 'its replacement'
  const counts = job ? ` — ${job.work_items_done} of ${job.work_items_total}` : ''
  const state = job?.status === 'failed' || job?.status === 'interrupted' ? ` (${job.status})` : ''
  return `Replacing ${nameOf(row)} with ${to}${counts}${state}`
}
/** The replacement writing to this colour, by the name of the colour it replaces. */
function reservedBy(row: BrandRow): string | null {
  if (!slotOf(row)?.reserved) return null
  const token = `color.brand-${row.id}`
  const job = props.jobs.find((j) => j.to === token || j.contrast_to === `${token}-contrast` || j.contrast_to === token)
  if (!job) return 'another'
  return props.palette[`brand-${job.slot}`]?.name ?? `Brand ${job.slot}`
}
</script>

<template>
  <div class="space-y-3" data-test="brand-colors">
    <p class="text-xs text-muted" data-test="brand-count">{{ rows.length }} of {{ limit }}</p>
    <VueDraggable
      :model-value="rows"
      handle="[data-drag]"
      :animation="150"
      class="space-y-3"
      @update:model-value="(next: BrandRow[]) => emit('update:rows', next)"
    >
      <div v-for="row in rows" :key="row.key" class="space-y-2" :data-test="`brand-row-${row.id ?? row.key}`">
        <p v-if="replacing(row)" class="text-sm text-muted" :data-test="`brand-row-${row.id}-progress`">
          {{ progress(row) }}
        </p>
        <div v-else class="flex items-start gap-2">
          <button
            type="button"
            data-drag
            class="mt-2 cursor-grab text-dimmed hover:text-default"
            :aria-label="`Move ${nameOf(row)}`"
          >
            <UIcon name="i-lucide-grip-vertical" class="size-4" />
          </button>
          <UInput
            :model-value="row.name"
            :maxlength="NAME_MAX"
            placeholder="Name"
            class="w-40 shrink-0"
            :aria-label="`${nameOf(row)} name`"
            :data-test="`brand-row-${row.id ?? row.key}-name`"
            @update:model-value="(v: string | number) => set(row, 'name', String(v))"
          />
          <HexInput
            class="min-w-0 flex-1"
            :model-value="row.hex"
            :label="nameOf(row)"
            :data-test="`brand-row-${row.id ?? row.key}-hex`"
            @update:model-value="(v: string) => set(row, 'hex', v)"
          />
          <UButton
            v-if="row.id === null"
            color="neutral"
            variant="ghost"
            icon="i-lucide-trash-2"
            :aria-label="`Remove ${nameOf(row)}`"
            data-test="brand-row-remove"
            @click="remove(row)"
          />
          <UButton
            v-else-if="!reservedBy(row)"
            color="neutral"
            variant="ghost"
            icon="i-lucide-x"
            :aria-label="`Clear ${nameOf(row)}`"
            data-test="brand-row-clear"
            @click="emit('clear', row.id)"
          >
            Clear
          </UButton>
        </div>
        <p v-if="reservedBy(row)" class="text-xs text-muted" :data-test="`brand-row-${row.id}-reserved`">
          Reserved by the {{ reservedBy(row) }} replacement
        </p>
      </div>
    </VueDraggable>
    <div class="flex items-center gap-3">
      <UButton
        icon="i-lucide-plus"
        variant="outline"
        color="neutral"
        size="sm"
        :disabled="full"
        data-test="brand-add"
        @click="add"
      >
        Add colour
      </UButton>
      <p v-if="full" class="text-xs text-muted" data-test="brand-limit">This site allows {{ limit }} {{ unit }}</p>
    </div>
  </div>
</template>
```

`ClearBrandDialog.vue`:
- prop `id: number` replaces `slot`;
- watch `[props.open, props.id]`;
- `fetchPaletteUsage(props.id)`, and pass `props.id` to `clearBrand` / `replaceBrand`;
- `destinations` uses `const m = /^brand-(\d+)$/.exec(name)` and `Number(m[1]) !== props.id && props.palette?.slots[name]?.state === 'configured'`;
- `hasPair = (token: string) => token === 'color.accent' || /^color\.brand-\d+$/.test(token)`.

`ContrastChecks.vue` `label()`:

```ts
  const brand = /^brand-(\d+)(-contrast)?$/.exec(name)
  if (brand) {
    const entry = props.look.palette.brands.find((b) => b.id === Number(brand[1]))
    const base = entry?.name || `Brand ${brand[1]}`
    return brand[2] ? `${base} — text` : base
  }
```

`appearanceTabs.ts`: in `TAB_KEYS.colours`, replace `'theme_brand_1', 'theme_brand_2', 'theme_brand_3'`
with `'theme_brand_colors'`.

- [ ] **Step 5: Run the tests to see them pass, then the admin gates**

Run: `cd admin && pnpm vitest run src/__tests__/brandColors.spec.ts src/__tests__/useSettingsForm.spec.ts src/__tests__/appearance-palette.spec.ts src/__tests__/clear-brand-dialog.spec.ts src/__tests__/appearanceTabs.spec.ts src/__tests__/appearancePage.spec.ts src/__tests__/generalSettingsPage.spec.ts`
Expected: PASS.

Run: `cd admin && pnpm test > "$SCRATCH/v7.log" 2>&1; tail -5 "$SCRATCH/v7.log"; pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/pages/appearance src/composables/useSettingsForm.ts src/__tests__/useSettingsForm.spec.ts src/queries/palette.ts src/queries/generalSettings.ts src/queries/styleSchema.ts src/__tests__/brandColors.spec.ts src/__tests__/appearance-palette.spec.ts src/__tests__/clear-brand-dialog.spec.ts src/__tests__/appearanceTabs.spec.ts`
Expected: all pass. Picker specs that build a schema with `unset` slots fail type-check until Task
8, so give their fixtures `limit`/`order` and drop `unset` entries here (ledger it).

- [ ] **Step 6: Commit**

```bash
git add admin/src
git commit -m "feat(admin): Appearance edits a list of brand colours — Add colour up to the limit (\"2 of 3\"), drag to reorder, remove an unsaved row, Clear a saved one by its id; a save's ids and revision are taken from its response, and an edit made while it was in flight stays unsaved"
```

### Task 8: The colour pickers — a brand group, its text colours, and unavailable colours by name

**Files:**
- Modify: `admin/src/editor/inspector/controls/TokenScaleControl.vue`
- Test: `admin/src/__tests__/color-token-picker.spec.ts`, `admin/src/__tests__/tokenField.spec.ts`, `admin/src/__tests__/responsive-field.spec.ts`, `admin/src/__tests__/helpers/classEditorSchema.ts`

**Interfaces:**
- Consumes: the schema's `vocabulary.domains.color` (brand names after the theme's), `palette.order`, `slots`, `labels`, `limit`, `can_manage` (Task 6); `PaletteSlot` (Task 7).
- Produces:
  - DOM hooks: `data-test="brand-group"`, `brand-text-toggle`, `brand-text-colours`, `manage-brand-colours`, `unavailable-colour`. The existing `token-<token>` buttons are unchanged.

- [ ] **Step 1: Write the failing tests**

In `color-token-picker.spec.ts` (keep its mount helper; build the palette with the new shape):

```ts
const palette = (over: Partial<NonNullable<StyleSchemaResult['palette']>> = {}) => ({
  limit: 3,
  order: ['brand-4', 'brand-12'],
  can_manage: true,
  slots: {
    'brand-4': { name: 'Gold', hex: '#8a6a2a', state: 'configured', reserved: false, replacing: null },
    'brand-12': { name: 'Sky', hex: '#38bdf8', state: 'configured', reserved: false, replacing: null },
    'brand-7': { name: 'Teal', state: 'removed' },
  },
  swatches: { 'color.accent': '#2563eb' },
  labels: {
    'color.accent': 'Accent',
    'color.brand-4': 'Gold',
    'color.brand-4-contrast': 'Gold — text',
    'color.brand-12': 'Sky',
    'color.brand-12-contrast': 'Sky — text',
    'color.brand-7': 'Teal',
  },
  generation: 1,
  replacements: { after: 0, through: 1, records: [] },
  ...over,
})
const NAMES = ['accent', 'text', 'brand-4', 'brand-4-contrast', 'brand-12', 'brand-12-contrast']

it('groups the brand colours after the theme’s, in order, with their text colours folded away', async () => {
  const w = mountPicker({ names: NAMES, palette: palette(), modelValue: null })
  const group = w.get('[data-test="brand-group"]')
  expect(group.text()).toContain('Brand colours')
  expect(group.findAll('[data-test^="token-color.brand-"]').map((b) => b.attributes('data-test'))).toEqual([
    'token-color.brand-4',
    'token-color.brand-12',
  ])
  expect(w.find('[data-test="brand-text-colours"]').exists()).toBe(false)
  await w.get('[data-test="brand-text-toggle"]').trigger('click')
  expect(w.get('[data-test="brand-text-colours"]').text()).toContain('Gold — text')
})

it('opens the text colours when the stored value is one', () => {
  const w = mountPicker({ names: NAMES, palette: palette(), modelValue: 'color.brand-12-contrast' })
  expect(w.find('[data-test="brand-text-colours"]').exists()).toBe(true)
})

it('links to the Colours tab for a manager, and only for a manager', () => {
  const link = mountPicker({ names: NAMES, palette: palette(), modelValue: null }).get('[data-test="manage-brand-colours"]')
  expect(link.text()).toBe('Manage brand colours')
  expect(link.attributes('href')).toBe('/appearance?tab=colours')
  const editor = mountPicker({ names: NAMES, palette: palette({ can_manage: false }), modelValue: null })
  expect(editor.find('[data-test="manage-brand-colours"]').exists()).toBe(false)
})

it('with no brand colours shows only the link to a manager, and nothing to anyone else', () => {
  const none = palette({ order: [], slots: {} })
  const names = ['accent', 'text']
  expect(mountPicker({ names, palette: none, modelValue: null }).get('[data-test="brand-group"]').text()).toContain('Manage brand colours')
  expect(mountPicker({ names, palette: { ...none, can_manage: false }, modelValue: null }).find('[data-test="brand-group"]').exists()).toBe(false)
})

it('hides the brand group when brand colours are off', () => {
  const w = mountPicker({ names: ['accent', 'text'], palette: palette({ limit: 0, order: [], slots: {} }), modelValue: null })
  expect(w.find('[data-test="brand-group"]').exists()).toBe(false)
})

it('names an unavailable colour: removed by its name, never issued by its number', () => {
  const removed = mountPicker({ names: NAMES, palette: palette(), modelValue: 'color.brand-7' })
  expect(removed.get('[data-test="unavailable-colour"]').text()).toContain('Unavailable colour: Teal (removed)')
  expect(removed.get('[data-test="unavailable-colour"]').text()).toContain('No colour applied')
  const foreign = mountPicker({ names: NAMES, palette: palette(), modelValue: 'color.brand-99-contrast' })
  expect(foreign.get('[data-test="unavailable-colour"]').text()).toContain('Unavailable colour: Brand 99')
})
```

(`mountPicker` mounts `TokenScaleControl` with `domain: 'color'`, `values: {}`, and a
`RouterLink` stub rendering `<a :href="to">`; follow the file's existing helper.) Update
`helpers/classEditorSchema.ts`, `tokenField.spec.ts` and `responsive-field.spec.ts` fixtures to the
new palette shape (`limit`, `order`, no `unset`).

- [ ] **Step 2: Run them to see them fail**

Run: `cd admin && pnpm vitest run src/__tests__/color-token-picker.spec.ts`
Expected: FAIL. There is no `brand-group`, no text toggle and no manage link, and the unavailable
text reads "Brand 7" from the `[123]` regex.

- [ ] **Step 3: Implement**

`TokenScaleControl.vue` script, replacing `slotOf` … `unavailable`:

```ts
const BRAND = /^color\.brand-(\d+)(?:-contrast)?$/
function slotOf(token: string): number | null {
  const m = BRAND.exec(token)
  return m ? Number(m[1]) : null
}
function slot(n: number) {
  return props.palette?.slots[`brand-${n}`]
}
const isText = (token: string) => slotOf(token) !== null && token.endsWith('-contrast')

/** New choices: a brand colour only while configured (reserved ones stay); never one removed or being replaced. */
const visible = computed(() =>
  items.value.filter((item) => {
    if (!colour.value) return true
    const n = slotOf(item.token)
    return n === null || slot(n)?.state === 'configured'
  }),
)
const themeItems = computed(() => visible.value.filter((i) => slotOf(i.token) === null))
const brandItems = computed(() => visible.value.filter((i) => slotOf(i.token) !== null && !isText(i.token)))
const brandTexts = computed(() => visible.value.filter((i) => isText(i.token)))
const canManage = computed(() => props.palette?.can_manage === true)
/** The group shows while brand colours are on and there is a colour to offer or a manager to add one. */
const showBrandGroup = computed(
  () => colour.value && (props.palette?.limit ?? 0) > 0 && (brandItems.value.length > 0 || canManage.value),
)
const showTexts = ref(props.modelValue !== null && isText(props.modelValue))
watch(
  () => props.modelValue,
  (v) => {
    if (v !== null && isText(v)) showTexts.value = true
  },
)

const storedSlot = computed(() => (props.modelValue && colour.value ? slotOf(props.modelValue) : null))
/** The stored colour names an id nothing configures — removed, never issued, or brand colours off. */
const unavailable = computed<string | null>(() => {
  const n = storedSlot.value
  if (n === null) return null
  const s = slot(n)
  if (s?.state === 'configured' || s?.state === 'replacing') return null
  if (s?.state === 'removed') return `${s.name} (removed)`
  return props.palette?.labels[`color.brand-${n}`] ?? `Brand ${n}`
})
```

(Import `watch` from `vue`.) Keep `replacing`, `swatchOf`, `swatchStyle`, `labelOf` and
`chooseAnother` as they are. The template:
- the unavailable notice's label reads `Unavailable colour: {{ unavailable }}`;
- the single button row becomes two blocks.

Factor the button into a local render loop: repeat the existing `<button>` markup for each list.
Use `v-for="item in themeItems"` in the first group (its existing classes, `ref="first"` kept on
this first group), then:

```vue
<div v-if="showBrandGroup" class="space-y-1" data-test="brand-group">
  <div class="flex items-center justify-between text-[11px] text-dimmed">
    <span>Brand colours</span>
    <RouterLink
      v-if="canManage"
      to="/appearance?tab=colours"
      class="text-primary hover:underline"
      data-test="manage-brand-colours"
    >
      Manage brand colours
    </RouterLink>
  </div>
  <div v-if="brandItems.length > 0" class="flex flex-wrap gap-1" role="group" aria-label="Brand colours">
    <!-- the same button, v-for="item in brandItems" -->
  </div>
  <button
    v-if="brandTexts.length > 0"
    type="button"
    class="text-[11px] text-muted hover:text-default"
    :aria-expanded="showTexts ? 'true' : 'false'"
    data-test="brand-text-toggle"
    @click="showTexts = !showTexts"
  >
    Text colours
  </button>
  <div v-if="showTexts && brandTexts.length > 0" class="flex flex-wrap gap-1" role="group" aria-label="Brand text colours" data-test="brand-text-colours">
    <!-- the same button, v-for="item in brandTexts" -->
  </div>
</div>
```

To avoid copying the button three times, move it into a small inline component in the same file.
If `<script setup>` makes that awkward, add `TokenSwatchButton.vue` next to it with props
`item, selected, disabled, colour, swatch, label` and an emitted `choose`, and use it in all three
lists. Either way the `data-test="token-<token>"` attribute stays on the button.

Update the file's header comment: brand colours are their own group with their text colours folded
away; a manager gets a link to Appearance's Colours tab; an unavailable colour is named by what it
was.

- [ ] **Step 4: Run them to see them pass, then the admin gates**

Run: `cd admin && pnpm vitest run src/__tests__/color-token-picker.spec.ts src/__tests__/tokenField.spec.ts src/__tests__/responsive-field.spec.ts src/__tests__/clear-brand-dialog.spec.ts`
Expected: PASS.

Run: `cd admin && pnpm test > "$SCRATCH/v8.log" 2>&1; tail -5 "$SCRATCH/v8.log"; pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/editor/inspector/controls src/__tests__/color-token-picker.spec.ts src/__tests__/tokenField.spec.ts src/__tests__/responsive-field.spec.ts src/__tests__/helpers/classEditorSchema.ts`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add admin/src
git commit -m "feat(admin): colour pickers group brand colours after the theme's, fold their text colours behind a disclosure, link a manager to Appearance's Colours tab, and name an unavailable colour (\"Teal (removed)\", \"Brand 7\")"
```

### Task 9: Browser proofs, OpenAPI, docs and the changelog

**Files:**
- Modify: `scripts/build-palette-fixtures` (L109–122, L134–143, L159)
- Modify: `tools/runtime-browser/tests/palette.spec.js`
- Modify: `admin/e2e/helpers.ts` (the style-schema palette mock, if it builds `slots`), `admin/e2e/tests/palette-normalized-save.spec.ts`, `admin/e2e/tests/palette-restore-history.spec.ts`, `admin/e2e/tests/appearance-page.spec.ts`
- Modify: `docs/openapi.json` (hand-spliced: the three `/appearance/palette/brand/{id}` operations, `PUT /settings/general` request properties and example, `GET /render/style-schema` palette descriptions, `POST /appearance/palette/preview` and `POST …/preview` mint `palette` descriptions)
- Modify: `admin/src/api/schema.d.ts` (`pnpm gen:api` after the splice)
- Modify: `docs/guides/01-appearance.md`, `docs/reference/05-style-settings.md`, `packages/thallo-render/docs/THEMING.md`, `docs/reference/02-configuration.md`, `docs/reference/04-block-library.md` (where colour lists appear)
- Modify: `CHANGELOG.md` (`[Unreleased]`)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: The fixtures and the browser proof**

In `scripts/build-palette-fixtures`:
- Replace the two `theme_brand_N` saves with one `theme_brand_colors` save (`BrandColors::encode([1 => new BrandSlot('Gold dark', '#8a6a2a'), 12 => new BrandSlot('Rose', '#c98a8a')], [], 1)`).
- Change the `color.brand-2` tokens to `color.brand-12`.
- Change the `theme_brand_2 => ''` clear to `BrandColors::cleared($settings->stored('theme_brand_colors'), 12)` written under the palette row, the way the script already takes it. If it writes settings directly, keep that.

In `tools/runtime-browser/tests/palette.spec.js`:
- read `brand-2` as `brand-12` throughout;
- add one check: the page's `<link>` list contains a `colors-` stylesheet before the inline `<style>`, and a computed style shows the Rose fill on the `brand-12` block.

Run: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH; CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-palette-fixtures && (cd tools/runtime-browser && npx playwright test tests/palette.spec.js tests/hover.spec.js tests/layout.spec.js tests/listing-layout.spec.js)`
Expected: all pass. The hover proof (unset hover keeps its colour) still holds: an unconfigured
id gets no class.

- [ ] **Step 2: The admin e2e specs**

- Update `admin/e2e/helpers.ts`'s style-schema mock palette to `{limit: 3, order: […], slots: {…}, …}` without `unset` entries.
- In `palette-normalized-save.spec.ts` and `palette-restore-history.spec.ts`, open Appearance with `?tab=colours` where they drive Appearance.
- Add to `appearance-page.spec.ts` one test, *"a brand colour can be added, saved with an id, and offered in a block's colour picker"*: it routes the settings save and asserts the sent `theme_brand_colors` names `base` and has a new entry without an id. A second save after the routed response (revision + 1, the new id) sends that id and the new `base`. It does not need the picker.

Rebuild the e2e fixtures first (`rm -rf admin/e2e/fixtures && CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`).

Run: `cd admin && npx playwright test > "$SCRATCH/e2e9.log" 2>&1; tail -6 "$SCRATCH/e2e9.log"`
Expected: all pass. Rerun a shop sign-in timeout alone before calling it a failure.

- [ ] **Step 3: OpenAPI and the generated admin schema**

Regenerate into a scratch copy and splice only the changed operations: `cp docs/openapi.json "$SCRATCH/openapi.before.json"`, then
`CACHE_DRIVER=array php glueful docs:openapi`. The command exits 0 even when Redis is missing, so
check that the file actually changed. Copy the regenerated text of:
- the three `/v1/admin/appearance/palette/brand/{id}` operations (path key, `id` parameter, `pattern: "[1-9][0-9]{0,3}"`, descriptions);
- `PUT /v1/admin/settings/general`'s `theme_brand_colors` property (replacing the three `theme_brand_N` properties and example lines);
- `GET /v1/admin/render/style-schema`'s palette description fields (`limit`, `order`, `can_manage`, `slots`);
- the preview operations' `palette.brands` description.

Restore everything else from `$SCRATCH/openapi.before.json`. `git diff --stat docs/openapi.json`
should touch only those operations. Then `cd admin && pnpm gen:api`, and check that
`git diff admin/src/api/schema.d.ts` shows only those paths and properties.

- [ ] **Step 4: Docs**

- **`docs/guides/01-appearance.md` "Brand colours":** **Add colour** appends a row, and each colour gets a permanent id. The count shows against the limit ("2 of 3"), and the limit is your host's setting. Drag to reorder: the order is the pickers' order. A colour that's never been saved has a remove button; a saved one leaves only through **Clear**. If someone else changed the brand colours since you opened the page, Save asks you to reload rather than overwrite their change. Remove "Up to three".
- **`docs/reference/05-style-settings.md`:** the colour names include `brand-N` / `brand-N-contrast` for each configured colour. Pickers group them as **Brand colours** with **Text colours** folded away. An unavailable colour reads "Unavailable colour: Teal (removed)" or "Brand 7" and applies no colour.
- **`packages/thallo-render/docs/THEMING.md`:** `brand-*` tokens are site-controlled (any id). A theme's mapping is ignored and the Doctor warns. Their utilities come from the workspace's colours stylesheet, linked by `theme_colors_style()`, so a theme layout needs nothing new.
- **`docs/reference/02-configuration.md`:** a row for `THALLO_BRAND_COLORS_MAX` (`theme.brand_colors.max`, default 3, 0–12, 0 turns brand colours off; lowering it keeps existing colours).
- **`docs/reference/04-block-library.md`:** wherever a colour list names `brand-1`…`brand-3`, say "your brand colours".

- [ ] **Step 5: The changelog**

In `CHANGELOG.md` `[Unreleased]`, rewrite the existing custom-palette **Added** bullet's brand sentence (it says three named brand colours) to:

> Brand colours are a list you add to with **Add colour**, up to your host's limit
> (`THALLO_BRAND_COLORS_MAX`, default 3, 0–12); each keeps a permanent id, so a cleared colour's
> references stay unavailable rather than taking a later colour. Their utilities come from a
> per-workspace colours stylesheet, so the shared theme stylesheet carries none.

Also:
- rewrite the `GET /v1/admin/render/style-schema` **Changed** bullet to mention `limit`, `order`, removed colours and `can_manage`;
- add a **Changed** bullet: "The brand colour endpoints take the colour's id: `/v1/admin/appearance/palette/brand/{id}`";
- if any `[Unreleased]` bullet mentions `theme_brand_1` … `theme_brand_3`, say `theme_brand_colors` instead.

- [ ] **Step 6: Full gates, then commit**

Run each gate attached, one at a time:
1. `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH; vendor/bin/phpcs; echo "phpcs exit $?"`. Expected: `phpcs exit 0`.
2. `composer test:reset-db && composer test:migrate && composer test > "$SCRATCH/unit.log" 2>&1; tail -5 "$SCRATCH/unit.log"`. Expected: OK.
3. Each integration shard in turn (`INTEGRATION_SHARD_A` … `G` as `.github/workflows/ci.yml` lists them). Expected: OK.
4. The tenancy harness, one file per process with `THALLO_TENANCY_DEV_LINK=1` (the colours tenancy test included). Expected: all pass.
5. `cd admin && pnpm test && pnpm type-check && pnpm lint && pnpm fmt:check`. Expected: clean.

```bash
git add scripts/build-palette-fixtures tools/runtime-browser/tests/palette.spec.js admin/e2e admin/src/api/schema.d.ts docs packages/thallo-render/docs CHANGELOG.md
git commit -m "docs(palette): brand colours as a list — Appearance guide, style reference, theming, the THALLO_BRAND_COLORS_MAX reference, OpenAPI and changelog; browser proofs use brand-12 and the colours stylesheet"
```
