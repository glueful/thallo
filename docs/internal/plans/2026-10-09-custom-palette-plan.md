# Custom palette — Implementation Plan

> Amended 2026-10-09 after plan review:
> - **The trusted basis is a set of permitted tokens per location**, built by `basisOf(string $kind, ?ContentTypeSchema $schema, array ...$docs)`. A current Accent no longer hides a historical Brand 1 at the same location (Task 9).
> - **Content import fences publication pointers and pinned versions.** An `entry_publication` record loads and normalises the version it points at, appending a normalised version when needed (Task 11).
> - **Replace runs in its workspace.** The queued job carries the workspace and enters it before resolving palette services. Proven from a fresh worker and across two workspaces (Task 14).
> - **Every job status change is a permitted transition checked under the palette lock.** `completed` and `cancelled` are terminal, and resume eligibility is checked inside the lock (Tasks 8, 14).
> - **The contrast mapping is decided under the lock.** A replacement without one refuses any contrast reference that arrives later; it never maps text to the fill colour (Tasks 9, 14).
> - **The editor contract is complete.**
>   - The draft remembers, server-side, the version last restored into it, so restore → undo → redo saves.
>   - Every fenced save returns its `palette_rewrites`, which editors adopt without losing edits made during the request.
>   - Both sequences have browser proofs (Task 12).
> - **The palette runner invalidates caches after each successful write.** A partly completed job's rewritten publications render their replacement (Task 14).
> - **Real two-process database proofs** cover save-versus-clear, worker-versus-cancel and conflicting starts. `ensureRow()` inserts inside a savepoint (Tasks 8, 14).
>
> Amended again after the second plan review:
> - **Colour locations are keyed by block id**, not array index: `<blockId>:<relative path>`, e.g. `head00000001:settings.style.colors.text`. Rewrites, refusals and the trusted basis all follow a block through moves and survive another block taking its index (Tasks 7, 12).
> - **A save response never marks newer edits as saved.** The saved baseline is the submitted document plus its accepted rewrites. The current document is reconciled separately and keeps its newer edits and dirty state (Task 12).
> - **History is reconciled with the palette.** Editors rewrite replaced tokens inside every recorded operation payload, whole blocks included, so undo and redo can never reintroduce a replaced colour. The third review replaced this round's `palette_mappings` / `retired` mechanism with ordered replacement records (below).
> - **Restore provenance is the persisted basis itself** (`entry_drafts.restore_basis`), derived on the server when the restore runs. Version retention pruning can no longer remove it (Task 12).
> - **Two-process proofs observe the wait.** The holder signals only after acquiring its lock. The contender runs in its own process, and the parent watches `pg_stat_activity` until the contender's backend is waiting on a lock, then releases the holder. Timeouts are failure bounds only (Task 14).
>
> Amended a third time after the third plan review (all Task 12):
> - **Adoption never cancels an in-flight transaction.** A new `history.adopt()` replaces the document underneath the active transaction instead of `rebase()`, which cancels and inverts it.
> - **History is reconciled by ordered, identified replacement records, never by bare token maps.**
>   - A record is a *completed* Replace job: its id, slot, destinations, and the palette generation at which it completed.
>   - Each editor applies each record once, in completion order, and only to state it held before that record.
>   - A document loaded or restored after a record completed is that record's post-replacement truth and is never mapped through it. This preserves deliberately restored historical colours.
>   - Running jobs' maps no longer reach history at all: the server maps those saves itself.
> - **The walker's no-id fallback test** expects the index path again.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Authors set a Custom neutral (six light hex values plus a dark-mode base family) and three named brand colours in Appearance. Every colour picker offers them by name with swatches. A brand colour that is cleared stops applying without disturbing anything else. Clearing a used colour goes through a fenced, resumable Replace job that never lets a stale save bring the old reference back.

**Architecture:**
- **Values.** A `Palette` value object in `thallo-contracts` (custom neutral, dark base, three `BrandSlot`s). Core reads it from six new general-settings keys through `PaletteSettings`.
- **Emission.** Render emits it in `themeColorsStyle()` (`ThemeColors::paletteCss`). Six new colour tokens are site-controlled: a theme cannot remap them. `StyleCompiler::VERSION` goes to 24.
- **Availability.** It is decided at render time, on the two paths that turn a stored token into a class:
  - `BlockStyleEmitter`, which strips unavailable values from every cascade layer before resolving;
  - `token_class()`.
- **Concurrency.**
  - **Palette state:** a per-workspace `palette_state` row (`generation`). Jobs and their reservations live in `palette_jobs`.
  - **The fence:** every writer normalises with `PaletteNormalizer` through `PaletteFence`. The fence takes the palette row **first** in the writer's transaction, re-normalises from the original payload when the generation moved, then runs the writer's own locks and write.
  - **Replace:** a queued, resumable job (`PaletteReplaceRunner`) modelled on the style-class jobs. Cancellation is fenced by job id.
- **Admin.**
  - The Appearance form gains Custom neutral, Dark mode base and Brand colours.
  - Contrast rows come from a server preview endpoint, so the admin mirrors no colour tables.
  - Pickers gain swatches and author names from the style schema's new `palette` block.

**Tech Stack:** PHP 8.4 (Glueful, PHPUnit), Twig 3, CSS `@layer settings`, PostgreSQL (the supported database, `docs/limitations.md`), Vue 3 + Nuxt UI (vitest, oxfmt, `pnpm type-check`), Playwright (`tools/runtime-browser`).

**Spec:** `docs/internal/superpowers/specs/2026-10-09-custom-palette-design.md` (revision 4, `05400628`). Every section is in this release.

## Rulings made while planning (from the code)

1. **Restore-to-draft becomes a server endpoint.**
   - **Today:** the admin builds the restored fields itself (`admin/src/editor/restoreVersion.ts`) and saves them through the ordinary `PUT /entries/{uuid}/draft/{locale}`. The server never knows a save is a restore, and the client supplies the old content. That contradicts §4.5's server-loaded trusted basis.
   - **New:** `POST /entries/{uuid}/draft/{locale}/restore` takes `{version_uuid, lock_version}`. The server loads the version from the entry's own retained versions, projects it (`BlockRestoreProjector`), builds the restored fields with the same rule as `restoredFields()`, normalises with the version as extra basis, and saves.
   - **Admin:** both restore callers use it. The design page then applies the returned fields as one local undoable transaction, without saving again.
   - **Provenance:** the restore persists its server-derived basis on the draft (`entry_drafts.restore_basis`), keyed by block id. So restore → undo → redo saves even after retention prunes the version, while the client can never nominate a version or supply a basis (Task 12).
   - **Cost if wrong:** the restored blocks keep their cleared colours placeable on themselves until the draft is published or discarded. That is the content the author restored.
2. **The palette row is locked by an `UPDATE`, through the query builder.**
   - Glueful's builder has no `lockForUpdate()`. Precedents are `AppearanceLock::within` and `SiteStyleGeneration::incrementWithin`.
   - `PaletteState::lock()` runs `UPDATE palette_state SET generation = generation WHERE site = 'site'`. Tenancy scopes it like every builder write, and it reads the row back on the same connection.
   - **Cost if wrong:** none on PostgreSQL, the only supported database.
3. **The fence holds the palette row from the start of the writer's transaction, so a mismatch is caught exactly once.**
   - The writer normalises before its transaction (recording the generation G it read). It then opens its transaction, locks the palette row and compares. On a mismatch it re-normalises **from the original payload** against the state it now holds locked.
   - No palette mutation can commit while the row is held, so a second mismatch is impossible and §4.3's "three attempts, then 409" loop is never needed. A re-normalisation that refuses still returns its 422.
   - **Cost if wrong:** one retry loop to add.
4. **Lock order is enforced, not only documented.**
   - `PaletteFence` refuses (`LogicException`) to take the palette row inside a transaction that has not already taken it.
   - So a writer called from an outer transaction that already holds a document lock fails loudly in tests instead of deadlocking in production. The order is in Task 8.
5. **Jobs live in a `palette_jobs` table, not inside the `palette_state` row.**
   - The state row stays a single lockable row. Its `generation` guards everything, and every job change happens while it is held.
   - A job's reservations are derived from its row: `to` / `contrast_to` slots while it is `running` or `failed`.
   - **Cost if wrong:** none; the spec's "palette state" is these two tables under one lock.
6. **Colour-bearing locations the spec's lists leave implicit are included** in usage (blocking), normalisation, rendering and Replace:
   - an entry's `_presentation.style` (Page tab), painted by `PageStyle::classes()`;
   - a region's own `settings.style` (its Style tab), in `regions.settings`;
   - a layout's frame `settings.style`;
   - Animated text's **third** colour field, `suffix_color`.

   `ColorTokenWalker` names every location once and is shared by all four consumers.
7. **Two palette-only document sources write region and layout `settings`:** `RegionSettingsSource` and `LayoutSettingsSource`.
   - `RegionsSource` and `LayoutsSource` expose and write only `blocks`, and other walkers (block migrations, style-class jobs) depend on that.
   - The new sources are **not** in the shared `BlockDocumentSources` registry. A palette registry (`PaletteDocumentSources`) lists the six existing sources plus these two plus `StyleClassesSource`.
8. **The scoped-palette swatch rule is "site default" whenever the block, or an ancestor in the editor tree, carries a scoped accent or neutral.**
   - Resolving a scope would need the family tables in the admin; §5.2 allows the label when the editor does not resolve the scope.
   - **Cost if wrong:** scoped swatches show the site value labelled "site default" rather than the scoped value.
9. **Swatches need hexes for every colour token, not only brand slots.**
   - The `palette` block also carries `swatches: {token: lightHex}`, computed server-side from the effective palette (light mode; Plain/Tinted applied).
   - `transparent` has no swatch and is drawn as a checkerboard.
10. **Contrast rows for unsaved values come from `POST /v1/admin/appearance/palette/preview`.**
    - The admin's `style/contrast.ts` has no neutral tables or dark derivation. Mirroring `ThemeColors` in TypeScript would add a second source of truth.
    - The form posts its unsaved look, debounced, and renders the server's rows.
11. **Replace needs `content.manage`, per the spec.** Style-class jobs use `styles.manage`; Replace deliberately does not.
12. **Writers that are not fenced, and why** (Task 8's inventory, pinned by tests in Task 11):
    - **Entry create, discard, soft delete, unpublish:** write no style values.
    - **Block-type migrations (`BlockBackfillRunner`), content-type migrations (`BackfillRunner`), the settings converter (`SettingsConversion`, no stage ships) and `RetireAccountLinkCommand`:**
      - They carry existing values forward and create none.
      - Each writes through a revision-conditional write (or reads and writes under its document lock), so a write based on a read from before a Replace rewrite fails its condition.
      - A read made after the rewrite carries the destination.
    - **Seeders (`TenantSeeder`, `StarterSync`):** starter payloads hold no brand token; a test pins that.
13. **Locale copy is fenced and its overwrite gains a CAS.**
    - Today `EntryRepository::createLocaleDraft` copies the source draft unconditionally and resets `lock_version` to 0.
    - Fencing it is required (it copies brand tokens verbatim). Its normaliser basis is the source draft re-read under the lock.
14. **Publish, scheduled publish, locale copy and rollback normalise server-read payloads.**
    - On a generation mismatch they **re-read** their source under the palette lock, then normalise, because they have no client payload.
    - Their trusted basis is the source document as re-read: the draft being published, the source locale's draft, or the version being re-pinned.
    - Rollback whose normalisation changes the fields takes the append-a-version path, never a plain re-pin.
15. **Concurrency proofs use one explicit seam, `PaletteState::afterNextSnapshot(Closure)`.**
    - It runs once, right after the next unlocked snapshot, which is exactly the window between a writer's normalisation and its fenced write.
    - **Why not the alternatives:** writers are shared container services built once, so substituting `PaletteState` after boot does not reach them. A second PHP process (the `StyleClassConcurrentWriterTest` pattern) cannot pause a writer at that point without a seam anyway.
    - **Cost if wrong:** one test-only method on a production class, documented as such and never called outside tests.
16. **A replaced publication's new version carries no note.**
    - `entry_versions` has no note column (`VersionRepository::appendVersion` takes fields, schema version and actor only).
    - The version's actor is the user who started the job. The audit entry `palette.brand.replaced` records the slot, the name, both destinations and per-source counts.
    - **Cost if wrong:** a version-history label needs a column later.
17. **An interrupted job is detected by heartbeat.**
    - A worker that dies leaves `status = running`. Each processed document touches `palette_jobs.heartbeat_at`.
    - A `running` job whose heartbeat is older than 120 seconds reads as `interrupted` in the job response and may be resumed.
    - A resumed job runs alongside a possibly-alive old worker without harm: both normalise the same way, and the conditional writes let one win per document. A publication already rewritten no longer references the slot and is skipped, so it never gains a second version.

## Global Constraints

- New colour tokens, exactly: `brand-1`, `brand-1-contrast`, `brand-2`, `brand-2-contrast`, `brand-3`, `brand-3-contrast`; values `var(--brand-N)` / `var(--brand-N-ink)`.
- New settings keys, exactly: `theme_neutral` gains value `custom`; `theme_neutral_custom` (JSON `{"bg","surface","surface_2","ink","muted","line"}`), `theme_dark_base` (a neutral family), `theme_brand_1` … `theme_brand_3` (JSON `{"name","hex"}`, name ≤ 32 characters).
- Hex input `#abc` / `#aabbcc`, any case; stored lower-case six digits.
- Brand dark value: mixed toward white in 5% steps until 4.5:1 against the effective dark Background, at most 20 steps. Contrast colour: `#000000` or `#ffffff`, whichever contrasts more with that mode's fill.
- Contrast checks pass at 4.5:1 and are advisory only.
- `StyleCompiler::VERSION` 23 → **24**; `Vocabulary::VERSION` stays **1**; `StyleSchema::VERSION` unchanged.
- Existing sites: `themeColorsStyle()` output byte-identical; rendered HTML byte-identical with the compiled-stylesheet URL normalised.
- Permissions:
  - style schema read: `content.edit,content.manage,templates.manage,styles.manage`;
  - usage, clear, replace, job, cancel, resume, palette preview and palette saves: `content.manage`.
- Lock order (Task 8): palette row → advisory document locks → `style_classes` rows → document rows → `style_generations`.
- Commit messages carry no AI attribution lines (the user's standing rule).
- PHP gates:
  - MAMP PHP first on `PATH` (`export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`);
  - phpcs judged by exit code;
  - integration shards one at a time;
  - `composer test:reset-db && composer test:migrate` before the suite;
  - every new `tests/Integration` file is listed in exactly one `INTEGRATION_SHARD_*` in `.github/workflows`.
- Admin gates: `pnpm type-check`, `pnpm lint`, `pnpm exec oxfmt --check <touched files>`, vitest.
- `docs/openapi.json` regenerated by provision is never committed by accident. Hand-splice changed operations (`CACHE_DRIVER=array`).
- Changelog bullets ride with each change under `[Unreleased]`.

## Review Focus

1. **A page whose style class sets a brand colour that is later cleared:** blocks that name the class render as if the class never set it, on every page and in every region, with no stale cached page. Pinned in Task 4 (cascade) and Task 13 (clear purges after commit).
2. **An author who renames a brand colour mid-replace of another slot:** allowed only for a reserved slot, refused for the slot being replaced, and pickers show the new name at once. Pinned in Tasks 14 and 16.
3. **An import of a bundle exported from a site with different brand slots:** records naming unconfigured slots are refused per record with the slot named, and nothing else in the import fails. Pinned in Task 11.
4. **A worker that crashes after rewriting some documents:** the slot is still configured and rendering, Appearance shows Resume, and Resume finishes without double-writing publications (no extra version for an already-rewritten entry). Pinned in Task 14.
5. **Custom neutral with colour mode off:** no dark block is emitted, the stored dark base survives and returns when colour mode is turned back on. Pinned in Task 2.

---

## Shared contracts (named once, used by every task)

```php
// packages/thallo-contracts/src/Style/BrandSlot.php (Task 1)
final class BrandSlot
{
    public function __construct(public readonly string $name, public readonly string $hex) {}
    /** @return array{name:string,hex:string} */
    public function toArray(): array;
}

// packages/thallo-contracts/src/Style/Palette.php (Task 1)
final class Palette
{
    public const SLOTS = [1, 2, 3];
    public const NEUTRAL_KEYS = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line'];

    /**
     * @param array<string,string>|null $customNeutral NEUTRAL_KEYS => #rrggbb, null when unset
     * @param array<int,BrandSlot|null> $brands slot => slot or null (keys 1..3, always present)
     */
    public function __construct(
        public readonly ?array $customNeutral = null,
        public readonly ?string $darkBase = null,
        public readonly array $brands = [1 => null, 2 => null, 3 => null],
    ) {}

    public static function empty(): self;
    public function brand(int $slot): ?BrandSlot;
    public function isConfigured(int $slot): bool;
    /** 1..3 for color.brand-N / color.brand-N-contrast, else null. */
    public static function slotOf(string $token): ?int;
    public static function isContrastToken(string $token): bool;
    /** True for a brand token whose slot is not configured. */
    public function isUnavailable(string $token): bool;
    public function isEmpty(): bool;          // nothing set: the upgrade path
    public function fingerprint(): string;    // '' when empty, else sha1 of the canonical JSON
    public function withBrands(array $brands): self;
}

// packages/thallo-contracts/src/Style/PaletteProvider.php (Task 1)
interface PaletteProvider
{
    public function palette(): Palette;
    /** The palette an Appearance preview claims, unset keys falling back to the stored ones (added in Task 5). */
    public function preview(array $claim): Palette;
}

// core/src/Content/Palette/PaletteSnapshot.php (Task 8)
final class PaletteSnapshot
{
    /** @param list<PaletteJob> $activeJobs status running|failed */
    public function __construct(
        public readonly int $generation,
        public readonly Palette $palette,
        public readonly array $activeJobs,
    ) {}
    public function jobReplacing(int $slot): ?PaletteJob;
    /** @return list<int> slots reserved by active jobs (to / contrast_to brand slots) */
    public function reservedSlots(): array;
}

// core/src/Content/Palette/PaletteJob.php (Task 8)
final class PaletteJob
{
    public function __construct(
        public readonly string $id,
        public readonly int $slot,
        public readonly string $to,            // e.g. 'color.accent'
        public readonly ?string $contrastTo,   // e.g. 'color.accent-contrast', 'color.text'; null = no contrast mapping (contrast references are refused)
        public readonly string $status,        // running|failed|completed|cancelled
        public readonly int $passes,
        public readonly int $total,
        public readonly int $done,
        public readonly int $failed,
        public readonly array $failureReport,
    ) {}
}

// core/src/Content/Palette/ColorTokenWalker.php (Task 7)
final class ColorTokenWalker
{
    public const KIND_ENTRY = 'entry';          // fields incl. blocks fields and _presentation.style
    public const KIND_REGION = 'region';        // {blocks, settings}
    public const KIND_LAYOUT = 'layout';        // {blocks, settings}
    public const KIND_SECTION = 'saved_section';// {blocks: [block]}
    public const KIND_CLASS = 'style_class';    // {style}

    /**
     * Visits every colour-token value; $fn(string $location, string $token): ?string returns a
     * replacement token, or null to keep. Returns the rewritten document.
     * Locations inside a block are "<blockId>:<path relative to the block>", e.g.
     * "head00000001:settings.parts.link.hover.colors.surface", "anim00000001:data.prefix_color" —
     * stable across moves and index shifts. A block without an id (raw, pre-validation data) falls
     * back to its index path, "body.0.settings.style.colors.text". Locations outside blocks are
     * dotted paths: "_presentation.style.colors.surface", "settings.style.colors.text", "style.colors.text".
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function map(string $kind, array $doc, callable $fn, ?ContentTypeSchema $schema = null): array;

    /** @return array<string,string> location => token, colour tokens only */
    public function tokens(string $kind, array $doc, ?ContentTypeSchema $schema = null): array;
}

// core/src/Content/Palette/PaletteNormalizer.php (Task 9)
final class PaletteNormalizer
{
    /**
     * @param array<string,list<string>> $basis location => every token a server-loaded trusted document holds there
     * @throws PaletteRefusal (422) naming each refused location and slot name
     */
    public function normalize(string $kind, array $doc, PaletteSnapshot $snapshot, array $basis, ?ContentTypeSchema $schema = null): Normalized;
    /** @return array<string,list<string>> */
    public function basisOf(string $kind, ?ContentTypeSchema $schema, array ...$docs): array;
}
final class Normalized
{
    /** @param list<array{location:string,from:string,to:string}> $rewrites what normalisation changed */
    public function __construct(
        public readonly array $doc,
        public readonly bool $originalHadBrand,
        public readonly bool $normalizedHasBrand,
        public readonly array $rewrites = [],
    ) {}
    public function fenced(): bool; // originalHadBrand || normalizedHasBrand
}

// core/src/Content/Palette/PaletteFence.php (Task 10)
final class PaletteFence
{
    /**
     * @template T
     * @param callable(PaletteSnapshot): Normalized $normalize   re-run from the ORIGINAL payload on mismatch
     * @param callable(array $doc, PaletteSnapshot $held): T $write runs inside the fence transaction, after the palette row
     * @return T
     */
    public function write(callable $normalize, callable $write, bool $force = false): mixed;
}

// core/src/Content/Palette/PaletteState.php (Task 8)
final class PaletteState
{
    public function ensureRow(): void;                           // conflict-safe: the insert runs in its own savepoint
    public function snapshot(): PaletteSnapshot;                 // unlocked read
    public function lock(): PaletteSnapshot;                     // inside a transaction: UPDATE-as-lock, then read
    public function bump(): int;                                 // inside a held lock: generation + 1
    public function heldInThisTransaction(): bool;
    /** Concurrency proofs only: runs once, right after the next unlocked snapshot is read. */
    public function afterNextSnapshot(\Closure $fn): void;
}
```

---
### Task 1: The palette values and settings keys

**Files:**
- Create: `packages/thallo-contracts/src/Style/BrandSlot.php`, `packages/thallo-contracts/src/Style/Palette.php`, `packages/thallo-contracts/src/Style/PaletteProvider.php`
- Create: `core/src/Settings/PaletteSettings.php` (implements `PaletteProvider`; parsing and validation)
- Modify: `core/src/Settings/GeneralSettings.php` (`DEFS`, `save()` deletion set)
- Modify: `core/src/Http/DTOs/UpdateGeneralSettingsData.php`, `core/src/Http/DTOs/Responses/GeneralSettingsData.php`
- Modify: `core/src/Http/Controllers/GeneralSettingsController.php` (`validate()`, the `save([...])` map)
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind `PaletteProvider` → `PaletteSettings`, shared, autowire, next to `ThemeAppearanceProvider` at ~1407)
- Test: `tests/Unit/Contracts/PaletteTest.php`, `tests/Integration/Settings/PaletteSettingsTest.php`

**Interfaces:**
- Produces: `Palette`, `BrandSlot`, `PaletteProvider` (shared contracts above); `PaletteSettings::palette(): Palette`; `PaletteSettings::normalizeHex(string): ?string`; `PaletteSettings::parseNeutral(string): ?array`; `PaletteSettings::parseBrand(string): ?BrandSlot`; `PaletteSettings::validate(UpdateGeneralSettingsData): array<string,string>`.

This task stores values only. Brand saves become palette mutations under the palette lock in Task 13. Until then, the General settings save writes them directly.

- [ ] **Step 1: Write the failing unit test for `Palette`**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;

final class PaletteTest extends TestCase
{
    public function testSlotOfReadsBrandAndContrastTokens(): void
    {
        self::assertSame(1, Palette::slotOf('color.brand-1'));
        self::assertSame(3, Palette::slotOf('color.brand-3-contrast'));
        self::assertNull(Palette::slotOf('color.accent'));
        self::assertNull(Palette::slotOf('color.brand-4'));
        self::assertTrue(Palette::isContrastToken('color.brand-2-contrast'));
        self::assertFalse(Palette::isContrastToken('color.brand-2'));
    }

    public function testAnUnconfiguredSlotIsUnavailableAndAConfiguredOneIsNot(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        self::assertFalse($p->isUnavailable('color.brand-1'));
        self::assertFalse($p->isUnavailable('color.brand-1-contrast'));
        self::assertTrue($p->isUnavailable('color.brand-2'));
        self::assertTrue($p->isUnavailable('color.brand-2-contrast'));
        self::assertFalse($p->isUnavailable('color.accent'));
    }

    public function testAnEmptyPaletteHasNoFingerprint(): void
    {
        self::assertTrue(Palette::empty()->isEmpty());
        self::assertSame('', Palette::empty()->fingerprint());
        $p = new Palette(darkBase: 'stone');
        self::assertFalse($p->isEmpty());
        self::assertSame(40, strlen($p->fingerprint()));
        self::assertNotSame($p->fingerprint(), (new Palette(darkBase: 'zinc'))->fingerprint());
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Contracts/PaletteTest.php`
Expected: FAIL, `Class "Thallo\Contracts\Style\Palette" not found`.

- [ ] **Step 3: Implement `BrandSlot`, `Palette`, `PaletteProvider`**

```php
<?php
declare(strict_types=1);
namespace Thallo\Contracts\Style;

/** A configured brand colour (custom palette spec §2.3): the author's label and its light hex. */
final class BrandSlot
{
    public function __construct(public readonly string $name, public readonly string $hex)
    {
    }

    /** @return array{name:string,hex:string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'hex' => $this->hex];
    }
}
```

```php
<?php
declare(strict_types=1);
namespace Thallo\Contracts\Style;

/**
 * The site's own colours beyond the families (custom palette spec §2): a Custom neutral, the
 * dark-mode base family it uses, and three stable brand slots. Stored references name the slot
 * token (`color.brand-1`), never the author's label; a reference to an unconfigured slot is valid
 * data that renders no colour (§3.2).
 */
final class Palette
{
    public const SLOTS = [1, 2, 3];
    public const NEUTRAL_KEYS = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line'];

    /**
     * @param array<string,string>|null $customNeutral
     * @param array<int,BrandSlot|null> $brands
     */
    public function __construct(
        public readonly ?array $customNeutral = null,
        public readonly ?string $darkBase = null,
        public readonly array $brands = [1 => null, 2 => null, 3 => null],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function brand(int $slot): ?BrandSlot
    {
        return $this->brands[$slot] ?? null;
    }

    public function isConfigured(int $slot): bool
    {
        return $this->brand($slot) !== null;
    }

    public static function slotOf(string $token): ?int
    {
        return preg_match('/\Acolor\.brand-([123])(?:-contrast)?\z/', $token, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isContrastToken(string $token): bool
    {
        return self::slotOf($token) !== null && str_ends_with($token, '-contrast');
    }

    public function isUnavailable(string $token): bool
    {
        $slot = self::slotOf($token);
        return $slot !== null && !$this->isConfigured($slot);
    }

    public function isEmpty(): bool
    {
        return $this->customNeutral === null && $this->darkBase === null
            && array_filter($this->brands) === [];
    }

    public function fingerprint(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $brands = [];
        foreach (self::SLOTS as $slot) {
            $brands[$slot] = $this->brand($slot)?->toArray();
        }
        return sha1((string) json_encode([$this->customNeutral, $this->darkBase, $brands]));
    }

    /** @param array<int,BrandSlot|null> $brands */
    public function withBrands(array $brands): self
    {
        return new self($this->customNeutral, $this->darkBase, $brands + $this->brands);
    }
}
```

```php
<?php
declare(strict_types=1);
namespace Thallo\Contracts\Style;

/** The workspace's palette (custom palette spec §2), read by render and by the palette services. */
interface PaletteProvider
{
    public function palette(): Palette;
}
```

- [ ] **Step 4: Run the unit test**

Run: `vendor/bin/phpunit tests/Unit/Contracts/PaletteTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing settings test**

`tests/Integration/Settings/PaletteSettingsTest.php`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Integration\Settings;

use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §2: keys, hex forms, JSON shapes, 422s, normalisation, lifecycle on the server. */
final class PaletteSettingsTest extends AppTestCase
{
    private const SIX = '{"bg":"#F8F4EC","surface":"#fff","surface_2":"#efe7d8","ink":"#1b1712","muted":"#6b6156","line":"#e2d8c6"}';

    private function save(array $args): \Glueful\Http\Response
    {
        return $this->container()->get(GeneralSettingsController::class)->update(new UpdateGeneralSettingsData(...$args));
    }

    public function testCustomNeutralIsStoredNormalised(): void
    {
        $res = $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX, 'theme_dark_base' => 'stone']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $p = $this->container()->get(PaletteProvider::class)->palette();
        self::assertSame('#f8f4ec', $p->customNeutral['bg']);
        self::assertSame('#ffffff', $p->customNeutral['surface']);
        self::assertSame('stone', $p->darkBase);
        self::assertSame('custom', $this->container()->get(GeneralSettings::class)->themeNeutral());
    }

    public function testCustomRequiresAllSixValues(): void
    {
        $res = $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => '{"bg":"#fff"}']);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('theme_neutral_custom', (string) $res->getContent());
        // custom with nothing stored and nothing sent is refused too
        self::assertSame(422, $this->save(['theme_neutral' => 'custom'])->getStatusCode());
    }

    public function testMalformedValuesAre422NamingTheField(): void
    {
        foreach ([
            ['theme_neutral_custom', '{"bg":"red","surface":"#fff","surface_2":"#fff","ink":"#000","muted":"#000","line":"#000"}'],
            ['theme_neutral_custom', 'not json'],
            ['theme_dark_base', 'purple'],
            ['theme_brand_1', '{"name":"Gold","hex":"#12345"}'],
            ['theme_brand_1', '{"name":"","hex":"#123456"}'],
            ['theme_brand_1', '{"name":"' . str_repeat('x', 33) . '","hex":"#123456"}'],
            ['theme_brand_1', '{"name":"Gold","hex":"#fff;}body{"}'],
        ] as [$key, $value]) {
            $res = $this->save([$key => $value]);
            self::assertSame(422, $res->getStatusCode(), "{$key}={$value}");
            self::assertStringContainsString($key, (string) $res->getContent());
        }
    }

    public function testABrandSlotIsStoredWithItsNameTrimmedAndHexNormalised(): void
    {
        $res = $this->save(['theme_brand_1' => '{"name":"  Gold dark ","hex":"#8A6A2A"}']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $slot = $this->container()->get(PaletteProvider::class)->palette()->brand(1);
        self::assertSame('Gold dark', $slot?->name);
        self::assertSame('#8a6a2a', $slot?->hex);
    }

    public function testCustomValuesAreKeptWhenTheNeutralSwitchesToAFamilyAndBack(): void
    {
        $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX, 'theme_dark_base' => 'stone']);
        $this->save(['theme_neutral' => 'zinc']);
        $p = $this->container()->get(PaletteProvider::class)->palette();
        self::assertSame('#f8f4ec', $p->customNeutral['bg']);
        self::assertSame('stone', $p->darkBase);
        // back to Custom without resending: the stored values are used
        self::assertSame(200, $this->save(['theme_neutral' => 'custom'])->getStatusCode());
    }

    public function testResetClearsTheCustomValues(): void
    {
        $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX]);
        self::assertSame(200, $this->save(['theme_neutral' => 'stone', 'theme_neutral_custom' => ''])->getStatusCode());
        self::assertNull($this->container()->get(PaletteProvider::class)->palette()->customNeutral);
    }

    public function testResetWhileCustomIsSelectedIsRefused(): void
    {
        $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX]);
        self::assertSame(422, $this->save(['theme_neutral_custom' => ''])->getStatusCode());
    }

    public function testAStoredValueThatNoLongerParsesReadsAsUnset(): void
    {
        $this->connection()->table('settings')->insert(['key' => 'theme_brand_2', 'value' => '{"hex":"nope"}', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        self::assertNull($this->container()->get(PaletteProvider::class)->palette()->brand(2));
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Settings/PaletteSettingsTest.php`
Expected: FAIL, unknown named parameter `theme_neutral_custom`.

- [ ] **Step 7: Implement**

`GeneralSettings::DEFS`, after `'theme_background'`:

```php
'theme_neutral_custom' => ['thallo.theme.neutral_custom', 'string', ''],
'theme_dark_base' => ['thallo.theme.dark_base', 'string', ''],
'theme_brand_1' => ['thallo.theme.brand_1', 'string', ''],
'theme_brand_2' => ['thallo.theme.brand_2', 'string', ''],
'theme_brand_3' => ['thallo.theme.brand_3', 'string', ''],
```

`GeneralSettings::save()`: extend the explicit-clear set (an empty string deletes the row) to `['homepage_entry', 'theme', 'theme_neutral_custom', 'theme_brand_1', 'theme_brand_2', 'theme_brand_3']`. Brand clears are refused in the controller (Task 13 owns them), so only `theme_neutral_custom` reaches this path from the form.

`UpdateGeneralSettingsData`, after `$theme_background`:

```php
/** @var string|null Custom neutral: JSON {bg,surface,surface_2,ink,muted,line} of hex; '' resets it. */
#[Rule('string')]
public readonly ?string $theme_neutral_custom = null,
/** @var string|null Dark-mode base family under Custom; enum-validated in the controller. */
#[Rule('string')]
public readonly ?string $theme_dark_base = null,
/** @var string|null Brand colour 1: JSON {name, hex}; cleared only through Clear (§4). */
#[Rule('string')]
public readonly ?string $theme_brand_1 = null,
#[Rule('string')]
public readonly ?string $theme_brand_2 = null,
#[Rule('string')]
public readonly ?string $theme_brand_3 = null,
```

Add the same five keys (nullable strings) to `GeneralSettingsData`, which `all()` feeds.

`core/src/Settings/PaletteSettings.php`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Render\Theme\ThemeColors;

/**
 * The palette from general settings (custom palette spec §2): six keys, read DB → config →
 * default like the other theme keys. A stored value that no longer parses reads as unset.
 */
final class PaletteSettings implements PaletteProvider
{
    public const NAME_MAX = 32;

    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function palette(): Palette
    {
        $brands = [];
        foreach (Palette::SLOTS as $slot) {
            $brands[$slot] = self::parseBrand($this->settings->stored('theme_brand_' . $slot));
        }
        $base = $this->settings->stored('theme_dark_base');
        return new Palette(
            self::parseNeutral($this->settings->stored('theme_neutral_custom')),
            ThemeColors::normalizeNeutral($base) === null ? null : $base,
            $brands,
        );
    }

    public static function normalizeHex(string $value): ?string
    {
        $hex = ThemeColors::normalizeSiteAccent(trim($value));
        return $hex !== null && str_starts_with($hex, '#') ? $hex : null;
    }

    /** @return array<string,string>|null */
    public static function parseNeutral(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $out = [];
        foreach (Palette::NEUTRAL_KEYS as $key) {
            $hex = is_string($data[$key] ?? null) ? self::normalizeHex($data[$key]) : null;
            if ($hex === null) {
                return null;
            }
            $out[$key] = $hex;
        }
        return $out;
    }

    public static function parseBrand(string $json): ?BrandSlot
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['hex'] ?? null)) {
            return null;
        }
        $name = trim($data['name']);
        $hex = self::normalizeHex($data['hex']);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || $hex === null) {
            return null;
        }
        return new BrandSlot($name, $hex);
    }

    /** @return array<string,string> field => message */
    public function validate(UpdateGeneralSettingsData $input): array
    {
        $errors = [];
        if ($input->theme_neutral_custom !== null && $input->theme_neutral_custom !== ''
            && self::parseNeutral($input->theme_neutral_custom) === null) {
            $errors['theme_neutral_custom'] = 'six hex colours are required: bg, surface, surface_2, ink, muted, line';
        }
        $neutral = $input->theme_neutral ?? $this->settings->themeNeutral();
        if ($neutral === 'custom') {
            $sent = $input->theme_neutral_custom;
            $stored = self::parseNeutral($this->settings->stored('theme_neutral_custom'));
            if ($sent === '' || ($sent === null && $stored === null)) {
                $errors['theme_neutral_custom'] ??= 'Custom needs all six colours (reset only after choosing a family)';
            }
        }
        if ($input->theme_dark_base !== null && ThemeColors::normalizeNeutral($input->theme_dark_base) === null) {
            $errors['theme_dark_base'] = 'unknown neutral family';
        }
        foreach (Palette::SLOTS as $slot) {
            $value = $input->{'theme_brand_' . $slot};
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                $errors['theme_brand_' . $slot] = 'clear a brand colour with Clear, which checks where it is used';
            } elseif (self::parseBrand($value) === null) {
                $errors['theme_brand_' . $slot] = 'a name (1–' . self::NAME_MAX . ' characters) and a hex colour are required';
            }
        }
        return $errors;
    }

    /** The stored spelling of a submitted brand slot: trimmed name, normalised hex. */
    public static function encodeBrand(string $json): string
    {
        $slot = self::parseBrand($json) ?? throw new \InvalidArgumentException('invalid brand slot');
        return (string) json_encode($slot->toArray());
    }

    public static function encodeNeutral(string $json): string
    {
        return $json === '' ? '' : (string) json_encode(self::parseNeutral($json) ?? throw new \InvalidArgumentException('invalid neutral'));
    }
}
```

Add `GeneralSettings::stored(string $key): string`, returning the raw stored row or `''` (wraps `storedValue()` without clearing the cache):

```php
/** The raw stored value of a key, '' when no row: the palette keys have no config fallback worth reading. */
public function stored(string $key): string
{
    return (string) ($this->store->get($key) ?? '');
}
```

`ThemeColors::normalizeNeutral` must accept `custom` for `theme_neutral`. Do **not** add `custom` to `NEUTRALS`, because scoped skins and the dark base stay family-only. Instead, in `GeneralSettingsController::validate()` replace the neutral check with:

```php
if ($input->theme_neutral !== null && $input->theme_neutral !== 'custom'
    && ThemeColors::normalizeNeutral($input->theme_neutral) === null) {
    $errors['theme_neutral'] = 'unknown neutral color';
}
$errors += $this->palette->validate($input);
```

Inject `private readonly ?PaletteSettings $palette = null` into the controller constructor (autowired). In the `save([...])` map add:

```php
'theme_neutral_custom' => $input->theme_neutral_custom === null ? null : PaletteSettings::encodeNeutral($input->theme_neutral_custom),
'theme_dark_base' => $input->theme_dark_base,
'theme_brand_1' => $input->theme_brand_1 === null ? null : PaletteSettings::encodeBrand($input->theme_brand_1),
'theme_brand_2' => $input->theme_brand_2 === null ? null : PaletteSettings::encodeBrand($input->theme_brand_2),
'theme_brand_3' => $input->theme_brand_3 === null ? null : PaletteSettings::encodeBrand($input->theme_brand_3),
```

`EngineThemeAppearanceProvider::neutral()` keeps returning the stored value, which may now be `custom`. `ThemeAppearanceSource` falls back for an unknown family, and Task 2 teaches render what `custom` means.

Bind in `CoreServiceProvider` next to `ThemeAppearanceProvider`:

```php
\Thallo\Contracts\Style\PaletteProvider::class => [
    'class'    => \Thallo\Core\Settings\PaletteSettings::class,
    'shared'   => true,
    'autowire' => true,
],
```

- [ ] **Step 8: Run both tests and the existing appearance tests**

Run: `vendor/bin/phpunit tests/Unit/Contracts/PaletteTest.php tests/Integration/Settings/PaletteSettingsTest.php tests/Integration/Content/GeneralSettingsAppearanceTest.php tests/Integration/Settings/ThemeAppearanceSettingsTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add packages/thallo-contracts/src/Style/{BrandSlot,Palette,PaletteProvider}.php core/src/Settings/{PaletteSettings,GeneralSettings}.php core/src/Http core/src/Providers/CoreServiceProvider.php tests/Unit/Contracts/PaletteTest.php tests/Integration/Settings/PaletteSettingsTest.php .github/workflows
git commit -m "feat(palette): the palette's settings — a Custom neutral, a dark-mode base and three brand slots, validated and normalised"
```

---

### Task 2: Emitting the palette and the effective palette

**Files:**
- Modify: `packages/thallo-render/src/Theme/ThemeColors.php`
- Modify: `packages/thallo-render/src/Theme/ThemeDesign.php` (Tinted under Custom)
- Create: `packages/thallo-render/src/Theme/EffectivePalette.php` (light/dark values per colour token plus the §6 contrast rows)
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (`themeColorsStyle()`; new `?PaletteProvider $palettes` constructor parameter; a `$appearancePaletteOverride` property)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (soft-bind `PaletteProvider` into the extension like `ThemeAppearanceProvider`)
- Test: `tests/Integration/Render/PaletteCssTest.php`, `tests/Unit/Render/EffectivePaletteTest.php`

**Interfaces:**
- Consumes: `Palette`, `BrandSlot`.
- Produces:
  - `ThemeColors::paletteCss(string $accent, string $neutral, Palette $palette, bool $colorMode): string` returns `''` for an empty palette with a family neutral, so existing sites keep today's `css()` output.
  - `ThemeColors::brandVars(string $hex, string $mode, string $darkGround): array` → `['--brand-N' => …]` is built by the caller; this returns `[fill, ink]`.
  - `EffectivePalette::of(string $accent, string $neutral, string $background, Palette $palette): EffectivePalette` with `values(string $mode): array<string,string>` (token name → hex, e.g. `'surface-2' => '#efe7d8'`), `contrastRows(): list<array{fg:string,on:string,mode:string,ratio:float,passes:bool}>` and `swatches(): array<string,string>` (light mode, `color.*` keys).

- [ ] **Step 0: Capture today's rendered page, before any render change**

Create `tests/Integration/Render/PrePaletteRenderSnapshotTest.php`. It renders one fixture page through the real public render path, and compares the result to `tests/fixtures/palette/pre-palette-page.html` with the compiled-stylesheet URL normalised. The fixture page has a heading, a section on `color.surface-2`, and a button with a hover colour and the accent. The URL normalisation is `preg_replace('#/_thallo/style/[^"\']+#', '{{STYLESHEET}}', $html)`; match the path to the stylesheet link `layout.twig` emits.

When the fixture file is missing, the test writes it and calls `markTestIncomplete('captured')`.

Run it once **now**, before any change in this task: `vendor/bin/phpunit tests/Integration/Render/PrePaletteRenderSnapshotTest.php`. Then run it again: expected PASS. Commit the fixture with this task. It stays green through every later task, which is the §7 guarantee for rendered HTML. Task 3 changes the compiled stylesheet's bytes, so its URL changes; the normalisation absorbs that and nothing else.

- [ ] **Step 1: Write the failing CSS test**

`tests/Integration/Render/PaletteCssTest.php`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;

/** Custom palette spec §2.2, §2.3, §3.2, §3.3, §7. */
final class PaletteCssTest extends TestCase
{
    private const SIX = ['bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8', 'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6'];

    public function testAnEmptyPaletteEmitsExactlyTodaysCss(): void
    {
        foreach ([['blue', 'slate'], ['rose', 'stone'], ['#0a7c66', 'zinc']] as [$a, $n]) {
            self::assertSame(ThemeColors::css($a, $n), ThemeColors::paletteCss($a, $n, Palette::empty(), true), "{$a}/{$n}");
        }
    }

    public function testCustomEmitsTheSixLightValuesAndTheDarkBaseFamily(): void
    {
        $css = ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, 'stone'), true);
        self::assertStringContainsString(':root{--bg:#f8f4ec;--surface:#ffffff;--surface-2:#efe7d8;--ink:#1b1712;--muted:#6b6156;--line:#e2d8c6;', $css);
        $dark = substr($css, strpos($css, 'html[data-theme="dark"]{'));
        foreach (ThemeColors::neutralTokens('stone', 'dark') as $var => $hex) {
            self::assertStringContainsString("{$var}:{$hex};", $dark);
        }
    }

    public function testTheDarkBaseDefaultsToSlateWhenUnset(): void
    {
        $css = ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, null), true);
        self::assertStringContainsString('--bg:' . ThemeColors::neutralTokens('slate', 'dark')['--bg'] . ';', $css);
    }

    public function testWithColourModeOffNoDarkBlockIsEmitted(): void
    {
        self::assertStringNotContainsString('data-theme="dark"', ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, 'stone'), false));
    }

    public function testAHexAccentDarkensAgainstTheEffectiveDarkGround(): void
    {
        $custom = ThemeColors::paletteCss('#1b3a8a', 'custom', new Palette(self::SIX, 'stone'), true);
        $family = ThemeColors::css('#1b3a8a', 'stone');
        $pick = static fn (string $css): string => preg_match('/html\[data-theme="dark"\]\{[^}]*--accent:(#[0-9a-f]{6})/', $css, $m) === 1 ? $m[1] : '';
        self::assertSame($pick($family), $pick($custom)); // same dark ground → same derivation
    }

    public function testAConfiguredBrandSlotEmitsFillAndInkInBothModesAndAnUnsetOneNothing(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $css = ThemeColors::paletteCss('blue', 'slate', $p, true);
        self::assertStringContainsString(':root{', $css);
        self::assertMatchesRegularExpression('/:root\{[^}]*--brand-1:#8a6a2a;--brand-1-ink:#(?:000000|ffffff);/', $css);
        self::assertMatchesRegularExpression('/html\[data-theme="dark"\]\{[^}]*--brand-1:#[0-9a-f]{6};--brand-1-ink:#(?:000000|ffffff);/', $css);
        self::assertStringNotContainsString('--brand-2', $css);
        [$darkFill] = ThemeColors::brandVars('#8a6a2a', 'dark', ThemeColors::neutralTokens('slate', 'dark')['--bg']);
        self::assertGreaterThanOrEqual(4.5, ThemeColors::contrast($darkFill, ThemeColors::neutralTokens('slate', 'dark')['--bg']));
    }

    public function testTintedSwapsTheCustomBackgroundAndSurface(): void
    {
        $css = ThemeDesign::css('soft', 'system', 'tinted', 'custom', null, null, self::SIX);
        self::assertStringContainsString('--bg:#ffffff', $css);
        self::assertStringContainsString('--surface:#f8f4ec', $css);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Render/PaletteCssTest.php`
Expected: FAIL, `Call to undefined method ThemeColors::paletteCss()`.

- [ ] **Step 3: Implement in `ThemeColors`**

```php
/** The palette's custom keys → the neutral variables it emits (custom palette spec §3.3). */
private const CUSTOM_VARS = ['bg' => '--bg', 'surface' => '--surface', 'surface_2' => '--surface-2', 'ink' => '--ink', 'muted' => '--muted', 'line' => '--line'];

/**
 * The site's colour declarations including its palette (custom palette spec §3.2, §3.3). An
 * empty palette over a family neutral is exactly css(): existing sites are byte-identical (§7).
 */
public static function paletteCss(string $accent, string $neutral, Palette $palette, bool $colorMode): string
{
    $custom = $neutral === 'custom' && $palette->customNeutral !== null;
    if (!$custom && array_filter($palette->brands) === []) {
        return self::css($accent, $neutral === 'custom' ? self::DEFAULT_NEUTRAL : $neutral);
    }
    $darkFamily = $custom ? ($palette->darkBase ?? self::DEFAULT_NEUTRAL) : $neutral;
    $light = $custom ? self::customVars($palette->customNeutral) : self::neutralVars($neutral, 'light');
    $light += self::accentVars($accent, 'light', $darkFamily);
    $dark = self::neutralVars($darkFamily, 'dark') + self::accentVars($accent, 'dark', $darkFamily);
    $ground = $dark['--bg'];
    foreach (Palette::SLOTS as $slot) {
        $brand = $palette->brand($slot);
        if ($brand === null) {
            continue; // an unset slot emits nothing (§3.2)
        }
        [$fill, $ink] = self::brandVars($brand->hex, 'light', $ground);
        $light["--brand-{$slot}"] = $fill;
        $light["--brand-{$slot}-ink"] = $ink;
        [$fill, $ink] = self::brandVars($brand->hex, 'dark', $ground);
        $dark["--brand-{$slot}"] = $fill;
        $dark["--brand-{$slot}-ink"] = $ink;
    }
    $css = ':root{' . self::declarations($light) . '}';
    return $colorMode ? $css . 'html[data-theme="dark"]{' . self::declarations($dark) . '}' : $css;
}

/** @return array{0:string,1:string} the fill and its black-or-white text colour for one mode (§2.3) */
public static function brandVars(string $hex, string $mode, string $darkGround): array
{
    $fill = $hex;
    if ($mode === 'dark') {
        for ($step = 1; $step <= 20 && self::contrast($fill, $darkGround) < 4.5; $step++) {
            $fill = self::mix($hex, '#ffffff', $step * 0.05);
        }
    }
    $ink = self::contrast($fill, '#000000') > self::contrast($fill, '#ffffff') ? '#000000' : '#ffffff';
    return [$fill, $ink];
}

/** @param array<string,string> $six @return array<string,string> */
public static function customVars(array $six): array
{
    $out = [];
    foreach (self::CUSTOM_VARS as $key => $var) {
        $out[$var] = $six[$key];
    }
    return $out;
}
```

`paletteCss()` with `$colorMode = true` and no palette returns `css()` unchanged, which today always emits a dark block. The existing `themeColorsStyle()` never gated `css()` on colour mode, so the empty-palette branch must keep emitting what it emits today. Pass `$colorMode` into the Custom branch only (as written above).

Make `ThemeDesign::css()` take a seventh parameter `?array $customNeutral = null`. In the Tinted swap, read `$light = $customNeutral !== null && $neutral === 'custom' ? ThemeColors::customVars($customNeutral) : ThemeColors::neutralTokens($neutral, 'light');`.

`RenderContextExtension::themeColorsStyle()`:
- Read `$palette = $this->appearancePaletteOverride ?? $this->palettes?->palette() ?? Palette::empty()`.
- Accept `custom` as a neutral when `$palette->customNeutral !== null`, otherwise normalise as today.
- Replace `ThemeColors::css($accent, $neutral)` with `ThemeColors::paletteCss($accent, $neutral, $palette, $this->colorModeEnabled)`.
- Pass `$palette->customNeutral` to `ThemeDesign::css`.

`ThemeAppearanceSource::neutral()` normalises against the family enum and falls back (with a log line) for anything else. Make it pass `custom` through unchanged, and add a case to `tests/Integration/Render/ThemeAppearanceSourceTest.php` asserting `custom` is returned and logs nothing.

Add the constructor parameter `private readonly ?\Thallo\Contracts\Style\PaletteProvider $palettes = null` next to `fontSnapshots`. In `RenderServiceProvider`, where the extension is built (~820), add `palettes: $container->has(PaletteProvider::class) ? $container->get(PaletteProvider::class) : null`.

- [ ] **Step 4: Run the CSS test**

Run: `vendor/bin/phpunit tests/Integration/Render/PaletteCssTest.php tests/Integration/Render/ThemeColorsTest.php tests/Integration/Render/ThemeColorsStyleTest.php tests/Integration/Render/ThemeDesignTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing `EffectivePalette` test**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Render\Theme\EffectivePalette;
use Thallo\Render\Theme\ThemeColors;

/** Custom palette spec §6: specific pairs, effective palette, both modes, 4.5:1. */
final class EffectivePaletteTest extends TestCase
{
    public function testRowsCoverTheSpecifiedPairsInBothModes(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $rows = EffectivePalette::of('blue', 'slate', 'plain', $p)->contrastRows();
        $pairs = array_map(static fn (array $r): string => "{$r['mode']}:{$r['fg']}/{$r['on']}", $rows);
        foreach (['light', 'dark'] as $m) {
            foreach (['text', 'muted'] as $fg) {
                foreach (['background', 'surface', 'surface-2'] as $on) {
                    self::assertContains("{$m}:{$fg}/{$on}", $pairs);
                }
            }
            self::assertContains("{$m}:accent/background", $pairs);
            self::assertContains("{$m}:accent-contrast/accent", $pairs);
            self::assertContains("{$m}:brand-1/background", $pairs);
            self::assertContains("{$m}:brand-1-contrast/brand-1", $pairs);
            self::assertNotContains("{$m}:brand-2/background", $pairs);
        }
        self::assertCount(2 * (6 + 2 + 2), $rows);
    }

    public function testTintedSwapsBackgroundAndSurfaceBeforeChecking(): void
    {
        $plain = EffectivePalette::of('blue', 'slate', 'plain', Palette::empty())->values('light');
        $tinted = EffectivePalette::of('blue', 'slate', 'tinted', Palette::empty())->values('light');
        self::assertSame($plain['surface'], $tinted['background']);
        self::assertSame($plain['background'], $tinted['surface']);
    }

    public function testARowBelowTheThresholdFails(): void
    {
        $six = ['bg' => '#ffffff', 'surface' => '#ffffff', 'surface_2' => '#ffffff', 'ink' => '#000000', 'muted' => '#eeeeee', 'line' => '#dddddd'];
        $rows = EffectivePalette::of('blue', 'custom', 'plain', new Palette($six, 'slate'))->contrastRows();
        $muted = array_values(array_filter($rows, static fn (array $r): bool => $r['mode'] === 'light' && $r['fg'] === 'muted' && $r['on'] === 'background'))[0];
        self::assertFalse($muted['passes']);
        self::assertEqualsWithDelta(ThemeColors::contrast('#eeeeee', '#ffffff'), $muted['ratio'], 0.01);
    }

    public function testSwatchesAreLightModeColourTokens(): void
    {
        $s = EffectivePalette::of('blue', 'slate', 'plain', Palette::empty())->swatches();
        self::assertSame(ThemeColors::neutralTokens('slate', 'light')['--surface'], $s['color.surface']);
        self::assertSame('#ffffff', $s['color.white']);
        self::assertArrayNotHasKey('color.transparent', $s);
        self::assertArrayNotHasKey('color.brand-1', $s);
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Render/EffectivePaletteTest.php`
Expected: FAIL, class not found.

- [ ] **Step 7: Implement `EffectivePalette`**

```php
<?php
declare(strict_types=1);
namespace Thallo\Render\Theme;

use Thallo\Contracts\Style\Palette;

/**
 * The values the site would render (custom palette spec §6): Plain/Tinted applied, the dark base
 * resolved, brand fills and inks derived — the one source for the contrast checks and the
 * pickers' swatches. Light and dark keyed by colour token name.
 */
final class EffectivePalette
{
    private const VAR_OF = ['background' => '--bg', 'surface' => '--surface', 'surface-2' => '--surface-2', 'text' => '--ink', 'muted' => '--muted', 'line' => '--line', 'accent' => '--accent', 'accent-contrast' => '--accent-ink'];

    /** @param array<string,array<string,string>> $modes */
    private function __construct(private readonly array $modes, private readonly Palette $palette)
    {
    }

    public static function of(string $accent, string $neutral, string $background, Palette $palette): self
    {
        $custom = $neutral === 'custom' && $palette->customNeutral !== null;
        $family = ThemeColors::normalizeNeutral($neutral) ?? ThemeColors::DEFAULT_NEUTRAL;
        $darkFamily = $custom ? ($palette->darkBase ?? ThemeColors::DEFAULT_NEUTRAL) : $family;
        $accent = ThemeColors::normalizeSiteAccent($accent) ?? ThemeColors::DEFAULT_ACCENT;
        $vars = [
            'light' => ($custom ? ThemeColors::customVars($palette->customNeutral) : ThemeColors::neutralTokens($family, 'light'))
                + ThemeColors::tokens($accent, $darkFamily, 'light'),
            'dark' => ThemeColors::tokens($accent, $darkFamily, 'dark'),
        ];
        if ($background === 'tinted') {
            [$vars['light']['--bg'], $vars['light']['--surface']] = [$vars['light']['--surface'], $vars['light']['--bg']];
        }
        $ground = $vars['dark']['--bg'];
        $modes = [];
        foreach ($vars as $mode => $v) {
            foreach (self::VAR_OF as $name => $var) {
                $modes[$mode][$name] = $v[$var];
            }
            $modes[$mode]['white'] = '#ffffff';
            $modes[$mode]['black'] = '#000000';
            foreach (Palette::SLOTS as $slot) {
                $brand = $palette->brand($slot);
                if ($brand !== null) {
                    [$fill, $ink] = ThemeColors::brandVars($brand->hex, $mode, $ground);
                    $modes[$mode]["brand-{$slot}"] = $fill;
                    $modes[$mode]["brand-{$slot}-contrast"] = $ink;
                }
            }
        }
        return new self($modes, $palette);
    }

    /** @return array<string,string> */
    public function values(string $mode): array
    {
        return $this->modes[$mode];
    }

    /** @return list<array{fg:string,on:string,mode:string,ratio:float,passes:bool}> */
    public function contrastRows(): array
    {
        $pairs = [];
        foreach (['text', 'muted'] as $fg) {
            foreach (['background', 'surface', 'surface-2'] as $on) {
                $pairs[] = [$fg, $on];
            }
        }
        $pairs[] = ['accent', 'background'];
        $pairs[] = ['accent-contrast', 'accent'];
        foreach (Palette::SLOTS as $slot) {
            if ($this->palette->isConfigured($slot)) {
                $pairs[] = ["brand-{$slot}", 'background'];
                $pairs[] = ["brand-{$slot}-contrast", "brand-{$slot}"];
            }
        }
        $rows = [];
        foreach (['light', 'dark'] as $mode) {
            foreach ($pairs as [$fg, $on]) {
                $ratio = round(ThemeColors::contrast($this->modes[$mode][$fg], $this->modes[$mode][$on]), 2);
                $rows[] = ['fg' => $fg, 'on' => $on, 'mode' => $mode, 'ratio' => $ratio, 'passes' => $ratio >= 4.5];
            }
        }
        return $rows;
    }

    /** @return array<string,string> `color.<name>` => light hex; brand slots are carried by the palette block */
    public function swatches(): array
    {
        $out = [];
        foreach ($this->modes['light'] as $name => $hex) {
            if (!str_starts_with($name, 'brand-')) {
                $out['color.' . $name] = $hex;
            }
        }
        return $out;
    }
}
```

Make `ThemeColors::tokens()` accept a hex accent and a family (it already does, through `accentVars`). `ThemeColors::neutralTokens()` is already public.

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/Render/EffectivePaletteTest.php tests/Integration/Render/PaletteCssTest.php`
Expected: PASS.

- [ ] **Step 9: Pin byte-identity for an existing site end to end**

Add to `tests/Integration/Render/ThemeColorsStyleTest.php`:

```php
public function testASiteWithNoPaletteKeysEmitsByteIdenticalAppearanceCss(): void
{
    // the expected string is today's output, captured from ThemeColors::css + ThemeDesign::css for the
    // same settings — palette keys absent, neutral a family
    $ext = $this->extension(accent: 'rose', neutral: 'stone');
    $expected = '<style>' . ThemeColors::css('rose', 'stone') . ThemeDesign::css('soft', 'system', 'plain', 'stone') . '</style>';
    self::assertSame($expected, (string) $ext->themeColorsStyle());
}
```

The test file's existing helper builds the extension. Reuse it, or add an `extension(accent, neutral)` helper over `FixedThemeAppearance` (`tests/Support/Fonts/FixedThemeAppearance.php`) with no `PaletteProvider` bound.

Run: `vendor/bin/phpunit tests/Integration/Render/ThemeColorsStyleTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add packages/thallo-render/src/Theme packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/RenderServiceProvider.php tests/Integration/Render/PaletteCssTest.php tests/Unit/Render/EffectivePaletteTest.php tests/Integration/Render/ThemeColorsStyleTest.php tests/Integration/Render/PrePaletteRenderSnapshotTest.php tests/fixtures/palette/pre-palette-page.html .github/workflows
git commit -m "feat(palette): emit a Custom neutral, the dark base and brand colours, with the effective palette for checks and swatches"
```

---

### Task 3: Site-controlled brand tokens in the vocabulary

**Files:**
- Modify: `packages/thallo-contracts/src/Style/Vocabulary.php` (`DOMAINS['color']`; new `SITE_CONTROLLED`)
- Modify: `packages/thallo-render/src/Style/ThemeVocabulary.php` (`fromThemeJson`: fill site-controlled values, ignore a theme's mapping, record it)
- Modify: `core/src/Setup/Doctor/Doctor.php` (`themeVocabularyCheck`: warn on ignored mappings)
- Modify: `packages/thallo-render/src/Style/StyleCompiler.php` (`VERSION = 24` plus a history comment)
- Modify: `tests/fixtures/style/compiled-default-artifact.json` (version 24, new sha256)
- Modify: `tests/Unit/Contracts/StyleSchemaTest.php` (colour list, count `8 + 4 + 5 + 17 + 6 + 7`)
- Modify: `admin/src/style/schema.ts` and the parity fixture, if `style-schema-parity.spec.ts` pins vocabulary names
- Test: `tests/Unit/Render/ThemeVocabularyTest.php`, `tests/Unit/Setup/DoctorTest.php`, `tests/Unit/Render/StyleCompilerTest.php`

**Interfaces:**
- Produces:
  - `Vocabulary::SITE_CONTROLLED` (`array<string,string>`: token → `var(--brand-N)` / `var(--brand-N-ink)`);
  - `ThemeVocabulary::ignored(): list<string>`, the site-controlled tokens a theme tried to map.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Render/ThemeVocabularyTest.php`:

```php
public function testBrandTokensAreSiteControlledAndAThemeCannotRemapThem(): void
{
    $json = $this->minimalThemeJson(); // the file's existing helper: every baseline token mapped
    $json['vocabulary']['color.brand-1'] = '#ff0000';
    $v = ThemeVocabulary::fromThemeJson($json, $this->themeDir());
    self::assertSame('var(--brand-1)', $v->value('color.brand-1'));
    self::assertSame('var(--brand-1-ink)', $v->value('color.brand-1-contrast'));
    self::assertSame(['color.brand-1'], $v->ignored());
}

public function testAThemeWithoutBrandTokensLoads(): void
{
    $json = $this->minimalThemeJson();
    foreach (array_keys(Vocabulary::SITE_CONTROLLED) as $t) {
        unset($json['vocabulary'][$t]);
    }
    $v = ThemeVocabulary::fromThemeJson($json, $this->themeDir());
    self::assertSame('var(--brand-3)', $v->value('color.brand-3'));
    self::assertSame([], $v->ignored());
}
```

If `minimalThemeJson()` / `themeDir()` don't exist, build the JSON from `Vocabulary::all()` with `'x'` values and a temp dir holding one stylesheet, as the file's other tests do.

Append to `tests/Unit/Setup/DoctorTest.php` a case where the theme maps `color.brand-2`. The expected outcome is the `theme-vocabulary` check with status `warn` and a message containing `brand colours are set in Appearance`.

Update `tests/Unit/Contracts/StyleSchemaTest.php::testTheVocabularyIsTheBaseline`:

```php
self::assertSame(
    ['background', 'surface', 'surface-2', 'text', 'muted', 'line', 'accent', 'accent-contrast', 'transparent', 'white', 'black',
     'brand-1', 'brand-1-contrast', 'brand-2', 'brand-2-contrast', 'brand-3', 'brand-3-contrast'],
    Vocabulary::names('color'),
);
self::assertCount(8 + 4 + 5 + 17 + 6 + 7, Vocabulary::all());
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/Render/ThemeVocabularyTest.php tests/Unit/Contracts/StyleSchemaTest.php tests/Unit/Setup/DoctorTest.php`
Expected: FAIL. `SITE_CONTROLLED` is undefined, the colour list is short, and the Doctor reports `ok`.

- [ ] **Step 3: Implement**

`Vocabulary`: append the six names to `DOMAINS['color']` after `black`, and add:

```php
/**
 * Values the SITE sets, not the theme (custom palette spec §3.1): always the variables
 * themeColorsStyle() emits from the palette. A theme's mapping for these is ignored.
 */
public const SITE_CONTROLLED = [
    'color.brand-1' => 'var(--brand-1)', 'color.brand-1-contrast' => 'var(--brand-1-ink)',
    'color.brand-2' => 'var(--brand-2)', 'color.brand-2-contrast' => 'var(--brand-2-ink)',
    'color.brand-3' => 'var(--brand-3)', 'color.brand-3-contrast' => 'var(--brand-3-ink)',
];
```

`ThemeVocabulary::fromThemeJson`, right after `$vocabulary += Vocabulary::LITERAL_DEFAULTS;`:

```php
$ignored = array_values(array_intersect(array_keys(Vocabulary::SITE_CONTROLLED), array_keys($vocabulary)));
$vocabulary = Vocabulary::SITE_CONTROLLED + $vocabulary; // the site's variables win over a theme's mapping
```

Pass `$ignored` to the constructor as a new last parameter `private readonly array $ignored = []`, and add `public function ignored(): array { return $this->ignored; }`.

`Doctor::themeVocabularyCheck`: when `$vocabulary->ignored() !== []`, return `[$vocabulary, Check::warn('theme-vocabulary', "Theme \"{$name}\" maps " . implode(', ', $vocabulary->ignored()) . ' — ignored: brand colours are set in Appearance.')]`.

`StyleCompiler`: set `public const VERSION = 24;` and add the history line `// 24: the six site-controlled brand colour tokens (custom palette spec §3.1).`

Do **not** add brand entries to `packages/thallo-render/themes/default/theme.json`, because a theme may not map them.

- [ ] **Step 4: Re-pin the compiled artifact**

Run: `vendor/bin/phpunit --filter testTheArtifactsBytesAreTiedToTheCompilerVersion tests/Unit/Render/StyleCompilerTest.php`
Expected: FAIL, showing the new sha256. Update `tests/fixtures/style/compiled-default-artifact.json` to `{"version": 24, "sha256": "<the printed value>"}` and update the other `VERSION` assertion at ~537. Re-run; expected PASS.

- [ ] **Step 5: Check the compiled utilities exist**

Append to `StyleCompilerTest`:

```php
public function testTheBrandColourUtilitiesAreCompiled(): void
{
    $css = $this->compileDefault(); // the file's existing helper that compiles the default theme
    self::assertStringContainsString('.' . \Thallo\Render\Style\ClassNames::for('colors.text', 'color.brand-1'), $css);
    self::assertStringContainsString('.' . \Thallo\Render\Style\ClassNames::for('colors.surface', 'color.brand-2-contrast'), $css);
    self::assertStringContainsString('.' . \Thallo\Render\Style\ClassNames::for('hover.colors.text', 'color.brand-3'), $css);
    self::assertStringContainsString('--t-color-brand-3:var(--brand-3)', str_replace(' ', '', $css));
}
```

Run: `vendor/bin/phpunit tests/Unit/Render/StyleCompilerTest.php tests/Unit/Render/ThemeVocabularyTest.php tests/Unit/Contracts tests/Unit/Setup/DoctorTest.php`
Expected: PASS.

- [ ] **Step 6: Update the admin parity, then commit**

Run: `cd admin && pnpm vitest run src/__tests__/style-schema-parity.spec.ts`. If it fails on vocabulary names, add the six names to `admin/src/style/schema.ts`'s colour list and to `src/__tests__/helpers/classEditorSchema.ts`, then re-run. Expected: PASS.

```bash
git add packages/thallo-contracts/src/Style/Vocabulary.php packages/thallo-render/src/Style core/src/Setup/Doctor/Doctor.php tests/Unit tests/fixtures/style/compiled-default-artifact.json admin/src/style/schema.ts admin/src/__tests__/helpers/classEditorSchema.ts
git commit -m "feat(palette): six site-controlled brand colour tokens — compiled once, never remapped by a theme (StyleCompiler 24)"
```

---
### Task 4: Render-time availability, freshness and the fingerprint

**Files:**
- Create: `packages/thallo-render/src/Style/RequestPalette.php`, the palette one request sees (memo plus preview override), modelled on `RequestFontSnapshot`
- Create: `packages/thallo-render/src/Style/PaletteAvailability.php` (strips unavailable colour values from a style layer for one path)
- Modify: `packages/thallo-render/src/Style/BlockStyleEmitter.php` (`classesFor(..., ?Palette $palette = null)`)
- Modify: `packages/thallo-render/src/Style/PageStyle.php` (`classes(?array $style, ?BlockStyleEmitter $emitter = null, ?Palette $palette = null)`)
- Modify: `packages/thallo-render/src/Layouts/FramePresentation.php` (`fixed(?array $frame, ?Palette $palette = null)` and the composing method at ~1282's caller), `packages/thallo-render/src/Http/Controllers/RenderController.php:1282`
- Modify: `packages/thallo-render/src/RenderContextExtension.php`: replace Task 2's `?PaletteProvider $palettes` with `?RequestPalette $paletteRequest`, and add `palette(): Palette`. Pass `$this->palette()` to every `classesFor` call (`styleClasses` ~574, `parentStyleClasses` ~614, `noteMotion` ~697, `regionStyleClasses` ~1394) and to `tokenClass`.
- Modify: `packages/thallo-render/src/ThemeAppearanceSource.php` (a `paletteFingerprint` closure; segment `p<8>`)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (register `RequestPalette` shared; feed the extension, the controllers and `ThemeAppearanceSource`)
- Modify: `core/src/Http/Controllers/GeneralSettingsController.php` (fire `ThemeAppearanceChanged` when the palette fingerprint changed)
- Test: `tests/Unit/Render/BlockStyleEmitterPaletteTest.php`, `tests/Integration/Render/StyleTargetsRenderTest.php`, `tests/Integration/Render/ThemeAppearanceSourceTest.php`, `tests/Integration/Content/GeneralSettingsAppearanceTest.php`, `tests/Integration/Render/PagePaletteAvailabilityTest.php`

**Interfaces:**
- Consumes: `Palette::isUnavailable()`, `PaletteProvider`.
- Produces:
  - `RequestPalette::current(): Palette`, `RequestPalette::override(?Palette $p): void`, `RequestPalette::refresh(): void`;
  - `PaletteAvailability::strip(array $style, string $path, Palette $palette): array`;
  - `RenderContextExtension::palette(): Palette`;
  - `BlockStyleEmitter::classesFor(array $settings, StyleTargets $targets, string $target, array $classDefinitions = [], ?FontLibrarySnapshotView $fonts = null, ?Palette $palette = null): array`.

- [ ] **Step 1: Write the failing emitter test**

`tests/Unit/Render/BlockStyleEmitterPaletteTest.php`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Render\Style\BlockStyleEmitter;

/** Custom palette spec §3.2: an unavailable reference contributes no colour override, in every layer. */
final class BlockStyleEmitterPaletteTest extends TestCase
{
    private function targets(): StyleTargets
    {
        return StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['colors' => 'root', 'hover' => 'root'],
        ]);
    }

    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    private function emit(array $settings, array $classes, Palette $palette): array
    {
        return (new BlockStyleEmitter())->classesFor($settings, $this->targets(), 'root', $classes, null, $palette);
    }

    public function testAClassAccentShowsThroughAnUnavailableInstanceBrand(): void
    {
        $settings = ['classes' => ['c1'], 'style' => ['colors' => ['text' => self::tok('color.brand-1')]]];
        $classes = [['id' => 'c1', 'style' => ['colors' => ['text' => self::tok('color.accent')]]]];
        self::assertSame(['t-fg-accent'], $this->emit($settings, $classes, Palette::empty()));
    }

    public function testAnUnavailableClassValueUnderAnInstanceAccentStaysAccent(): void
    {
        $settings = ['classes' => ['c1'], 'style' => ['colors' => ['text' => self::tok('color.accent')]]];
        $classes = [['id' => 'c1', 'style' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        self::assertSame(['t-fg-accent'], $this->emit($settings, $classes, Palette::empty()));
    }

    public function testAnUnavailableValueAloneEmitsNothing(): void
    {
        $settings = ['style' => ['colors' => ['text' => self::tok('color.brand-3-contrast')]]];
        self::assertSame([], $this->emit($settings, [], Palette::empty()));
    }

    public function testAMissingHoverColourLeavesTheNormalColour(): void
    {
        $settings = ['style' => [
            'colors' => ['text' => self::tok('color.accent')],
            'hover' => ['colors' => ['text' => self::tok('color.brand-1')]],
        ]];
        $classes = $this->emit($settings, [], Palette::empty());
        self::assertSame(['t-fg-accent'], $classes, 'no hover utility, so the resting colour applies on hover and focus');
    }

    public function testAConfiguredSlotEmitsItsUtility(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $settings = ['style' => ['colors' => ['text' => self::tok('color.brand-1')], 'hover' => ['colors' => ['surface' => self::tok('color.brand-1-contrast')]]]];
        $out = $this->emit($settings, [], $p);
        self::assertContains(\Thallo\Render\Style\ClassNames::for('colors.text', 'color.brand-1'), $out);
        self::assertContains(\Thallo\Render\Style\ClassNames::for('hover.colors.surface', 'color.brand-1-contrast'), $out);
    }

    public function testPartsFollowTheSameRule(): void
    {
        $targets = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'parts' => ['link' => ['label' => 'Link', 'capabilities' => ['colors.text', 'hover.colors.text']]],
        ]);
        $settings = ['parts' => ['link' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        self::assertSame([], (new BlockStyleEmitter())->classesFor($settings, $targets, 'link', [], null, Palette::empty()));
    }

    public function testWithoutAPaletteNothingIsFiltered(): void
    {
        // callers that pass no palette (none after this task) keep today's behaviour
        $settings = ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]];
        self::assertSame([\Thallo\Render\Style\ClassNames::for('colors.text', 'color.brand-1')], (new BlockStyleEmitter())->classesFor($settings, $this->targets(), 'root'));
    }
}
```

If `map` keys in `fromDeclaration` must name exact paths rather than groups, use `'colors.text' => 'root', 'hover.colors.text' => 'root', 'hover.colors.surface' => 'root'`; check `StyleTargets::fromDeclaration`'s mapping rules.

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Render/BlockStyleEmitterPaletteTest.php`
Expected: FAIL. Too many arguments to `classesFor()`, or `t-fg-brand-1` emitted.

- [ ] **Step 3: Implement `PaletteAvailability` and the emitter change**

```php
<?php
declare(strict_types=1);
namespace Thallo\Render\Style;

use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\StyleSchema;

/**
 * Custom palette spec §3.2: a colour value naming an unconfigured brand slot (or its contrast
 * token) is removed from a cascade layer before the cascade resolves, so the layer behaves as
 * if it never set the property and a lower layer — or the theme default — shows through. CSS
 * cannot do this: an undefined variable resolves to the inherited/initial value, not to an
 * earlier declaration.
 */
final class PaletteAvailability
{
    /** @param array<string,mixed> $style @return array<string,mixed> */
    public static function strip(array $style, string $path, Palette $palette): array
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        $node = &$style;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                return $style;
            }
            $node = &$node[$part];
        }
        if (!isset($node[$last]) || !is_array($node[$last])) {
            return $style;
        }
        $value = &$node[$last];
        if (isset($value['type'])) {
            if (self::unavailable($value, $palette)) {
                unset($node[$last]);
            }
            return $style;
        }
        foreach (StyleSchema::BREAKPOINTS as $bp) {
            if (isset($value[$bp]) && is_array($value[$bp]) && self::unavailable($value[$bp], $palette)) {
                unset($value[$bp]);
            }
        }
        return $style;
    }

    /** @param array<string,mixed> $typed */
    private static function unavailable(array $typed, Palette $palette): bool
    {
        return ($typed['type'] ?? null) === 'token' && is_string($typed['value'] ?? null)
            && $palette->isUnavailable($typed['value']);
    }
}
```

In `BlockStyleEmitter::classesFor`, inside the `foreach ($paths as $path)` loop, right before `$resolved = $this->resolver->resolve(...)`:

```php
if ($palette !== null && $def->tokenDomain === 'color') {
    $instance = PaletteAvailability::strip($instance, $path, $palette);
    foreach ($classDefinitions as $i => $class) {
        $classDefinitions[$i]['style'] = PaletteAvailability::strip((array) ($class['style'] ?? []), $path, $palette);
    }
}
```

`$instance` and `$classDefinitions` are by-value locals, so stripping one path cannot leak into another path's resolution or into the caller.

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit tests/Unit/Render/BlockStyleEmitterPaletteTest.php tests/Unit/Render/BlockStyleEmitterTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing render-path tests**

Append to `tests/Integration/Render/StyleTargetsRenderTest.php`:

```php
public function testTokenClassEmitsNothingForAnUnavailableBrandToken(): void
{
    $env = $this->env(); // no palette configured in the test workspace
    $render = static fn (string $twig): string => $env->createTemplate($twig)->render([]);
    self::assertSame('', $render("{{ token_class('colors.text', 'color.brand-1') }}"));
    self::assertSame('', $render("{{ token_class('colors.text', 'color.brand-2-contrast') }}"));
}
```

Create `tests/Integration/Render/PagePaletteAvailabilityTest.php`. It saves a brand slot through `GeneralSettings::save(['theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}'])`, then renders:

- an **Animated text** block whose `prefix_color`, `rotate_color` and `suffix_color` are `{type:'token', value:'color.brand-1'}`, `color.brand-2` and `color.accent`. Prefix carries the brand-1 utility, rotate carries no colour utility, suffix carries `t-fg-accent`. Use the render helper `StarterTemplatesTest::testAnimatedTextIntervalAndSegmentStyles` uses (~751).
- a page whose `_presentation.style.colors.surface` is `color.brand-2`: `<main>` carries no surface utility.
- a region (header) whose own `settings.style.colors.surface` is `color.brand-2`: `region_style_classes('header')` carries no surface utility, and with `color.brand-1` it carries the brand-1 utility.
- a layout frame `style.colors.surface` of `color.brand-2` through `FramePresentation::fixed()`: no utility.

```php
public function testUnavailableValuesRenderNoClassOnEveryPath(): void
{
    $this->settings()->save(['theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}']);
    $this->refreshRequestPalette(); // container()->get(RequestPalette::class)->refresh()

    $html = $this->renderBlock('animated_text', ['data' => [
        'prefix' => 'Made', 'rotate' => ['by hand'], 'suffix' => 'here',
        'prefix_color' => ['type' => 'token', 'value' => 'color.brand-1'],
        'rotate_color' => ['type' => 'token', 'value' => 'color.brand-2'],
        'suffix_color' => ['type' => 'token', 'value' => 'color.accent'],
    ]]);
    self::assertStringContainsString(ClassNames::for('colors.text', 'color.brand-1'), $html);
    self::assertStringNotContainsString(ClassNames::for('colors.text', 'color.brand-2'), $html);
    self::assertStringContainsString('t-fg-accent', $html);

    $palette = $this->container()->get(RequestPalette::class)->current();
    self::assertSame('', PageStyle::classes(['colors' => ['surface' => ['type' => 'token', 'value' => 'color.brand-2']]], null, $palette));
    self::assertSame('', FramePresentation::fixed(['style' => ['colors' => ['surface' => ['type' => 'token', 'value' => 'color.brand-2']]]], $palette)['style_classes']);
    // the region's own style
    $this->connection()->table('regions')->where('slug', '=', 'header')->update(['settings' => json_encode(['style' => ['colors' => ['surface' => ['type' => 'token', 'value' => 'color.brand-2']]]])]);
    self::assertStringNotContainsString('brand-2', (string) $this->extension()->regionStyleClasses('header'));
}
```

Use the animated text data keys exactly as `StarterBlockTypes.php` (~649) declares them. The keys above are illustrative where the schema differs; read the schema and use its field names.

- [ ] **Step 6: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Render/StyleTargetsRenderTest.php tests/Integration/Render/PagePaletteAvailabilityTest.php`
Expected: FAIL. `token_class` emits `t-fg-brand-1`, and `PageStyle::classes` does not accept a palette.

- [ ] **Step 7: Implement**

`RequestPalette`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Render\Style;

use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteProvider;

/**
 * The palette one request sees (custom palette spec §3.2, §5.1): taken once, shared by the
 * fingerprint, themeColorsStyle() and every class decision; an Appearance preview overrides it
 * for its own render only.
 */
final class RequestPalette
{
    private ?Palette $palette = null;
    private ?Palette $override = null;

    public function __construct(private readonly ?PaletteProvider $provider = null)
    {
    }

    public function current(): Palette
    {
        return $this->override ?? ($this->palette ??= $this->provider?->palette() ?? Palette::empty());
    }

    public function override(?Palette $palette): void
    {
        $this->override = $palette;
    }

    public function refresh(): void
    {
        $this->palette = null;
        $this->override = null;
    }
}
```

In `RenderContextExtension`:
- add `public function palette(): Palette { return $this->paletteRequest?->current() ?? Palette::empty(); }`;
- pass `$this->palette()` as the sixth argument at every `classesFor` call;
- in `tokenClass`, after `$valid` is computed, add `if ($valid && $def->tokenDomain === 'color' && $this->palette()->isUnavailable($value)) { return ''; }`;
- `themeColorsStyle()` reads `$this->palette()`.

`setThemeAppearanceOverride(...)` gains `?Palette $palette = null` and calls `$this->paletteRequest?->override($palette)`. The existing resets (`(null, null)` in `EntryBlocksRenderer`, `FragmentRenderer`, `ShopCartController`, `ShopPageRenderer`, `ShopCheckoutController`, `AccountPageRenderer`, `SearchPageController`) clear it through the same call.

`PageStyle::classes`: pass `null` fonts and `$palette` through to `classesFor`. In `RenderController::...` (~1282) pass `$this->paletteRequest?->current()`. Add `?RequestPalette $paletteRequest = null` to the controller's constructor and its factory (`RenderServiceProvider` ~540). The static presentation composer receives the palette as a new last parameter from its instance caller. `FramePresentation::fixed(?array $frame, ?Palette $palette = null)` forwards it; grep its callers and pass the extension's or controller's palette.

`RenderServiceProvider`:
- register `RequestPalette::class => ['factory' => [self::class, 'makeRequestPalette'], 'shared' => true]`, where the factory returns `new RequestPalette($c->has(PaletteProvider::class) ? $c->get(PaletteProvider::class) : null)`;
- inject it as `paletteRequest:` into the extension and the controllers;
- add a closure to `makeThemeAppearanceSource()`: `paletteFingerprint: static fn (): string => $c->get(RequestPalette::class)->current()->fingerprint()`.

`ThemeAppearanceSource`: add a constructor parameter `?Closure $paletteFingerprint = null`. In `segments()`, after the fonts segment: `$p = $this->paletteFingerprint === null ? '' : (string) ($this->paletteFingerprint)(); if ($p !== '') { $segments[] = 'p' . substr($p, 0, 8); }`. An empty palette adds nothing, so existing fingerprints are unchanged.

`GeneralSettingsController::update()`:
- capture `$paletteBefore = $this->palette?->palette()->fingerprint()` next to `$designBefore`;
- after the save, compare with `$this->palette?->palette()->fingerprint()`;
- add `|| $paletteAfter !== $paletteBefore` to the `ThemeAppearanceChanged` condition.

`PaletteSettings::palette()` reads through `GeneralSettings::stored()`. The settings store caches reads; call `$this->settings->storedValue(...)` (which clears the cache) for the "after" read, or clear the store cache before comparing.

- [ ] **Step 8: Write the fingerprint and event tests**

Append to `tests/Integration/Render/ThemeAppearanceSourceTest.php`:

```php
public function testAnEmptyPaletteLeavesTheFingerprintUnchangedAndAPaletteEntersIt(): void
{
    $palette = Palette::empty();
    $make = fn () => $this->source(paletteFingerprint: static fn (): string => $palette->fingerprint());
    $before = $make()->fingerprint();
    self::assertStringNotContainsString('-p', $before);
    $palette = new Palette(brands: [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => null, 3 => null]);
    self::assertStringContainsString('-p' . substr($palette->fingerprint(), 0, 8), $make()->fingerprint());
    self::assertStringContainsString('-p', $make()->appearanceFingerprint(), 'open stages refresh too');
}
```

`$this->source(...)` is the test's existing builder; add the named argument to it.

Append to `tests/Integration/Content/GeneralSettingsAppearanceTest.php`:

```php
public function testABrandColourChangeFiresThemeAppearanceChangedAndAnUnchangedOneDoesNot(): void
{
    $fired = [];
    $this->container()->get(EventService::class)->listen(ThemeAppearanceChanged::class, function ($e) use (&$fired): void { $fired[] = $e; });
    $c = $this->container()->get(GeneralSettingsController::class);
    $c->update(new UpdateGeneralSettingsData(theme_brand_1: '{"name":"Gold","hex":"#8a6a2a"}'));
    self::assertCount(1, $fired);
    $c->update(new UpdateGeneralSettingsData(theme_brand_1: '{"name":"Gold","hex":"#8A6A2A"}'));
    self::assertCount(1, $fired, 'same normalised value: no event');
    $c->update(new UpdateGeneralSettingsData(theme_dark_base: 'stone'));
    self::assertCount(2, $fired);
}
```

Match the event-listening idiom the file already uses for `ThemeAppearanceChanged`.

- [ ] **Step 9: Run everything touched**

Run: `vendor/bin/phpunit tests/Unit/Render tests/Integration/Render/StyleTargetsRenderTest.php tests/Integration/Render/PagePaletteAvailabilityTest.php tests/Integration/Render/ThemeAppearanceSourceTest.php tests/Integration/Content/GeneralSettingsAppearanceTest.php tests/Integration/Render/HoverStyleTest.php tests/Integration/Render/NavigationStyleTest.php tests/Integration/Render/TypefaceResolutionTest.php tests/Integration/Tenancy/AppearanceFingerprintTenancyTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add packages/thallo-render/src core/src/Http/Controllers/GeneralSettingsController.php tests .github/workflows
git commit -m "feat(palette): an unavailable brand colour contributes no colour — stripped from every cascade layer and token_class(); the palette enters the fingerprint"
```

---

### Task 5: The Appearance preview carries the palette

**Files:**
- Modify: `core/src/Content/Preview/PreviewToken.php` (claim `p`, present only when non-empty)
- Modify: `core/src/Content/Preview/PreviewMinter.php`, `core/src/Content/Http/Controllers/PreviewController.php`, `core/src/Content/Http/DTOs/MintPreviewData.php`
- Modify: `core/src/Content/Preview/EnginePreviewSessionVerifier.php`, `packages/thallo-contracts/src/Delivery/PreviewSession.php` (`?array $palette`)
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` (~1334: pass the session palette to `setThemeAppearanceOverride`), `packages/thallo-render/src/Fragments/FragmentRenderer.php:53`, `packages/thallo-render/src/Fragments/PreviewFragments.php:135`
- Test: `tests/Unit/Content/PreviewTokenTest.php`, `tests/Integration/Content/PreviewAppearanceTest.php`

**Interfaces:**
- Consumes: `PaletteSettings::parseNeutral/parseBrand/normalizeHex`, `RenderContextExtension::setThemeAppearanceOverride(..., ?Palette $palette)`.
- Produces:
  - `MintPreviewData::$palette`: `?array` with keys `neutral_custom` (six-key map or null), `dark_base` (?string) and `brands` (map slot → `{name,hex}` or null);
  - `PreviewSession::$palette` (the same array);
  - `PaletteSettings::paletteFromPreview(array $claim): Palette`.

- [ ] **Step 1: Write the failing token test**

Append to `tests/Unit/Content/PreviewTokenTest.php`:

```php
public function testThePaletteClaimRoundTripsAndIsAbsentWhenEmpty(): void
{
    $palette = ['neutral_custom' => ['bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8', 'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6'], 'dark_base' => 'stone', 'brands' => ['1' => ['name' => 'Gold', 'hex' => '#8a6a2a']]];
    $t = PreviewToken::mint('e1', 'en', null, time() + 60, 'k', null, null, null, null, $palette);
    self::assertSame($palette, PreviewToken::verify($t, 'k', time())->palette);
    $plain = PreviewToken::mint('e1', 'en', null, 2000000000, 'k');
    self::assertSame($plain, PreviewToken::mint('e1', 'en', null, 2000000000, 'k', null, null, null, null, null), 'byte-identical without a palette');
    self::assertNull(PreviewToken::verify($plain, 'k', time())->palette);
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Content/PreviewTokenTest.php`
Expected: FAIL (too many arguments).

- [ ] **Step 3: Implement the claim**

`PreviewToken`:
- add the constructor field `public readonly ?array $palette = null` and the `mint(...)` parameter `?array $palette = null`;
- write claim `p` only when `$palette !== null && $palette !== []`;
- in `verify()`, accept `p` only when `paletteClaim()` accepts it.

`paletteClaim()` allows exactly the keys `neutral_custom` (null or a map of the six `NEUTRAL_KEYS` → strings), `dark_base` (null or string) and `brands` (map of `'1'|'2'|'3'` → null or `{name: string, hex: string}`). Anything else makes the claim null. The hex values themselves are re-validated by the minter (Step 5), never trusted from the token's shape alone.

`PreviewSession` gains `public readonly ?array $palette = null`, and `EnginePreviewSessionVerifier::verify()` copies it.

- [ ] **Step 4: Write the failing preview render test**

Append to `tests/Integration/Content/PreviewAppearanceTest.php`:

```php
public function testAnUnsavedPaletteReachesThePreviewAndNothingElse(): void
{
    [$uuid] = $this->entryWithHeading('color.brand-1'); // a heading block whose text colour is Brand 1
    $res = $this->mint($uuid, ['palette' => ['brands' => ['1' => ['name' => 'Gold', 'hex' => '#8A6A2A']]]]);
    self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
    $preview = $this->renderPreview($this->tokenFrom($res));
    self::assertStringContainsString('--brand-1:#8a6a2a', $preview);
    self::assertStringContainsString(ClassNames::for('colors.text', 'color.brand-1'), $preview);
    $live = $this->renderPublic($uuid);
    self::assertStringNotContainsString('--brand-1', $live, 'unsaved colours never reach another page');
    self::assertStringNotContainsString(ClassNames::for('colors.text', 'color.brand-1'), $live, 'unconfigured on the live site: no class');
}

public function testAMalformedPaletteIs422NamingIt(): void
{
    [$uuid] = $this->entryWithHeading('color.accent');
    $res = $this->mint($uuid, ['palette' => ['brands' => ['1' => ['name' => 'Gold', 'hex' => 'red']]]]);
    self::assertSame(422, $res->getStatusCode());
    self::assertStringContainsString('palette', (string) $res->getContent());
}
```

Build the helpers from the file's existing mint and render helpers. `entryWithHeading` creates and publishes an entry with one heading block whose `settings.style.colors.text` is the given token.

- [ ] **Step 5: Implement minting and the override**

- **`MintPreviewData`:** `#[Rule('array')] public readonly ?array $palette = null`.
- **`PreviewController::mint`:** when `$input->palette !== null`, validate it as follows, then pass the normalised array to `PreviewMinter::mint(..., $palette)`. Any failure returns 422 `['palette' => '…']`.
  - `neutral_custom`, when present, goes through `PaletteSettings::parseNeutral(json_encode(...))`.
  - `dark_base` must be a family.
  - Each brand goes through `PaletteSettings::parseBrand(json_encode(...))`.
- **`PaletteSettings::paletteFromPreview(array $claim): Palette`:** builds the preview `Palette`. An omitted key falls back to the stored value: a preview that changes only brand 1 keeps the saved custom neutral and the other slots.
- **`RenderController` (~1334):** `$palette = $session->palette === null ? null : $this->paletteSettings?->paletteFromPreview($session->palette)`, then `setThemeAppearanceOverride($accent, $neutral, $design, $palette)`. `PaletteSettings` lives in core and render must not depend on core. So put `paletteFromPreview` behind a small contract method `PaletteProvider::preview(array $claim): Palette` instead, implemented in `PaletteSettings`, and call `$this->palettes?->preview(...)`.
- **`FragmentRenderer:53` and `PreviewFragments:135`:** do the same with the session they hold.

Update the Interfaces block accordingly: `PaletteProvider` gains `preview(array $claim): Palette`.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/Content/PreviewTokenTest.php tests/Integration/Content/PreviewAppearanceTest.php tests/Integration/Render/PreviewThemeTest.php tests/Integration/Http/AppearanceFamiliesPreviewTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add core/src/Content/Preview core/src/Content/Http packages/thallo-contracts/src packages/thallo-render/src core/src/Settings/PaletteSettings.php tests
git commit -m "feat(palette): the Appearance preview carries an unsaved palette in its signed token, confined to the preview render"
```

---

### Task 6: The style schema carries the palette, readable by every editor

**Files:**
- Modify: `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php` (constructor gains `?PaletteProvider`, `?ThemeAppearanceSource`, and a `?PaletteStatusReader` (Task 8 binds it) for replacing/reserved states)
- Create: `packages/thallo-contracts/src/Style/PaletteStatusReader.php` (`interface PaletteStatusReader { /** @return array<int,array{state:string,reserved:bool,replacing:?array{to:string,contrast_to:string}}> */ public function statuses(): array; }`)
- Create: `packages/thallo-render/src/Http/DTOs/StylePaletteData.php`, and add `palette` to `StyleSchemaData`
- Modify: `packages/thallo-render/routes/admin-routes.php:53` (four-permission rule)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php:327` (factory)
- Test: `tests/Integration/Render/StyleSchemaEndpointTest.php`

**Interfaces:**
- Consumes: `EffectivePalette::of(...)->swatches()`, `PaletteProvider`, `PaletteStatusReader` (nullable until Task 8).
- Produces: the response block

```json
"palette": {
  "slots": {
    "brand-1": {"name": "Gold dark", "hex": "#8a6a2a", "state": "replacing", "reserved": false,
                "replacing": {"to": "color.accent", "to_label": "Accent", "contrast_to": "color.accent-contrast", "contrast_to_label": "Accent — text"}},
    "brand-2": {"name": "Rose", "hex": "#c98a8a", "state": "configured", "reserved": true, "replacing": null},
    "brand-3": {"name": null, "hex": null, "state": "unset", "reserved": false, "replacing": null}
  },
  "swatches": {"color.background": "#ffffff", "color.surface": "#f6f7f9", "…": "…"},
  "labels": {"color.background": "Background", "color.surface-2": "Surface 2", "color.brand-1": "Gold dark", "color.brand-1-contrast": "Gold dark — text", "…": "…"}
}
```

  `labels` covers every colour token. Neutral and accent labels are fixed English strings in `StyleSchemaController::LABELS`; brand labels come from author names, and unset slots get "Brand N".

- [ ] **Step 1: Write the failing tests**

Append to `tests/Integration/Render/StyleSchemaEndpointTest.php`:

```php
public function testTheSchemaCarriesThePaletteWithStatesSwatchesAndLabels(): void
{
    $this->container()->get(\Thallo\Core\Settings\GeneralSettings::class)->save(['theme_brand_1' => '{"name":"Gold dark","hex":"#8a6a2a"}']);
    $data = json_decode((string) $this->container()->get(StyleSchemaController::class)->show()->getContent(), true)['data'];
    $slots = $data['palette']['slots'];
    self::assertSame(['name' => 'Gold dark', 'hex' => '#8a6a2a', 'state' => 'configured', 'reserved' => false, 'replacing' => null], $slots['brand-1']);
    self::assertSame('unset', $slots['brand-2']['state']);
    self::assertSame('Gold dark — text', $data['palette']['labels']['color.brand-1-contrast']);
    self::assertSame('Brand 2', $data['palette']['labels']['color.brand-2']);
    self::assertSame('Surface 2', $data['palette']['labels']['color.surface-2']);
    self::assertMatchesRegularExpression('/\A#[0-9a-f]{6}\z/', $data['palette']['swatches']['color.surface']);
    self::assertArrayNotHasKey('color.transparent', $data['palette']['swatches']);
}

public function testAnyEditorReadsTheSchema(): void
{
    $route = $this->findRoute('GET', '/v1/admin/render/style-schema');
    self::assertContains('content_permission:content.edit,content.manage,templates.manage,styles.manage', $route['middleware']);
    foreach (['content.edit', 'content.manage', 'templates.manage', 'styles.manage'] as $permission) {
        $user = $this->userWith('test_schema_' . str_replace('.', '_', $permission), [$permission]);
        self::assertTrue($this->allows($user, 'content.edit,content.manage,templates.manage,styles.manage'), $permission);
    }
    self::assertFalse($this->allows($this->userWith('test_schema_none', ['content.view']), 'content.edit,content.manage,templates.manage,styles.manage'));
}
```

Add `use GrantsPermissions;`, plus the `findRoute`, `userWith` and `allows` helpers copied from `FontLibraryApiTest` (~80–99). If they are shared through a support trait, use that.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Render/StyleSchemaEndpointTest.php`
Expected: FAIL. There is no `palette` key, and the middleware is `content.manage`.

- [ ] **Step 3: Implement**

`StyleSchemaController`:

```php
public const LABELS = [
    'background' => 'Background', 'surface' => 'Surface', 'surface-2' => 'Surface 2', 'text' => 'Text',
    'muted' => 'Muted', 'line' => 'Line', 'accent' => 'Accent', 'accent-contrast' => 'Accent — text',
    'transparent' => 'Transparent', 'white' => 'White', 'black' => 'Black',
];

/** @return array<string,mixed> */
private function palette(): array
{
    $palette = $this->palettes?->palette() ?? Palette::empty();
    $statuses = $this->statuses?->statuses() ?? [];
    $labels = [];
    foreach (self::LABELS as $name => $label) {
        $labels['color.' . $name] = $label;
    }
    foreach (Palette::SLOTS as $slot) {
        $brand = $palette->brand($slot);
        $labels["color.brand-{$slot}"] = $brand?->name ?? "Brand {$slot}";
        $labels["color.brand-{$slot}-contrast"] = ($brand?->name ?? "Brand {$slot}") . ' — text';
    }
    $slots = [];
    foreach (Palette::SLOTS as $slot) {
        $brand = $palette->brand($slot);
        $status = $statuses[$slot] ?? null;
        $replacing = $status['replacing'] ?? null;
        $slots["brand-{$slot}"] = [
            'name' => $brand?->name,
            'hex' => $brand?->hex,
            'state' => $brand === null ? 'unset' : ($replacing !== null ? 'replacing' : 'configured'),
            'reserved' => (bool) ($status['reserved'] ?? false),
            'replacing' => $replacing === null ? null : [
                'to' => $replacing['to'], 'to_label' => $labels[$replacing['to']] ?? $replacing['to'],
                'contrast_to' => $replacing['contrast_to'], 'contrast_to_label' => $labels[$replacing['contrast_to']] ?? $replacing['contrast_to'],
            ],
        ];
    }
    $look = $this->appearance;
    $swatches = EffectivePalette::of($look?->accent() ?? 'blue', $look?->neutral() ?? 'slate', $look?->background() ?? 'plain', $palette)->swatches();
    return ['slots' => $slots, 'swatches' => $swatches, 'labels' => $labels];
}
```

Add `'palette' => $this->palette()` to the success payload. Update the OpenAPI attribute description, which drops "Requires `content.manage`" in favour of the four-permission rule. Update `StyleSchemaData` with `StylePaletteData` (slots as `array`, swatches as `array<string,string>`, labels as `array<string,string>`).

Route `admin-routes.php:53`:

```php
// Any editor that picks styles reads it (custom palette spec §5.2): the Typeface picker's rule.
$router->get('/style-schema', [StyleSchemaController::class, 'show'])
    ->middleware('content_permission:content.edit,content.manage,templates.manage,styles.manage');
```

Factory at `RenderServiceProvider.php:327`:

```php
new StyleSchemaController(
    $c->get(ThemeLocator::class),
    $c->has(PaletteProvider::class) ? $c->get(PaletteProvider::class) : null,
    $c->get(ThemeAppearanceSource::class),
    $c->has(PaletteStatusReader::class) ? $c->get(PaletteStatusReader::class) : null,
)
```

- [ ] **Step 4: Run them**

Run: `vendor/bin/phpunit tests/Integration/Render/StyleSchemaEndpointTest.php tests/Unit/Contracts/StyleSchemaSnapshotTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render packages/thallo-contracts/src/Style/PaletteStatusReader.php tests/Integration/Render/StyleSchemaEndpointTest.php
git commit -m "feat(palette): the style schema carries the palette — slot states, swatches and labels — and any style editor may read it"
```

---
### Task 7: Where a brand colour is used

**Files:**
- Create: `core/src/Content/Palette/ColorTokenWalker.php`
- Create: `core/src/Content/Palette/BrandColorUsage.php`
- Create: `core/src/Content/Palette/Http/PaletteController.php` (this task: `usage(int $slot)`)
- Modify: `core/routes/admin.php` (`GET /v1/admin/appearance/palette/brand/{slot}/usage`, `content_permission:content.manage`)
- Modify: `core/src/Providers/CoreServiceProvider.php` (autowire both, shared)
- Test: `tests/Unit/Content/Palette/ColorTokenWalkerTest.php`, `tests/Integration/Content/Palette/BrandColorUsageTest.php`

**Interfaces:**
- Consumes: `BlockDocumentSources` (registry order: drafts, published, versions, regions, saved sections, layouts), `BlockStyleRegistry::regionsFor()`, `BlockTypeRepository::schemasBySlug()`, `Palette::slotOf()`.
- Produces:
  - `ColorTokenWalker::map(string $kind, array $doc, callable $fn, ?ContentTypeSchema $schema = null): array`;
  - `ColorTokenWalker::tokens(string $kind, array $doc, ?ContentTypeSchema $schema = null): array<string,string>`;
  - `ColorTokenWalker::hasBrand(string $kind, array $doc, ?ContentTypeSchema $schema = null): bool`;
  - `BrandColorUsage::of(int $slot): array`, with shape:
    ```
    {slot:int,
     blocking:{entries: list<{uuid,title,locale,draft:bool,published:bool}>, regions: list<string>,
               layouts: list<{id,name}>, saved_sections: list<{id,name}>, style_classes: list<{id,name}>, total:int,
               contrast_references:bool},
     historical:{entries: list<{uuid,title,locale,versions:int}>, total:int}}
    ```
  - `BrandColorUsage::blockingTotal(int $slot): int`.

**Walker rules.** One place names every colour-token location:
- **Style records.** Under a block's `settings.style` and every `settings.parts.<name>`, any node `{type:'token', value:'color.…'}`, at any depth. That covers targets, hover and responsive breakpoints, so a later colour property needs no walker change.
- **Content fields.** Each `data.<field>` whose field definition in the block type's schema is `type: token, domain: color`, stored as `{type:'token', value}`.
- **Nested blocks.** Under `data.<slot>` for each `BlockStyleRegistry::regionsFor($type)` slot.
- **Location identity.** A token inside a block is located by the **innermost block's id** plus the path relative to that block (`<id>:settings.style.colors.text`). It never uses the array index, so moving a block, or another block taking its index, cannot make a location name a different block. Every validated block has an id (`FieldValidator` builds blocks through `Block::fromArray(['id' => …])`). A block without one falls back to its index path.
- **Per document kind:**
  - **entry:** every `blocks`-typed field of `$schema`, plus `_presentation.style`;
  - **region:** `blocks` plus `settings.style`;
  - **layout:** `blocks` plus `settings.style`;
  - **saved_section:** `blocks` (a one-element list);
  - **style_class:** `style`.

- [ ] **Step 1: Write the failing walker test**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Unit\Content\Palette;

use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/** Custom palette spec §4.1, §4.5: every colour-token location, named once. */
final class ColorTokenWalkerTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    private function walker(): ColorTokenWalker
    {
        return $this->container()->get(ColorTokenWalker::class);
    }

    public function testItFindsStylePartsHoverDataNestedAndPageLocations(): void
    {
        $doc = [
            'body' => [[
                'id' => 'sect00000001',
                'type' => 'section',
                'settings' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]],
                'data' => ['children' => [[
                    'id' => 'link00000001',
                    'type' => 'links',
                    'settings' => ['parts' => ['link' => ['hover' => ['colors' => ['surface' => self::tok('color.brand-2')]]]]],
                    'data' => ['items' => []],
                ], [
                    'id' => 'anim00000001',
                    'type' => 'animated_text',
                    'data' => ['prefix_color' => self::tok('color.brand-3'), 'suffix_color' => self::tok('color.accent'), 'prefix' => 'color.brand-1'],
                ]]],
            ]],
            '_presentation' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1-contrast')]]],
        ];
        $tokens = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, $doc, $this->schemaWithBlocksField('body'));
        self::assertSame([
            'sect00000001:settings.style.colors.surface' => 'color.brand-1',
            'link00000001:settings.parts.link.hover.colors.surface' => 'color.brand-2',
            'anim00000001:data.prefix_color' => 'color.brand-3',
            'anim00000001:data.suffix_color' => 'color.accent',
            '_presentation.style.colors.surface' => 'color.brand-1-contrast',
        ], $tokens, 'a plain string that looks like a token (prefix text) is not a token');
    }

    public function testALocationFollowsItsBlockThroughMovesAndIndexShifts(): void
    {
        $a = ['id' => 'head0000000a', 'type' => 'heading', 'data' => ['text' => 'A'], 'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]];
        $b = ['id' => 'head0000000b', 'type' => 'heading', 'data' => ['text' => 'B'], 'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]];
        $schema = $this->schemaWithBlocksField('body');
        $before = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$a, $b]], $schema);
        $reordered = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$b, $a]], $schema);
        self::assertEquals($before, $reordered, 'same blocks, same colours, same locations');
        $onlyB = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$b]], $schema); // b takes index 0
        self::assertSame(['head0000000b:settings.style.colors.text' => 'color.brand-1'], $onlyB);
    }

    public function testABlockWithoutAnIdFallsBackToItsIndexPath(): void
    {
        $doc = ['body' => [['type' => 'heading', 'data' => ['text' => 'x'], 'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]]]];
        self::assertSame(['body.0.settings.style.colors.text' => 'color.brand-1'], $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, $doc, $this->schemaWithBlocksField('body')));
    }

    public function testMapRewritesOnlyWhatTheCallbackReplaces(): void
    {
        $doc = ['settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1'), 'surface' => self::tok('color.surface')]]], 'blocks' => []];
        $out = $this->walker()->map(ColorTokenWalker::KIND_REGION, $doc, static fn (string $loc, string $t): ?string => $t === 'color.brand-1' ? 'color.accent' : null);
        self::assertSame('color.accent', $out['settings']['style']['colors']['text']['value']);
        self::assertSame('color.surface', $out['settings']['style']['colors']['surface']['value']);
    }

    public function testStyleClassesAndSavedSections(): void
    {
        $class = ['style' => ['hover' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        self::assertSame(['style.hover.colors.text' => 'color.brand-2'], $this->walker()->tokens(ColorTokenWalker::KIND_CLASS, $class));
        $section = ['blocks' => [['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'x'], 'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-3')]]]]]];
        self::assertTrue($this->walker()->hasBrand(ColorTokenWalker::KIND_SECTION, $section));
    }
}
```

`schemaWithBlocksField('body')` builds a `ContentTypeSchema` with one `blocks` field named `body`. Use `ContentTypeSchema::fromArray([...])` (check the constructor in `core/src/Content/Schema/ContentTypeSchema.php`). `section`'s nested slot name must be what `BlockStyleRegistry::regionsFor('section')` returns; use that name in place of `children` if it differs.

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Content/Palette/ColorTokenWalkerTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the walker**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Every colour-token location a document holds (custom palette spec §4.1, §4.5): style records
 * (targets, parts, hover, breakpoints) at any depth, colour token content fields, nested blocks,
 * and the page/region/layout style frames. Shared by usage, normalisation and Replace so the three
 * can never disagree about where a colour lives.
 */
final class ColorTokenWalker
{
    public const KIND_ENTRY = 'entry';
    public const KIND_REGION = 'region';
    public const KIND_LAYOUT = 'layout';
    public const KIND_SECTION = 'saved_section';
    public const KIND_CLASS = 'style_class';

    /** @var array<string, list<string>>|null block type => colour token field names */
    private ?array $tokenFields = null;

    public function __construct(
        private readonly BlockStyleRegistry $registry,
        private readonly BlockTypeRepository $blockTypes,
    ) {
    }

    public function map(string $kind, array $doc, callable $fn, ?ContentTypeSchema $schema = null): array
    {
        foreach ($this->roots($kind, $doc, $schema) as [$key, $type]) {
            $node = self::get($doc, $key);
            if ($node === null) {
                continue;
            }
            $node = $type === 'blocks' ? $this->blocks($node, $key, $fn) : self::style($node, $key, $fn);
            $doc = self::set($doc, $key, $node);
        }
        return $doc;
    }

    public function tokens(string $kind, array $doc, ?ContentTypeSchema $schema = null): array
    {
        $out = [];
        $this->map($kind, $doc, static function (string $loc, string $token) use (&$out): ?string {
            $out[$loc] = $token;
            return null;
        }, $schema);
        return $out;
    }

    public function hasBrand(string $kind, array $doc, ?ContentTypeSchema $schema = null): bool
    {
        foreach ($this->tokens($kind, $doc, $schema) as $token) {
            if (Palette::slotOf($token) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{0:string,1:string}> dotted root key and 'blocks'|'style' */
    private function roots(string $kind, array $doc, ?ContentTypeSchema $schema): array
    {
        return match ($kind) {
            self::KIND_ENTRY => array_merge(
                array_map(static fn ($f): array => [$f->name, 'blocks'], array_values(array_filter(
                    $schema?->fields() ?? [],
                    static fn ($f): bool => $f->type === 'blocks',
                ))),
                [['_presentation.style', 'style']],
            ),
            self::KIND_REGION, self::KIND_LAYOUT => [['blocks', 'blocks'], ['settings.style', 'style']],
            self::KIND_SECTION => [['blocks', 'blocks']],
            self::KIND_CLASS => [['style', 'style']],
            default => throw new \InvalidArgumentException("unknown document kind {$kind}"),
        };
    }

    private function blocks(mixed $list, string $at, callable $fn): mixed
    {
        if (!is_array($list)) {
            return $list;
        }
        foreach ($list as $i => $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            // Identity, not position: the innermost block's id, then the path relative to it.
            $id = is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : null;
            $base = $id !== null ? "{$id}:" : "{$at}.{$i}.";
            if (is_array($block['settings']['style'] ?? null)) {
                $block['settings']['style'] = self::style($block['settings']['style'], "{$base}settings.style", $fn);
            }
            foreach (is_array($block['settings']['parts'] ?? null) ? $block['settings']['parts'] : [] as $name => $part) {
                if (is_array($part)) {
                    $block['settings']['parts'][$name] = self::style($part, "{$base}settings.parts.{$name}", $fn);
                }
            }
            foreach ($this->tokenFieldsOf($block['type']) as $field) {
                $value = $block['data'][$field] ?? null;
                if (is_array($value) && ($value['type'] ?? null) === 'token' && is_string($value['value'] ?? null)
                    && str_starts_with($value['value'], 'color.')) {
                    $new = $fn("{$base}data.{$field}", $value['value']);
                    if ($new !== null) {
                        $block['data'][$field]['value'] = $new;
                    }
                }
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                if (isset($block['data'][$slot])) {
                    $block['data'][$slot] = $this->blocks($block['data'][$slot], "{$base}data.{$slot}", $fn);
                }
            }
            $list[$i] = $block;
        }
        return $list;
    }

    private static function style(mixed $node, string $at, callable $fn): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (($node['type'] ?? null) === 'token' && is_string($node['value'] ?? null)) {
            if (str_starts_with($node['value'], 'color.')) {
                $new = $fn($at, $node['value']);
                if ($new !== null) {
                    $node['value'] = $new;
                }
            }
            return $node;
        }
        foreach ($node as $k => $child) {
            if (is_array($child)) {
                $node[$k] = self::style($child, "{$at}.{$k}", $fn);
            }
        }
        return $node;
    }

    /** @return list<string> */
    private function tokenFieldsOf(string $type): array
    {
        if ($this->tokenFields === null) {
            $this->tokenFields = [];
            foreach ($this->blockTypes->schemasBySlug() as $slug => $schema) {
                foreach ($schema->fields() as $field) {
                    if ($field->type === 'token' && $field->domain === 'color') {
                        $this->tokenFields[$slug][] = $field->name;
                    }
                }
            }
        }
        return $this->tokenFields[$type] ?? [];
    }

    private static function get(array $doc, string $dotted): mixed
    {
        $node = $doc;
        foreach (explode('.', $dotted) as $k) {
            if (!is_array($node) || !array_key_exists($k, $node)) {
                return null;
            }
            $node = $node[$k];
        }
        return $node;
    }

    private static function set(array $doc, string $dotted, mixed $value): array
    {
        $keys = explode('.', $dotted);
        $ref = &$doc;
        foreach ($keys as $k) {
            $ref = &$ref[$k];
        }
        $ref = $value;
        return $doc;
    }
}
```

`set()` only runs after `get()` found the node, so it never creates keys.

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit tests/Unit/Content/Palette/ColorTokenWalkerTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing usage test**

`tests/Integration/Content/Palette/BrandColorUsageTest.php`, built on `FontUsageTest`'s in-memory source idiom (`self::source(...)`, replacing `BlockDocumentSources` in the container for the test):

```php
public function testUsageSplitsBlockingFromHistorical(): void
{
    $uuid = 'entry0000001';
    $schema = $this->blocksSchema(); // one blocks field `body`
    $brand = ['type' => 'heading', 'data' => ['text' => 'x'], 'settings' => ['style' => ['colors' => ['text' => ['type' => 'token', 'value' => 'color.brand-1']]]]];
    $plain = ['type' => 'heading', 'data' => ['text' => 'x']];
    $this->useSources(
        self::source(EntryDraftsSource::ID, new DocumentRef(EntryDraftsSource::ID, $uuid, 'en', '1', $schema, ['title' => 'Home', 'body' => [$plain]])),
        self::source(PublishedEntriesSource::ID, new DocumentRef(PublishedEntriesSource::ID, $uuid, 'en', 'ver2', $schema, ['body' => [$brand]])),
        self::source(EntryVersionsSource::ID,
            new DocumentRef(EntryVersionsSource::ID, 'ver1', 'en', '1', $schema, ['body' => [$brand]], ['entry_uuid' => $uuid]),
            new DocumentRef(EntryVersionsSource::ID, 'ver2', 'en', '1', $schema, ['body' => [$brand]], ['entry_uuid' => $uuid]),
        ),
    );
    $this->connection()->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en', 'version_uuid' => 'ver2', 'published_at' => gmdate('Y-m-d H:i:s')]);
    $usage = $this->container()->get(BrandColorUsage::class)->of(1);
    self::assertSame(1, $usage['blocking']['total']);
    self::assertSame([['uuid' => $uuid, 'title' => 'Home', 'locale' => 'en', 'draft' => false, 'published' => true]], $usage['blocking']['entries']);
    self::assertSame(1, $usage['historical']['total'], 'ver1 is history; ver2 is the current publication, counted once as blocking');
    self::assertSame(1, $usage['historical']['entries'][0]['versions']);
}

public function testRegionAndLayoutStyleFramesStyleClassesAndContrastTokensAreBlocking(): void
{
    $this->connection()->table('regions')->where('slug', '=', 'header')->update(['settings' => json_encode(['style' => ['colors' => ['surface' => ['type' => 'token', 'value' => 'color.brand-2-contrast']]]])]);
    $this->insertLayout('entry', 'page', ['style' => ['colors' => ['surface' => ['type' => 'token', 'value' => 'color.brand-2']]]]);
    $this->insertStyleClass('cls000000001', 'Card', ['hover' => ['colors' => ['text' => ['type' => 'token', 'value' => 'color.brand-2']]]]);
    $usage = $this->container()->get(BrandColorUsage::class)->of(2);
    self::assertSame(['header'], $usage['blocking']['regions']);
    self::assertCount(1, $usage['blocking']['layouts']);
    self::assertSame([['id' => 'cls000000001', 'name' => 'Card']], $usage['blocking']['style_classes']);
    self::assertSame(3, $usage['blocking']['total']);
    self::assertTrue($usage['blocking']['contrast_references'], 'the header names brand-2-contrast');
    self::assertSame(0, $this->container()->get(BrandColorUsage::class)->blockingTotal(3));
}
```

Workspace scoping lives in its own retrofit suite, `tests/Integration/Content/Palette/BrandColorUsageTenancyTest.php` (opt-in like every retrofit suite: `THALLO_TENANCY_DEV_LINK=1`):

```php
final class BrandColorUsageTenancyTest extends RetrofittedTenantTestCase
{
    public function testAWorkspacesUsageCountsOnlyItsOwnDocuments(): void
    {
        $heading = ['type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['colors' => ['text' => ['type' => 'token', 'value' => 'color.brand-1']]]]];
        foreach (['A' => self::$tenantAUuid, 'B' => self::$tenantBUuid] as $name => $tenant) {
            $this->runAsTenant($tenant, function () use ($name, $heading): void {
                $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
                $this->container()->get(SavedSectionRepository::class)->create('Section ' . $name, 'Saved', null, $heading, null, null);
            });
        }
        $usage = $this->runAsTenant(self::$tenantAUuid, fn () => (new BrandColorUsage(
            $this->container()->get(BlockDocumentSources::class)->only(SavedSectionsSource::ID),
            $this->container()->get(ColorTokenWalker::class),
            $this->connection(),
        ))->of(1));
        self::assertSame(['Section A'], array_column($usage['blocking']['saved_sections'], 'name'));
    }
}
```

`BrandColorUsage`'s constructor is `(BlockDocumentSources $sources, ColorTokenWalker $walker, Connection $db)`.

`insertLayout` / `insertStyleClass` insert rows directly, with the columns used by `tests/Integration/Content/StyleClassJobTest.php` and the layouts tests.

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/BrandColorUsageTest.php`
Expected: FAIL, class not found.

- [ ] **Step 7: Implement `BrandColorUsage`**

Model it on `FontUsage::scan()`:
- iterate `$this->sources->each(...)`;
- per ref, compute `ColorTokenWalker::tokens(KIND_ENTRY | KIND_REGION | KIND_LAYOUT | KIND_SECTION, $fields, $ref->schema)` by `sourceType`:
  - region refs carry only `blocks`, so walk them as `KIND_SECTION`-shaped `{blocks}`;
  - read region and layout style frames separately, from the `regions.settings` and `layouts.settings` rows;
- the ref uses the slot when any token has `Palette::slotOf($t) === $slot`.

Classification:
- `EntryDraftsSource` → blocking entry, `draft = true`;
- `PublishedEntriesSource` → blocking entry, `published = true`;
- `EntryVersionsSource` whose `sourceId` is a current publication (`publishedVersionUuids()`) → skip;
- any other `EntryVersionsSource` → historical, `versions += 1`;
- regions, layouts and saved sections → blocking;
- style classes (read every `style_classes` row, archived too) → blocking.

`total` counts distinct listed documents. `contrast_references` is true when any blocking token is `color.brand-N-contrast`, which tells Replace whether a contrast destination is needed. Return the shape in the Interfaces block. `blockingTotal()` returns `of($slot)['blocking']['total']`.

`PaletteController::usage(int $slot)`: 404 for a slot outside 1..3; else `Response::success(['usage' => $this->usage->of($slot)])`.

Route in `core/routes/admin.php`, next to the font routes:

```php
// The palette (custom palette spec §4): usage, clear and replace need content.manage.
$router->get('/appearance/palette/brand/{slot}/usage', [\Thallo\Core\Content\Palette\Http\PaletteController::class, 'usage'])
    ->where('slot', '[123]')
    ->middleware('content_permission:content.manage');
```

- [ ] **Step 8: Run usage and route tests**

Add a route-permission test (`testEveryPaletteRouteCarriesAuthAndContentManage`) to a new `tests/Integration/Http/PaletteApiTest.php`. Tasks 13 and 14 extend its route table.

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Http/PaletteApiTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add core/src/Content/Palette core/routes/admin.php core/src/Providers/CoreServiceProvider.php tests .github/workflows
git commit -m "feat(palette): where a brand colour is used — blocking documents and historical versions, every colour location walked once"
```

---

### Task 8: Lock order, writer inventory and the palette state

This task lands the §4.6 requirements **before any fence code**.

**Files:**
- Create: `docs/internal/palette-lock-order.md`, the lock order and the complete writer inventory (content below)
- Create: `core/database/migrations/043_CreatePaletteTables.php` (`palette_state`, `palette_jobs`)
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php` (`palette_state` with widened unique `['uniq_palette_state_site', ['tenant_uuid', 'site']]`; `palette_jobs`)
- Modify: `tests/Unit/Tenancy/ThalloTenantTablesTest.php` (list both)
- Create: `core/src/Content/Palette/{PaletteState,PaletteSnapshot,PaletteJob,PaletteJobRepository,EnginePaletteStatusReader}.php`
- Modify: `core/src/Providers/CoreServiceProvider.php` (bind `PaletteStatusReader` → `EnginePaletteStatusReader`; autowire the rest)
- Test: `tests/Integration/Content/Palette/PaletteStateTest.php`, `tests/Unit/Content/Palette/LockOrderDocTest.php`

**Interfaces:**
- Consumes: `PaletteProvider::palette()`.
- Produces: `PaletteState` (shared contracts), plus:
  - `PaletteJobRepository::start(int $slot, string $to, ?string $contrastTo, ?string $actor, ?string $workspace): string` (inside a held lock; `$workspace` is recorded for the queued worker);
  - `PaletteJobRepository::find(string $id): ?PaletteJob`, `active(): list<PaletteJob>`;
  - `PaletteJobRepository::transition(string $id, string $to): bool`. It requires the palette lock (`LogicException` otherwise) and applies only a permitted transition, returning false and writing nothing otherwise. The permitted transitions are:
    - `running` → `completed` | `failed` | `cancelled`;
    - `failed` → `running` (resume) | `cancelled`;
    - `running` → `running` (resume of an interrupted job: refreshes `heartbeat_at`).

    `completed` and `cancelled` are terminal. There is no unconditional `setStatus`.
  - `PaletteJobRepository::beginPass(string $id, int $total): void`, `incrementDone(string $id): void`;
  - `PaletteJobRepository::recordFailure(string $id, string $source, string $docId, ?string $locale, string $reason): void`;
  - `PaletteJobRepository::isActive(string $id): bool`;
  - `EnginePaletteStatusReader::statuses()` (Task 6's contract).

- [ ] **Step 1: Write the lock-order and inventory document**

`docs/internal/palette-lock-order.md`:

```markdown
# Palette lock order and writer inventory

Custom palette spec §4.3–§4.6. Every path that takes the palette row takes it FIRST.

## Lock order

1. `palette_state` row: `UPDATE palette_state SET generation = generation` (PaletteState::lock()), or
   `generation = generation + 1` for a palette mutation (PaletteState::bump()).
2. Advisory document locks, each path's existing order unchanged:
   - entry versions: `pg_advisory_xact_lock(thallo:entry_versions:{entry}:{locale})` (VersionRepository::reserveNextVersionNumber)
   - regions: `pg_advisory_xact_lock(crc32('thallo:regions'))` (RegionWriteLock)
   - layouts: type `thallo:layouts:type:{slug}` then layout `thallo:layouts:{surface}:{target}` (LayoutWriteLock)
3. `style_classes` rows (StyleClassReferenceGuard; StyleClassRepository::bump()).
4. Document rows: `entry_drafts` / `entry_versions` / `entry_publications` / `regions` / `layouts` /
   `saved_sections` conditional updates.
5. `style_generations` row (SiteStyleGeneration::incrementWithin).

`PaletteFence` refuses to take (1) inside a transaction that has not already taken it
(`LogicException`), so a path that would take (1) after (2)–(5) fails in tests, not in production.
Settings writes (`settings` table, `AppearanceLock`) are never taken while (1) is held, except the
palette mutations' own settings write, which happens after (1) and takes no other lock.

## Writer inventory

| Writer | Path | Fenced | Basis on mismatch | Notes |
|---|---|---|---|---|
| Editor draft save | EntryController::saveDraft → EntryRepository::saveDraft | yes | current draft ∪ the draft's persisted restore_basis | Task 10, 12; returns palette_rewrites, palette_replacements and palette_generation |
| Authoring engine | EngineContentWriter::createDraft / EngineContentUpserter::updateDraft → saveDraft | yes (via saveDraft) | current draft | importers: CSV, Markdown, WordPress, Markdown folder |
| Locale copy | EntryRepository::createLocaleDraft | yes (+ CAS on overwrite) | source draft, re-read | Task 10 |
| Publish / publishStarter | PublishService::publishInternal | yes | the draft, re-read | Task 10 |
| Scheduled publish | ScheduleRunner::fire → PublishService::publish | yes (via publish) | the draft, re-read | claim transaction commits first |
| Rollback | PublishService::rollback | yes | the version, server-loaded | changed fields force append-a-version |
| Restore to draft | POST /entries/{uuid}/draft/{locale}/restore (new) | yes | current draft ∪ the version, server-loaded; persists the server-derived restore_basis on the draft | Task 12 |
| Region save | RegionSaver::save / RegionRepository::saveExpected | yes | current regions | Task 11 |
| Region save (unconditional) | RegionRepository::save | no | — | RegionKind / RetireAccountLinkCommand: reads and writes under the region lock; starter payloads have no brand token |
| Layout save | LayoutSaver::save | yes | current layout | Task 11 |
| Layout rebinding | LayoutBindings (content-type migrations) | no | — | renames/deletes bindings only; under layout locks |
| Saved section create | SavedSectionController::store | yes | none (new) | Task 11 |
| Saved section block | SavedSectionRepository::replaceBlock | yes | current section | Task 11 |
| Style class save | StyleClassController::store / update | yes | current class | Task 11 |
| Style class detach job | StyleClassJobRunner::process (detach) | yes | current document | copies class values into blocks — Task 11 |
| Style class remove job | StyleClassJobRunner::process (remove) | no | — | removes a class reference, adds no value; CAS |
| Content bundle import: entry_draft | ContentImporter::upsert | yes | stored draft | per-record refusal — Task 11 |
| Content bundle import: entry_version | ContentImporter::upsert | yes when it is the current publication; history stored as given | the stored version | Task 11 |
| Content bundle import: entry_publication | ContentImporter::upsert | yes (a pointer change is a rollback) | the referenced version, server-loaded; a changed one is appended and pinned | Task 11 |
| Block-type migration | BlockBackfillRunner::process | no | — | Delete/Rename on data fields; CAS persist |
| Content-type migration | BackfillRunner::processDraft / processPublished | no | — | DeleteField/RenameField; CAS / pin re-check |
| Settings converter | SettingsConversion::apply | no | — | no shipped stage; conversion tables predate brand tokens; CAS persist |
| Tenant seed / starter sync | TenantSeeder / StarterSync kinds | no | — | starter payloads carry no brand token (pinned) |
| Entry create / discard / delete / unpublish | EntryRepository / PublishService | no | — | write no style values |
| Replace job | PaletteReplaceRunner (queued as RunPaletteReplaceJob, inside its workspace) | yes (forced, job id asserted; every status change a guarded transition) | current document | Task 14 |
| Palette mutations | PaletteMutations (configure, rename, re-colour, clear, reset) | takes and bumps the row | — | Task 13 |
```

- [ ] **Step 2: Pin the document against the code**

`tests/Unit/Content/Palette/LockOrderDocTest.php` asserts that the inventory names every class that writes a block-bearing table. Its list is the set of PHP files under `core/src` and `packages/*/src` containing an `update(`/`insert(` on `entry_drafts`, `entry_versions`, `entry_publications`, `regions`, `layouts`, `saved_sections` or `style_classes`, found by a regex scan. Each must appear (by class name) in `docs/internal/palette-lock-order.md`.

```php
public function testEveryDocumentWriterIsInTheInventory(): void
{
    $doc = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/internal/palette-lock-order.md');
    $tables = 'entry_drafts|entry_versions|entry_publications|regions|layouts|saved_sections|style_classes';
    $missing = [];
    foreach (['core/src', 'packages'] as $root) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/' . $root));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match("/(?:table\\('(?:{$tables})'\\)[^;]*->(?:update|insert)\\(|(?:UPDATE|INSERT INTO)\\s+(?:{$tables})\\b)/s", $src) !== 1) {
                continue;
            }
            $class = basename($file->getPathname(), '.php');
            if (!str_contains($doc, $class)) {
                $missing[] = $class;
            }
        }
    }
    self::assertSame([], $missing, 'a new writer of block-bearing tables must be added to docs/internal/palette-lock-order.md');
}
```

Run: `vendor/bin/phpunit tests/Unit/Content/Palette/LockOrderDocTest.php`
Expected: on the first run, FAIL listing the repository classes the scan finds: `EntryRepository`, `VersionRepository`, `RegionRepository`, `LayoutRepository`, `SavedSectionRepository`, `StyleClassRepository`, the sources, `ContentImporter`, `BackfillRunner`, … Add a **"Storage classes"** section to the document listing each with the writer rows it serves. Re-run until it passes. This turns the inventory into a gate for future writers.

- [ ] **Step 3: Write the failing state test**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.3: one lockable row per workspace; jobs and reservations under it. */
final class PaletteStateTest extends AppTestCase
{
    private function state(): PaletteState
    {
        return $this->container()->get(PaletteState::class);
    }

    public function testTheRowIsCreatedLazilyAndStartsAtGenerationZero(): void
    {
        self::assertSame(0, $this->state()->snapshot()->generation);
        self::assertSame(1, $this->connection()->table('palette_state')->count());
    }

    public function testLockReadsAndBumpIncrementsInsideOneTransaction(): void
    {
        $db = $this->container()->get(Connection::class);
        $g = $db->transaction(function (): int {
            $held = $this->state()->lock();
            self::assertTrue($this->state()->heldInThisTransaction());
            return $this->state()->bump();
        });
        self::assertSame(1, $g);
        self::assertFalse($this->state()->heldInThisTransaction(), 'released at commit');
        self::assertSame(1, $this->state()->snapshot()->generation);
    }

    public function testLockOutsideATransactionIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->state()->lock();
    }

    public function testTheSnapshotCarriesThePaletteAndActiveJobsWithTheirReservations(): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}',
            'theme_brand_2' => '{"name":"Rose","hex":"#c98a8a"}',
        ]);
        $db = $this->container()->get(Connection::class);
        $id = $db->transaction(function (): string {
            $this->state()->lock();
            return $this->container()->get(PaletteJobRepository::class)->start(1, 'color.brand-2', 'color.brand-2-contrast', 'user00000001', null);
        });
        $snap = $this->state()->snapshot();
        self::assertSame($id, $snap->jobReplacing(1)?->id);
        self::assertSame([2], $snap->reservedSlots());
        $this->container()->get(Connection::class)->transaction(function () use ($id): void {
            $this->state()->lock();
            self::assertTrue($this->container()->get(PaletteJobRepository::class)->transition($id, 'cancelled'));
        });
        self::assertSame([], $this->state()->snapshot()->reservedSlots());
    }

    public function testCompletedAndCancelledAreTerminalAndTransitionsNeedTheLock(): void
    {
        $db = $this->container()->get(Connection::class);
        $jobs = $this->container()->get(PaletteJobRepository::class);
        $id = $db->transaction(function () use ($jobs): string {
            $this->state()->lock();
            return $jobs->start(1, 'color.accent', 'color.accent-contrast', null, null);
        });
        $db->transaction(function () use ($jobs, $id): void {
            $this->state()->lock();
            self::assertTrue($jobs->transition($id, 'completed'));
            self::assertFalse($jobs->transition($id, 'failed'), 'completed is terminal');
            self::assertFalse($jobs->transition($id, 'running'));
            self::assertFalse($jobs->transition($id, 'cancelled'));
        });
        self::assertSame('completed', $jobs->find($id)?->status);
        $this->expectException(\LogicException::class);
        $jobs->transition($id, 'cancelled');
    }
}
```

The first-row race (two sessions creating the row at once, the loser inside an open transaction) cannot be staged on one connection. It is proven with two processes in Task 14's real-database proofs (scenario `first-row`).

- [ ] **Step 4: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteStateTest.php`
Expected: FAIL, `relation "palette_state" does not exist`.

- [ ] **Step 5: Implement the migration**

`core/database/migrations/043_CreatePaletteTables.php`:

```php
<?php
declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Custom palette (spec §4.3): the per-workspace palette state row every palette mutation and every
 * fenced save locks first, and the replace jobs whose reservations it guards. The tenant column is
 * retrofitted by the tenancy pack, which widens the site unique to (tenant_uuid, site).
 */
final class CreatePaletteTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('palette_state')) {
            $schema->createTable('palette_state', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('site', 4)->default('site');
                $table->bigInteger('generation')->default(0);
                $table->timestamp('updated_at')->nullable();
                $table->unique('site', 'uniq_palette_state_site');
            });
        }
        if (!$schema->hasTable('palette_jobs')) {
            $schema->createTable('palette_jobs', function ($table): void {
                $table->string('id', 12)->primary();
                $table->integer('slot');
                $table->string('to_token', 64);
                $table->string('contrast_to_token', 64)->nullable(); // null: no contrast mapping (§4.2)
                $table->string('status', 16);              // running | failed | completed | cancelled
                $table->integer('passes')->default(0);
                $table->integer('work_items_total')->default(0);
                $table->integer('work_items_done')->default(0);
                $table->integer('work_items_failed')->default(0);
                $table->json('failure_report')->nullable();
                $table->json('counts')->nullable();        // per source, for the audit entry
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable(); // touched per document; a stale running job reads as interrupted
                $table->index('status');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('palette_jobs');
        $schema->dropTableIfExists('palette_state');
    }

    public function getDescription(): string
    {
        return 'Create palette_state (the per-workspace palette lock and generation) and palette_jobs (replace jobs).';
    }
}
```

`ThalloTenantTables::all()`, next to `style_generations`:

```php
'palette_state' => self::row($def, [['uniq_palette_state_site', ['tenant_uuid', 'site']]]),
'palette_jobs' => self::row($def),
```

Add both names to `ThalloTenantTablesTest`'s expected list.

- [ ] **Step 6: Implement `PaletteState`, the snapshot, the job and its repository**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\PaletteProvider;

/**
 * The workspace's palette state (custom palette spec §4.3): one row whose generation moves with
 * every palette mutation. lock() is an UPDATE-as-lock through the query builder — scoped like every
 * builder write — so it serialises with every other holder until the transaction ends.
 */
final class PaletteState
{
    private ?int $heldAtLevel = null;
    private ?\Closure $afterSnapshot = null;

    public function __construct(
        private readonly Connection $db,
        private readonly PaletteProvider $palettes,
        private readonly PaletteJobRepository $jobs,
    ) {
    }

    /**
     * Creates the workspace's row when missing. The insert runs in its own transaction, or, inside
     * an open one, a SAVEPOINT (Connection::transaction nests as savepoints). A duplicate-key loss to a
     * concurrent first writer rolls back only the savepoint, so the caller's PostgreSQL transaction
     * is never left aborted.
     */
    public function ensureRow(): void
    {
        if ($this->db->table('palette_state')->select(['id'])->first() !== null) {
            return;
        }
        try {
            $this->db->transaction(function (): void {
                $this->db->table('palette_state')->insert(['site' => 'site', 'generation' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            });
        } catch (\Throwable $e) {
            if ($this->db->table('palette_state')->select(['id'])->first() === null) {
                throw $e; // not the race: surface it
            }
        }
    }

    public function snapshot(): PaletteSnapshot
    {
        $this->ensureRow();
        $snapshot = $this->read();
        if ($this->afterSnapshot !== null) {
            $fn = $this->afterSnapshot;
            $this->afterSnapshot = null;
            $fn(); // a concurrency proof commits its palette mutation here, between read and write
        }
        return $snapshot;
    }

    /**
     * Concurrency proofs only (custom palette plan ruling 15): runs once, right after the next
     * unlocked snapshot is read — the exact window between a writer's normalisation and its
     * fenced write. Production code never calls it.
     */
    public function afterNextSnapshot(\Closure $fn): void
    {
        $this->afterSnapshot = $fn;
    }

    public function lock(): PaletteSnapshot
    {
        if (!$this->db->withinTransaction()) {
            throw new \LogicException('PaletteState::lock() needs an open transaction');
        }
        $this->db->table('palette_state')->where('site', '=', 'site')->update(['updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($this->heldAtLevel === null) {
            $this->heldAtLevel = $this->db->transactionLevel();
            $release = function (): void {
                $this->heldAtLevel = null;
            };
            $this->db->afterCommit($release);
            $this->db->afterRollback($release);
        }
        return $this->read();
    }

    public function bump(): int
    {
        if (!$this->heldInThisTransaction()) {
            throw new \LogicException('PaletteState::bump() needs the palette lock');
        }
        $current = (int) ($this->db->table('palette_state')->select(['generation'])->first()['generation'] ?? 0);
        $this->db->table('palette_state')->where('site', '=', 'site')->update(['generation' => $current + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $current + 1;
    }

    public function heldInThisTransaction(): bool
    {
        return $this->heldAtLevel !== null && $this->db->withinTransaction();
    }

    private function read(): PaletteSnapshot
    {
        $row = $this->db->table('palette_state')->select(['generation'])->first();
        return new PaletteSnapshot((int) ($row['generation'] ?? 0), $this->palettes->palette(), $this->jobs->active());
    }
}
```

`bump()` reads then writes inside the held row lock, so the read-modify-write cannot race. `PaletteProvider::palette()` must read settings uncached here: `PaletteSettings` reads through `GeneralSettings::stored()`, which goes to the store. Make `PaletteSettings::palette()` call `$this->settings->clearStoreCache()` (add a one-line `GeneralSettings::clearStoreCache()` → `$this->store->clearCache()`) before reading, so a snapshot taken under the lock sees a mutation committed by another request.

`PaletteSnapshot`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\Palette;

final class PaletteSnapshot
{
    /** @param list<PaletteJob> $activeJobs */
    public function __construct(
        public readonly int $generation,
        public readonly Palette $palette,
        public readonly array $activeJobs,
    ) {
    }

    public function jobReplacing(int $slot): ?PaletteJob
    {
        foreach ($this->activeJobs as $job) {
            if ($job->slot === $slot) {
                return $job;
            }
        }
        return null;
    }

    /** @return list<int> */
    public function reservedSlots(): array
    {
        $out = [];
        foreach ($this->activeJobs as $job) {
            foreach (array_filter([$job->to, $job->contrastTo]) as $token) {
                $slot = Palette::slotOf($token);
                if ($slot !== null) {
                    $out[$slot] = $slot;
                }
            }
        }
        sort($out);
        return array_values($out);
    }
}
```

`PaletteJob` holds exactly the fields in the shared contracts. `PaletteJobRepository` follows `StyleClassJobRepository` (`core/src/Content/Style/Classes/StyleClassJobRepository.php`):
- `start(int $slot, string $to, ?string $contrastTo, ?string $actor, ?string $workspace)`: id via the same 12-character id generator that repository uses; status `running`; `heartbeat_at` now. Add a nullable `workspace` column (`string(12)`) to the migration above.
- `find`, and `active()` (status `running`/`failed`).
- `transition()`:

  ```php
  private const TRANSITIONS = ['running' => ['completed', 'failed', 'cancelled', 'running'], 'failed' => ['running', 'cancelled']];

  public function transition(string $id, string $to): bool
  {
      if (!$this->state->heldInThisTransaction()) {
          throw new \LogicException('a palette job changes state only under the palette lock');
      }
      $job = $this->find($id);
      if ($job === null || !in_array($to, self::TRANSITIONS[$job->status] ?? [], true)) {
          return false; // completed and cancelled are terminal; anything else unlisted is refused
      }
      $changes = ['status' => $to, 'heartbeat_at' => gmdate('Y-m-d H:i:s')];
      if (in_array($to, ['completed', 'cancelled', 'failed'], true)) {
          $changes['finished_at'] = gmdate('Y-m-d H:i:s');
      }
      return $this->db->table('palette_jobs')->where('id', '=', $id)->where('status', '=', $job->status)->update($changes) === 1;
  }
  ```

  `PaletteState` is injected lazily (a `Closure(): PaletteState` or the container) to avoid the constructor cycle with `PaletteState`, which reads `active()`.
- `beginPass`; `incrementDone` and `touchHeartbeat` (raw `UPDATE … +1` / `SET heartbeat_at`, scoped by tenant through the barrier, exactly as `StyleClassJobRepository::incrementDone`, each `WHERE status = 'running'`); `recordFailure`; `recordCounts(string $id, array $counts)`; `isActive(string $id): bool`.

A new raw-PDO file must be classified in `tests/Unit/Tenancy/RawPdoScopingLintTest.php`'s `SCOPED` list.

`EnginePaletteStatusReader::statuses()` reads `PaletteState::snapshot()`:
- each active job's slot → `['state' => 'replacing', 'reserved' => false, 'replacing' => ['to' => $job->to, 'contrast_to' => $job->contrastTo]]`;
- each reserved slot → `reserved: true`;
- the rest → `configured`/`unset` by the palette.

- [ ] **Step 7: Run the state, tenancy and lint tests**

Run: `composer test:migrate && vendor/bin/phpunit tests/Integration/Content/Palette/PaletteStateTest.php tests/Unit/Tenancy tests/Unit/Content/Palette/LockOrderDocTest.php tests/Integration/Render/StyleSchemaEndpointTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add docs/internal/palette-lock-order.md core/database/migrations/043_CreatePaletteTables.php packages/thallo-tenancy/src/ThalloTenantTables.php core/src/Content/Palette core/src/Settings core/src/Providers/CoreServiceProvider.php tests .github/workflows
git commit -m "feat(palette): the palette lock order and writer inventory, and the per-workspace palette state and job tables"
```

---

### Task 9: Normalising a document against the palette

**Files:**
- Create: `core/src/Content/Palette/PaletteNormalizer.php`, `core/src/Content/Palette/Normalized.php`, `core/src/Content/Palette/PaletteRefusal.php`
- Test: `tests/Unit/Content/Palette/PaletteNormalizerTest.php`

**Interfaces:**
- Consumes: `ColorTokenWalker`, `PaletteSnapshot`, `Palette`.
- Produces:
  - `PaletteNormalizer::normalize(string $kind, array $doc, PaletteSnapshot $snapshot, array $basis, ?ContentTypeSchema $schema = null): Normalized`;
  - `PaletteNormalizer::basisOf(string $kind, ?ContentTypeSchema $schema, array ...$docs): array<string, list<string>>` (location → every token any of the given server-loaded documents holds there);
  - `PaletteRefusal extends \RuntimeException` with `public readonly array $errors` (location → message), rendered by every caller as a 422 `{palette: [...]}`.

**Rules (spec §4.5).** For each colour token in the **original** document:
1. **Source slot of an active job:**
   - the brand token → `job->to`;
   - its contrast token → `job->contrastTo`;
   - when `contrastTo` is null (the job has no contrast mapping, Task 14), the contrast token is **refused**: `"Text on Brand N has no replacement in the running replacement"`. Text is never silently mapped to a fill colour.
2. **Unconfigured slot:** kept if `in_array($token, $basis[$location] ?? [], true)`. Otherwise refused: `"Brand N is no longer in the palette"`, because the label is gone.
3. **Anything else:** kept.

`originalHadBrand` / `normalizedHasBrand` come from scanning before and after. `rewrites` lists each mapping applied (`location`, `from`, `to`). Writers return them so editors can adopt the accepted result (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Unit\Content\Palette;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\PaletteJob;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.5. */
final class PaletteNormalizerTest extends AppTestCase
{
    private const K = ColorTokenWalker::KIND_CLASS;

    private static function cls(string $text, ?string $surface = null): array
    {
        $style = ['colors' => ['text' => ['type' => 'token', 'value' => $text]]];
        if ($surface !== null) {
            $style['colors']['surface'] = ['type' => 'token', 'value' => $surface];
        }
        return ['style' => $style];
    }

    private static function snap(array $brands, array $jobs = []): PaletteSnapshot
    {
        return new PaletteSnapshot(7, new Palette(brands: $brands + [1 => null, 2 => null, 3 => null]), $jobs);
    }

    private static function job(int $slot, string $to, ?string $contrastTo): PaletteJob
    {
        return new PaletteJob('job000000001', $slot, $to, $contrastTo, 'running', 0, 0, 0, 0, []);
    }

    private function n(): PaletteNormalizer
    {
        return $this->container()->get(PaletteNormalizer::class);
    }

    public function testAnActiveJobsSourceIsMappedIncludingItsContrastToken(): void
    {
        $snap = self::snap([1 => new BrandSlot('Gold', '#8a6a2a')], [self::job(1, 'color.accent', 'color.accent-contrast')]);
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1', 'color.brand-1-contrast'), $snap, []);
        self::assertSame('color.accent', $out->doc['style']['colors']['text']['value']);
        self::assertSame('color.accent-contrast', $out->doc['style']['colors']['surface']['value']);
        self::assertTrue($out->originalHadBrand);
        self::assertFalse($out->normalizedHasBrand);
        self::assertTrue($out->fenced(), 'fenced because the ORIGINAL named a brand token');
    }

    public function testAFreshReferenceToAnUnconfiguredSlotIsRefusedNamingIt(): void
    {
        try {
            $this->n()->normalize(self::K, self::cls('color.brand-2'), self::snap([]), []);
            self::fail('expected a refusal');
        } catch (PaletteRefusal $e) {
            self::assertSame(['style.colors.text' => 'Brand 2 is no longer in the palette'], $e->errors);
        }
    }

    public function testAReferenceTheBasisAlreadyHoldsAtThatLocationIsKept(): void
    {
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-2'));
        $out = $this->n()->normalize(self::K, self::cls('color.brand-2'), self::snap([]), $basis);
        self::assertSame('color.brand-2', $out->doc['style']['colors']['text']['value']);
    }

    public function testTheBasisIsPerLocationNotPerDocument(): void
    {
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-2')); // text holds it
        $this->expectException(PaletteRefusal::class);
        $this->n()->normalize(self::K, self::cls('color.accent', 'color.brand-2'), self::snap([]), $basis); // moved to surface
    }

    public function testAConfiguredSlotAndOrdinaryTokensPassUnchanged(): void
    {
        $snap = self::snap([3 => new BrandSlot('Ink', '#111111')]);
        $doc = self::cls('color.brand-3', 'color.surface');
        $out = $this->n()->normalize(self::K, $doc, $snap, []);
        self::assertSame($doc, $out->doc);
        $plain = $this->n()->normalize(self::K, self::cls('color.text'), $snap, []);
        self::assertFalse($plain->fenced());
    }

    public function testAJobsSourceWinsOverTheBasisDuringAReplacement(): void
    {
        $snap = self::snap([1 => new BrandSlot('Gold', '#8a6a2a')], [self::job(1, 'color.brand-2', 'color.brand-2-contrast')]);
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-1'));
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1'), $snap, $basis);
        self::assertSame('color.brand-2', $out->doc['style']['colors']['text']['value']);
        self::assertSame([['location' => 'style.colors.text', 'from' => 'color.brand-1', 'to' => 'color.brand-2']], $out->rewrites);
    }

    public function testACurrentAccentDoesNotHideAHistoricalBrandAtTheSameLocation(): void
    {
        // the current draft holds Accent where the restored version held the now-cleared Brand 1
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.accent'), self::cls('color.brand-1'));
        self::assertSame(['style.colors.text' => ['color.accent', 'color.brand-1']], $basis);
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1'), self::snap([]), $basis);
        self::assertSame('color.brand-1', $out->doc['style']['colors']['text']['value']);
    }

    public function testAJobWithoutAContrastMappingRefusesAContrastReference(): void
    {
        $snap = self::snap([1 => new BrandSlot('Gold', '#8a6a2a')], [self::job(1, 'color.surface', null)]);
        try {
            $this->n()->normalize(self::K, self::cls('color.brand-1', 'color.brand-1-contrast'), $snap, []);
            self::fail('a contrast reference with no mapping is refused');
        } catch (PaletteRefusal $e) {
            self::assertSame(['style.colors.surface' => 'Text on Brand 1 has no replacement in the running replacement'], $e->errors);
        }
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/Content/Palette/PaletteNormalizerTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Custom palette spec §4.5: a document's colour tokens against the palette state a writer holds.
 * Always run on the ORIGINAL payload — on a generation mismatch the fence runs it again from the
 * same original, never from an earlier result (§4.3).
 */
final class PaletteNormalizer
{
    public function __construct(private readonly ColorTokenWalker $walker)
    {
    }

    /** @param array<string,list<string>> $basis */
    public function normalize(string $kind, array $doc, PaletteSnapshot $snapshot, array $basis, ?ContentTypeSchema $schema = null): Normalized
    {
        $hadBrand = false;
        $errors = [];
        $rewrites = [];
        $out = $this->walker->map($kind, $doc, static function (string $loc, string $token) use ($snapshot, $basis, &$hadBrand, &$errors, &$rewrites): ?string {
            $slot = Palette::slotOf($token);
            if ($slot === null) {
                return null;
            }
            $hadBrand = true;
            $job = $snapshot->jobReplacing($slot);
            if ($job !== null) {
                $to = Palette::isContrastToken($token) ? $job->contrastTo : $job->to;
                if ($to === null) {
                    $errors[$loc] = "Text on Brand {$slot} has no replacement in the running replacement";
                    return null;
                }
                $rewrites[] = ['location' => $loc, 'from' => $token, 'to' => $to];
                return $to;
            }
            if (!$snapshot->palette->isConfigured($slot) && !in_array($token, $basis[$loc] ?? [], true)) {
                $errors[$loc] = "Brand {$slot} is no longer in the palette";
            }
            return null;
        }, $schema);
        if ($errors !== []) {
            throw new PaletteRefusal($errors);
        }
        return new Normalized($out, $hadBrand, $this->walker->hasBrand($kind, $out, $schema), $rewrites);
    }

    /** @return array<string,list<string>> */
    public function basisOf(string $kind, ?ContentTypeSchema $schema, array ...$docs): array
    {
        $out = [];
        foreach ($docs as $doc) {
            foreach ($this->walker->tokens($kind, $doc, $schema) as $loc => $token) {
                if (!in_array($token, $out[$loc] ?? [], true)) {
                    $out[$loc][] = $token;
                }
            }
        }
        return $out;
    }
}
```

`Normalized` and `PaletteRefusal` follow the shared contracts. `PaletteRefusal::__construct(array $errors)` sets `$this->errors` and the message `implode('; ', $errors)`.

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit tests/Unit/Content/Palette/PaletteNormalizerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add core/src/Content/Palette tests/Unit/Content/Palette/PaletteNormalizerTest.php
git commit -m "feat(palette): normalise a document's colour tokens — map a replacement's source, refuse a fresh reference to a cleared slot, keep a trusted basis"
```

---
### Task 10: The fence, and the entry writers behind it

**Files:**
- Create: `core/src/Content/Palette/PaletteFence.php`
- Modify: `core/src/Content/Repositories/EntryRepository.php` (`saveDraft` through the fence; `createLocaleDraft` through the fence with a CAS on overwrite)
- Modify: `core/src/Content/Services/PublishService.php` (`publishInternal` and `rollback` through the fence)
- Modify: `core/src/Content/Http/Controllers/EntryController.php`, `core/src/Content/Http/Controllers/PublicationController.php` (`PaletteRefusal` → 422 `{palette: {location: message}}`)
- Create: `core/src/Content/Palette/PaletteRefusalResponse.php` (one helper: `PaletteRefusal` → `Response::validation(['palette' => $e->errors])`)
- Test: `tests/Integration/Content/Palette/PaletteFenceTest.php`, `tests/Integration/Content/Palette/EntryWritersFenceTest.php`

**Interfaces:**
- Consumes: `PaletteState` (`snapshot`, `lock`, `afterNextSnapshot`, `heldInThisTransaction`), `PaletteNormalizer`, `ColorTokenWalker::KIND_ENTRY`.
- Produces:
  - `PaletteFence::write(callable $normalize, callable $write, bool $force = false): mixed`;
  - `PaletteFence::within(callable $work): mixed`, which opens a transaction and takes the palette row first, for writers that must hold it across several writes (palette mutations, the replace job, an import);
  - `PaletteRefusalResponse::from(PaletteRefusal $e): Response`.

- [ ] **Step 1: Write the failing fence unit test**

`tests/Integration/Content/Palette/PaletteFenceTest.php`:

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\Normalized;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.3; plan rulings 3 and 4. */
final class PaletteFenceTest extends AppTestCase
{
    private function fence(): PaletteFence
    {
        return $this->container()->get(PaletteFence::class);
    }

    private function bumpInItsOwnTransaction(): void
    {
        $this->container()->get(Connection::class)->transaction(function (): void {
            $state = $this->container()->get(PaletteState::class);
            $state->lock();
            $state->bump();
        });
    }

    public function testAnUnfencedWriteTakesNoLockAndNormalisesOnce(): void
    {
        $calls = 0;
        $out = $this->fence()->write(
            function (PaletteSnapshot $s) use (&$calls): Normalized { $calls++; return new Normalized(['x' => 1], false, false); },
            fn (array $doc): array => [$doc, $this->container()->get(PaletteState::class)->heldInThisTransaction()],
        );
        self::assertSame([['x' => 1], false], $out);
        self::assertSame(1, $calls);
    }

    public function testAFencedWriteHoldsThePaletteRowAndReNormalisesFromTheOriginalOnAMismatch(): void
    {
        $seen = [];
        $this->container()->get(PaletteState::class)->afterNextSnapshot(fn () => $this->bumpInItsOwnTransaction());
        $out = $this->fence()->write(
            function (PaletteSnapshot $s) use (&$seen): Normalized { $seen[] = $s->generation; return new Normalized(['g' => $s->generation], true, false); },
            fn (array $doc): array => [$doc, $this->container()->get(PaletteState::class)->heldInThisTransaction()],
        );
        self::assertSame([0, 1], $seen, 'normalised at the read generation, then again at the held one');
        self::assertSame([['g' => 1], true], $out);
    }

    public function testTakingThePaletteRowAfterAnotherLockIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->container()->get(Connection::class)->transaction(function (): void {
            $this->connection()->table('settings')->where('key', '=', 'x')->update(['value' => 'y']); // some other lock first
            $this->fence()->write(static fn (PaletteSnapshot $s): Normalized => new Normalized([], true, true), static fn (array $d) => null);
        });
    }

    public function testWithinTakesTheRowFirstSoNestedFencedWritesAreAllowed(): void
    {
        $result = $this->fence()->within(fn () => $this->fence()->write(
            static fn (PaletteSnapshot $s): Normalized => new Normalized(['ok' => true], true, true),
            static fn (array $doc): array => $doc,
        ));
        self::assertSame(['ok' => true], $result);
    }

    public function testForceFencesAWriteWhosePayloadsNameNoBrandToken(): void
    {
        $held = $this->fence()->write(
            static fn (PaletteSnapshot $s): Normalized => new Normalized([], false, false),
            fn (array $doc): bool => $this->container()->get(PaletteState::class)->heldInThisTransaction(),
            force: true,
        );
        self::assertTrue($held);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteFenceTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `PaletteFence`**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;

/**
 * Custom palette spec §4.3 and docs/internal/palette-lock-order.md. A writer normalises its
 * ORIGINAL payload against an unlocked snapshot; if the submitted or normalised payload names a
 * brand token (or $force), its write runs in a transaction that takes the palette row FIRST and,
 * when the generation moved, normalises the original again against the state now held. While the
 * row is held no palette mutation can commit, so one re-normalisation is enough (plan ruling 3).
 */
final class PaletteFence
{
    public function __construct(private readonly Connection $db, private readonly PaletteState $state)
    {
    }

    public function write(callable $normalize, callable $write, bool $force = false): mixed
    {
        $read = $this->state->snapshot();
        $normalized = $normalize($read);
        if (!$force && !$normalized->fenced()) {
            return $write($normalized->doc, $read);
        }
        return $this->within(function () use ($normalize, $write, $read, $normalized): mixed {
            $held = $this->state->lock();
            if ($held->generation !== $read->generation) {
                $normalized = $normalize($held);
            }
            return $write($normalized->doc, $held);
        });
    }

    public function within(callable $work): mixed
    {
        if ($this->db->withinTransaction() && !$this->state->heldInThisTransaction()) {
            throw new \LogicException(
                'the palette row is taken first in a transaction (docs/internal/palette-lock-order.md); '
                . 'wrap the outer transaction in PaletteFence::within()',
            );
        }
        $this->state->ensureRow();
        $run = function () use ($work): mixed {
            $this->state->lock();
            return $work();
        };
        return $this->db->withinTransaction() ? $run() : $this->db->transaction($run);
    }
}
```

`within()` checks before it locks, so a caller already holding the palette row may nest. In Step 1's refused case, a settings `UPDATE` ran first in a transaction that never took the row, so `within()` throws.

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteFenceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Write the failing entry-writer tests (both orderings, every entry writer)**

`tests/Integration/Content/Palette/EntryWritersFenceTest.php`. Shared helpers:
- `entry()` creates a content type with a `body` blocks field and an entry, returning `[uuid, lockVersion]`;
- `heading(string $token, string $id = 'head00000001')` returns a heading block with that id whose text colour is `$token`;
- `configure(int $slot, string $name, string $hex)` saves the slot through `GeneralSettings::save`;
- `clear(int $slot)` deletes the settings row and bumps the palette generation inside `PaletteFence::within`;
- `startJob(int $slot, string $to, string $contrastTo)` locks, bumps and calls `PaletteJobRepository::start`, all inside `PaletteFence::within`.

```php
public function testSaveFirstThenTheJobStarts(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    self::assertSame(1, $this->usage()->blockingTotal(1), 'the job\'s scan sees the committed reference');
}

public function testJobStartsWhileTheSaveIsPausedAfterNormalising(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $rewrites = $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    self::assertSame('color.accent', $this->draftToken($uuid), 'the fence caught the mutation and re-normalised');
    self::assertSame([['location' => 'head00000001:settings.style.colors.text', 'from' => 'color.brand-1', 'to' => 'color.accent']], $rewrites, 'the editor is told what was accepted');
}

public function testAStaleMappingIsNotUsedAfterCancelAndANewReplacement(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $first = $this->startJob(1, 'color.accent', 'color.accent-contrast');
    // the save's normalised payload under the first job names no brand token
    $this->state()->afterNextSnapshot(function () use ($first): void {
        $this->cancelJob($first);
        $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
    });
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    self::assertSame('color.brand-2', $this->draftToken($uuid), 're-normalised from the submitted Brand 1, not the obsolete Accent');
}

public function testOrdinaryClearFirstRefusesAFreshReferenceButKeepsAStoredOne(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->state()->afterNextSnapshot(fn () => $this->clear(1));
    try {
        $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
        self::fail('a fresh reference to a just-cleared slot is refused');
    } catch (PaletteRefusal $e) {
        self::assertSame(['head00000001:settings.style.colors.text' => 'Brand 1 is no longer in the palette'], $e->errors);
    }
    // a draft that already stores the reference saves (with an unrelated edit) unchanged
    $this->storeDraftRaw($uuid, ['body' => [$this->heading('color.brand-1')], 'title' => 'a']);
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')], 'title' => 'b'], 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
}

public function testSaveFirstRefusesTheOrdinaryClear(): void
{
    // covered by Task 13's clear endpoint test; here: the usage the clear reads under the lock sees it
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    self::assertSame(1, $this->usage()->blockingTotal(1));
}

public function testASavePausedAcrossTheJobsClearCannotCommitTheOldToken(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $job = $this->startJob(1, 'color.accent', 'color.accent-contrast');
    // the job runs to completion (rewrites nothing, clears) while the save is paused
    $this->state()->afterNextSnapshot(function () use ($job): void {
        $this->cancelJob($job);  // simplest completed-clear stand-in until Task 14: clear the slot
        $this->clear(1);
    });
    $this->expectException(PaletteRefusal::class);
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
}

public function testASaveNamingNoBrandTokenTakesNoFence(): void
{
    [$uuid, $lock] = $this->entry();
    $this->state()->afterNextSnapshot(fn () => $this->clear(2)); // a mutation that a fenced save would notice
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.accent')]], 1, $lock, 'user00000001');
    self::assertSame('color.accent', $this->draftToken($uuid));
}

public function testPublishLocaleCopyAndRollbackAreFenced(): void
{
    // publish: the draft names Brand 1; a job to Accent starts after publish reads the draft
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->publisher()->publish($uuid, 'en', 'user00000001');
    self::assertSame('color.accent', $this->publishedToken($uuid));

    // locale copy: the copy normalises the source draft the same way
    $this->repo()->createLocaleDraft($uuid, 'fr', 'en', false, 'user00000001');
    self::assertSame('color.accent', $this->draftToken($uuid, 'fr'));

    // rollback to a version naming a slot that is now being replaced → appended version, mapped
    $old = $this->versionUuidNaming($uuid, 'color.brand-1'); // inserted directly as a retained version
    $before = $this->versionCount($uuid);
    $this->publisher()->rollback($uuid, 'en', $old, 'user00000001');
    self::assertSame('color.accent', $this->publishedToken($uuid));
    self::assertSame($before + 1, $this->versionCount($uuid), 'a changed rollback appends a version, never re-pins the old one');
}

public function testScheduledPublishGoesThroughTheSameFence(): void
{
    [$uuid, $lock] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    $this->scheduleDuePublish($uuid, 'en'); // inserts a pending `publish` schedule whose run_at is in the past
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->container()->get(ScheduleRunner::class)->run();
    self::assertSame('color.accent', $this->publishedToken($uuid));
}
```

`scheduleDuePublish()` inserts the row with the columns and status values the scheduling tests use: grep `ScheduleRunner` under `tests/Integration` for the existing setup. `testSaveFirstRefusesTheOrdinaryClear` pins the precondition here; Task 13 adds the endpoint-level refusal.

Also add `testTheAuthoringEngineIsFencedThroughSaveDraft`. It calls `EngineContentUpserter::updateDraft` with a Brand 1 body while a job is started in `afterNextSnapshot`, and asserts the destination is stored.

- [ ] **Step 6: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/EntryWritersFenceTest.php`
Expected: FAIL. The draft stores `color.brand-1` (no fence yet), and the refusal tests see no exception.

- [ ] **Step 7: Fence `saveDraft`**

Wrap the body of `EntryRepository::saveDraft` from `$oldFields = …` to the end of the transaction:

```php
$schema = $this->schemaOf($entryUuid); // ContentTypeSchema via findEntry + types->findByUuid, null when none
$fields = $this->fence->write(
    fn (PaletteSnapshot $s): Normalized => $this->normalizer->normalize(
        ColorTokenWalker::KIND_ENTRY,
        $fields, // the ORIGINAL submitted fields, captured by the closure
        $s,
        $this->normalizer->basisOf(ColorTokenWalker::KIND_ENTRY, $schema, $this->draftFields($entryUuid, $locale)),
        $schema,
    ),
    function (array $doc) use ($entryUuid, $locale, $schemaVersion, $expectedLockVersion, $actor, &$oldFields, &$oldAssets, &$changed): array {
        $oldFields = $this->draftFields($entryUuid, $locale);
        $oldAssets = $this->assetTargets($entryUuid, $oldFields);
        $changed = $oldFields != $doc;
        db($this->context)->transaction(function () use ($entryUuid, $locale, $doc, $oldFields, $schemaVersion, $expectedLockVersion, $actor): void {
            // … the existing guard + CAS body, with $fields replaced by $doc …
        });
        return $doc;
    },
);
```

The events after the transaction then use `$fields` (now the normalised document). Inject `PaletteFence $fence` and `PaletteNormalizer $normalizer` as nullable constructor parameters, and skip the fence when either is null, so the many existing hand-built `EntryRepository` instances in tests keep working. The basis is re-read inside the normaliser closure on every call, so the second call reads the draft as stored under the held lock.

Task 12 merges the draft's persisted `restore_basis` into this basis (`mergeBasis`).

**`saveDraft` returns what normalisation changed.** In this task the return type is `list<array{location:string,from:string,to:string}>`. Capture `$rewrites` from the `Normalized` the fence used last: have the normalise closure store its result in a by-reference `$last`. Task 12 widens the return to `PaletteOutcome` (rewrites plus the running replacements' mappings).

`EntryController::saveDraft` adds `palette_rewrites` to its 200 response and to the response DTO's OpenAPI attributes. `EngineContentWriter` and `EngineContentUpserter` ignore the return value.

- [ ] **Step 8: Fence publish, rollback and locale copy**

**`PublishService::publishInternal`.** Keep the existence checks and gates where they are. From `$draft = $this->entries->findDraft(...)` through the transaction, wrap in:

```php
$versionUuid = $this->fence->write(
    function (PaletteSnapshot $s) use ($entryUuid, $locale, $schema): Normalized {
        $draft = $this->entries->findDraft($entryUuid, $locale) ?? throw new \RuntimeException("no draft for {$entryUuid}/{$locale}");
        $fields = (array) $draft['fields'];
        // the draft IS the payload: re-read on every call, so a mismatch normalises the draft as stored now
        return $this->normalizer->normalize(ColorTokenWalker::KIND_ENTRY, $fields, $s,
            $this->normalizer->basisOf(ColorTokenWalker::KIND_ENTRY, $schema, $fields), $schema);
    },
    function (array $fields) use (/* … */): string {
        // the existing block-gate check, projection, strict validation and transaction, on $fields
    },
);
```

The draft's `schema_version` is needed for projection. Return it alongside by re-reading the draft row in the write closure (a cheap read), or capture it in the normalise closure through a by-reference variable.

**`PublishService::rollback`.** The normaliser's payload is the projected version fields, and the basis is those same fields (server-loaded). In the write closure, when the normalised fields differ from the projected ones, take the existing **materialize** branch (append a version and pin) even where the plain re-pin would otherwise run.

**`EntryRepository::createLocaleDraft`.**
- The normaliser's payload is the source locale's seeded fields, re-read on every call, with those fields as basis.
- The write closure runs the existing insert or overwrite inside `db()->transaction`.
- The overwrite gains `->where('lock_version', '=', $currentLock)`, with `$currentLock` read in the same transaction, and sets `lock_version` to `$currentLock + 1` instead of `0`. Zero affected rows throws `OptimisticLockException`.

**Controllers.** `EntryController::saveDraft` / `createLocaleDraft` and `PublicationController::publish` / `rollback` catch `PaletteRefusal` and return `PaletteRefusalResponse::from($e)`.

- [ ] **Step 9: Run the entry-writer tests and the suites they touch**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Content/EntryRepositoryTest.php tests/Integration/Content/PublishServiceTest.php tests/Integration/Content/BlockRestoreProjectionTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add core/src/Content/Palette core/src/Content/Repositories/EntryRepository.php core/src/Content/Services/PublishService.php core/src/Content/Http/Controllers tests .github/workflows
git commit -m "feat(palette): the palette fence — entry drafts, locale copies, publish, scheduled publish and rollback normalise under the palette row"
```

---

### Task 11: Fencing the remaining writers, and pinning the unfenced ones

**Files:**
- Modify:
  - `core/src/Content/Regions/RegionSaver.php` (`save`: `PaletteFence::within` around `RegionWriteLock::within`; normalise both regions, `KIND_REGION`, basis = current rows)
  - `core/src/Content/Layouts/LayoutSaver.php` (`save`: `PaletteFence::within` around `locked()`; normalise `KIND_LAYOUT`, basis = current row)
  - `core/src/Content/Http/Controllers/SavedSectionController.php` (`store`: `KIND_SECTION`, basis empty)
  - `core/src/Content/Patterns/SavedSectionRepository.php` (`replaceBlock` callers through the fence)
  - `core/src/Content/Http/Controllers/StyleClassController.php` (`store` / `update`: `KIND_CLASS`, basis = current class)
  - `core/src/Content/Style/Classes/StyleClassJobRunner.php` (`process`, detach only: fenced write; basis = the document as read; a refusal is a recorded failure)
  - `core/src/Content/ImportExport/ContentImporter.php` (`upsert` per record: `PaletteFence::write`; a refusal is a per-record error, the import continues)
- Test: `tests/Integration/Content/Palette/OtherWritersFenceTest.php`, `tests/Integration/Content/Palette/UnfencedWritersTest.php`

**Interfaces:**
- Consumes: `PaletteFence::write/within`, `PaletteNormalizer`, `ColorTokenWalker` kinds.
- Produces: no new public API beyond responses.
  - Each writer's 422 bodies gain `palette` errors.
  - Each writer's success response gains `palette_rewrites` (`list<{location, from, to}>`, empty when nothing changed). That covers the region save, layout save, saved-section create and style-class save; Task 12's editors adopt it.
  - The importer's per-record result gains `{"record": …, "error": "palette", "details": {...}}`.

**Lock order in these writers.** `PaletteFence::within()` must wrap the writer's **outermost** transaction, so the palette row precedes the advisory locks `RegionWriteLock` and `LayoutWriteLock` take. The normaliser itself runs inside, after the region or layout lock, because these writers validate inside their locks. On a generation mismatch nothing needs re-running: the palette row is already held when they normalise. Write that as:

```php
// RegionSaver::save
return $this->fence->within(function () use ($posted, $expected, $actor) {
    $snapshot = $this->state->lock(); // already held: returns the held state
    return $this->lock->within(function () use ($snapshot, $posted, $expected, $actor) {
        // existing: read both regions, version check, RegionValidator::validateBoth …
        // NEW: for each posted region doc {blocks, settings}:
        //   $doc = $this->normalizer->normalize(ColorTokenWalker::KIND_REGION, $doc, $snapshot,
        //            $this->normalizer->basisOf(ColorTokenWalker::KIND_REGION, null, $currentRow), null)->doc;
        // existing: saveExpected per slug, with the normalised doc
    });
});
```

Taking the row unconditionally costs one row lock per region or layout save; both are low-frequency admin saves. Make the same structure for `LayoutSaver::save` (around `locked()`).

**Saved sections.** Wrap `classGuard->assertBlocksWritable` and `SavedSectionRepository::create` in one `PaletteFence::write` with `KIND_SECTION`; the write closure opens the transaction both run in. `replaceBlock` is reached only from `SavedSectionsSource::persist`, i.e. the style-class job and the Replace job, which are fenced by their callers.

**Style classes.** `StyleClassController::store` / `update` validate, then call the repository inside `PaletteFence::write(KIND_CLASS)`. `StyleClassRepository::write()` opens its transaction inside the fence's.

**Style-class detach job.** In `StyleClassJobRunner::process`, when `$kind === 'detach'`, build the rewritten fields as today, then:

```php
$this->fence->write(
    fn (PaletteSnapshot $s): Normalized => $this->normalizer->normalize($kindOf($ref), $fields, $s,
        $this->normalizer->basisOf($kindOf($ref), $ref->schema, $ref->fields), $ref->schema),
    fn (array $doc): bool => $source->persist($ref, $doc) || throw new DocumentMoved(),
);
```

A `DocumentMoved` (a private exception) records the existing "changed concurrently" failure, and a `PaletteRefusal` records `reason = 'names a cleared brand colour'`. `$kindOf` maps `sourceType` to a walker kind: regions and saved sections are walked as `{blocks}` (`KIND_SECTION`), and entries as `KIND_ENTRY`.

**Content import.** Three record kinds can change what a site renders, and each goes through `PaletteFence::write`. A `PaletteRefusal` adds a per-record error and the import continues.

- **`entry_draft`:**
  - the payload is the record's `fields`, walked as `KIND_ENTRY` with the record's content type schema;
  - the basis is the stored draft's fields, when one exists.
- **`entry_version`:**
  - **Not the current publication** (no `entry_publications` row points at its uuid, read under the lock): history, stored as given. That matches §4.1: history is never rewritten and renders a cleared colour as unavailable.
  - **The current publication:** the import is rewriting live content. Normalise it with the stored version as basis, and store the normalised fields.
- **`entry_publication`:** a pointer change is treated as a rollback, under the fence.
  - Load the referenced version server-side: the database row, which a version record earlier in the same bundle has already written. A pointer to a version that doesn't exist is a per-record error.
  - Normalise its fields with that version itself as the basis. That is rollback's rule (§4.5): pinning a version may keep the cleared colours it holds, as unavailable references.
  - **When normalisation changed nothing:** upsert the pointer as given.
  - **When it changed something** (a running job maps the version's Brand 1): don't pin the referenced version. Under the same transaction, append a normalised version through `VersionRepository::reserveNextVersionNumber` / `appendVersion` (actor null) and pin that instead, then `ReferenceProjectionRepository::rebuildForEntry`.

  So a publication record can never repin a version that still names a job's source slot after the job scanned publications.
- **Every other kind:** unaffected. None of them carries style values.

- [ ] **Step 1: Write the failing tests**

`OtherWritersFenceTest.php`, one test per fenced writer. Each sets `PaletteState::afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'))` (or, for writers that normalise under the lock, starts the job before the save) and asserts the stored document carries `color.accent`:

```php
public function testRegionSaveIsFenced(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $this->regionSaver()->save(['header' => ['blocks' => [], 'settings' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]]]], $this->expectedVersions(), 'user00000001');
    self::assertSame('color.accent', $this->regionStyleToken('header'));
}

public function testRegionSaveWaitsForAPaletteMutationHoldingTheRow(): void
{
    // the region save takes the palette row before the region lock: a refused fresh reference proves it
    // read the state as of the clear, not before
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->clear(1);
    $this->expectException(PaletteRefusal::class);
    $this->regionSaver()->save(['header' => ['blocks' => [$this->heading('color.brand-1')], 'settings' => []]], $this->expectedVersions(), 'user00000001');
}

public function testLayoutSaveIsFenced(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $this->layoutSaver()->save('entry', 'page', ['blocks' => [], 'settings' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]]], $this->layoutVersion('entry', 'page'), 'user00000001');
    self::assertSame('color.accent', $this->layoutFrameToken('entry', 'page'));
}

public function testSavedSectionCreateRefusesAFreshReferenceToAClearedSlot(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->clear(1);
    $res = $this->sectionController()->store($this->dto(CreateSavedSectionData::class, ['name' => 'Hero', 'block' => $this->heading('color.brand-1')]), $this->requestAs($this->manager()));
    self::assertSame(422, $res->getStatusCode());
    self::assertStringContainsString('palette', (string) $res->getContent());
}

public function testSavedSectionCreateCatchesAMutationAfterItsRead(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->sectionController()->store($this->dto(CreateSavedSectionData::class, ['name' => 'Hero', 'block' => $this->heading('color.brand-1')]), $this->requestAs($this->manager()));
    self::assertSame('color.accent', $this->sectionToken('Hero'));
}

public function testStyleClassSaveCatchesAMutationAfterItsRead(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $class = $this->createClass('Card');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->classController()->update($this->dto(UpdateStyleClassData::class, ['version' => $class['version'], 'style' => ['hover' => ['colors' => ['text' => self::tok('color.brand-1')]]]]), $this->requestAs($this->stylist()), $class['id']);
    self::assertSame('color.accent', $this->classToken($class['id'], 'hover.colors.text'));
}

public function testStyleClassDetachCopiesThroughTheFence(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $class = $this->createClass('Card', ['colors' => ['text' => self::tok('color.brand-1')]]);
    [$uuid] = $this->entryWithDraft(['body' => [$this->headingWithClass($class['id'])]]);
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $job = $this->container()->get(StyleClassJobService::class)->queue($class['id'], 'detach');
    $this->container()->get(StyleClassJobRunner::class)->run($job);
    self::assertSame('color.accent', $this->draftToken($uuid), 'the detached value went through the fence');
}

public function testImportRefusesARecordNamingAnUnconfiguredSlotAndKeepsTheRest(): void
{
    $result = $this->importer()->process($this->bundle([
        $this->draftRecord('entry0000001', ['body' => [$this->heading('color.brand-3')]]),
        $this->draftRecord('entry0000002', ['body' => [$this->heading('color.accent')]]),
    ]));
    self::assertSame('palette', $result['errors'][0]['error']);
    self::assertSame('entry0000001', $result['errors'][0]['record']);
    self::assertNotNull($this->draftOf('entry0000002'));
}

public function testAnImportedPublicationPointerToAVersionNamingAJobsSourceAppendsANormalisedVersion(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.accent');
    $old = $this->retainedVersionNaming($uuid, 'color.brand-1');   // already exists, not pinned
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $before = $this->versionCount($uuid);
    $this->importer()->process($this->bundle([$this->publicationRecord($uuid, 'en', $old)]));
    self::assertNotSame($old, $this->publishedVersionUuid($uuid), 'the old version is not repinned as it is');
    self::assertSame('color.accent', $this->publishedToken($uuid));
    self::assertSame($before + 1, $this->versionCount($uuid));
    self::assertSame('color.brand-1', $this->versionToken($old), 'history untouched');
}

public function testAnImportedPublicationPointerAfterTheSlotWasClearedPinsTheVersionWithAnUnavailableColour(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.accent');
    $old = $this->retainedVersionNaming($uuid, 'color.brand-1');
    $this->clear(1);
    $this->importer()->process($this->bundle([$this->publicationRecord($uuid, 'en', $old)]));
    self::assertSame($old, $this->publishedVersionUuid($uuid), 'a rollback-equivalent pin: the version itself is the basis');
    self::assertStringNotContainsString('brand-1', $this->renderPublic($uuid), 'renders no colour for it');
}

public function testAnImportedVersionRecordThatIsTheCurrentPublicationIsNormalised(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.accent');
    $pinned = $this->publishedVersionUuid($uuid);
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $this->importer()->process($this->bundle([$this->versionRecord($pinned, $uuid, 'en', ['body' => [$this->heading('color.brand-1')]])]));
    self::assertSame('color.accent', $this->versionToken($pinned));
}

public function testAnImportedHistoricalVersionRecordIsStoredAsGiven(): void
{
    [$uuid] = $this->publishedEntryNaming('color.accent');
    $this->importer()->process($this->bundle([$this->versionRecord('verhist00001', $uuid, 'en', ['body' => [$this->heading('color.brand-3')]])]));
    self::assertSame('color.brand-3', $this->versionToken('verhist00001'));
}

public function testImportCatchesAMutationAfterItsRead(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->importer()->process($this->bundle([$this->draftRecord('entry0000003', ['body' => [$this->heading('color.brand-1')]])]));
    self::assertSame('color.accent', $this->draftToken('entry0000003'));
}
```

Helper notes:
- `layoutSaver()->save(...)` uses `LayoutSaver::save`'s real parameter order; read `core/src/Content/Layouts/LayoutSaver.php:44`.
- DTO class names (`CreateSavedSectionData`, `UpdateStyleClassData`) are whatever `SavedSectionController::store` and `StyleClassController::update` take; read their signatures.
- `requestAs`, `manager()` and `stylist()` follow `FontLibraryApiTest`'s `userWith(...)` idiom, with grants `content.manage` and `styles.manage`.
- The `*Token(...)` helpers read the stored JSON and return the token at the named location.

`UnfencedWritersTest.php` pins the justified writers:

```php
public function testStarterPayloadsCarryNoBrandToken(): void
{
    $walker = $this->container()->get(ColorTokenWalker::class);
    foreach ($this->starterRegionPayloads() as $slug => $doc) {            // RegionKind's sources
        self::assertFalse($walker->hasBrand(ColorTokenWalker::KIND_REGION, $doc), $slug);
    }
    self::assertFalse($walker->hasBrand(ColorTokenWalker::KIND_SECTION, ['blocks' => $this->homepageStarterBlocks()]));
}

public function testCasWritersCannotCarryAStaleReferencePastAReplaceRewrite(): void
{
    // a block-type migration's persist with a ref read before the job rewrote the draft fails its condition
    [$uuid] = $this->entryWithDraft(['body' => [$this->heading('color.brand-1')]]);
    $ref = $this->draftRef($uuid);                    // read now
    $this->rewriteDraftAsTheJobWould($uuid, 'color.accent');
    self::assertFalse($this->container()->get(EntryDraftsSource::class)->persist($ref, $ref->fields));
}

public function testNoShippedConversionEmitsABrandToken(): void
{
    self::assertSame([], iterator_to_array(ConversionTables::shipped()->all())); // none ship; when one does, this fails and it must be fenced
}
```

`starterRegionPayloads()` and `homepageStarterBlocks()` read the same sources `RegionKind` and `HomepageEntryKind` use: grep `core/src/Content/Starter`. If `ConversionStages` exposes no `all()`, assert whatever "is empty" accessor it has.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/OtherWritersFenceTest.php tests/Integration/Content/Palette/UnfencedWritersTest.php`
Expected: the fenced-writer tests FAIL (Brand 1 stored); the unfenced pins PASS.

- [ ] **Step 3: Implement** the writer changes described above.

- [ ] **Step 4: Run the writer tests and every suite they touch**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Content/RegionWriteLockTest.php tests/Integration/Content/SavedSectionApiTest.php tests/Integration/Content/SavedSectionDocumentsTest.php tests/Integration/Content/StyleClassJobTest.php tests/Integration/Content/StyleClassApiTest.php tests/Integration/ImportExport/ContentImporterTest.php tests/Integration/Importers/ContentImporterWritesViaContractTest.php`

Also run the layout and region admin tests: grep `tests/Integration` for `LayoutSaver` and `RegionSaver`.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add core/src tests .github/workflows
git commit -m "feat(palette): fence regions, layouts, saved sections, style classes, the detach job and content import; pin the writers that need no fence"
```

---

### Task 12: The editor contract — restore with a persisted basis, adopting saves, reconciling history by replacement records

**Files:**
- Create: `core/database/migrations/044_AddRestoreBasisToEntryDrafts.php` (`entry_drafts.restore_basis`, nullable JSON)
- Create: `core/database/migrations/045_AddCompletedGenerationToPaletteJobs.php` (`palette_jobs.completed_generation`, nullable bigint, indexed)
- Create: `core/src/Content/Palette/PaletteOutcome.php` (`list $rewrites`, `int $generation`)
- Create: `core/src/Content/Palette/PaletteReplacements.php` (completed replacement records, ordered)
- Modify:
  - `core/src/Content/Palette/PaletteFence.php` (the write closure receives the held snapshot);
  - `core/src/Content/Palette/PaletteNormalizer.php` (`mergeBasis`);
  - `core/src/Content/Palette/PaletteJobRepository.php` (`transition(..., 'completed')` records `completed_generation`);
  - `core/src/Content/Repositories/EntryRepository.php` (`saveDraft` returns `PaletteOutcome`; `restoreBasis()`; the restore write);
  - `core/src/Content/Services/PublishService.php` (publish clears `restore_basis`).
- Create: `core/src/Content/Services/DraftRestore.php`, `core/src/Content/Http/DTOs/RestoreDraftData.php` (`string $version_uuid`, `int $lock_version`)
- Modify:
  - `core/src/Content/Http/Controllers/EntryController.php` (`restoreDraft`; the draft GET and save responses carry `palette_generation`; saves carry `palette_rewrites`);
  - the region, layout, saved-section and style-class load and save responses (the same two fields);
  - `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php` (`palette.generation`, `palette.replacements`);
  - the draft routes file (grep `draft/{locale}`): `POST /entries/{uuid}/draft/{locale}/restore`, same permission as the draft `PUT`.
- Create:
  - `admin/src/editor/paletteRewrites.ts` (`applyPaletteRewrites`, `mapPaletteTokens`);
  - `admin/src/editor/paletteReplacements.ts` (`createReplacementLedger`).
- Modify:
  - `admin/src/editor/ops/history.ts` (`adopt(next)`, `reconcileTokens(mapping)`);
  - `admin/src/editor/stage/useStageEditor.ts` (`adoptPaletteResult`, `applyReplacements`, `resetPaletteBaseline`);
  - `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`, `admin/src/pages/content/[type]/[uuid]/index.vue`;
  - `admin/src/pages/regions/useRegionHost.ts`, `admin/src/pages/layouts/useLayoutHost.ts`;
  - `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue`, the saved-section save path;
  - `admin/src/queries/drafts.ts`, `admin/src/queries/styleSchema.ts`;
  - `admin/e2e/helpers.ts`.
- Test:
  - `tests/Integration/Content/Palette/DraftRestoreTest.php`, `tests/Integration/Content/Palette/PaletteReplacementsTest.php`;
  - `admin/src/__tests__/palette-rewrites.spec.ts`, `admin/src/__tests__/palette-history.spec.ts`, `admin/src/__tests__/palette-ledger.spec.ts`, `admin/src/__tests__/restore-version.spec.ts`;
  - `admin/e2e/tests/palette-restore-history.spec.ts`, `admin/e2e/tests/palette-normalized-save.spec.ts`.

**Interfaces:**
- Consumes: Task 7's id-keyed locations, Task 9's `Normalized::$rewrites`, `VersionRepository::findVersionByUuid()`, the projector `PublishService::rollback` uses, and `PaletteState::bump()` (returns the new generation).
- Produces:
  - **`PaletteOutcome { list<array{location,from,to}> $rewrites; int $generation }`.** `generation` is the palette generation the write held.
  - **A replacement record:**
    ```
    {id: string, slot: 1|2|3, map: {"color.brand-N": to, "color.brand-N-contrast"?: contrast_to}, completed_generation: int}
    ```
    Only `completed` jobs are records. `map` omits the contrast key when `contrast_to` is null.
  - **`PaletteReplacements::since(int $generation): list<record>`:** completed records whose `completed_generation > $generation`, ordered by `completed_generation` ascending.
  - **`palette_generation: int`:** on every editor **load** response (draft GET, region, layout, style class, saved section) and every fenced **save** and **restore** response.
  - **Fenced save and restore responses** also carry `palette_rewrites` and `palette_replacements` (`since(<the request's loaded generation>)`). The client sends its baseline generation as `palette_generation` in save bodies; when absent, the server sends `[]`.
  - **The style schema `palette`** gains `generation` and `replacements` (completed records of the last 90 days, ordered). The slot field `retired` from the previous revision is dropped.
  - **Admin:**
    - `applyPaletteRewrites(doc, rewrites)`;
    - `mapPaletteTokens(value, mapping)`;
    - `history.adopt(next)`, `history.reconcileTokens(mapping)`;
    - `createReplacementLedger(baseline: number): { baseline: number; pending(records): Record[]; markApplied(records): void; reset(generation): void }`;
    - `editor.adoptPaletteResult(outcome)`, `editor.applyReplacements(records)`, `editor.resetPaletteBaseline(generation)`.

**1. Restore provenance is a persisted basis.**
- **Writing it:** the restore endpoint computes the projected version's brand-colour tokens on the server (`basisOf(KIND_ENTRY, $schema, $projected)`, kept only where a token is a brand token). It stores them as `entry_drafts.restore_basis` in the same conditional update that writes the restored fields.
- **Using it:** every later draft save merges that basis into its trusted basis.
- **Clearing it:** a publish, a discard, or another restore.

Because the basis is persisted, version retention pruning cannot remove it. The client never sends or sees it. Its locations are id-keyed, so restored colours stay trusted on their own blocks wherever they move, and a new block gains nothing.

**2. Adopting a save's result never marks newer edits saved, and never cancels an in-flight edit.**
- **Saved position:** a save already marks only the submitted sequence as saved (`design/[locale].vue:394`), and that stays.
- **Why not `rebase()`:** `history.rebase()` calls `cancel()` when a transaction is active (`history.ts:225`). `cancel()` inverts the active ops and drops them, and text edits stay active through the 500 ms debounce. A save response arriving then would leave the text on screen but erase its history entry, so the dirty check would read clean.
- **`history.adopt(next)`** replaces the history's document **underneath** the active transaction:
  - the active transaction's ops are kept, uncommitted, and still coalesce;
  - entries and sequences are untouched;
  - `next` is the current fields snapshot, which already contains the active edits, with the accepted rewrites applied by block id.

  A rewrite whose location the active transaction changed is skipped by `applyPaletteRewrites`'s `from` check.
- **`adoptPaletteResult(outcome)`:**
  1. applies `outcome.palette_rewrites` to the current document through `applyPaletteRewrites` (block id plus relative path; only where the value still equals `from`);
  2. hands the result to `history.adopt()` through a `pendingAdopt` flag, mirroring `pendingRebase`, so the fields watcher records nothing;
  3. then calls `applyReplacements(outcome.palette_replacements)` (section 3).

  Edits made during the request keep `currentSequence > savedSequence`. The editor stays dirty, and the next save carries them.

**3. History is reconciled by ordered, identified replacement records.**

**Why not bare token maps.** A token map is not enough:
- **Chains:** Brand 1 → Brand 2 then Brand 2 → Accent. A one-pass map turns Brand 1 into the cleared Brand 2, and a later refresh changes it again, so it is not idempotent.
- **Slot reuse:** Brand 1 replaced, reconfigured, then replaced again. A map can't tell the two uses apart.
- **Stale responses:** a response's map can arrive after its job was cancelled and a newer one started.
- **Restores:** a map would overwrite a colour deliberately restored after the replacement finished.

**The ledger** (`createReplacementLedger(baseline)`) gives each editor session one baseline generation:
- **Its starting value:** the `palette_generation` of the response that **loaded** the document it holds.
- **The rule:** a record applies to an editor **only if** `record.completed_generation > ledger.baseline` and the record isn't yet applied. Records apply **one at a time, in `completed_generation` order**. Each one deep-maps its `map` through every history entry, the active transaction (`history.reconcileTokens`) and the current document (through `adopt`), and is then marked applied. Applying the same record set again, in any order or any number of times, changes nothing.
- **Why it is correct:** a record with `completed_generation > baseline` completed after the editor's document was read, so everything the editor holds predates it and the server rewrote the same tokens in stored documents. A record at or below the baseline completed before the read. The loaded document is already that record's post-replacement truth, and anything in it (including restored unavailable colours) must not be mapped.
- **Restores move the baseline.** A restore response carries `palette_generation` and `palette_replacements`. The editor applies those records to its **existing** history and document **first**, then sets the baseline to the response's generation, **then** applies the restore operations. The restored content was produced after every record the server knew about, so it is never mapped through any of them. A later schema refresh brings only records completed after that generation. If slot 1 is reconfigured and replaced again, that is a record completed after the restore, and the server rewrites stored documents the same way, so mapping matches the server.
- **A full reload** (conflict reload, page reload, new stage) resets the ledger to the reloaded document's generation (`resetPaletteBaseline`).
- **Ordering and identity:**
  - **Chains:** a chain's second job can't start until the first ends, because its source slot is reserved. So completion order is application order. Brand 1 → Brand 2 → Accent applies as brand-1 → brand-2, then brand-2 → accent, and ends at Accent.
  - **Stale responses:** a delayed response's records are a subset or superset of known ones by id, so it can only add records not yet applied, in order.
  - **Cancelled and running jobs** are not records and never touch history. A running job's saves are mapped by the server's normaliser, and a cancelled job leaves the slot configured.

- [ ] **Step 1: Write the failing server tests**

`tests/Integration/Content/Palette/DraftRestoreTest.php`:

```php
public function testRestoringBringsBackAnUnavailableReferenceAndSavingItAgainKeepsIt(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    $res = $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
    self::assertSame('color.brand-1', $this->draftToken($uuid));
    $edited = $this->draftFields($uuid);
    $edited['title'] = 'Changed';
    $this->repo()->saveDraft($uuid, 'en', $edited, 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
}

public function testRestoreThenUndoThenRedoIsAccepted(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    $before = $this->draftFields($uuid);
    $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    $restored = $this->draftFields($uuid);
    $this->repo()->saveDraft($uuid, 'en', $before, 1, $this->lockOf($uuid), 'user00000001');
    $this->repo()->saveDraft($uuid, 'en', $restored, 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
}

public function testRedoStillSavesAfterRetentionPrunedTheRestoredVersion(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    $before = $this->draftFields($uuid);
    $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    $restored = $this->draftFields($uuid);
    $this->repo()->saveDraft($uuid, 'en', $before, 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame(1, $this->container()->get(VersionPruner::class)->deleteGuarded([$old]));
    $this->repo()->saveDraft($uuid, 'en', $restored, 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
}

public function testAVersionPrunedBeforeTheRestoreReadsItIs404(): void
{
    [$uuid] = $this->entry();
    $old = $this->publishWith($uuid, 'color.accent');
    $this->publishWith($uuid, 'color.accent');
    $this->container()->get(VersionPruner::class)->deleteGuarded([$old]);
    self::assertSame(404, $this->restore($uuid, 'en', $old, $this->lockOf($uuid))->getStatusCode());
}

public function testAVersionPrunedAfterTheRestoreReadItStillRestoresWithItsBasis(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    $this->state()->afterNextSnapshot(fn () => $this->container()->get(VersionPruner::class)->deleteGuarded([$old]));
    self::assertSame(200, $this->restore($uuid, 'en', $old, $this->lockOf($uuid))->getStatusCode());
    $this->repo()->saveDraft($uuid, 'en', $this->draftFields($uuid), 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
}

public function testTheBasisFollowsTheRestoredBlockButNotANewOne(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWithBlocks($uuid, [$this->heading('color.brand-1', 'head0000000a'), $this->heading('color.accent', 'head0000000b')]);
    $this->replaceAndClear(1, 'color.accent');
    $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    $moved = ['body' => [$this->heading('color.accent', 'head0000000b'), $this->heading('color.brand-1', 'head0000000a')]];
    $this->repo()->saveDraft($uuid, 'en', $moved, 1, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->blockToken($uuid, 'head0000000a'));
    $added = ['body' => [...$moved['body'], $this->heading('color.brand-1', 'head0000000c')]];
    $this->expectException(PaletteRefusal::class);
    $this->repo()->saveDraft($uuid, 'en', $added, 1, $this->lockOf($uuid), 'user00000001');
}

public function testAPlainSaveCannotSmuggleAHistoricalReference(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    self::assertSame(422, $this->putDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], $this->lockOf($uuid))->getStatusCode());
}

public function testPublishForgetsTheBasis(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->replaceAndClear(1, 'color.accent');
    $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    self::assertNotNull($this->restoreBasisOf($uuid));
    $this->publisher()->publish($uuid, 'en', 'user00000001');
    self::assertNull($this->restoreBasisOf($uuid));
}

public function testAnotherEntrysVersionIs404(): void
{
    [$a] = $this->entry();
    [$b] = $this->entry();
    self::assertSame(404, $this->restore($a, 'en', $this->publishWith($b, 'color.accent'), $this->lockOf($a))->getStatusCode());
}

public function testRestoreAndSaveResponsesCarryTheGenerationRewritesAndRecordsSinceTheClientsBaseline(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $loaded = self::data($this->getDraft($uuid, 'en'))['palette_generation'];
    $this->replaceAndClear(1, 'color.accent');                    // completes a job after the client loaded
    $data = self::data($this->restore($uuid, 'en', $old, $this->lockOf($uuid), paletteGeneration: $loaded));
    self::assertGreaterThan($loaded, $data['palette_generation']);
    self::assertCount(1, $data['palette_replacements']);
    self::assertSame(['color.brand-1' => 'color.accent', 'color.brand-1-contrast' => 'color.accent-contrast'], $data['palette_replacements'][0]['map']);
    self::assertSame([], self::data($this->putDraft($uuid, 'en', $this->draftFields($uuid), $this->lockOf($uuid), paletteGeneration: $data['palette_generation']))['palette_replacements'], 'nothing newer than the baseline');
}

public function testRestoringDuringAReplacementMapsAndReportsTheRewrite(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $data = self::data($this->restore($uuid, 'en', $old, $this->lockOf($uuid)));
    self::assertSame('color.accent', $this->draftToken($uuid));
    self::assertSame([['location' => 'head00000001:settings.style.colors.text', 'from' => 'color.brand-1', 'to' => 'color.accent']], $data['palette_rewrites']);
    self::assertSame([], $data['palette_replacements'], 'a running job is not a record');
}

public function testRestoringIsFencedAgainstAMutationAfterItsRead(): void
{
    [$uuid] = $this->entry();
    $this->configure(1, 'Gold', '#8a6a2a');
    $old = $this->publishWith($uuid, 'color.brand-1');
    $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
    $this->restore($uuid, 'en', $old, $this->lockOf($uuid));
    self::assertSame('color.accent', $this->draftToken($uuid));
}
```

`tests/Integration/Content/Palette/PaletteReplacementsTest.php`:

```php
public function testRecordsAreCompletedJobsInCompletionOrderWithSlotReuseKeptApart(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $g0 = $this->state()->snapshot()->generation;
    $this->replaceAndClear(1, 'color.brand-2');                   // record A: brand-1 → brand-2
    $this->replaceAndClear(2, 'color.accent');                    // record B: brand-2 → accent
    $this->configure(1, 'Ink', '#111111');                        // slot 1 reused
    $this->replaceAndClear(1, 'color.surface', 'color.text');     // record C: brand-1 → surface
    $cancelled = $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $records = $this->container()->get(PaletteReplacements::class)->since($g0);
    self::assertSame(['color.brand-1' => 'color.brand-2', 'color.brand-1-contrast' => 'color.brand-2-contrast'], $records[0]['map']);
    self::assertSame(['color.brand-2' => 'color.accent', 'color.brand-2-contrast' => 'color.accent-contrast'], $records[1]['map']);
    self::assertSame(['color.brand-1' => 'color.surface', 'color.brand-1-contrast' => 'color.text'], $records[2]['map']);
    self::assertCount(3, $records, 'a running job is not a record');
    self::assertTrue($records[0]['completed_generation'] < $records[1]['completed_generation'] && $records[1]['completed_generation'] < $records[2]['completed_generation']);
    self::assertSame([$records[2]], $this->container()->get(PaletteReplacements::class)->since($records[1]['completed_generation']));
    $this->cancelJob($cancelled);
    self::assertCount(3, $this->container()->get(PaletteReplacements::class)->since($g0), 'a cancelled job never becomes a record');
}

public function testAMappinglessContrastIsOmittedFromTheRecord(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->replaceAndClear(1, 'color.surface', null);             // no contrast references, no mapping
    $records = $this->container()->get(PaletteReplacements::class)->since(0);
    self::assertSame(['color.brand-1' => 'color.surface'], $records[0]['map']);
}
```

`replaceAndClear(int $slot, string $to, ?string $contrastTo = <pair>)` is a stand-in until Task 14:
1. record the job (`PaletteJobRepository::start`);
2. rewrite the draft and current publication through `PaletteFence::within`;
3. clear the slot;
4. `transition($id, 'completed')`, which records `completed_generation` from `bump()`.

Task 14 Step 7 re-runs both files against the real runner.

- [ ] **Step 2: Write the failing admin unit specs**

`admin/src/__tests__/palette-rewrites.spec.ts` checks:
- a rewrite follows its block through a reorder of two same-coloured blocks;
- a deleted block is skipped even when another takes its index;
- an edit made during the request is kept;
- nested blocks and token content fields;
- page-level plain paths;
- `mapPaletteTokens` maps only token nodes.

The code is unchanged from the previous revision of this task:

```ts
import { describe, expect, it } from 'vitest'
import { applyPaletteRewrites, mapPaletteTokens } from '@/editor/paletteRewrites'

const tok = (v: string) => ({ type: 'token', value: v })
const heading = (id: string, colour: string, text = 'x') => ({ id, type: 'heading', data: { text }, settings: { style: { colors: { text: tok(colour) } } } })
const rw = (id: string) => ({ location: `${id}:settings.style.colors.text`, from: 'color.brand-1', to: 'color.accent' })
const colourOf = (doc: any, i: number) => doc.body[i].settings.style.colors.text.value

describe('applyPaletteRewrites', () => {
  it('follows the block through a reorder of two blocks with the same colour', () => {
    const { doc } = applyPaletteRewrites({ body: [heading('b', 'color.brand-1'), heading('a', 'color.brand-1')] }, [rw('a')])
    expect(colourOf(doc, 1)).toBe('color.accent')
    expect(colourOf(doc, 0)).toBe('color.brand-1')
  })
  it('skips a deleted block even when another block takes its index', () => {
    const { doc, applied } = applyPaletteRewrites({ body: [heading('b', 'color.brand-1')] }, [rw('a')])
    expect(colourOf(doc, 0)).toBe('color.brand-1')
    expect(applied).toHaveLength(0)
  })
  it('keeps an edit made during the request', () => {
    const { doc, applied } = applyPaletteRewrites({ body: [heading('a', 'color.muted')] }, [rw('a')])
    expect(colourOf(doc, 0)).toBe('color.muted')
    expect(applied).toHaveLength(0)
  })
  it('finds nested blocks and token content fields', () => {
    const doc = { body: [{ id: 's', type: 'section', data: { children: [{ id: 't', type: 'animated_text', data: { prefix_color: tok('color.brand-1') } }] } }] }
    const { doc: out } = applyPaletteRewrites(doc, [{ location: 't:data.prefix_color', from: 'color.brand-1', to: 'color.accent' }])
    expect((out as any).body[0].data.children[0].data.prefix_color.value).toBe('color.accent')
  })
  it('resolves page-level locations as plain paths', () => {
    const { doc } = applyPaletteRewrites({ _presentation: { style: { colors: { surface: tok('color.brand-1') } } } }, [{ location: '_presentation.style.colors.surface', from: 'color.brand-1', to: 'color.accent' }])
    expect((doc as any)._presentation.style.colors.surface.value).toBe('color.accent')
  })
})

describe('mapPaletteTokens', () => {
  it('maps every token node, deep, and nothing else', () => {
    const out = mapPaletteTokens({ a: tok('color.brand-1'), b: [{ c: tok('color.brand-1-contrast') }], d: 'color.brand-1' }, { 'color.brand-1': 'color.accent', 'color.brand-1-contrast': 'color.accent-contrast' }) as any
    expect(out.a.value).toBe('color.accent')
    expect(out.b[0].c.value).toBe('color.accent-contrast')
    expect(out.d).toBe('color.brand-1')
  })
})
```

`admin/src/__tests__/palette-ledger.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { createReplacementLedger } from '@/editor/paletteReplacements'

const A = { id: 'jobA', slot: 1, map: { 'color.brand-1': 'color.brand-2' }, completed_generation: 5 }
const B = { id: 'jobB', slot: 2, map: { 'color.brand-2': 'color.accent' }, completed_generation: 7 }
const C = { id: 'jobC', slot: 1, map: { 'color.brand-1': 'color.surface' }, completed_generation: 9 } // slot 1 reused

describe('replacement ledger', () => {
  it('returns pending records newer than the baseline, in completion order, whatever order they arrive in', () => {
    const l = createReplacementLedger(4)
    expect(l.pending([B, A]).map((r) => r.id)).toEqual(['jobA', 'jobB'])
  })
  it('never returns a record twice', () => {
    const l = createReplacementLedger(4)
    l.markApplied(l.pending([A, B]))
    expect(l.pending([A, B, C]).map((r) => r.id)).toEqual(['jobC'])
    l.markApplied(l.pending([C]))
    expect(l.pending([C, B, A])).toEqual([])
  })
  it('ignores records at or below the baseline (the loaded document already reflects them)', () => {
    expect(createReplacementLedger(7).pending([A, B, C]).map((r) => r.id)).toEqual(['jobC'])
  })
  it('reset moves the baseline and forgets applied ids below it', () => {
    const l = createReplacementLedger(4)
    l.markApplied(l.pending([A]))
    l.reset(7)
    expect(l.pending([A, B, C]).map((r) => r.id)).toEqual(['jobC'])
  })
})
```

`admin/src/__tests__/palette-history.spec.ts`, built on `createEditorHistory` as `editor-history.spec.ts` builds it:

```ts
it('a chained pair applied in order maps Brand 1 to Accent, and again changes nothing', () => {
  const ed = editorOver({ fields: { body: [heading('a', 'color.text')] } }, { paletteGeneration: 4 })
  ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
  ed.applyReplacements([B, A])                       // delivered out of order
  expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  ed.applyReplacements([A, B])                       // a delayed duplicate delivery
  expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  ed.undo(); ed.redo()
  expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
})

it('a reused slot maps only what predates its second replacement', () => {
  const ed = editorOver({ fields: { body: [heading('a', 'color.text')] } }, { paletteGeneration: 8 }) // A and B already in the loaded document
  ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))        // the NEW Brand 1 (Ink)
  ed.applyReplacements([A, B, C])
  expect(colourOf(ed.document.fields, 0)).toBe('color.surface')                           // C only, never A
})

it('a restore after completion is never mapped through records it postdates', () => {
  const ed = editorOver({ fields: { body: [heading('a', 'color.text')] } }, { paletteGeneration: 4 })
  // the restore response: record A completed at 5, the restore ran at generation 6
  ed.restoreFromResponse({ fields: { body: [heading('a', 'color.brand-1')] }, palette_generation: 6, palette_replacements: [A] })
  expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
  ed.applyReplacements([A])                          // a later schema refresh
  expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
  ed.undo(); ed.redo()
  expect(colourOf(ed.document.fields, 0)).toBe('color.brand-1')
})

it('reconciles whole blocks carried by insert, remove and duplicate operations', () => {
  const ed = editorOver({ fields: { body: [heading('a', 'color.text')] } }, { paletteGeneration: 4 })
  ed.record(insertBlock(1, heading('b', 'color.brand-1')))
  ed.record(removeBlock(1, heading('b', 'color.brand-1')))
  ed.applyReplacements([{ ...A, map: { 'color.brand-1': 'color.accent' } }])
  ed.undo()
  expect(colourOf(ed.document.fields, 1)).toBe('color.accent')
  ed.undo(); ed.redo()
  expect(colourOf(ed.document.fields, 1)).toBe('color.accent')
})

it('adopting a save result keeps an active, uncommitted text transaction and its undo record', () => {
  const ed = editorOver({ fields: { body: [heading('a', 'color.text', 'old')] } }, { paletteGeneration: 4 })
  ed.record(setSetting('a', 'colors.text', tok('color.text'), tok('color.brand-1')))
  const submitted = ed.currentSequence()
  ed.markSaved(submitted)
  ed.beginTyping('a', 'text', 'old', 'new')          // records into the ACTIVE transaction; not committed
  ed.adoptPaletteResult({ palette_rewrites: [rw('a')], palette_replacements: [] })
  expect(ed.document.fields.body[0].data.text).toBe('new')
  expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
  ed.commitTyping()                                  // the debounce fires
  expect(ed.isDirty()).toBe(true)
  ed.undo()
  expect(ed.document.fields.body[0].data.text).toBe('old')
  expect(colourOf(ed.document.fields, 0)).toBe('color.accent')
})
```

`editorOver` wraps `createEditorHistory` plus the ledger, with the same adopt and apply logic `useStageEditor` uses. Extract that logic into a pure `createPaletteReconciler(history, ledger)` in `paletteReplacements.ts`, so the stage editor and these specs share one implementation. `beginTyping` / `commitTyping` record into, and commit, an active transaction the way the inspector's debounced text field does.

`admin/src/__tests__/restore-version.spec.ts` checks the design page's restore:
- it calls `restoreDraft(uuid, 'en', versionUuid, currentLock, paletteGeneration)`;
- it applies the response's records to existing history first;
- it moves the ledger baseline to the response's generation;
- it applies one undoable `SetPageSettings` transaction built by `restoreOps`, marked persisted;
- undo and redo send ordinary saves only.

- [ ] **Step 3: Write the failing browser proofs**

`admin/e2e/helpers.ts`:
- `World` gains `restore?: { fields; lock_version; palette_generation; palette_replacements }`, answering `POST …/draft/{locale}/restore` and recorded as `recorded.restores`;
- it gains `saveResponses?: Array<{ palette_rewrites?; palette_replacements?; palette_generation? }>` and `saveDelayMs?`;
- it gains `schemaPalette?` (merged into the schema fixture's `palette`, including `generation` and `replacements`);
- it gains `reloadDraftFrom: 'last-save'`;
- the draft GET returns `palette_generation` (default 1).

`admin/e2e/tests/palette-normalized-save.spec.ts`:

```ts
import { test, expect } from '@playwright/test'
import { hooks, openDesignPage } from '../helpers'

const rewrite = { location: 'ctabutn0001:settings.style.colors.text', from: 'color.brand-1', to: 'color.accent' }
const record = { id: 'jobA', slot: 1, map: { 'color.brand-1': 'color.accent', 'color.brand-1-contrast': 'color.accent-contrast' }, completed_generation: 3 }

test('a save response arriving while a text edit is still in its debounce keeps that edit, its undo and its dirty state', async ({ page }) => {
  const recorded = await openDesignPage(page, { saveResponses: [{ palette_rewrites: [rewrite], palette_generation: 2 }, {}], saveDelayMs: 300, reloadDraftFrom: 'last-save' })
  await hooks(page).setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  const saving = hooks(page).saveNow()
  await hooks(page).typeInInspector('ctabutn0001', 'label', 'Order now')     // the text transaction stays ACTIVE (500 ms debounce)
  await saving                                                                // the response lands inside the debounce
  expect(await hooks(page).hasActiveTransaction()).toBe(true)
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.accent')
  await expect.poll(() => hooks(page).hasActiveTransaction()).toBe(false)    // the debounce commits it
  expect(await hooks(page).isDirty()).toBe(true)
  await page.keyboard.press('ControlOrMeta+z')
  expect(await hooks(page).fieldText('ctabutn0001', 'label')).not.toBe('Order now') // its undo record exists
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await hooks(page).saveNow()
  const second = JSON.stringify(recorded.saves[1].fields)
  expect(second).toContain('Order now')
  expect(second).toContain('color.accent')
  await page.reload()
  await expect.poll(() => hooks(page).fieldText('ctabutn0001', 'label')).toBe('Order now')
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.accent')
})

test('a rewrite never lands on a different block after a reorder during the save', async ({ page }) => {
  await openDesignPage(page, { saveResponses: [{ palette_rewrites: [rewrite] }], saveDelayMs: 300 })
  await hooks(page).setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  await hooks(page).setStyleToken('ctabutn0002', 'colors.text', 'color.brand-1')
  const saving = hooks(page).saveNow()
  await hooks(page).moveBlock('ctabutn0002', 'before', 'ctabutn0001')
  await saving
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.accent')
  expect(await hooks(page).blockToken('ctabutn0002')).toBe('color.brand-1')  // only the named block was rewritten
})

test('after the replacement completes, undo then redo replays Accent, not Brand 1', async ({ page }) => {
  const recorded = await openDesignPage(page, { saveResponses: [{ palette_rewrites: [rewrite], palette_generation: 2 }, {}] })
  await hooks(page).setStyleToken('ctabutn0001', 'colors.text', 'color.brand-1')
  await hooks(page).saveNow()
  await hooks(page).setSchemaPalette({ generation: 3, replacements: [record] })   // the job finished
  await page.keyboard.press('ControlOrMeta+z')
  await page.keyboard.press('ControlOrMeta+Shift+z')
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.accent')
  await hooks(page).saveNow()
  expect(JSON.stringify(recorded.saves.at(-1)!.fields)).not.toContain('color.brand-1')
})
```

`admin/e2e/tests/palette-restore-history.spec.ts`:

```ts
test('complete replacement → restore → refresh schema → undo → redo → save keeps the restored unavailable colour', async ({ page }) => {
  const restored = fixtureWithBrandOne()                                         // the CTA button's colour is Brand 1
  const recorded = await openDesignPage(page, {
    schemaPalette: { generation: 3, replacements: [record] },                    // the replacement already completed
    restore: { fields: restored, lock_version: 3, palette_generation: 4, palette_replacements: [record] },
    saveResponses: [{}, {}],
  })
  await page.locator('[data-test="versions-open"]').click()
  await page.locator('[data-test="version-restore"]').first().click()
  await expect.poll(() => hooks(page).blockToken('ctabutn0001')).toBe('color.brand-1')
  await hooks(page).refreshSchema()                                              // brings the completed record again
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.brand-1')     // not mapped to Accent
  await page.keyboard.press('ControlOrMeta+z')
  await page.keyboard.press('ControlOrMeta+Shift+z')
  expect(await hooks(page).blockToken('ctabutn0001')).toBe('color.brand-1')
  await hooks(page).saveNow()
  const saved = JSON.stringify(recorded.saves.at(-1)!.fields)
  expect(saved).toContain('color.brand-1')
  expect(saved).not.toContain('"version')                                         // still an ordinary save
})

test('restore → undo → redo sends the restore once, then ordinary saves', async ({ page }) => {
  const restored = fixtureWithBrandOne()
  const recorded = await openDesignPage(page, { restore: { fields: restored, lock_version: 3, palette_generation: 1, palette_replacements: [] } })
  await page.locator('[data-test="versions-open"]').click()
  await page.locator('[data-test="version-restore"]').first().click()
  await expect.poll(() => recorded.restores.length).toBe(1)
  await page.keyboard.press('ControlOrMeta+z')
  await expect.poll(() => recorded.saves.length).toBe(1)
  await page.keyboard.press('ControlOrMeta+Shift+z')
  await expect.poll(() => recorded.saves.length).toBe(2)
  expect(recorded.saves[1].fields).toEqual(restored)
})
```

`fixtureWithBrandOne()` builds from `api/draft.json` through `fixturePath()`, as in the previous revision.

Add these E2E hooks beside `applies()`, present only in an E2E build: `setStyleToken`, `typeInInspector` (types through the real inspector field, so its debounce applies), `hasActiveTransaction`, `fieldText`, `blockToken`, `moveBlock`, `saveNow`, `isDirty`, `setSchemaPalette`, `refreshSchema`. The fixture needs two CTA buttons (`ctabutn0001`, `ctabutn0002`); add the second in `scripts/build-builder-proof-fixtures` if it's missing.

- [ ] **Step 4: Run them all to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/DraftRestoreTest.php tests/Integration/Content/Palette/PaletteReplacementsTest.php && (cd admin && pnpm vitest run src/__tests__/palette-rewrites.spec.ts src/__tests__/palette-ledger.spec.ts src/__tests__/palette-history.spec.ts src/__tests__/restore-version.spec.ts)`
Run: `CACHE_DRIVER=array scripts/build-builder-proof-fixtures && cd admin/e2e && npx playwright test tests/palette-restore-history.spec.ts tests/palette-normalized-save.spec.ts`
Expected: FAIL.

- [ ] **Step 5: Implement the server side**

- **Migrations:**
  - 044 adds the nullable JSON `restore_basis` on `entry_drafts`;
  - 045 adds the nullable bigint `completed_generation` on `palette_jobs`, with an index.

  Use the alter-table API of `039_FieldLabelsOnSavedSections.php`.
- **`PaletteJobRepository::transition($id, 'completed')`:** also writes `completed_generation`, taken from the `bump()` the completing transaction made. The runner's completion and the `replaceAndClear` stand-in both call `bump()` before `transition`, so pass that value: `transition(string $id, string $to, ?int $generation = null)`.
- **`PaletteReplacements::since(int $g)`:** reads `palette_jobs WHERE status = 'completed' AND completed_generation > :g ORDER BY completed_generation`. It maps each row to the record shape, and `map` omits the contrast key when `contrast_to_token` is null. `recent(int $days = 90)` feeds the style schema.
- **`PaletteNormalizer::mergeBasis(array ...$bases)`:** unions the location → token lists.
- **`EntryRepository::saveDraft(..., array $extraBasis = [], ?array $recordRestoreBasis = null): PaletteOutcome`:**
  - the basis is `mergeBasis(basisOf(KIND_ENTRY, $schema, $currentDraft), $this->restoreBasis($uuid, $locale), $extraBasis)`;
  - the CAS writes `restore_basis` when `$recordRestoreBasis` is given;
  - it returns the rewrites and the held generation.
- **`DraftRestore::restore`:**
  1. load and verify the version (404);
  2. project it;
  3. build the fields, keeping the draft's `_schema`;
  4. validate them;
  5. compute the brand-only basis;
  6. call `saveDraft(..., extraBasis: $basis, recordRestoreBasis: $basis)`.
- **`PublishService::publishInternal`:** clears `restore_basis` after `pin`, inside its transaction.
- **Controllers:**
  - Load responses add `palette_generation` (`PaletteState::snapshot()->generation`).
  - Save and restore responses add `palette_generation` (from the outcome), `palette_rewrites`, and `palette_replacements = PaletteReplacements::since($input->palette_generation)`, or `[]` when the body has none.
  - Save DTOs gain `?int $palette_generation`.
- **`StyleSchemaController`:** `palette.generation` and `palette.replacements` (`recent()`). Update Task 6's exact-array assertion; the slot carries no `retired`.

- [ ] **Step 6: Implement the admin side**

`admin/src/editor/paletteRewrites.ts`: `applyPaletteRewrites` (block id plus relative path, `from` check) and `mapPaletteTokens` (deep token map), exactly as in the previous revision of this task.

`history.ts` gains:

```ts
/**
 * Adopt a server-reconciled document underneath the ACTIVE transaction (custom palette spec §4.5):
 * unlike rebase(), nothing is cancelled or inverted; the open transaction keeps its ops and keeps
 * coalescing, and entries and sequences are untouched. `next` already contains the active edits.
 */
function adopt(next: EditorDocument): void {
  document = next
}

/** Map every token node in every entry, the active transaction and the document (one replacement record). */
function reconcileTokens(mapping: Record<string, string>): void {
  if (Object.keys(mapping).length === 0) return
  for (const entry of entries) {
    entry.ops = entry.ops.map((op) => mapPaletteTokens(op, mapping))
    entry.bytes = JSON.stringify(entry.ops).length
  }
  if (active !== null) active.ops = active.ops.map((op) => mapPaletteTokens(op, mapping))
  document = mapPaletteTokens(document, mapping)
}
```

`admin/src/editor/paletteReplacements.ts`:

```ts
export interface ReplacementRecord { id: string; slot: number; map: Record<string, string>; completed_generation: number }

export function createReplacementLedger(baseline: number) {
  const applied = new Set<string>()
  return {
    get baseline() { return baseline },
    pending(records: ReplacementRecord[]): ReplacementRecord[] {
      return records
        .filter((r) => r.completed_generation > baseline && !applied.has(r.id))
        .sort((a, b) => a.completed_generation - b.completed_generation)
    },
    markApplied(records: ReplacementRecord[]): void {
      for (const r of records) applied.add(r.id)
    },
    reset(generation: number): void {
      baseline = generation
      applied.clear()
    },
  }
}

/** One implementation for the stage editor and its specs: ordered, once-only, identity-safe. */
export function createPaletteReconciler(deps: {
  history: { reconcileTokens(m: Record<string, string>): void; adopt(next: EditorDocument): void }
  currentFields(): Record<string, unknown>
  replaceFields(next: Record<string, unknown>): void // sets the fields model with the adopt flag raised
  ledger: ReturnType<typeof createReplacementLedger>
}) {
  return {
    applyReplacements(records: ReplacementRecord[]): void {
      for (const record of deps.ledger.pending(records)) {
        deps.history.reconcileTokens(record.map)
        deps.replaceFields(mapPaletteTokens(deps.currentFields(), record.map))
        deps.ledger.markApplied([record])
      }
    },
    adoptPaletteResult(outcome: { palette_rewrites?: PaletteRewrite[]; palette_replacements?: ReplacementRecord[] }): void {
      const next = applyPaletteRewrites(deps.currentFields(), outcome.palette_rewrites ?? []).doc
      deps.replaceFields(next)
      this.applyReplacements(outcome.palette_replacements ?? [])
    },
    restoreFromResponse(res: { palette_generation: number; palette_replacements: ReplacementRecord[] }, applyRestore: () => void): void {
      this.applyReplacements(res.palette_replacements) // existing history first
      deps.ledger.reset(res.palette_generation)         // the restored content postdates every known record
      applyRestore()                                     // the undoable SetPageSettings transaction
    },
  }
}
```

In `useStageEditor.ts`:
- **Ledger:** create the ledger with the draft GET's `palette_generation`.
- **`replaceFields(next)`:** set `pendingAdopt = true`, then assign the fields model. The fields watcher, seeing `pendingAdopt`, calls `history.adopt({ fields: snapshotFields() })` instead of `rebase` and records nothing, then schedules a working-copy apply.
- **API:** expose `adoptPaletteResult`, `applyReplacements`, `resetPaletteBaseline(g)` (→ `ledger.reset(g)`) and `restoreFromResponse`.
- **Full reload:** `reloadStage` and a conflict reload call `resetPaletteBaseline` with the reloaded draft's generation.

Wiring:
- **Design page:**
  - `saveDraftOnly` sends `palette_generation: editor.paletteBaseline()` in the body, calls `editor.markSaved(sequence)` as today, then `editor.adoptPaletteResult(result.data)`.
  - The restore flow calls `restoreDraft(..., editor.paletteBaseline())`, then `editor.restoreFromResponse(res, () => applyRestoreOps(res.draft.fields))`, then adopts `lock_version`.
  - On every style-schema load or refresh it calls `editor.applyReplacements(schema.palette.replacements)`.
- **Region and layout hosts, and the style-class editor:** the same three calls on their own editors.
- **Form page and saved sections:** no op history. Each keeps a ledger, applies records to its form model, adopts rewrites, keeps its dirty flag against the submitted snapshot, and moves the baseline on restore and reload.

- [ ] **Step 7: Run everything touched**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Content/EntryRepositoryTest.php tests/Integration/Content/PublishServiceTest.php tests/Integration/Render/StyleSchemaEndpointTest.php && (cd admin && pnpm vitest run src/__tests__/palette-rewrites.spec.ts src/__tests__/palette-ledger.spec.ts src/__tests__/palette-history.spec.ts src/__tests__/restore-version.spec.ts src/__tests__/editor-history.spec.ts src/__tests__/region-style-editor.spec.ts src/__tests__/style-class-editor-output.spec.ts && pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/editor src/queries src/pages e2e/helpers.ts e2e/tests/palette-restore-history.spec.ts e2e/tests/palette-normalized-save.spec.ts src/__tests__/palette-rewrites.spec.ts src/__tests__/palette-ledger.spec.ts src/__tests__/palette-history.spec.ts src/__tests__/restore-version.spec.ts) && (cd admin/e2e && npx playwright test tests/palette-restore-history.spec.ts tests/palette-normalized-save.spec.ts)`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add core/database/migrations/044_AddRestoreBasisToEntryDrafts.php core/database/migrations/045_AddCompletedGenerationToPaletteJobs.php core/src core/routes packages/thallo-render/src tests admin/src admin/e2e scripts .github/workflows
git commit -m "feat(palette): the editor contract — persisted restore basis, saves adopted by block identity under an open transaction, history reconciled by ordered replacement records"
```

---

### Task 13: Palette mutations, Clear, and the contrast preview

**Files:**
- Create: `core/src/Content/Palette/PaletteMutations.php`
- Create: `core/src/Content/Palette/BrandColorInUse.php` (exception carrying the usage), `core/src/Content/Palette/PaletteConflict.php` (409 with a message)
- Modify: `core/src/Http/Controllers/GeneralSettingsController.php` (palette keys go through `PaletteMutations::save()`)
- Modify: `core/src/Content/Palette/Http/PaletteController.php` (`clear(int $slot)`, `preview(PalettePreviewData $input)`)
- Create: `core/src/Content/Palette/Http/PalettePreviewData.php`
- Modify: `core/routes/admin.php`
- Test: `tests/Integration/Content/Palette/PaletteMutationsTest.php`, `tests/Integration/Http/PaletteApiTest.php`

**Interfaces:**
- Consumes: `PaletteFence::within`, `PaletteState::lock/bump`, `BrandColorUsage::of/blockingTotal`, `GeneralSettings::save`, `EffectivePalette`, `AuditRecorderInterface`, `EventService`.
- Produces:
  - `PaletteMutations::save(array $pairs, ?string $actor): bool` takes already-validated and encoded palette key pairs. It returns whether the palette changed, and throws `PaletteConflict` when a running job forbids the change.
  - `PaletteMutations::clear(int $slot, ?string $actor): void` throws `BrandColorInUse` (with `usage`) or `PaletteConflict`.
  - Routes:
    - `DELETE /v1/admin/appearance/palette/brand/{slot}` → 200 `{palette}` | 409 `{usage}` | 409 `{conflict}`;
    - `POST /v1/admin/appearance/palette/preview` → 200 `{rows, swatches, values: {light, dark}}`.

**Rules.**
- Every mutation runs in `PaletteFence::within`, then `PaletteState::lock()`, then the checks, then `bump()`, then the settings write. Events and the audit entry are queued with `afterCommit`.
- **`save`:**
  - A changed `theme_brand_N` whose slot is the **source** of an active job → `PaletteConflict('Gold dark is being replaced')`. That covers rename and re-colour.
  - A reserved slot may be renamed or re-coloured.
  - `theme_neutral`, `theme_neutral_custom` and `theme_dark_base` are never blocked.
- **`clear`:**
  - **Conflicts:** the slot is a job's source or reserved → `PaletteConflict`.
  - **Usage:** run the blocking scan inside the transaction, after `bump()`. Any blocking use → `BrandColorInUse`, and the transaction rolls back, so the bump rolls back too.
  - **Otherwise:** delete the `theme_brand_N` row. After commit, record the audit entry `palette.brand.cleared` and fire `ThemeAppearanceChanged`.

- [ ] **Step 1: Write the failing tests**

`PaletteMutationsTest.php`:

```php
public function testClearWithNoUsageClearsBumpsAndFiresAfterCommit(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $fired = $this->listen(ThemeAppearanceChanged::class);
    $g = $this->state()->snapshot()->generation;
    $this->mutations()->clear(1, 'user00000001');
    self::assertNull($this->palette()->brand(1));
    self::assertSame($g + 1, $this->state()->snapshot()->generation);
    self::assertCount(1, $fired);
    self::assertSame('palette.brand.cleared', $this->lastAudit()['action']);
}

public function testClearWithBlockingUsageIsRefusedAndChangesNothing(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftNaming('color.brand-1');
    $g = $this->state()->snapshot()->generation;
    try {
        $this->mutations()->clear(1, 'user00000001');
        self::fail('in use');
    } catch (BrandColorInUse $e) {
        self::assertSame(1, $e->usage['blocking']['total']);
    }
    self::assertNotNull($this->palette()->brand(1));
    self::assertSame($g, $this->state()->snapshot()->generation, 'rolled back with the refusal');
}

public function testClearWithOnlyHistoricalUsageClears(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->retainedVersionNaming('color.brand-1'); // neither draft nor current publication
    $this->mutations()->clear(1, 'user00000001');
    self::assertNull($this->palette()->brand(1));
}

public function testASaveCommittedBeforeTheClearRefusesItAndOneAfterIsRefusedByTheFence(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    // save first: the clear's in-transaction scan sees it
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    $this->expectException(BrandColorInUse::class);
    $this->mutations()->clear(1, 'user00000001');
}

public function testRenamingTheSourceOfAJobIsAConflictButRenamingAReservedSlotIsNot(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
    self::assertTrue($this->mutations()->save(['theme_brand_2' => '{"name":"Blush","hex":"#c98a8a"}'], 'user00000001'));
    $this->expectException(PaletteConflict::class);
    $this->mutations()->save(['theme_brand_1' => '{"name":"Old gold","hex":"#8a6a2a"}'], 'user00000001');
}

public function testClearingAReservedSlotOrAJobsSourceIsAConflict(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
    foreach ([1, 2] as $slot) {
        try {
            $this->mutations()->clear($slot, 'user00000001');
            self::fail("slot {$slot}");
        } catch (PaletteConflict) {
            self::assertNotNull($this->palette()->brand($slot));
        }
    }
}
```

`PaletteApiTest.php` (extend the route table from Task 7):

```php
['DELETE', '/v1/admin/appearance/palette/brand/{slot}', 'content_permission:content.manage'],
['POST', '/v1/admin/appearance/palette/preview', 'content_permission:content.manage'],
```

```php
public function testTheGeneralSettingsSaveRefusesRenamingASlotBeingReplaced(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->startJob(1, 'color.accent', 'color.accent-contrast');
    $res = $this->settingsController()->update(new UpdateGeneralSettingsData(theme_brand_1: '{"name":"New","hex":"#8a6a2a"}'));
    self::assertSame(409, $res->getStatusCode());
}

public function testClearReturnsTheUsageOn409(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftNaming('color.brand-1');
    $res = $this->paletteController()->clear(1);
    self::assertSame(409, $res->getStatusCode());
    self::assertSame(1, self::data($res)['usage']['blocking']['total']);
}

public function testThePreviewReturnsRowsForUnsavedValues(): void
{
    $res = $this->paletteController()->preview(self::dto(PalettePreviewData::class, [
        'theme_accent' => 'blue', 'theme_neutral' => 'custom', 'theme_background' => 'tinted',
        'palette' => ['neutral_custom' => ['bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8', 'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6'], 'dark_base' => 'stone', 'brands' => ['1' => ['name' => 'Gold', 'hex' => '#8a6a2a']]],
    ]));
    self::assertSame(200, $res->getStatusCode());
    $data = self::data($res);
    self::assertCount(2 * (6 + 2 + 2), $data['rows']);
    self::assertSame('#ffffff', $data['values']['light']['background'], 'tinted swapped');
    self::assertSame(422, $this->paletteController()->preview(self::dto(PalettePreviewData::class, ['palette' => ['dark_base' => 'purple']]))->getStatusCode());
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteMutationsTest.php tests/Integration/Http/PaletteApiTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `PaletteMutations`**

```php
<?php
declare(strict_types=1);
namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Settings\GeneralSettings;

/**
 * Every change to the palette (custom palette spec §4.3): under the palette row, generation bumped,
 * checked against running jobs and their reservations, effects after commit.
 */
final class PaletteMutations
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaletteFence $fence,
        private readonly PaletteState $state,
        private readonly GeneralSettings $settings,
        private readonly BrandColorUsage $usage,
        private readonly ?EventService $events = null,
        private readonly ?AuditRecorderInterface $audit = null,
    ) {
    }

    /** @param array<string,string> $pairs encoded palette keys */
    public function save(array $pairs, ?string $actor): bool
    {
        return $this->fence->within(function () use ($pairs): bool {
            $held = $this->state->lock();
            $before = $held->palette->fingerprint();
            foreach (Palette::SLOTS as $slot) {
                $key = 'theme_brand_' . $slot;
                if (!array_key_exists($key, $pairs)) {
                    continue;
                }
                $current = $held->palette->brand($slot);
                $next = \Thallo\Core\Settings\PaletteSettings::parseBrand($pairs[$key]);
                $changed = $current?->toArray() !== $next?->toArray();
                if ($changed && $held->jobReplacing($slot) !== null) {
                    throw new PaletteConflict(($current?->name ?? "Brand {$slot}") . ' is being replaced');
                }
            }
            $this->state->bump();
            $this->settings->save($pairs);
            return $this->state->snapshot()->palette->fingerprint() !== $before;
        });
    }

    public function clear(int $slot, ?string $actor): void
    {
        $name = '';
        $this->fence->within(function () use ($slot, &$name): void {
            $held = $this->state->lock();
            $brand = $held->palette->brand($slot);
            if ($brand === null) {
                return; // already clear: nothing to do
            }
            $name = $brand->name;
            if ($held->jobReplacing($slot) !== null || in_array($slot, $held->reservedSlots(), true)) {
                throw new PaletteConflict("{$name} is part of a running replacement");
            }
            $this->state->bump();
            $usage = $this->usage->of($slot);
            if ($usage['blocking']['total'] > 0) {
                throw new BrandColorInUse($usage); // rolls the bump back
            }
            $this->settings->save(['theme_brand_' . $slot => '']);
        });
        if ($name === '') {
            return;
        }
        $this->db->afterCommit(function () use ($slot, $name, $actor): void {
            $this->audit?->record(new AuditEntry(
                occurredAt: microtime(true), action: 'palette.brand.cleared', category: 'content',
                actorUuid: $actor, targetType: 'palette_slot', targetUuid: 'brand-' . $slot, targetLabel: $name,
            ));
            $this->events?->dispatch(new ThemeAppearanceChanged($this->settings->themeAccent(), $this->settings->themeNeutral()));
        });
    }
}
```

`afterCommit` outside any transaction fires immediately (Glueful), which is after `within()`'s commit.

`snapshot()` inside the held transaction reads on the same connection, so it sees this transaction's settings write.

`GeneralSettingsController::update()`:
- split the palette keys (`theme_brand_*`, `theme_neutral_custom`, `theme_dark_base`) out of the main `save([...])` map;
- call `$this->mutations->save($paletteKeys, $actor)` in a try that maps `PaletteConflict` → `Response::conflict` (409; use the framework's conflict response helper, or `Response::error(409, ...)` as other controllers do);
- keep the `ThemeAppearanceChanged` comparison from Task 4.

`PaletteController`:
- `clear(int $slot)`: catch `BrandColorInUse` → 409 `{usage}`, `PaletteConflict` → 409 `{conflict: message}`; success → 200 `{palette: <style schema palette block>}`.
- `preview(PalettePreviewData $input)`:
  - validate it the same way as `PreviewController` (Task 5);
  - build a `Palette` with `PaletteProvider::preview($claim)`;
  - return `EffectivePalette::of(accent ?? stored, neutral ?? stored, background ?? stored, $palette)` → `{rows: contrastRows(), swatches: swatches(), values: {light: values('light'), dark: values('dark')}}`.

Routes:

```php
$router->delete('/appearance/palette/brand/{slot}', [\Thallo\Core\Content\Palette\Http\PaletteController::class, 'clear'])
    ->where('slot', '[123]')->middleware('content_permission:content.manage');
$router->post('/appearance/palette/preview', [\Thallo\Core\Content\Palette\Http\PaletteController::class, 'preview'])
    ->middleware('content_permission:content.manage');
```

- [ ] **Step 4: Run them**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Http/PaletteApiTest.php tests/Integration/Settings/PaletteSettingsTest.php tests/Integration/Content/GeneralSettingsAppearanceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add core/src core/routes tests .github/workflows
git commit -m "feat(palette): palette changes under the palette row — Clear refuses a colour still in use, rename waits for its replacement, contrast preview for unsaved values"
```

---

### Task 14: The Replace job

**Files:**
- Create:
  - `core/src/Content/Palette/PaletteReplaceService.php` (start, cancel, resume, destination rules)
  - `core/src/Content/Palette/PaletteReplaceRunner.php`
  - `core/src/Content/Jobs/RunPaletteReplaceJob.php`
  - `core/src/Content/Palette/PaletteDocumentSources.php` (the six existing sources minus `EntryVersionsSource`, plus the three below)
  - `core/src/Content/Palette/Sources/RegionSettingsSource.php`, `LayoutSettingsSource.php`, `StyleClassesSource.php` (all implement `BlockDocumentSource`)
  - `core/src/Content/Palette/Http/ReplaceBrandData.php` (`string $to`, `?string $contrast_to`)
- Modify: `core/src/Content/Palette/Http/PaletteController.php` (`replace`, `job`, `jobs`, `cancel`, `resume`), `core/routes/admin.php`
- Create: `core/src/Content/Palette/PaletteCacheEffects.php` (after-commit invalidation per rewritten document)
- Test:
  - `tests/Integration/Content/Palette/PaletteReplaceTest.php`, `tests/Integration/Content/Palette/PaletteReplaceConcurrencyTest.php`;
  - `tests/Integration/Content/Palette/PaletteReplaceWorkerTest.php` (fresh worker), `tests/Integration/Content/Palette/PaletteReplaceTenancyTest.php` (two workspaces, retrofit);
  - `tests/Integration/Content/Palette/PaletteTwoProcessTest.php` and its actor `tests/Integration/Content/Palette/PaletteConcurrentActorTest.php`;
  - `tests/Integration/Http/PaletteApiTest.php`.

**Interfaces:**
- Consumes: everything from Tasks 7–13; `PublishedEntriesSource::persist($ref, $fields, $actor)` (append-and-repin); `QueueManager::push`; `TenantScope::current($tenants, $context)`; `TenantContextRunner::runAsTenant(string $workspace, callable $fn)`; `RenderedPageCachePurge::purge(array $tags)`.
- Produces:
  - `PaletteReplaceService::start(int $slot, string $to, ?string $contrastTo, ?string $actor): string` (job id). Throws `PaletteConflict` (409), or `\InvalidArgumentException` (422) for a bad destination;
  - `PaletteReplaceService::cancel(string $jobId): void`, `resume(string $jobId): void`;
  - `PaletteReplaceRunner::run(string $jobId): array{status:string,passes:int,done:int,failed:int}`;
  - job JSON `{id, slot, to, contrast_to, status: running|interrupted|failed|completed|cancelled, passes, work_items_total, work_items_done, work_items_failed, failure_report, created_at, finished_at}`.

**Destination rules (spec §4.2).**
- **`to` must be:** a colour token, not `color.brand-N` and not `color.brand-N-contrast` for the slot being replaced, not an unconfigured brand slot, not a slot that is a job's source, and not any contrast token (`accent-contrast`, `brand-M-contrast`).
- **`contrast_to`, when omitted:**
  - `to` has a pair (`accent` → `accent-contrast`, `brand-M` → `brand-M-contrast`): the pair is the mapping.
  - `to` has no pair: the contrast references are counted **under the palette lock**, in the start transaction.
    - **Some exist:** 422 `contrast_to is required`, and nothing is recorded.
    - **None exist:** the job records `contrast_to = null`, meaning **no contrast mapping**. A contrast reference arriving later is never mapped to the fill colour. The normaliser refuses it on an editor's save (422, Task 9), and the runner records it as a failure (`'a text colour on Brand N arrived after the replacement started; cancel and choose a text colour'`), so the job cannot complete until someone cancels and starts again with a mapping.
- **`contrast_to`, when given:** passes the same rules as `to`.

**Runner (spec §4.4).** For each pass, 1..5:
1. Touch the heartbeat.
2. Enumerate `PaletteDocumentSources`, keeping refs whose walked tokens name the slot. `beginPass(count)`.
3. **When none remain:** take `PaletteFence::within` → `lock()` → recount the blocking usage inside the transaction.
   - **Zero:** `transition($jobId, 'completed')`. When it returns false (the job was cancelled or finished by another worker), write nothing and stop. Otherwise `bump()`, delete `theme_brand_N`, record the counts, and queue the audit entry `palette.brand.replaced` plus `ThemeAppearanceChanged` after commit. Done.
   - **Not zero:** end the transaction and continue to the next pass.
4. **Otherwise,** for each ref (up to three attempts):

   ```php
   $written = $this->fence->write(
       fn (PaletteSnapshot $s): Normalized => $this->normalizer->normalize($kind, $ref->fields, $s,
           $this->normalizer->basisOf($kind, $ref->schema, $ref->fields), $ref->schema),
       function (array $doc) use ($jobId, $source, $ref, $actor): bool {
           if (!$this->jobs->isActive($jobId)) {
               throw new JobFenced($jobId);         // cancelled: write nothing, stop the run
           }
           return $doc === $ref->fields ? true : $source->persist($ref, $doc, $actor);
       },
       force: true,
   );
   ```

   - `false` → re-read the ref from its source (`find`-by-identity: re-enumerate that one source and match `identity()`), then retry. After three attempts, `recordFailure(... 'document changed concurrently; retried on the next pass')`.
   - `JobFenced` → return immediately with the job's current status.
   - `PaletteRefusal` (a contrast reference under a job with no contrast mapping) → `recordFailure` with the reason above. The document is not written.
   - Success → `incrementDone` and `touchHeartbeat`, add to the per-source counts, and register `PaletteCacheEffects::afterWrite($ref)` with `afterCommit` (see below).

**Every status change is a guarded transition under the palette lock** (`PaletteJobRepository::transition`, Task 8).
- **Five passes without completing:** `within` → `lock()` → `transition($jobId, 'failed')`. When it returns false, another worker completed the job or a cancel committed. The failure is then dropped: the job's status, its reservations and the slot are left exactly as the other actor left them. Only when the transition succeeds are the remaining refs recorded as failures.
- **Resume:** eligibility is decided inside the lock. `failed` → `running`, or `running` with a stale heartbeat → `running`; anything else returns 409 without queueing.
- **Cancel:** `running`/`failed` → `cancelled`.

A worker therefore can never revive a terminal job or restore its reservations.

**Job writes and the normaliser.** The normaliser maps the slot to `to`/`contrast_to` because the job is active in the held snapshot. Once the job is cancelled, the `isActive` check refuses the write first.

**Cache effects after each successful write (review point 7).** `BlockDocumentSource::persist()` does not dispatch change events everywhere. `PublishedEntriesSource` appends and repins without the publication event, which is why `StyleClassJobRunner` purges separately. `PaletteCacheEffects::afterWrite(DocumentRef $ref)` runs after the write's commit:

| Source | Effect |
|---|---|
| `entry_draft` | `EntryUpdated` is not needed for rendering; open stages refresh through the draft's next load. No purge. |
| `entry_published` | `RenderedPageCachePurge::purge(['thallo:entry:{uuid}', 'thallo:type:{content_type}'])`, as `StyleClassJobRunner::purgeFor` does for entries. |
| `region`, `region_settings` | `purge(['thallo:render:page'])` (every page shows the header and footer). |
| `layout`, `layout_settings` | `LayoutChanges::announce($surface, $target)` (the layout source's own effect, which forgets the layout and purges its pages). `LayoutSettingsSource::persist` does this itself; the runner does not double it. |
| `saved_section` | none (the library is read fresh; no page renders a saved section as such). |
| `style_class` | `StyleClassRepository::write()` dispatches `StyleClassSaved`. Assert in the test that its listener purges rendered pages; if none does, purge `thallo:render:page` here. |

These are per document, after commit, so a job that later fails has already invalidated every page it rewrote.

**The queued job carries its workspace (review point 3).**
- `start()` records `TenantScope::current($this->tenants, $this->context)` on the job row and pushes `['job_id' => $id, 'workspace' => $workspace]`.
- `RunPaletteReplaceJob::handle()`:
  1. reads both values;
  2. when `workspace` is a non-empty string and the container has `TenantContextRunner`, runs the whole body inside `runAsTenant($workspace, …)`, resolving `PaletteReplaceRunner` **inside** that callback so every palette service is built in the workspace's context;
  3. otherwise runs it directly (single-store site).
- `IntentRetirement::runner()` (`core/src/Payments/Tenancy/IntentRetirement.php:97`) is the core precedent for obtaining the runner; `SearchWakeJob` is the queued-job precedent.
- Resume pushes the same payload, read from the job row.

- [ ] **Step 1: Write the failing replace tests**

`PaletteReplaceTest.php`:

```php
public function testReplaceRewritesEveryBlockingDocumentAppendsPublicationVersionsAndClears(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.brand-1');            // draft + current publication
    $old = $this->retainedVersionNaming($uuid, 'color.brand-1');       // history
    $this->regionStyleNaming('header', 'color.brand-1-contrast');
    $this->layoutFrameNaming('entry', 'page', 'color.brand-1');
    $this->savedSectionNaming('Hero', 'color.brand-1');
    $class = $this->styleClassNaming('Card', 'color.brand-1');
    $this->animatedTextDraftNaming('color.brand-1');                    // data token field
    $versionsBefore = $this->versionCount($uuid);
    $pinnedBefore = $this->publishedVersionUuid($uuid);

    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $result = $this->runner()->run($job);

    self::assertSame('completed', $result['status']);
    self::assertSame('color.accent', $this->draftToken($uuid));
    self::assertSame('color.accent', $this->publishedToken($uuid));
    self::assertSame($versionsBefore + 1, $this->versionCount($uuid), 'append-and-repin');
    self::assertNotSame($pinnedBefore, $this->publishedVersionUuid($uuid));
    self::assertSame('color.brand-1', $this->versionToken($old), 'history untouched');
    self::assertSame('color.accent-contrast', $this->regionStyleToken('header'));
    self::assertSame('color.accent', $this->layoutFrameToken('entry', 'page'));
    self::assertSame('color.accent', $this->sectionToken('Hero'));
    self::assertSame('color.accent', $this->classToken($class, 'colors.text'));
    self::assertSame('color.accent', $this->animatedTextToken());
    self::assertNull($this->palette()->brand(1), 'cleared');
    self::assertSame('palette.brand.replaced', $this->lastAudit()['action']);
}

public function testThePreviousPublicationVersionIsUnchangedAndAuthoredByTheStarter(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.brand-1');
    $pinnedBefore = $this->publishedVersionUuid($uuid);
    $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000002'));
    self::assertSame('color.brand-1', $this->versionToken($pinnedBefore), 'the old publication version is kept as it was');
    $new = $this->connection()->table('entry_versions')->where('uuid', '=', $this->publishedVersionUuid($uuid))->first();
    self::assertSame('user00000002', $new['created_by']);
}

public function testRestoringAnOlderVersionAfterReplaceShowsAnUnavailableColour(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.brand-1');
    $old = $this->publishedVersionUuid($uuid);
    $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000001'));
    $this->container()->get(DraftRestore::class)->restore($uuid, 'en', $old, $this->lockOf($uuid), 'user00000001');
    self::assertSame('color.brand-1', $this->draftToken($uuid));
    self::assertStringNotContainsString('brand-1', $this->renderDraftPreview($uuid), 'no colour applied');
}

public function testDestinationRules(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    foreach (['color.brand-1', 'color.brand-1-contrast', 'color.brand-3', 'color.accent-contrast', 'color.brand-2-contrast', 'color.nope'] as $bad) {
        try {
            $this->service()->start(1, $bad, null, 'user00000001');
            self::fail($bad);
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->jobs()->active(), $bad);
        }
    }
}

public function testContrastReferencesMapToThePairOrRequireAnExplicitDestination(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftNaming('color.brand-1-contrast');
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->start(1, 'color.surface', null, 'user00000001'); // no pair, contrast refs exist
}

public function testAnExplicitContrastDestinationIsUsedAndReserved(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(3, 'Ink', '#111111');
    $this->draftNaming('color.brand-1-contrast');
    $job = $this->service()->start(1, 'color.surface', 'color.brand-3', 'user00000001');
    self::assertSame([3], $this->state()->snapshot()->reservedSlots());
    $this->runner()->run($job);
    self::assertSame('color.brand-3', $this->draftToken());
}
```

`created_by` is the actor column `appendVersion` writes; check its name in `VersionRepository::appendVersion`. `renderDraftPreview` is the preview render helper from `PreviewAppearanceTest`.

`PaletteReplaceConcurrencyTest.php`:

```php
public function testAConcurrentEditorSaveMakesPersistFailAndTheJobRewritesTheFreshDocument(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')], 'title' => 'a'], 1, $lock, 'user00000001');
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    // first persist: an editor saves (title b, still Brand 1 → mapped to Accent by the fence) before the
    // job's write lands, so the job's conditional write fails; the retry re-reads and finds nothing to do
    $drafts = new EntryDraftsSource($this->connection());
    $flaky = new class ($drafts, fn () => $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')], 'title' => 'b'], 1, $this->lockOf($uuid), 'user00000001')) implements BlockDocumentSource {
        public int $persists = 0;
        public function __construct(private EntryDraftsSource $inner, private \Closure $editorSave) {}
        public function id(): string { return $this->inner->id(); }
        public function each(callable $fn): void { $this->inner->each($fn); }
        public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
        {
            if ($this->persists++ === 0) {
                ($this->editorSave)();
            }
            return $this->inner->persist($ref, $fields, $actor);
        }
    };
    self::assertSame('completed', $this->runnerWith($flaky)->run($job)['status']);
    self::assertSame('b', $this->draftFields($uuid)['title'], 'the editor\'s save survived');
    self::assertSame('color.accent', $this->draftToken($uuid));
}

public function testAPublicationLandingMidJobIsRewrittenOnRetry(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.brand-1');
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $published = $this->container()->get(PublishedEntriesSource::class);
    $racing = new class ($published, fn () => $this->insertPublicationNaming($uuid, 'color.brand-1')) implements BlockDocumentSource {
        public int $persists = 0;
        public function __construct(private PublishedEntriesSource $inner, private \Closure $land) {}
        public function id(): string { return $this->inner->id(); }
        public function each(callable $fn): void { $this->inner->each($fn); }
        public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
        {
            if ($this->persists++ === 0) {
                ($this->land)(); // a publication pinned directly, bypassing the fence, as a racing writer would
            }
            return $this->inner->persist($ref, $fields, $actor);
        }
    };
    self::assertSame('completed', $this->runnerWith($racing)->run($job)['status']);
    self::assertSame('color.accent', $this->publishedToken($uuid));
}

public function testAStaleDraftSavedWhileTheJobRunsStoresTheDestination(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1')]], 1, $lock, 'user00000001');
    self::assertSame('color.accent', $this->draftToken($uuid));
}

public function testAJobForcedToFailMidwayLeavesTheSlotUsableAndResumeCompletes(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftsNaming('color.brand-1', 3);
    [$published] = $this->publishedEntryNaming('color.brand-1');
    $versionsBefore = $this->versionCount($published);
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->runnerWithSourceThatThrowsAfter(1)->run($job);            // a source double that throws on its 2nd persist
    self::assertSame('failed', $this->jobs()->find($job)->status);
    self::assertNotNull($this->palette()->brand(1), 'still configured');
    self::assertSame([], $this->eventsFired(ThemeAppearanceChanged::class));
    $this->service()->resume($job);
    self::assertSame('completed', $this->runner()->run($job)['status']);
    self::assertSame($versionsBefore + 1, $this->versionCount($published), 'one new publication version in total across the failed run and the resume');
}

public function testAnInterruptedJobIsReportedAndResumable(): void
{
    $job = $this->runningJobWithHeartbeat(gmdate('Y-m-d H:i:s', time() - 300));
    self::assertSame('interrupted', self::data($this->paletteController()->job($job))['job']['status']);
    $this->service()->resume($job);
}

public function testCancelStopsARunningWorkerAndItNeverClears(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftsNaming('color.brand-1', 2);
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    // cancel after the first document: a source double that calls cancel() inside its first persist
    $this->runnerWithSourceThatCancelsDuringFirstPersist($job)->run($job);
    self::assertSame('cancelled', $this->jobs()->find($job)->status);
    self::assertNotNull($this->palette()->brand(1));
    self::assertSame(1, $this->countDraftsNaming('color.brand-1'), 'the second document was never written');
}

public function testAWorkerResumedAfterCancelWritesNothingEvenAfterTheSlotWasEditedOrANewJobStarted(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftsNaming('color.brand-1', 2);
    $old = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->service()->cancel($old);
    $this->mutations()->save(['theme_brand_1' => '{"name":"Gold","hex":"#99772e"}'], 'user00000001'); // re-coloured
    $new = $this->service()->start(1, 'color.surface', null, 'user00000001');
    $result = $this->runner()->run($old);                              // the stale worker wakes up
    self::assertSame('cancelled', $result['status']);
    self::assertSame(2, $this->countDraftsNaming('color.brand-1'));
    self::assertNotNull($this->palette()->brand(1));
    self::assertSame('running', $this->jobs()->find($new)->status);
}

public function testRenameRecolourClearAndReplaceOfTheSourceAre409(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->service()->start(1, 'color.accent', null, 'user00000001');
    foreach ([
        fn () => $this->mutations()->save(['theme_brand_1' => '{"name":"Old gold","hex":"#8a6a2a"}'], 'user00000001'),
        fn () => $this->mutations()->save(['theme_brand_1' => '{"name":"Gold","hex":"#99772e"}'], 'user00000001'),
        fn () => $this->mutations()->clear(1, 'user00000001'),
        fn () => $this->service()->start(1, 'color.surface', null, 'user00000001'),
    ] as $i => $attempt) {
        try {
            $attempt();
            self::fail("attempt {$i}");
        } catch (PaletteConflict) {
            self::assertSame('Gold', $this->palette()->brand(1)?->name);
        }
    }
}

public function testOverlappingReplacements(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $first = $this->service()->start(1, 'color.brand-2', null, 'user00000001');
    foreach ([fn () => $this->service()->start(2, 'color.accent', null, 'user00000001'), fn () => $this->mutations()->clear(2, 'user00000001')] as $attempt) {
        try { $attempt(); self::fail('reserved'); } catch (PaletteConflict) {}
    }
    self::assertTrue($this->mutations()->save(['theme_brand_2' => '{"name":"Blush","hex":"#c98a8a"}'], 'user00000001'), 'rename allowed');
    $this->service()->cancel($first);
    $second = $this->service()->start(2, 'color.accent', null, 'user00000001');
    $this->expectException(PaletteConflict::class);
    $this->service()->start(1, 'color.brand-2', null, 'user00000001'); // Brand 2 is now a source
}

public function testTwoRacingStartsForConflictingSlots(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    // start(1 → brand-2) commits while start(2 → accent) is paused after its unlocked read
    $this->state()->afterNextSnapshot(fn () => $this->service()->start(1, 'color.brand-2', null, 'user00000001'));
    $this->expectException(PaletteConflict::class);
    $this->service()->start(2, 'color.accent', null, 'user00000001');
}

public function testThemeAppearanceChangedFiresAfterTheClearingCommit(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $seenInsideTransaction = null;
    $this->container()->get(EventService::class)->listen(ThemeAppearanceChanged::class, function () use (&$seenInsideTransaction): void {
        $seenInsideTransaction = $this->container()->get(Connection::class)->withinTransaction();
    });
    $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000001'));
    self::assertFalse($seenInsideTransaction, 'dispatched after the clearing transaction committed');
    self::assertNull($this->palette()->brand(1));
}

public function testAContrastReferenceArrivingBeforeStartTakesTheLockIsSeenAndRefusesAMappinglessStart(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    // start's unlocked read sees no contrast references; one is saved before start locks
    $this->state()->afterNextSnapshot(fn () => $this->draftNaming('color.brand-1-contrast'));
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->start(1, 'color.surface', null, 'user00000001');
}

public function testAStaleEditorIntroducingAContrastReferenceDuringAMappinglessJobIsRefused(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    $job = $this->service()->start(1, 'color.surface', null, 'user00000001');
    self::assertNull($this->jobs()->find($job)->contrastTo);
    $this->expectException(PaletteRefusal::class);
    $this->repo()->saveDraft($uuid, 'en', ['body' => [$this->heading('color.brand-1-contrast')]], 1, $lock, 'user00000001');
}

public function testAContrastReferenceThatSlipsInIsAFailureNeverAFillColour(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $job = $this->service()->start(1, 'color.surface', null, 'user00000001');
    $uuid = $this->storeDraftRawNaming('color.brand-1-contrast'); // written without the fence, as a pre-palette writer would
    $result = $this->runner()->run($job);
    self::assertSame('failed', $result['status']);
    self::assertSame('color.brand-1-contrast', $this->draftToken($uuid), 'never rewritten to the fill');
    self::assertStringContainsString('arrived after the replacement started', json_encode($this->jobs()->find($job)->failureReport));
    self::assertNotNull($this->palette()->brand(1));
}

public function testAnOldWorkerReachingFailureAfterAnotherCompletedChangesNothing(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->runner()->run($job);                                       // completes: slot cleared
    self::assertSame('completed', $this->jobs()->find($job)->status);
    $this->failUnderLock($job);                                       // what an old worker's exhausted path does
    self::assertSame('completed', $this->jobs()->find($job)->status, 'terminal');
    self::assertSame([], $this->state()->snapshot()->reservedSlots());
    self::assertSame([], $this->state()->snapshot()->activeJobs);
}

public function testAnOldWorkerReachingFailureAfterCancellationChangesNothing(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    $job = $this->service()->start(1, 'color.brand-2', null, 'user00000001');
    $this->service()->cancel($job);
    $this->runnerWhoseSourcesNeverConverge()->run($job);              // would exhaust five passes and fail
    self::assertSame('cancelled', $this->jobs()->find($job)->status);
    self::assertSame([], $this->state()->snapshot()->reservedSlots(), 'Brand 2 is not reserved again');
    $this->mutations()->clear(2, 'user00000001');                     // proves it: a reserved slot could not clear
    self::assertNull($this->palette()->brand(2));
}

public function testResumeEligibilityIsDecidedUnderTheLock(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->runner()->run($job);                                       // completed
    $this->expectException(PaletteConflict::class);
    $this->service()->resume($job);
}

public function testRewrittenPublicationsRenderTheirReplacementEvenWhenTheJobLaterFails(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$a] = $this->publishedEntryNaming('color.brand-1');
    [$b] = $this->publishedEntryNaming('color.brand-1');
    $this->renderPublic($a);                                          // warm the render cache for both
    $this->renderPublic($b);
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $this->runnerWithSourceThatThrowsAfter(1)->run($job);             // rewrites $a's publication, then fails
    self::assertSame('failed', $this->jobs()->find($job)->status);
    self::assertStringContainsString(ClassNames::for('colors.text', 'color.accent'), $this->renderPublic($a), 'purged after its write committed');
}
```

`runnerWith($source)` builds a `PaletteReplaceRunner` by hand, with a `PaletteDocumentSources` whose matching source is replaced by the double; this is the idiom of `tests/Integration/Content/StyleClassJobTest.php` (~242–330). `runnerWithSourceThatThrowsAfter(1)` and `runnerWithSourceThatCancelsDuringFirstPersist($job)` are the same with a throwing or cancelling double. Match the event listener idiom to the one `GeneralSettingsAppearanceTest` uses.

`testTwoRacingStartsForConflictingSlots` depends on `start()` reading an unlocked snapshot first, for the 422 checks. Its fenced re-check under the lock (`within` → `lock()`) then sees the first job and throws `PaletteConflict`.

Helper notes:
- `failUnderLock($job)` runs `PaletteFence::within(fn () => $this->jobs()->transition($job, 'failed'))`, exactly what the runner's exhausted path does.
- `runnerWhoseSourcesNeverConverge()` holds a source double that always reports a reference and always refuses its write.
- `storeDraftRawNaming()` inserts a draft row directly.

**Fresh worker and workspace isolation.** `PaletteReplaceWorkerTest.php` follows `SearchWakeQueueTest::testHandlingTheWakeUpBuildsWhatIsDue`:

```php
public function testAQueuedReplacementRunsFromAFreshWorker(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid] = $this->publishedEntryNaming('color.brand-1');
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $row = $this->lastQueueJobRow();
    self::assertStringContainsString('"workspace"', (string) $row['payload']);
    // a new application, as a worker process boots one: nothing shared with the request that queued it
    $worker = self::bootAppWithConfigOverride('thallo', []);
    (new RunPaletteReplaceJob(['job_id' => $job, 'workspace' => null], $worker))->handle();
    self::assertSame('completed', $this->jobs()->find($job)->status);
    self::assertSame('color.accent', $this->publishedToken($uuid));
}
```

`PaletteReplaceTenancyTest.php` extends `RetrofittedTenantTestCase`, opt-in like every retrofit suite:

```php
public function testAQueuedReplacementRunsInItsOwnWorkspaceOnly(): void
{
    foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
        $this->runAsTenant($tenant, function (): void {
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            $this->container()->get(GeneralSettings::class)->save(['theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}']);
            $this->container()->get(SavedSectionRepository::class)->create('Hero', 'Saved', null, $this->heading('color.brand-1'), null, null);
        });
    }
    $job = $this->runAsTenant(self::$tenantAUuid, fn () => $this->container()->get(PaletteReplaceService::class)->start(1, 'color.accent', null, null));
    // handled with NO tenant context, as a worker starts: the job must enter A itself
    $this->runAsSystem(fn () => (new RunPaletteReplaceJob(['job_id' => $job, 'workspace' => self::$tenantAUuid], $this->appContext()))->handle());
    self::assertSame('color.accent', $this->runAsTenant(self::$tenantAUuid, fn () => $this->sectionToken('Hero')));
    self::assertSame('color.brand-1', $this->runAsTenant(self::$tenantBUuid, fn () => $this->sectionToken('Hero')), 'B untouched');
    self::assertNotNull($this->runAsTenant(self::$tenantBUuid, fn () => $this->container()->get(PaletteProvider::class)->palette()->brand(1)), "B's slot still configured");
}
```

`PaletteApiTest` route rows:

```php
['POST', '/v1/admin/appearance/palette/brand/{slot}/replace', 'content_permission:content.manage'],
['GET', '/v1/admin/appearance/palette/jobs', 'content_permission:content.manage'],
['GET', '/v1/admin/appearance/palette/jobs/{id}', 'content_permission:content.manage'],
['POST', '/v1/admin/appearance/palette/jobs/{id}/cancel', 'content_permission:content.manage'],
['POST', '/v1/admin/appearance/palette/jobs/{id}/resume', 'content_permission:content.manage'],
```

Add a permissions test: users with only `content.edit`, only `templates.manage` and only `styles.manage` are each refused `content.manage` (`allows(...) === false`), i.e. usage, clear and replace are 403 for them.

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteReplaceTest.php tests/Integration/Content/Palette/PaletteReplaceConcurrencyTest.php tests/Integration/Http/PaletteApiTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the three sources**

`RegionSettingsSource`:
- `id()` = `'region_settings'`.
- `each()` yields one `DocumentRef('region_settings', $slug, null, (string) $lockVersion, ContentTypeSchema::fromArray(['fields' => []]), ['settings' => $settings])` per `regions` row.
- `persist()` runs inside `RegionWriteLock::within`: `UPDATE regions SET settings = :json, lock_version = lock_version + 1 WHERE slug = :slug AND lock_version = :rev`, returning `affected === 1`.

`LayoutSettingsSource`:
- the same over `layouts` (non-tombstoned rows), keyed `surface:target`;
- writes `settings` inside `LayoutWriteLock::within($surface, $target)`, with a CAS on `lock_version`.

`StyleClassesSource`:
- `each()` yields `DocumentRef('style_class', $id, null, (string) $version, emptySchema, ['style' => $style])`;
- `persist()` calls `StyleClassRepository::update($id, (int) $ref->revision, ['style' => $fields['style']])` and returns `false` on `StyleClassVersionConflict`. Locked classes (`locked_by_job`) refuse writes; record that as a failure reason so the job retries next pass.

`PaletteDocumentSources::each()` iterates, in order: `EntryDraftsSource`, `PublishedEntriesSource`, `RegionsSource`, `SavedSectionsSource`, `LayoutsSource`, `RegionSettingsSource`, `LayoutSettingsSource`, `StyleClassesSource`. `EntryVersionsSource` is excluded: history is never rewritten.

Kind per source (a `match` on `sourceType`):
- `entry_draft` / `entry_published` → `KIND_ENTRY`;
- `region` / `saved_section` / `layout` → `KIND_SECTION`, since their fields are `{blocks}`;
- `region_settings` / `layout_settings` → `KIND_REGION`, walked with only `settings` present;
- `style_class` → `KIND_CLASS`.

- [ ] **Step 4: Implement the service, runner, queue job and endpoints**

`PaletteReplaceService::start`:

```php
public function start(int $slot, string $to, ?string $contrastTo, ?string $actor): string
{
    $read = $this->state->snapshot();
    $this->assertDestination($read, $slot, $to, 'to');
    if ($contrastTo !== null) {
        $this->assertDestination($read, $slot, $contrastTo, 'contrast_to');
    }
    $workspace = TenantScope::current($this->tenants, $this->context);
    $id = $this->fence->within(function () use ($slot, $to, $contrastTo, $actor, $workspace): string {
        $held = $this->state->lock();
        if (!$held->palette->isConfigured($slot)) {
            throw new PaletteConflict("Brand {$slot} is not configured");
        }
        if ($held->jobReplacing($slot) !== null || in_array($slot, $held->reservedSlots(), true)) {
            throw new PaletteConflict(($held->palette->brand($slot)?->name ?? "Brand {$slot}") . ' is already part of a replacement');
        }
        // the contrast decision is made HERE, under the lock (review point 5)
        $mapping = $contrastTo ?? self::pairOf($to);
        if ($mapping === null && $this->usage->of($slot)['blocking']['contrast_references']) {
            throw new \InvalidArgumentException('contrast_to is required: ' . $to . ' has no text colour of its own');
        }
        foreach (array_filter([$to, $mapping]) as $dest) {
            $d = Palette::slotOf($dest);
            if ($d !== null && (!$held->palette->isConfigured($d) || $held->jobReplacing($d) !== null)) {
                throw new PaletteConflict("Brand {$d} cannot be a destination right now");
            }
        }
        $this->state->bump();
        return $this->jobs->start($slot, $to, $mapping, $actor, $workspace); // $mapping null = no contrast mapping
    });
    $this->db->afterCommit(fn () => $this->queue->push(RunPaletteReplaceJob::class, ['job_id' => $id, 'workspace' => $workspace]));
    return $id;
}

public static function pairOf(string $token): ?string
{
    if ($token === 'color.accent') {
        return 'color.accent-contrast';
    }
    $slot = Palette::slotOf($token);
    return $slot !== null && !Palette::isContrastToken($token) ? "color.brand-{$slot}-contrast" : null;
}
```

`assertDestination` throws `\InvalidArgumentException` for:
- a token not in `Vocabulary::names('color')` (prefixed `color.`);
- `Palette::slotOf($t) === $slot`;
- `Palette::isContrastToken($t)` or `$t === 'color.accent-contrast'`;
- an unconfigured brand slot;
- a slot whose job is active (`$read->jobReplacing`).

`cancel()`: `within` → `lock()` → `transition($id, 'cancelled')`. A false return (already terminal) is a 409. Then `bump()`.

`resume()`: `within` → `lock()` → re-read the job **under the lock**. It is eligible only when `failed`, or `running` with `heartbeat_at` older than 120 s. Then `transition($id, 'running')` → `bump()`, and push `['job_id', 'workspace']` (from the row) after commit. An ineligible job returns 409 and queues nothing.

`RunPaletteReplaceJob::handle(array $data)` resolves `PaletteReplaceRunner` and calls `run($data['job_id'])`, as `RunStyleClassJob` does.

`PaletteController`:
- `replace(ReplaceBrandData $input, int $slot)`: 202 `{job}`; 409 `PaletteConflict`; 422 `InvalidArgumentException`.
- `jobs()`: 200 `{jobs: active()}`.
- `job(string $id)`: 404 when unknown; `status` rendered as `interrupted` for a stale `running` job.
- `cancel` and `resume`.

Register the routes from Step 1.

- [ ] **Step 5: Run the replace suites**

Run: `vendor/bin/phpunit tests/Integration/Content/Palette tests/Integration/Http/PaletteApiTest.php tests/Integration/Content/StyleClassJobTest.php tests/Integration/Content/BlockDocumentSourcesTest.php`
Run (retrofit, opt-in): `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit tests/Integration/Content/Palette/PaletteReplaceTenancyTest.php tests/Integration/Content/Palette/BrandColorUsageTenancyTest.php`
Expected: PASS.

- [ ] **Step 6: Real-database concurrency proofs (two processes, observed waits)**

The snapshot hook proves the re-check logic on one connection. These proofs establish what it cannot:
- that the contender **actually waits** on the intended lock;
- the lock order;
- visibility of another session's commit.

Timing is never the evidence. Every timeout is only a failure bound.

**Protocol.** Each scenario runs **two** actor processes, a *holder* and a *contender*. The test process orchestrates them and observes the database:
1. **Holder:** inside `PaletteFence::within` (or the scenario's own transaction), performs the operation that **acquires** the lock: the palette-row `UPDATE`, or the uncommitted insert for `first-row`. Only **after** that statement returns does it write `held-<scenario>` containing its backend pid (`SELECT pg_backend_pid()`). It then blocks until `release` exists, and commits.
2. **Test:** waits for `held-`, then starts the contender.
3. **Contender:** writes `pid-<scenario>` (its backend pid, read **before** its attempt), then performs the competing operation. When it returns, it writes `outcome-<scenario>` as JSON: `{"result":"ok"|"refused"|"conflict"|"in_use"|"cancelled", "detail":…}`.
4. **Test:** polls `pg_stat_activity` and `pg_locks` until the contender's backend shows a lock wait blocked by the holder's pid:
   ```sql
   SELECT a.wait_event_type, pg_blocking_pids(a.pid) AS blockers
   FROM pg_stat_activity a WHERE a.pid = :contender
   ```
   The condition is `wait_event_type = 'Lock'` and the holder's pid in `blockers`. That is the observed wait; a 20 s bound fails the test.
5. **Test:** asserts the contender has **not** written `outcome-` yet, touches `release`, waits for both processes to exit 0 (bounded), and reads `outcome-`.

`pg_blocking_pids` names exactly whose lock the contender waits on. That makes this a proof of lock order: a contender blocked by some other lock, or not blocked at all, fails the test.

`PaletteConcurrentActorTest.php` is the actor. It is skipped unless `THALLO_PALETTE_ACTOR` (`<scenario>:<role>`) and `THALLO_PALETTE_BARRIER` are set, and it skips truncation like `StyleClassConcurrentWriterTest`:

```php
public function testActor(): void
{
    [$scenario, $role] = explode(':', (string) getenv('THALLO_PALETTE_ACTOR')) + [1 => ''];
    $dir = (string) getenv('THALLO_PALETTE_BARRIER');
    if ($scenario === '' || $dir === '') {
        self::markTestSkipped('launched only by PaletteTwoProcessTest');
    }
    $db = $this->container()->get(Connection::class);
    $pid = (int) ($db->query()->executeRawFirst('SELECT pg_backend_pid() AS pid')['pid'] ?? 0);
    $held = function () use ($dir, $scenario, $pid): void {
        file_put_contents("{$dir}/held-{$scenario}", (string) $pid);   // AFTER the lock is acquired
        $deadline = microtime(true) + 30;
        while (!is_file("{$dir}/release")) {
            usleep(5000);
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('never released');
            }
        }
    };
    if ($role === 'contender') {
        file_put_contents("{$dir}/pid-{$scenario}", (string) $pid);
    }
    $outcome = ['result' => 'ok', 'detail' => null];
    try {
        $outcome['detail'] = match ("{$scenario}:{$role}") {
            'save-clear:holder' => $this->fence()->within(function () use ($held) {
                $this->state()->lock();   // acquired
                $this->repo()->saveDraft($this->env('ENTRY'), 'en', ['body' => [self::heading('color.brand-1')]], 1, (int) $this->env('LOCK'), 'user00000001');
                $held();
            }),
            'save-clear:contender' => $this->mutations()->clear(1, 'user00000001'),
            'clear-save:holder' => $this->fence()->within(function () use ($held) {
                $this->mutations()->clear(1, 'user00000001'); // takes and bumps the row
                $held();
            }),
            'clear-save:contender' => $this->repo()->saveDraft($this->env('ENTRY'), 'en', ['body' => [self::heading('color.brand-1')]], 1, (int) $this->env('LOCK'), 'user00000001'),
            'cancel-worker:holder' => $this->fence()->within(function () use ($held) {
                $this->service()->cancel($this->env('JOB'));
                $held();
            }),
            'cancel-worker:contender' => $this->runner()->run($this->env('JOB')),
            'start-start:holder' => $this->fence()->within(function () use ($held) {
                $this->service()->start(1, 'color.brand-2', null, 'user00000001');
                $held();
            }),
            'start-start:contender' => $this->service()->start(2, 'color.accent', null, 'user00000001'),
            'first-row:holder' => $db->transaction(function () use ($db, $held) {
                $db->table('palette_state')->insert(['site' => 'site', 'generation' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
                $held();                 // uncommitted: the contender's existence check sees no row
            }),
            'first-row:contender' => $db->transaction(function () use ($db): int {
                $this->state()->ensureRow();                        // its INSERT waits on the holder's uncommitted key
                return $db->table('palette_state')->count();        // runs only if the savepoint kept the transaction usable
            }),
        };
    } catch (BrandColorInUse) {
        $outcome['result'] = 'in_use';
    } catch (PaletteRefusal) {
        $outcome['result'] = 'refused';
    } catch (PaletteConflict) {
        $outcome['result'] = 'conflict';
    }
    if ($role === 'contender') {
        file_put_contents("{$dir}/outcome-{$scenario}", json_encode($outcome));
    }
}
```

`env('X')` reads `THALLO_PALETTE_X`. `fence()`, `state()`, `repo()`, `mutations()`, `service()` and `runner()` resolve from the container.

`PaletteTwoProcessTest.php` orchestrates. Its `race(string $scenario, array $env): array` does the following:
1. Launches the holder with `proc_open` (as `StyleClassRepositoryTest:132` does) and waits for `held-` (bound 30 s).
2. Launches the contender and waits for `pid-`.
3. Polls the query above until the contender is `Lock`-waiting with the holder's pid among its blockers (bound 20 s, else fail with both processes' output).
4. Asserts `outcome-` does not exist yet, touches `release`, and waits for both processes to exit 0 (bound 30 s).
5. Returns the decoded outcome.

```php
public function testASaveHoldingThePaletteRowMakesAClearWaitThenRefuse(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    self::assertSame('in_use', $this->race('save-clear', ['ENTRY' => $uuid, 'LOCK' => (string) $lock])['result']);
    self::assertNotNull($this->palette()->brand(1));
}

public function testAClearHoldingThePaletteRowMakesASaveWaitThenRefuseTheFreshReference(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    [$uuid, $lock] = $this->entry();
    self::assertSame('refused', $this->race('clear-save', ['ENTRY' => $uuid, 'LOCK' => (string) $lock])['result']);
    self::assertSame(0, $this->countDraftsNaming('color.brand-1'));
}

public function testACancelHoldingTheRowStopsAWorkerBeforeItsFirstWrite(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->draftsNaming('color.brand-1', 3);
    $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
    $outcome = $this->race('cancel-worker', ['JOB' => $job]);
    self::assertSame('cancelled', $outcome['detail']['status']);
    self::assertSame(3, $this->countDraftsNaming('color.brand-1'), 'nothing written after the cancel');
    self::assertNotNull($this->palette()->brand(1));
}

public function testConflictingStartsInTwoSessions(): void
{
    $this->configure(1, 'Gold', '#8a6a2a');
    $this->configure(2, 'Rose', '#c98a8a');
    self::assertSame('conflict', $this->race('start-start', [])['result']);
    self::assertCount(1, $this->jobs()->active());
}

public function testTheFirstRowRaceExercisesTheDuplicateInsertAndKeepsTheLosersTransactionUsable(): void
{
    $this->connection()->table('palette_state')->delete();
    $outcome = $this->race('first-row', []);
    // the observed wait in race() is the contender's INSERT blocked on the holder's uncommitted key —
    // ensureRow's existence check cannot block — so the duplicate-insert path was taken
    self::assertSame(['result' => 'ok', 'detail' => 1], $outcome, 'the savepoint absorbed the duplicate; the count ran');
    self::assertSame(1, $this->connection()->table('palette_state')->count());
}
```

For `cancel-worker`, the observed wait is the runner's first fenced write, blocked on the holder's palette row. The runner enumerates without locks, so the wait can only be that write. The holder is resolved before the job is cancelled, so the worker's first write sees `isActive` false.

Clean the barrier directory in `setUp` and `tearDown`. Put this file in its own `INTEGRATION_SHARD_*` slot after timing it.

Run: `vendor/bin/phpunit tests/Integration/Content/Palette/PaletteTwoProcessTest.php`
Expected: PASS. Each scenario's contender is observed lock-waiting on the holder's pid before release.

- [ ] **Step 7: Re-run Task 12's restore proof against the real job**

In `DraftRestoreTest`, replace the `replaceAndClear` helper body with `start` + `run`. Run: `vendor/bin/phpunit tests/Integration/Content/Palette/DraftRestoreTest.php`. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add core/src core/routes tests .github/workflows
git commit -m "feat(palette): Replace — a resumable, fenced job that rewrites every current document, appends publication versions, honours reservations and cancellation, then clears"
```

---
### Task 15: Appearance — Custom neutral, dark base, brand colours, contrast checks

**Files:**
- Create: `admin/src/queries/palette.ts` (usage, clear, replace, jobs, job, cancel, resume, preview)
- Create: `admin/src/pages/appearance/components/HexInput.vue` (a swatch plus a text input, normalised with `normalizeHex` from `@/style/contrast`)
- Create: `admin/src/pages/appearance/components/CustomNeutralFields.vue`
- Create: `admin/src/pages/appearance/components/BrandColorsField.vue`
- Create: `admin/src/pages/appearance/components/ContrastChecks.vue`
- Modify: `admin/src/pages/appearance/index.vue` (form keys, Neutral select gains Custom, the three components, `pendingLook.palette`, style-schema invalidation on save)
- Modify: `admin/src/pages/appearance/components/AppearancePreview.vue` (`PendingLook.palette?`)
- Modify: `admin/src/queries/generalSettings.ts` (the five keys on `GeneralSettings`; `onSettled` also invalidates `qk.styleSchema()`)
- Test: `admin/src/__tests__/appearance-palette.spec.ts`, `admin/src/__tests__/appearancePage.spec.ts`

**Interfaces:**
- Consumes:
  - general settings keys `theme_neutral_custom`, `theme_dark_base`, `theme_brand_1..3` (JSON strings);
  - `GET /render/style-schema` (`palette`);
  - `POST /appearance/palette/preview` → `{rows, swatches, values}`;
  - `useAppearanceChanges` (broadcast `'appearance'` after save).
- Produces:
  - `palette.ts`: `fetchPaletteUsage(slot: 1|2|3): Promise<PaletteUsage>`, `clearBrand(slot): Promise<void>`, `replaceBrand(slot, to: string, contrastTo?: string): Promise<PaletteJob>`, `fetchPaletteJobs(): Promise<PaletteJob[]>`, `fetchPaletteJob(id)`, `cancelPaletteJob(id)`, `resumePaletteJob(id)`, `previewPalette(look: PaletteLook): Promise<PalettePreview>`, plus the types `PaletteUsage`, `PaletteJob`, `PaletteLook`, `PalettePreview`, `ContrastRow`;
  - `PendingLook.palette?: { neutral_custom: Record<NeutralKey,string> | null; dark_base: string | null; brands: Record<'1'|'2'|'3', {name:string;hex:string} | null> }`.

**Form behaviour (spec §2.1, §5.1).**
- **Neutral select:** gains `custom` ("Custom — your own colours").
- **Choosing Custom with `theme_neutral_custom` empty:** pre-fills the six fields from the family the form had. The family hexes come from the style schema's `palette.swatches` for that family, so a family-swatch table is needed: add `GET`-free data by asking the preview endpoint with `theme_neutral: <family>` and copying `values.light`. The dark base is pre-filled with that family too.
- **Switching back to a family:** keeps the custom values in the form, and they are saved unchanged.
- **Reset to <family>:** sends `theme_neutral: <family>`, `theme_neutral_custom: ''`.
- **Dark mode base select:** hidden when `color_mode_enabled` is false. Read it from general settings if exposed; otherwise from `GET /render/style-schema`. Add `color_mode: boolean` to the style schema response in Task 6's controller if it is not already reachable. Its stored value is always sent unchanged.
- **Brand colours:** three rows. Each row has a name input (max 32), a `HexInput`, and either **Add** (unset) or **Clear** (configured).
  - A row whose slot `state === 'replacing'` shows the job's progress line in place of its inputs. A reserved row shows "Reserved by the <name> replacement" and no Clear.
  - Saving an unset slot sends nothing for it.
  - Clear opens Task 17's dialog; this task renders the button and emits `clear(slot)`.
- **Contrast checks:** below, debounced 600 ms on the pending look. Each row shows "Text on Background (light) — 12.6:1 ✓", or a warning in `text-warning` below 4.5. The caption reads "These pairs are checked; other combinations a block can make are not."

- [ ] **Step 1: Write the failing specs**

`admin/src/__tests__/appearance-palette.spec.ts` (mount `index.vue` with the general-settings, style-schema and preview fetches mocked, as `appearancePage.spec.ts` does):

```ts
import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountAppearance, mockPreview, savedPayload } from './helpers/appearance'

describe('Appearance › Theme colors › palette', () => {
  it('pre-fills Custom from the current family the first time, and keeps the values across a family switch', async () => {
    mockPreview({ light: { background: '#ffffff', surface: '#f6f7f9', 'surface-2': '#eef0f4', text: '#0f172a', muted: '#64748b', line: '#e2e8f0' } })
    const w = await mountAppearance({ theme_neutral: 'slate', theme_neutral_custom: '' })
    await w.find('[data-test="theme-neutral"]').setValue('custom')
    await flushPromises()
    expect((w.find('[data-test="neutral-custom-bg"] input').element as HTMLInputElement).value).toBe('#ffffff')
    expect((w.find('[data-test="theme-dark-base"]').element as HTMLSelectElement).value).toBe('slate')
    await w.find('[data-test="neutral-custom-bg"] input').setValue('#F8F4EC')
    await w.find('[data-test="theme-neutral"]').setValue('stone')
    await w.find('[data-test="theme-neutral"]').setValue('custom')
    expect((w.find('[data-test="neutral-custom-bg"] input').element as HTMLInputElement).value).toBe('#f8f4ec')
  })

  it('does not pre-fill again when Custom values are stored', async () => {
    const stored = JSON.stringify({ bg: '#f8f4ec', surface: '#ffffff', surface_2: '#efe7d8', ink: '#1b1712', muted: '#6b6156', line: '#e2d8c6' })
    const preview = mockPreview({})
    const w = await mountAppearance({ theme_neutral: 'stone', theme_neutral_custom: stored })
    await w.find('[data-test="theme-neutral"]').setValue('custom')
    expect((w.find('[data-test="neutral-custom-bg"] input').element as HTMLInputElement).value).toBe('#f8f4ec')
    expect(preview).not.toHaveBeenCalledWith(expect.objectContaining({ theme_neutral: 'stone' }))
  })

  it('Reset sends an empty custom value with a family', async () => {
    const w = await mountAppearance({ theme_neutral: 'custom', theme_neutral_custom: '{"bg":"#f8f4ec","surface":"#ffffff","surface_2":"#efe7d8","ink":"#1b1712","muted":"#6b6156","line":"#e2d8c6"}' })
    await w.find('[data-test="neutral-reset"]').trigger('click')
    await w.find('[data-test="appearance-save"]').trigger('click')
    expect(savedPayload()).toMatchObject({ theme_neutral: 'slate', theme_neutral_custom: '' })
  })

  it('hides the dark base while colour mode is off but keeps sending it unchanged', async () => {
    const w = await mountAppearance({ theme_neutral: 'custom', theme_dark_base: 'stone' }, { colorMode: false })
    expect(w.find('[data-test="theme-dark-base"]').exists()).toBe(false)
  })

  it('saves a brand slot as JSON and shows Clear only for a configured slot', async () => {
    const w = await mountAppearance({})
    await w.find('[data-test="brand-1-name"]').setValue('Gold dark')
    await w.find('[data-test="brand-1-hex"] input').setValue('#8A6A2A')
    await w.find('[data-test="appearance-save"]').trigger('click')
    expect(JSON.parse(savedPayload().theme_brand_1)).toEqual({ name: 'Gold dark', hex: '#8a6a2a' })
    expect(savedPayload().theme_brand_2).toBeUndefined()
  })

  it('shows a replacing slot as progress and a reserved slot without Clear', async () => {
    const w = await mountAppearance({ theme_brand_1: '{"name":"Gold","hex":"#8a6a2a"}', theme_brand_2: '{"name":"Rose","hex":"#c98a8a"}' }, {
      schemaPalette: { 'brand-1': { state: 'replacing', replacing: { to: 'color.brand-2', to_label: 'Rose' } }, 'brand-2': { state: 'configured', reserved: true } },
      jobs: [{ id: 'job1', slot: 1, status: 'running', work_items_done: 2, work_items_total: 5 }],
    })
    expect(w.find('[data-test="brand-1-progress"]').text()).toContain('Replacing Gold with Rose — 2 of 5')
    expect(w.find('[data-test="brand-2-clear"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-2-reserved"]').text()).toContain('Reserved by the Gold replacement')
  })

  it('renders contrast rows from the preview endpoint, warning below 4.5', async () => {
    mockPreview({}, [{ fg: 'muted', on: 'background', mode: 'dark', ratio: 3.1, passes: false }, { fg: 'text', on: 'background', mode: 'light', ratio: 12.6, passes: true }])
    const w = await mountAppearance({})
    await flushPromises()
    expect(w.find('[data-test="contrast-row-dark-muted-background"]').classes()).toContain('text-warning')
    expect(w.text()).toContain('These pairs are checked')
  })

  it('carries the unsaved palette into the preview look', async () => {
    const w = await mountAppearance({})
    await w.find('[data-test="brand-1-name"]').setValue('Gold')
    await w.find('[data-test="brand-1-hex"] input').setValue('#8a6a2a')
    expect(w.findComponent({ name: 'AppearancePreview' }).props('look').palette.brands['1']).toEqual({ name: 'Gold', hex: '#8a6a2a' })
  })

  it('invalidates the style schema after a save', async () => {
    const invalidate = vi.fn()
    const w = await mountAppearance({}, { onInvalidate: invalidate })
    await w.find('[data-test="brand-1-name"]').setValue('Gold')
    await w.find('[data-test="brand-1-hex"] input').setValue('#8a6a2a')
    await w.find('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(invalidate).toHaveBeenCalledWith(expect.objectContaining({ key: ['style-schema'] }))
  })
})
```

`helpers/appearance.ts` collects the mount and mocks that `appearancePage.spec.ts` already builds inline. Extract them there (no behaviour change to that spec), then add `mockPreview`, `savedPayload` and the `schemaPalette`, `jobs`, `colorMode` and `onInvalidate` options.

- [ ] **Step 2: Run them to see them fail**

Run: `cd admin && pnpm vitest run src/__tests__/appearance-palette.spec.ts`
Expected: FAIL. The data-test elements don't exist yet.

- [ ] **Step 3: Implement**

`palette.ts`, following `admin/src/queries/generalSettings.ts`'s `client` usage:

```ts
import { client } from '@/api/client'

export type NeutralKey = 'bg' | 'surface' | 'surface_2' | 'ink' | 'muted' | 'line'
export interface ContrastRow { fg: string; on: string; mode: 'light' | 'dark'; ratio: number; passes: boolean }
export interface PaletteLook {
  theme_accent?: string
  theme_neutral?: string
  theme_background?: string
  palette: { neutral_custom: Record<NeutralKey, string> | null; dark_base: string | null; brands: Record<'1' | '2' | '3', { name: string; hex: string } | null> }
}
export interface PalettePreview { rows: ContrastRow[]; swatches: Record<string, string>; values: { light: Record<string, string>; dark: Record<string, string> } }
export interface PaletteUsageGroup { entries: Array<{ uuid: string; title: string; locale: string; draft?: boolean; published?: boolean; versions?: number }>; total: number }
export interface PaletteUsage {
  slot: number
  blocking: PaletteUsageGroup & { regions: string[]; layouts: Array<{ id: string; name: string }>; saved_sections: Array<{ id: string; name: string }>; style_classes: Array<{ id: string; name: string }> }
  historical: PaletteUsageGroup
}
export interface PaletteJob {
  id: string; slot: number; to: string; contrast_to: string
  status: 'running' | 'interrupted' | 'failed' | 'completed' | 'cancelled'
  passes: number; work_items_total: number; work_items_done: number; work_items_failed: number
  failure_report: Array<{ source: string; id: string; locale: string | null; reason: string }>
}

const base = '/appearance/palette'
export async function previewPalette(look: PaletteLook): Promise<PalettePreview> {
  const { data, error } = await client.POST(`${base}/preview` as never, { body: look as never })
  if (error) throw error
  return (data as { data: PalettePreview }).data
}
export async function fetchPaletteUsage(slot: 1 | 2 | 3): Promise<PaletteUsage> {
  const { data, error } = await client.GET(`${base}/brand/{slot}/usage` as never, { params: { path: { slot } } } as never)
  if (error) throw error
  return (data as { data: { usage: PaletteUsage } }).data.usage
}
export class PaletteInUse extends Error {
  constructor(public readonly usage: PaletteUsage) { super('in use') }
}
export class PaletteConflict extends Error {}

export async function clearBrand(slot: 1 | 2 | 3): Promise<void> {
  const { error, response } = await client.DELETE(`${base}/brand/{slot}` as never, { params: { path: { slot } } } as never)
  if (!error) return
  const body = (error as { data?: { usage?: PaletteUsage; conflict?: string } }).data ?? {}
  if (response.status === 409 && body.usage) throw new PaletteInUse(body.usage)
  if (response.status === 409) throw new PaletteConflict(body.conflict ?? 'conflict')
  throw error
}
export async function replaceBrand(slot: 1 | 2 | 3, to: string, contrastTo?: string): Promise<PaletteJob> {
  const { data, error, response } = await client.POST(`${base}/brand/{slot}/replace` as never, {
    params: { path: { slot } },
    body: contrastTo === undefined ? { to } : { to, contrast_to: contrastTo },
  } as never)
  if (error) {
    if (response.status === 409) throw new PaletteConflict(String((error as { message?: string }).message ?? 'conflict'))
    throw error
  }
  return (data as { data: { job: PaletteJob } }).data.job
}
export async function fetchPaletteJobs(): Promise<PaletteJob[]> {
  const { data, error } = await client.GET(`${base}/jobs` as never)
  if (error) throw error
  return (data as { data: { jobs: PaletteJob[] } }).data.jobs
}
export async function fetchPaletteJob(id: string): Promise<PaletteJob> {
  const { data, error } = await client.GET(`${base}/jobs/{id}` as never, { params: { path: { id } } } as never)
  if (error) throw error
  return (data as { data: { job: PaletteJob } }).data.job
}
export async function cancelPaletteJob(id: string): Promise<PaletteJob> {
  const { data, error } = await client.POST(`${base}/jobs/{id}/cancel` as never, { params: { path: { id } } } as never)
  if (error) throw error
  return (data as { data: { job: PaletteJob } }).data.job
}
export async function resumePaletteJob(id: string): Promise<PaletteJob> {
  const { data, error } = await client.POST(`${base}/jobs/{id}/resume` as never, { params: { path: { id } } } as never)
  if (error) throw error
  return (data as { data: { job: PaletteJob } }).data.job
}
```

Check how `client` reports the status on an error (`response.status`) in `admin/src/api/client.ts`, and match the error-body shape the other query modules read. Once Task 19's OpenAPI splice lands, replace the `as never` casts with the generated `schema.d.ts` paths.

`CustomNeutralFields.vue`: props `modelValue: Record<NeutralKey,string>`, `darkBase: string`, `showDarkBase: boolean`, `family: string`; emits `update:modelValue`, `update:darkBase` and `reset`. It renders six `UFormField`s, labelled Background, Surface, Surface 2, Text, Muted and Line, each wrapping `<HexInput :data-test="`neutral-custom-${key}`">`, plus a `USelect data-test="theme-dark-base"` of the five families when `showDarkBase`, and `UButton data-test="neutral-reset"` "Reset to {family}".

`BrandColorsField.vue`: props `slots` (the three parsed slots), `palette` (the schema's `palette.slots`) and `jobs: PaletteJob[]`; emits `update:slots` and `clear(slot)`. It shows the rows described above, with test ids `brand-{n}-name`, `brand-{n}-hex`, `brand-{n}-clear`, `brand-{n}-progress` and `brand-{n}-reserved`. It refetches jobs every 2 s while one is running, and stops on completion or cancellation.

`ContrastChecks.vue`: prop `look: PaletteLook`. It watches `look` (deep), debounces 600 ms, calls `previewPalette`, and renders rows with `data-test="contrast-row-${mode}-${fg}-${on}"`. Failing rows get `text-warning`. Labels come from a local map matching `StyleSchemaController::LABELS` plus brand names.

`index.vue`:
- add the five keys to `useSettingsForm(...)` defaults (`''`);
- parse `theme_neutral_custom` and `theme_brand_*` into reactive objects, and serialise them back on save. Send a brand key only when it changed and is non-empty;
- add `custom` to `neutralItems`;
- build `pendingLook.palette` from the form;
- render the three components inside `theme-colors-card`.

`generalSettings.ts` `onSettled`: also `queryCache.invalidateQueries({ key: qk.styleSchema() })` (use the cache API the file already uses for its own key).

- [ ] **Step 4: Run them and the gates**

Run: `cd admin && pnpm vitest run src/__tests__/appearance-palette.spec.ts src/__tests__/appearancePage.spec.ts src/__tests__/brand-color-field.spec.ts && pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/queries/palette.ts src/queries/generalSettings.ts src/pages/appearance src/__tests__/appearance-palette.spec.ts src/__tests__/helpers/appearance.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src
git commit -m "feat(admin): Appearance gains a Custom neutral, a dark-mode base, three brand colours and contrast checks of the effective palette"
```

---

### Task 16: Colour pickers show swatches and names

**Files:**
- Modify: `admin/src/editor/inspector/controls/TokenScaleControl.vue` (colour domain: swatches, labels, hidden slots, the unavailable notice)
- Modify: `admin/src/queries/styleSchema.ts` (`StyleSchemaResult.palette`)
- Modify: `admin/src/editor/inspector/controls/ResponsiveField.vue`, `BoxField.vue`, `admin/src/editor/inspector/LayoutTab.vue`, `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue`, `admin/src/fields/components/TokenField.vue` (pass `palette`, handle `clear`)
- Modify: `admin/src/editor/inspector/StyleTab.vue` and `BlockInspector.vue` (a `scopedSkin: boolean` prop: the block or an ancestor is a `style` block with `data.accent` or `data.neutral` set, via `parentOfBlockById`)
- Modify: `admin/src/__tests__/helpers/classEditorSchema.ts` (fixture gains `palette`)
- Test: `admin/src/__tests__/color-token-picker.spec.ts`, `admin/src/__tests__/tokenField.spec.ts`

**Interfaces:**
- Consumes: `StyleSchemaResult.palette = { slots: Record<'brand-1'|'brand-2'|'brand-3', PaletteSlot>, swatches: Record<string,string>, labels: Record<string,string> }`, where `PaletteSlot = { name: string|null; hex: string|null; state: 'unset'|'configured'|'replacing'; reserved: boolean; replacing: null | { to; to_label; contrast_to; contrast_to_label } }`.
- Produces:
  - `TokenScaleControl` new optional props `palette?: StyleSchemaResult['palette']` and `scopedSkin?: boolean`;
  - new emit `clear: []`.

**Rules (spec §5.2).** For `domain === 'color'` with a palette:
- **Each button:** a 12px swatch, then the label (`palette.labels[token]`). The swatch is `palette.swatches[token]`; brand tokens use `slots['brand-N'].hex`; contrast tokens use a black or white disc, according to which the server picked. Read that from `labels`? No: use the slot's hex contrast via `contrast()` from `@/style/contrast` against `#000`/`#fff`, the same rule as the server's light mode. `transparent` uses a checkerboard.
- **Hidden from new choices:** `brand-N` and `brand-N-contrast` whose slot state is `unset` or `replacing`. Reserved slots stay.
- **Current value whose slot is `unset`:** a disabled first row reading **Unavailable colour: Brand N**, then "No colour applied", then **Choose another** (focuses the first swatch) and **Clear** (emits `clear`).
- **Current value whose slot is `replacing`:** its swatch, with "being replaced by {to_label}" from the response.
- **`scopedSkin`:** each swatch gets a small "site default" caption (one caption for the row, `data-test="swatch-site-default"`). Brand swatches are exempt.

- [ ] **Step 1: Write the failing spec**

```ts
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TokenScaleControl from '@/editor/inspector/controls/TokenScaleControl.vue'
import { paletteFixture } from './helpers/classEditorSchema'

const names = ['background', 'surface', 'accent', 'accent-contrast', 'transparent', 'brand-1', 'brand-1-contrast', 'brand-2', 'brand-2-contrast', 'brand-3', 'brand-3-contrast']

function picker(props: Record<string, unknown> = {}) {
  return mount(TokenScaleControl, { props: { domain: 'color', names, values: {}, modelValue: null, palette: paletteFixture(), ...props } })
}

describe('colour token picker', () => {
  it('shows swatches and author names', () => {
    const w = picker()
    const gold = w.find('[data-test="token-color.brand-1"]')
    expect(gold.text()).toContain('Gold dark')
    expect(gold.find('[data-test="swatch"]').attributes('style')).toContain('#8a6a2a')
    expect(w.find('[data-test="token-color.brand-1-contrast"]').text()).toContain('Gold dark — text')
    expect(w.find('[data-test="token-color.surface"] [data-test="swatch"]').attributes('style')).toContain('#f6f7f9')
  })

  it('hides unset and replacing slots from new choices but keeps reserved ones', () => {
    const w = picker({ palette: paletteFixture({ 'brand-2': { state: 'replacing' }, 'brand-3': { state: 'unset' }, 'brand-1': { reserved: true } }) })
    expect(w.find('[data-test="token-color.brand-2"]').exists()).toBe(false)
    expect(w.find('[data-test="token-color.brand-3"]').exists()).toBe(false)
    expect(w.find('[data-test="token-color.brand-1"]').exists()).toBe(true)
  })

  it('shows a stored reference to an unset slot as unavailable, with choose-another and clear', async () => {
    const w = picker({ modelValue: 'color.brand-3', palette: paletteFixture({ 'brand-3': { state: 'unset' } }) })
    const notice = w.find('[data-test="unavailable-colour"]')
    expect(notice.text()).toContain('Unavailable colour: Brand 3')
    expect(notice.text()).toContain('No colour applied')
    await notice.find('[data-test="unavailable-clear"]').trigger('click')
    expect(w.emitted('clear')).toHaveLength(1)
  })

  it('labels a slot being replaced with its destination', () => {
    const w = picker({ modelValue: 'color.brand-2', palette: paletteFixture({ 'brand-2': { state: 'replacing', replacing: { to: 'color.accent', to_label: 'Accent', contrast_to: 'color.accent-contrast', contrast_to_label: 'Accent — text' } } }) })
    expect(w.find('[data-test="replacing-colour"]').text()).toContain('being replaced by Accent')
  })

  it('captions swatches site default inside a scoped skin', () => {
    expect(picker({ scopedSkin: true }).find('[data-test="swatch-site-default"]').exists()).toBe(true)
    expect(picker().find('[data-test="swatch-site-default"]').exists()).toBe(false)
  })

  it('keeps the plain segmented control for other domains', () => {
    const w = mount(TokenScaleControl, { props: { domain: 'spacing', names: ['sm', 'lg'], values: {}, modelValue: null, palette: paletteFixture() } })
    expect(w.find('[data-test="swatch"]').exists()).toBe(false)
  })
})
```

`paletteFixture(overrides)` lives in `helpers/classEditorSchema.ts`. Its defaults:
- brand-1 configured "Gold dark" `#8a6a2a`;
- brand-2 configured "Rose" `#c98a8a`;
- brand-3 configured "Ink" `#111111`;
- swatches for the neutral tokens: `color.surface` = `#f6f7f9`;
- labels as the server builds them.

Each override merges into one slot.

Append to `tokenField.spec.ts`: a token content field (`domain: 'color'`) storing `color.brand-3` with slot 3 unset shows the unavailable notice, and Clear sets the field to `null`.

- [ ] **Step 2: Run it to see it fail**

Run: `cd admin && pnpm vitest run src/__tests__/color-token-picker.spec.ts src/__tests__/tokenField.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement**

In `TokenScaleControl.vue`, add a `colour` computed (true when `domain === 'color' && palette`), then:

```ts
const slotOf = (token: string): number | null => {
  const m = /^color\.brand-([123])(?:-contrast)?$/.exec(token)
  return m ? Number(m[1]) : null
}
const slot = (n: number) => props.palette!.slots[`brand-${n}` as 'brand-1']
const visible = computed(() =>
  items.value.filter((item) => {
    if (!colour.value) return true
    const n = slotOf(item.token)
    return n === null || slot(n).state === 'configured'
  }),
)
const unavailable = computed(() => {
  const n = props.modelValue && colour.value ? slotOf(props.modelValue) : null
  return n !== null && slot(n).state === 'unset' ? n : null
})
const replacing = computed(() => {
  const n = props.modelValue && colour.value ? slotOf(props.modelValue) : null
  return n !== null && slot(n).state === 'replacing' ? slot(n).replacing : null
})
function swatchOf(token: string): string | null {
  const n = slotOf(token)
  if (n === null) return props.palette!.swatches[token] ?? null
  const hex = slot(n).hex
  if (hex === null) return null
  if (!token.endsWith('-contrast')) return hex
  return contrast(hex, '#000000') > contrast(hex, '#ffffff') ? '#000000' : '#ffffff'
}
const labelOf = (token: string, name: string) => (colour.value ? (props.palette!.labels[token] ?? name) : name)
```

The template renders:
- the unavailable notice (`data-test="unavailable-colour"`, with `unavailable-choose` and `unavailable-clear`) when `unavailable !== null`;
- the replacing line (`data-test="replacing-colour"`) when set;
- then `visible` buttons. Each button gets `<span data-test="swatch" class="inline-block size-3 rounded-full ring-1 ring-default" :style="{ background: swatchOf(item.token) ?? 'repeating-conic-gradient(#ccc 0 25%, #fff 0 50%) 0 0/6px 6px' }" />` before the label when `colour`;
- the `site default` caption when `scopedSkin`.

Callers:
- pass `:palette="schema.palette"` (or `vocabulary` plus a new `palette` prop where only the vocabulary is threaded);
- map `@clear` to the existing `clear()` (`ResponsiveField` emits `set(path, bp, null)`) or to the field's null-set (`TokenField`).

`BlockInspector.vue` computes `scopedSkin`: walk `parentOfBlockById` from the block. True when the block itself, or any ancestor, has `type === 'style'` and a non-empty `data.accent` or `data.neutral`. Pass it through `StyleTab` to `ResponsiveField` to `TokenScaleControl`.

- [ ] **Step 4: Run them and the gates**

Run: `cd admin && pnpm vitest run src/__tests__/color-token-picker.spec.ts src/__tests__/tokenField.spec.ts src/__tests__/responsive-field.spec.ts src/__tests__/style-tab-hover.spec.ts src/__tests__/style-class-editor-output.spec.ts src/__tests__/region-style-editor.spec.ts src/__tests__/block-inspector.spec.ts && pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/editor/inspector src/fields/components/TokenField.vue src/pages/settings/style-classes/components/StyleClassEditor.vue src/queries/styleSchema.ts src/__tests__/color-token-picker.spec.ts src/__tests__/helpers/classEditorSchema.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src
git commit -m "feat(admin): colour pickers show swatches and the author's names, hide unset and replacing slots, and say when a stored colour is unavailable"
```

---

### Task 17: The Clear and Replace dialog, and job progress

**Files:**
- Create: `admin/src/pages/appearance/components/ClearBrandDialog.vue`
- Modify: `admin/src/pages/appearance/index.vue` (open the dialog from `BrandColorsField`'s `clear`; refresh the schema, settings and jobs after)
- Test: `admin/src/__tests__/clear-brand-dialog.spec.ts`

**Interfaces:**
- Consumes: `fetchPaletteUsage`, `clearBrand`, `replaceBrand`, `cancelPaletteJob`, `resumePaletteJob`, `previewPalette`, and the style schema's `palette` and `vocabulary.domains.color`.
- Produces: `<ClearBrandDialog :slot :name :palette :look @done>`.

**Dialog flow (spec §4).**
1. Open: fetch usage.
2. **`blocking.total === 0`:** "Gold dark isn't used on any current page." Then **Clear** and **Cancel**. Clear calls `clearBrand`, then `done`. When `historical.total > 0`, the dialog adds "12 older versions also use Gold dark; restoring one shows it as an unavailable colour."
3. **`blocking.total > 0`:** list the blocking groups with counts and the first five titles each, the historical line, then **Replace with…** (a swatch picker) and **Cancel**. There is no Clear.
4. **Replace with… offers** the configured colour tokens except the slot, its contrast, unset or replacing slots and every contrast token. It reuses `TokenScaleControl` with a filtered `names` list.
5. **The destination has no pair** (anything but `accent` or a brand slot) **and `usage.blocking.contrast_references` is true:** a second required picker, "Text on Gold dark becomes…", with the same exclusions. Below it, each mode's ratio of the chosen pair (`previewPalette(look).values[mode]` for both tokens, run through `contrast()`), with a warning under 4.5.
6. **Confirm:** calls `replaceBrand(slot, to, contrastTo)`. The dialog closes with `done`, and the row shows progress (Task 15).
7. **A 409 conflict** shows the server's message inline.

- [ ] **Step 1: Write the failing spec**

```ts
describe('Clear brand dialog', () => {
  it('clears an unused colour, mentioning history', async () => {
    mockUsage({ blocking: { total: 0 }, historical: { total: 12 } })
    const clear = mockClear()
    const w = await mountDialog({ slot: 1, name: 'Gold dark' })
    expect(w.text()).toContain('12 older versions also use Gold dark')
    await w.find('[data-test="clear-confirm"]').trigger('click')
    expect(clear).toHaveBeenCalledWith(1)
    expect(w.emitted('done')).toHaveLength(1)
  })

  it('offers only Replace when the colour is in use, excluding forbidden destinations', async () => {
    mockUsage({ blocking: { total: 3, entries: [{ uuid: 'e1', title: 'Home', locale: 'en', draft: true }], contrast_references: false }, historical: { total: 0 } })
    const w = await mountDialog({ slot: 1, name: 'Gold dark', palette: paletteFixture({ 'brand-3': { state: 'unset' } }) })
    expect(w.find('[data-test="clear-confirm"]').exists()).toBe(false)
    expect(w.text()).toContain('Home')
    for (const t of ['color.brand-1', 'color.brand-1-contrast', 'color.brand-3', 'color.accent-contrast', 'color.brand-2-contrast']) {
      expect(w.find(`[data-test="token-${t}"]`).exists()).toBe(false)
    }
    expect(w.find('[data-test="token-color.accent"]').exists()).toBe(true)
  })

  it('requires a contrast destination for a destination with no pair when contrast references exist, with both-mode ratios', async () => {
    mockUsage({ blocking: { total: 1, contrast_references: true }, historical: { total: 0 } })
    mockPreview({ light: { surface: '#f6f7f9', text: '#0f172a' }, dark: { surface: '#111a2e', text: '#e2e8f0' } })
    const replace = mockReplace()
    const w = await mountDialog({ slot: 1, name: 'Gold dark' })
    await w.find('[data-test="token-color.surface"]').trigger('click')
    expect(w.find('[data-test="replace-confirm"]').attributes('disabled')).toBeDefined()
    await w.find('[data-test="contrast-to"] [data-test="token-color.text"]').trigger('click')
    expect(w.find('[data-test="contrast-ratio-light"]').text()).toMatch(/\d+(\.\d+)?:1/)
    expect(w.find('[data-test="contrast-ratio-dark"]').exists()).toBe(true)
    await w.find('[data-test="replace-confirm"]').trigger('click')
    expect(replace).toHaveBeenCalledWith(1, 'color.surface', 'color.text')
  })

  it('maps contrast automatically for a destination with a pair', async () => {
    mockUsage({ blocking: { total: 1, contrast_references: true }, historical: { total: 0 } })
    const replace = mockReplace()
    const w = await mountDialog({ slot: 1, name: 'Gold dark' })
    await w.find('[data-test="token-color.accent"]').trigger('click')
    expect(w.find('[data-test="contrast-to"]').exists()).toBe(false)
    await w.find('[data-test="replace-confirm"]').trigger('click')
    expect(replace).toHaveBeenCalledWith(1, 'color.accent', undefined)
  })

  it('shows a conflict inline', async () => {
    mockUsage({ blocking: { total: 0 }, historical: { total: 0 } })
    mockClear(409, { conflict: 'Gold dark is part of a running replacement' })
    const w = await mountDialog({ slot: 1, name: 'Gold dark' })
    await w.find('[data-test="clear-confirm"]').trigger('click')
    expect(w.text()).toContain('part of a running replacement')
  })
})
```

The mocks follow the `vi.mock('@/queries/palette', ...)` idiom other dialog specs use.

- [ ] **Step 2: Run it to see it fail**

Run: `cd admin && pnpm vitest run src/__tests__/clear-brand-dialog.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement** `ClearBrandDialog.vue` per the flow above, as a `UModal` with `TokenScaleControl` (filtered names) for both pickers.

- [ ] **Step 4: Run it and the gates**

Run: `cd admin && pnpm vitest run src/__tests__/clear-brand-dialog.spec.ts src/__tests__/appearance-palette.spec.ts && pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/pages/appearance src/__tests__/clear-brand-dialog.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src
git commit -m "feat(admin): Clear checks where a brand colour is used and offers Replace, with a confirmed text colour and both-mode contrast"
```

---

### Task 18: Browser proofs

**Files:**
- Create: `scripts/build-palette-fixtures` (modelled on `scripts/build-hover-fixtures`)
- Create: `tools/runtime-browser/fixtures/palette/{public,stage}.html` (generated)
- Create: `tools/runtime-browser/tests/palette.spec.js`
- Test: the spec itself; the existing `tools/runtime-browser` suite (the template and CSS changed, per the standing rule)

**Interfaces:**
- Consumes: the compiled default stylesheet (StyleCompiler 24) and `themeColorsStyle()` output for a fixture palette: Custom neutral cream (`#f8f4ec`), dark base `stone`, Brand 1 "Gold dark" `#8a6a2a`, Brand 2 unset.

**The fixture page** (rendered by the build script through the real render path, with `CACHE_DRIVER=array`) contains:
- **`#brand`:** a Button whose surface is `color.brand-1` and text is `color.brand-1-contrast`;
- **`#hover-unset`:** a Button whose normal text is `color.accent`, hover text `color.brand-2` (unset);
- **`#heading-unset`:** a Heading inside a section with text colour `color.muted`, whose own text is `color.brand-2`;
- **`#band`:** a section on `color.surface-2`;
- **the page ground:** `color.background`.

- [ ] **Step 1: Write the failing spec**

```js
import { test, expect } from '@playwright/test'
import { fixtureUrl } from './helpers.js'

const rgb = (hex) => { const n = parseInt(hex.slice(1), 16); return `rgb(${n >> 16}, ${(n >> 8) & 255}, ${n & 255})` }

test.describe('custom palette', () => {
  test('Brand 1 paints its hex in light mode, its derived value in dark mode, and its contrast colour on it', async ({ page }) => {
    await page.goto(fixtureUrl('palette/public.html'))
    const btn = page.locator('#brand a, #brand button').first()
    await expect(btn).toHaveCSS('background-color', rgb('#8a6a2a'))
    const ink = await btn.evaluate((el) => getComputedStyle(el).color)
    expect([rgb('#000000'), rgb('#ffffff')]).toContain(ink)
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
    const dark = await btn.evaluate((el) => getComputedStyle(el).backgroundColor)
    expect(dark).not.toBe(rgb('#8a6a2a'))
  })

  test('a hover colour naming an unset slot keeps the normal colour on hover, focus and forced preview', async ({ page }) => {
    await page.goto(fixtureUrl('palette/public.html'))
    const btn = page.locator('#hover-unset a, #hover-unset button').first()
    const normal = await btn.evaluate((el) => getComputedStyle(el).color)
    await btn.hover()
    await expect(btn).toHaveCSS('color', normal)
    await page.mouse.move(0, 0)
    await btn.focus()
    await expect(btn).toHaveCSS('color', normal)
    await page.goto(fixtureUrl('palette/stage.html'))
    const staged = page.locator('#hover-unset a, #hover-unset button').first()
    await staged.evaluate((el) => el.closest('[data-thallo-block]')?.setAttribute('data-thallo-force-hover', ''))
    await expect(staged).toHaveCSS('color', normal)
  })

  test('a heading naming an unset slot keeps the colour it had without that setting', async ({ page }) => {
    await page.goto(fixtureUrl('palette/public.html'))
    const parent = await page.locator('#heading-unset').evaluate((el) => getComputedStyle(el.parentElement).color)
    await expect(page.locator('#heading-unset h1, #heading-unset h2, #heading-unset h3').first()).toHaveCSS('color', parent)
  })

  test('a Custom palette paints the cream ground and the Surface 2 band', async ({ page }) => {
    await page.goto(fixtureUrl('palette/public.html'))
    await expect(page.locator('body')).toHaveCSS('background-color', rgb('#f8f4ec'))
    await expect(page.locator('#band')).toHaveCSS('background-color', rgb('#efe7d8'))
  })
})
```

The forced-hover attribute must be the one the stage uses: check `tools/runtime-browser/tests/hover.spec.js` and copy its mechanism exactly. `#heading-unset`'s parent colour is the section's `color.muted`.

- [ ] **Step 2: Build the fixtures and run it**

Run: `CACHE_DRIVER=array scripts/build-palette-fixtures && cd tools/runtime-browser && npx playwright test tests/palette.spec.js --project=chromium`
Expected: PASS. If it fails, the failure is in Tasks 2–4's output, not the spec: debug with the fixture HTML open.

- [ ] **Step 3: Rebuild every runtime-browser fixture and run the whole suite**

Run `scripts/build-hover-fixtures`, `scripts/build-text-style-fixtures` and the other `scripts/build-*-fixtures`, then `cd tools/runtime-browser && npx playwright test --project=chromium --project=webkit`. Firefox cannot launch in the local sandbox; say so in the summary.
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add scripts/build-palette-fixtures tools/runtime-browser
git commit -m "test(palette): browser proofs — brand fills and contrast in both modes, unset hover and text keep their colours, the Custom ground"
```

---

### Task 19: Docs, OpenAPI and the changelog

**Files:**
- Modify: the Appearance guide (grep `docs/` for "Theme colors"), the style settings reference (the colour names list), `packages/thallo-render/docs/THEMING.md` (`brand-*` tokens are site-controlled; a theme mapping is ignored with a Doctor warning), and the block library pages where colour lists appear
- Modify: `docs/openapi.json` (hand-splice the new and changed operations: style-schema permission and `palette`; the palette routes; draft restore; general settings keys; preview mint `palette`. `CACHE_DRIVER=array php glueful docs:openapi` gives the generated shapes to splice from. Never commit the provision-regenerated file wholesale.)
- Modify: `admin/src/api/schema.d.ts` (regenerate from the spliced OpenAPI with the admin's script); replace Task 15's `as never` casts
- Modify: `CHANGELOG.md` `[Unreleased]`

- [ ] **Step 1: Write the docs**

Appearance guide sections:
- **Custom neutral:** six colours, the dark-mode base, Reset.
- **Brand colours:** names are labels; pickers show swatches. A cleared colour applies no colour; Clear and Replace; history is kept.
- **Contrast checks:** the pairs checked, advisory.
- Replace the `custom.css` override advice with a pointer to Custom.

The style reference lists all seventeen colour names and the unavailable state. THEMING.md documents `Vocabulary::SITE_CONTROLLED`.

- [ ] **Step 2: Splice OpenAPI and regenerate admin types**

Run: `CACHE_DRIVER=array php glueful docs:openapi --output=/tmp/openapi.generated.json` (or the command the memory note names), splice the operations listed above into `docs/openapi.json`, then run the admin's type generation script (`pnpm gen:api`, or check `admin/package.json`). Then `cd admin && pnpm type-check`.
Expected: PASS, with no `as never` left in `src/queries/palette.ts`.

- [ ] **Step 3: Changelog**

Under `## [Unreleased]`:

```markdown
### Added
- Appearance → Theme colors: a **Custom** neutral (six colours of your own, with a dark-mode base family), three named **brand colours** offered in every colour picker with swatches, and **contrast checks** of the effective palette in both modes.
- Clearing a brand colour that is still used offers **Replace with…**: a resumable job that rewrites every current page, region, layout, saved section and style class (publications get a new version), then clears it. Older versions are kept as they were.
- **Restore to draft** now runs on the server.

### Changed
- Colour pickers show swatches and your colour names.
- The style schema is readable by any style editor (content.edit, content.manage, templates.manage or styles.manage).
- The compiled stylesheet gains the brand colour utilities (StyleCompiler 24).
- A theme's `theme.json` can no longer remap brand colour tokens; the Doctor warns if it tries.
```

- [ ] **Step 4: Full gates (one at a time, never concurrent)**

```bash
export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH
composer test:reset-db && composer test:migrate
vendor/bin/phpcs; echo "phpcs exit $?"
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature
scratchpad/h.sh   # integration shards A–G, one at a time
composer test:boundaries
cd admin && pnpm vitest run && pnpm type-check && pnpm lint && pnpm fmt:check
```

Expected: phpcs exit 0, and every suite green. Every new `tests/Integration` file is in exactly one `INTEGRATION_SHARD_*`, and no shard exceeds ~3 minutes locally. Then run `git status` and confirm `docs/openapi.json` holds only the splice.

- [ ] **Step 5: Commit**

```bash
git add docs packages/thallo-render/docs admin/src/api/schema.d.ts admin/src/queries/palette.ts CHANGELOG.md
git commit -m "docs(palette): Appearance guide, style reference, theming, OpenAPI and changelog for the custom palette"
```
