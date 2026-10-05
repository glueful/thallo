# Per-block typeface and the site font library — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An author chooses the typeface of any text target — a Heading, a Rich text, a card's title — from the workspace's font library, in the Style tab or a style class; the library (built-in stacks plus uploaded `.woff2` families with faces read from the file) is managed in Site › Appearance and also feeds the site-wide Text and Headings choice.

**Architecture:**
- **Contract.** `typography.family` joins the `typography` group with a new value kind `font` (`{"type":"font","value":"<id>"}`), validated by shape only; non-responsive.
- **Library (core).** Two workspace-owned tables (`font_families`, `font_faces`), a repository, a `FontLibrarySnapshot` taken once per request, and a `Woff2FaceReader` that reads weight/italic/variable range from the file behind a `BrotliDecoder` seam.
- **Rendering (render pack).** Built-in utilities (`t-font-serif`, `t-font-theme`, `t-font-inherit`, `t-font-reset`) in the theme's compiled settings artifact; uploaded families in an immutable per-workspace `fonts-{hash}.css` (`@font-face` + utilities in `@layer settings`); `BlockStyleEmitter` substitutes `t-font-inherit` for a removed or unknown winning ID.
- **Appearance.** Custom picks Text/Headings families from the library; a repeatable, untouched-only provision step migrates today's uploads.
- **Admin.** A Typeface control on every typography target and in the style-class editor, a new computed-typography bridge message, a Typefaces card.

**Tech Stack:** PHP 8.3 (Glueful, PostgreSQL, PHPUnit), Twig 3, plain JS preview bridge, Vue 3 / Nuxt UI admin (Pinia Colada, vitest, Playwright e2e in `admin/e2e`), Python `fontTools` (fixture generation only, dev-time).

**Spec:** `docs/internal/superpowers/specs/2026-10-05-block-typeface-design.md` (approved at `b3e04084`). Every section is in this release.

> Amended 2026-10-05 after plan review: bounded Brotli decoding proven in a killed child process, the extension used only through its incremental API, and real decompressed tables for timing (Task 1); one lock order for blobs, families and faces, with race tests (Tasks 2, 8); targets versus parts from the real model, the Links fixture and the `settings.parts.<name>` path (Tasks 5, 8); stage-only target/part marker classes (Task 10); the Appearance cutover end to end — request and preview DTOs, contract, admin types, preview flow (Task 7); absent versus deliberately cleared assignments, the appearance lock, every workspace and an atomic per-workspace marker (Task 7); cross-tab notification, a freshness fingerprint, and reloads coordinated with applies (Task 11); one specimen-loading mechanism, `FontFace` per face (Task 9); OpenAPI and `gen:api` (Task 12); all pre-change references captured before Task 4.

## Rulings made while planning (from the code)

- **Library lives in core**, not the render pack: it owns settings, media and workspace tables, and the admin API sits beside `GeneralSettingsController`. The render pack consumes a neutral contract `Thallo\Contracts\Fonts\FontLibraryReader` (snapshot + theme-free stacks), so render never imports core. Cost if wrong: a contract move.
- **Media protection is new.** `MediaAdminController::destroy()` has no usage guard today and settings blobs are never counted in `media_usage`. The guard added here covers font-library files only (current and removed families); it does not change other media. Cost if wrong: none for other media.
- **Usage scan reuses `BlockDocumentSources`**, the same sources `StyleClassUsage` walks (`entry_drafts`, `entry_versions`, published, `regions`, `saved_sections`, `layouts`), plus `style_classes.style` and the two Appearance assignments.
- **Picker read route** uses the existing any-of middleware: `content_permission:content.edit,content.manage,templates.manage,styles.manage` (`RequirePermission` splits on commas; any-of).
- **Fonts artifact serving** reuses `RenderController::themeAsset()` / `previewAsset()` (they already branch on artifact file names), adding a `fonts-{hash}.css` branch.
- **Targets and parts.** A style path maps to one target per block, so two independently styled text elements in one block are a target plus a part (`settings.parts.<name>`, no `.style`); the shipped Links block (title target + link part) is the fixture.
- **Markers are stage-only classes** (`thallo-stage-target--<name>`, `thallo-stage-part--<name>`) added by `style_classes()`, because part elements do not call `style_attrs()`.
- **Stage refresh** cannot use the in-place patch (`useStageEditor.refreshStage()` swaps changed block wrappers only, never the head's stylesheet links). A library or theme change forces the stage's full iframe reload of the working copy (`bridge.stageRefresh()` answering `reload`, or `reloadStage()` directly). The working copy and undo history live in the admin, so both survive; the reloaded render takes one snapshot for markup and stylesheet by construction.
- **Blob files are read through a seam**, `FontBlobFiles::localPath(string $blobUuid): string`, whose production implementation copies the stored file to a temp path the way `UploadController::readToTempFile()` does; tests bind `tests/Support/Fonts/FixtureFontBlobFiles` mapping blob IDs to fixture paths and insert the `blobs` rows as `FieldValidatorMediaDiskTest::insertBlob()` does (mime `font/woff2`, visibility `public`).

## Global Constraints

- Store a stable font ID only: reserved `theme|serif|humanist|geometric|slab|mono|system`, or `[A-Za-z0-9]{12}`; `inherit` and `reset` are never IDs.
- CSS family name of an uploaded family is `"thallo-font-<id>"`; utility is `t-font-<id>`; no display name ever reaches CSS.
- Stack order: generated family, then the fallback's named stack, ending with its generic.
- Synthesis: uploaded `font-synthesis: style`; built-ins and `theme` `weight style`; removed/unknown `inherit` for both family and synthesis.
- Reset = `{"type":"reset"}` → `t-font-reset` (`font-family: revert-layer; font-synthesis: revert-layer`), "Returns this target to its contextual default."
- Non-responsive this release; a later responsive version reads a plain value as `base`.
- Typeface utilities live in `@layer settings`; custom CSS keeps its unlayered precedence.
- `fonts-{hash}.css`: one snapshot drives decisions, CSS, link and fingerprint; a hash serves its own version forever; publish before link; retain newest three and anything younger than a day.
- Theme face `@font-face` always declared; preload only when the site-wide Text uses it; no block-level preload.
- `theme.json` face metadata optional; absent → Theme resolves to `ThemeDesign`'s system stack.
- Picker read: any of `content.edit`, `content.manage`, `templates.manage`, `styles.manage`. Usage details and every mutation: `content.manage`. Usage is never in the picker response.
- Workspace-scoped everything; tables registered in `ThalloTenantTables`.
- Gates (memory): never run suites concurrently; MAMP PHP first on PATH; suite in parts (reset+migrate, Feature+Unit, then each `INTEGRATION_SHARD_*` from `ci.yml`); prefixed run only if routes change (they do here — run once prefixed); phpcs by exit code; admin `pnpm type-check`, lint, `fmt:check`, vitest; e2e fixtures rebuilt (`CACHE_DRIVER=array`); changelog bullet in the same commit; new `tests/Integration` top-level entries need a shard (none planned: `Fonts` tests go under existing dirs); shards stay under ~5 minutes locally.

## Review Focus

1. **A family name with quotes, `<`, `}` or emoji** — never reaches CSS (only the ID does); the card and pickers escape it. Pinned in Task 6 (`testADisplayNameNeverReachesCss`) and Task 10.
2. **A block imported from another workspace naming its uploaded family** — renders `inherit`, shows Unknown typeface, keeps the ID. Pinned in Task 5 (`testAnUnknownIdFromAnotherWorkspaceInherits`).
3. **A 10 MB `.woff2` or a decompression bomb on upload** — decoding is bounded: it stops at the cap within a deadline and a memory ceiling and refuses with a reason. Pinned in Task 1 (`testExpansionStopsAtTheCapUnderADeadline`, and the `bomb` case of `testAnUnsupportedFileIsRefusedWithItsReason`).
4. **A library edit landing between a page's markup and its stylesheet request** — the page's linked hash still serves its own CSS. Pinned in Task 6 (`testAnOldHashServesItsOwnVersionAfterAnEdit`).
5. **A style class setting a family applied to a block whose part also sets one** — the part keeps its own value; class does not reach it. Pinned in Task 5 (`testABlockClassDoesNotReachAPart`).

---

## File Structure

**Core (`core/src/Content/Fonts/`)** — new
- `Woff2FaceReader.php` — reads one `.woff2`: table directory, Brotli stream, `OS/2`, `head`, `fvar`.
- `FaceMetadata.php` — `weightMin`, `weightMax`, `italic`, `variable`.
- `UnreadableFont.php` — exception with a user-facing reason.
- `Brotli/BrotliDecoder.php` (interface), `Brotli/ExtBrotliDecoder.php`, `Brotli/PurePhpBrotliDecoder.php` (wraps the vendored decoder), `Brotli/BrotliDecoders.php` (picks ext when loaded).
- `FontId.php` — shape validation, reserved names.
- `FontLibrary.php` — repository over `font_families` / `font_faces`; bumps `fonts.generation`.
- `FontLibrarySnapshot.php` — immutable view; implements the contract.
- `FontUsage.php` — usage scan.
- `FontLibraryUpgrade.php` — the provision migration step.
- `Http/FontLibraryController.php` — admin API.
- `vendor-brotli/` — the vendored pure-PHP decoder (license file kept) if Task 1 selects it.

**Contracts** — `packages/thallo-contracts/src/Fonts/FontLibraryReader.php`, `FontFamilyView.php`; `Style/ValueKind.php` (+`Font`), `Style/StyleSchema.php` (+`typography.family`, VERSION 11), `Style/FontStacks.php` (the named built-in stacks, moved from `ThemeDesign` so contracts, core and render share one source).

**Core edits** — `core/src/Content/Style/SettingsValidator.php` (font branch), `core/database/migrations/042_CreateFontLibraryTables.php`, `core/src/Settings/GeneralSettings.php` (+ `theme_font_text_family`, `theme_font_headings_family`), `GeneralSettingsController.php`, `EngineThemeAppearanceProvider.php`, `MediaAdminController.php` (guard), `core/routes/admin.php`, `CoreServiceProvider.php`, `ProvisionCommand.php` (runs the upgrade step).

**Render edits** — `Style/ClassNames.php` (stem `font`), `Style/StyleCompiler.php` (built-in utilities, VERSION 13), `Style/ThemeVocabulary.php` (optional `face`), `Style/BlockStyleEmitter.php` (substitution), new `Style/FontsArtifact.php` + `Style/FontsArtifacts.php`, `Http/Controllers/RenderController.php` (serve), `RenderContextExtension.php` (`fonts_stylesheet_url`, `fontFacesStyle` split), `ThemeAppearanceSource.php` (fingerprint), `Theme/ThemeDesign.php` (Custom from families, synthesis tokens), `themes/default/theme.json` (`face`), `themes/default/templates/layout.twig`, `themes/default/assets/site.css` (synthesis tokens), `assets/preview/preview-bridge.js` (typography messages).

**Tenancy** — `packages/thallo-tenancy/src/ThalloTenantTables.php`.

**Admin** — `src/style/schema.ts`, `src/style/types.ts`, `src/fonts/loadFamilyFaces.ts`, `src/composables/useAppearanceChanges.ts`, `src/pages/appearance/components/AppearancePreview.vue`, `src/queries/generalSettings.ts`, `src/api/schema.d.ts` (regenerated), `src/queries/fontLibrary.ts`, `src/editor/inspector/controls/FontFamilyControl.vue`, `src/editor/inspector/StyleTab.vue`, `src/editor/inspector/controls/ResponsiveField.vue`, `src/editor/inspector/classFieldState.ts`, `src/composables/useCanvasBridge.ts`, `src/pages/appearance/index.vue`, `src/pages/appearance/components/TypefacesCard.vue`, `FontFamilyDialog.vue`, `FontUsageDialog.vue`, `FontFamilyPicker.vue` (replaces `FontFaceField.vue` for Custom).

**Tests** — `tests/fixtures/fonts/` + `scripts/build-font-fixtures.py`, `tests/Support/Fonts/{decode-probe.php,FixtureFontBlobFiles.php}`, `tests/fixtures/render/pre-typeface/` + `scripts/capture-pre-typeface.php`, `tests/Integration/Render/StageTargetMarkersTest.php`, `tests/Integration/Content/Fonts/{FontLibraryRacesTest,MediaFontGuardRaceTest}.php`, `tests/Integration/Http/AppearanceFamiliesPreviewTest.php`; `tests/Unit/Fonts/*`, `tests/Integration/Content/Fonts/*`, `tests/Integration/Render/Typeface*`, `tests/Integration/Http/FontLibraryApiTest.php`, `tests/Integration/Setup/FontLibraryUpgradeTest.php`; admin `src/__tests__/fontFamilyControl.spec.ts`, `typefacesCard.spec.ts`, `canvasBridgeTypography.spec.ts`; e2e `admin/e2e/tests/typeface.spec.ts` (+ scenario in `regions-scenarios.json`).

---

### Task 1: The WOFF2 reader, its dependency, and the fixture proof

**Files:**
- Create: `scripts/build-font-fixtures.py`, `tests/fixtures/fonts/*` (generated, committed, with `PROVENANCE.md` and the OFL notice), `core/src/Content/Fonts/{Woff2FaceReader,FaceMetadata,UnreadableFont}.php`, `core/src/Content/Fonts/Brotli/{BrotliDecoder,ExtBrotliDecoder,PurePhpBrotliDecoder,BrotliDecoders}.php`, `core/src/Content/Fonts/vendor-brotli/` (if selected), `tests/Unit/Fonts/BrotliDecoderTest.php`, `tests/Unit/Fonts/Woff2FaceReaderTest.php`, `tests/Support/Fonts/decode-probe.php` (the bounded-expansion probe)
- Modify: `composer.json` (only if a Packagist decoder is selected), `phpcs.xml` (exclude `vendor-brotli/`)

**Interfaces:**
- Produces:
  - `interface BrotliDecoder { public function decode(string $compressed, int $maxOutput): string; }` — **bounded**: it stops producing output once `$maxOutput` bytes are exceeded and throws `UnreadableFont('This font is too large to read')`; it never materialises more than `$maxOutput + one internal block` bytes. Corrupt input throws `UnreadableFont('Couldn\'t read this font\'s data')`, never a PHP error.
  - `BrotliDecoders::best(): BrotliDecoder` — the extension decoder **only if** it meets the same bounded contract (incremental API present, below); otherwise the pure-PHP decoder.
  - `final class FaceMetadata { public function __construct(public int $weightMin, public int $weightMax, public bool $italic, public bool $variable) }`.
  - `final class Woff2FaceReader { public function __construct(BrotliDecoder $brotli, int $maxDecompressed = 33554432); public function read(string $path): FaceMetadata }`.
  - `final class UnreadableFont extends \RuntimeException` with `public readonly string $reason`.

**Decision procedure (record the outcome as a ledger ruling):**
1. **The extension is never trusted merely because it is installed.** `brotli_uncompress()`'s second argument is a dictionary, not an output limit, so one-shot decoding cannot be bounded. `ExtBrotliDecoder` uses the incremental API — `brotli_uncompress_init()` then `brotli_uncompress_add($ctx, $chunk, BROTLI_PROCESS)` over 16 KiB input chunks, counting output and aborting past `$maxOutput` — and `BrotliDecoders::best()` selects it only when `function_exists('brotli_uncompress_init')`. It runs the same tests as the pure-PHP decoder wherever the extension exists (CI does not have it; the tests skip it there, and the ruling records that the extension path was proven locally or left unselected).
2. **The universal decoder is pure PHP** (no extension, no `proc_open`/`exec`, no FFI). Candidates in order: (a) the MIT PHP output of BrotliHaxe, vendored under `core/src/Content/Fonts/vendor-brotli/` with its license and a provenance note (commit, generator command, any patches); (b) another pure-PHP decoder found at selection time meeting the criteria. Exec-based packages (`n5s/brotli`, `vdechenaux/brotli`) are excluded.
3. **Acceptance criteria**, proven by the tests below: byte-exact on every vector; every WOFF2 fixture read; corrupt and truncated streams rejected as `UnreadableFont`; **bounded expansion** (the probe below finishes under a hard deadline and a memory ceiling); the real decompressed table stream of `variable.woff2` decodes in under 2 s and a 1 MiB table stream in under 20 s; MIT/BSD/Apache-compatible license. If the vendored decoder has no output cap, add one in its output loop (a running count checked as each block is emitted) and record the patch in `PROVENANCE.md`.
4. **If no pure-PHP candidate passes: STOP and ask the user.**

- [ ] **Step 1: Write the fixture generator** `scripts/build-font-fixtures.py` (dev-only; `fontTools` and `brotli`):

```python
#!/usr/bin/env python3
"""Build tests/fixtures/fonts/ — the WOFF2 reader's proof set (block typeface plan, Task 1).
Sources: the default theme's Figtree (OFL)."""
import os, random, struct, brotli
from fontTools.ttLib import TTFont
from fontTools.ttLib.woff2 import WOFF2Reader
from fontTools.varLib import instancer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'packages/thallo-render/themes/default/assets/fonts')
OUT = os.path.join(ROOT, 'tests/fixtures/fonts')
VEC = os.path.join(OUT, 'brotli')
os.makedirs(VEC, exist_ok=True)

def woff2(font, name):
    font.flavor = 'woff2'
    font.save(os.path.join(OUT, name))
    return os.path.join(OUT, name)

def src(name):
    return TTFont(os.path.join(SRC, name))

variable = woff2(src('figtree-roman-latin.woff2'), 'variable.woff2')
woff2(src('figtree-italic-latin.woff2'), 'variable-italic.woff2')
woff2(instancer.instantiateVariableFont(src('figtree-roman-latin.woff2'), {'wght': 700}), 'static-700.woff2')
woff2(instancer.instantiateVariableFont(src('figtree-italic-latin.woff2'), {'wght': 400}), 'static-400-italic.woff2')

data = open(variable, 'rb').read()
open(os.path.join(OUT, 'truncated.woff2'), 'wb').write(data[: len(data) // 2])
open(os.path.join(OUT, 'not-a-font.woff2'), 'wb').write(b'<html>not a font</html>')
woff1 = src('figtree-roman-latin.woff2'); woff1.flavor = 'woff'; woff1.save(os.path.join(OUT, 'wrong-flavour.woff'))

# The decompressed table stream (what the Brotli stream inflates to), from fontTools' reader.
with open(variable, 'rb') as f:
    reader = WOFF2Reader(f)
    tables = reader.transformBuffer
compressed_len = struct.unpack('>I', data[20:24])[0]          # totalCompressedSize
stream_offset = len(data) - compressed_len                       # header + directory precede it (no metadata/private blocks)
assert brotli.decompress(data[stream_offset:]) == tables

# A reader-level bomb: the REAL header and table directory (which advertise the real, small
# sizes), followed by a Brotli stream that inflates to 64 MiB. Nothing before decompression can
# reject it; only a bounded decoder stops it.
bomb_stream = brotli.compress(b'\0' * (64 * 1024 * 1024), quality=5)
header = bytearray(data[:stream_offset])
header[20:24] = struct.pack('>I', len(bomb_stream))             # totalCompressedSize = the bomb's
header[8:12] = struct.pack('>I', len(header) + len(bomb_stream)) # length
open(os.path.join(OUT, 'bomb.woff2'), 'wb').write(bytes(header) + bomb_stream)

# Brotli vectors: (raw, compressed) across qualities.
random.seed(7)
samples = {
    'empty': b'',
    'text': open(os.path.join(ROOT, 'LICENSE'), 'rb').read(),
    'random-4k': bytes(random.getrandbits(8) for _ in range(4096)),
    'repeat-256k': b'thallo ' * 37449,
    'font-tables': tables,                                        # the real decompressed table stream
    'big-tables': (tables * (1048576 // len(tables) + 1))[:1048576],  # ~1 MiB of real table bytes
}
for name, raw in samples.items():
    for q in (0, 5, 11):
        open(os.path.join(VEC, f'{name}.q{q}.br'), 'wb').write(brotli.compress(raw, quality=q))
    open(os.path.join(VEC, f'{name}.raw'), 'wb').write(raw)
open(os.path.join(VEC, 'expansion-64m.br'), 'wb').write(bomb_stream)  # the raw expansion stream
print('fixtures written to', OUT)
```

Run: `python3 scripts/build-font-fixtures.py`. Expected: `fixtures written to …` (the `assert` proves `font-tables` is the decompressed stream, not the compressed file). Record `fontTools` and `brotli` versions in `PROVENANCE.md`.

- [ ] **Step 2: Write the bounded-expansion probe** `tests/Support/Fonts/decode-probe.php` — run in a child process so a runaway decoder is **killed**, not timed afterwards:

```php
<?php
// Usage: php -d memory_limit=96M decode-probe.php <decoder: pure|ext> <file> <maxOutput>
// Prints REFUSED:<reason> or DECODED:<bytes>; a runaway decoder is killed by the parent.
declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
[$_, $which, $file, $max] = $argv;
$decoder = $which === 'ext'
    ? new \Thallo\Core\Content\Fonts\Brotli\ExtBrotliDecoder()
    : new \Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder();
try {
    echo 'DECODED:', strlen($decoder->decode((string) file_get_contents($file), (int) $max));
} catch (\Thallo\Core\Content\Fonts\UnreadableFont $e) {
    echo 'REFUSED:', $e->reason;
}
```

- [ ] **Step 3: Write the failing decoder test** `tests/Unit/Fonts/BrotliDecoderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\Brotli\ExtBrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder;
use Thallo\Core\Content\Fonts\UnreadableFont;

/** Every decoder Thallo may select meets one bounded contract (spec §2.3; plan Task 1). */
final class BrotliDecoderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts/brotli';
    private const PROBE = __DIR__ . '/../../Support/Fonts/decode-probe.php';

    /** @return iterable<string, array{string}> */
    public static function decoders(): iterable
    {
        yield 'pure php' => ['pure'];
        yield 'extension' => ['ext'];
    }

    private function make(string $which): BrotliDecoder
    {
        if ($which === 'ext') {
            if (!function_exists('brotli_uncompress_init')) {
                self::markTestSkipped('No incremental brotli extension here; it is never selected without one.');
            }
            return new ExtBrotliDecoder();
        }
        return new PurePhpBrotliDecoder();
    }

    /** @dataProvider decoders */
    public function testEveryVectorDecodesByteExact(string $which): void
    {
        $decoder = $this->make($which);
        foreach (glob(self::DIR . '/*.q*.br') ?: [] as $file) {
            $raw = (string) file_get_contents((string) preg_replace('/\.q\d+\.br$/', '.raw', $file));
            self::assertSame($raw, $decoder->decode((string) file_get_contents($file), 1 << 22), basename($file));
        }
    }

    /** @dataProvider decoders */
    public function testACorruptStreamIsUnreadableNotAnError(string $which): void
    {
        $this->expectException(UnreadableFont::class);
        $this->make($which)->decode(substr((string) file_get_contents(self::DIR . '/text.q11.br'), 0, 40), 1 << 20);
    }

    /**
     * Bounded expansion: a 64 MiB stream with a 1 MiB cap, in a child process with a 96 MiB memory
     * limit, killed after 10 s. Only a decoder that stops expanding passes.
     *
     * @dataProvider decoders
     */
    public function testExpansionStopsAtTheCapUnderADeadline(string $which): void
    {
        $this->make($which);
        $cmd = [PHP_BINARY, '-d', 'memory_limit=96M', self::PROBE, $which, self::DIR . '/expansion-64m.br', (string) (1 << 20)];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $deadline = microtime(true) + 10.0;
        $out = '';
        stream_set_blocking($pipes[1], false);
        while (proc_get_status($proc)['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail('the decoder kept expanding past the deadline');
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        self::assertSame('REFUSED:This font is too large to read', $out, $err);
    }

    public function testThePurePhpDecoderIsFastEnoughOnRealTables(): void
    {
        $decoder = new PurePhpBrotliDecoder();
        foreach (['font-tables' => 2.0, 'big-tables' => 20.0] as $name => $budget) {
            $start = hrtime(true);
            $decoder->decode((string) file_get_contents(self::DIR . "/{$name}.q11.br"), 1 << 22);
            self::assertLessThan($budget, (hrtime(true) - $start) / 1e9, $name);
        }
    }

    public function testTheExtensionIsSelectedOnlyWithItsIncrementalApi(): void
    {
        self::assertInstanceOf(
            function_exists('brotli_uncompress_init') ? ExtBrotliDecoder::class : PurePhpBrotliDecoder::class,
            BrotliDecoders::best(),
        );
    }
}
```

The probe test uses `proc_open` only in the test suite; production code never does.

- [ ] **Step 4: Run it.** `vendor/bin/phpunit tests/Unit/Fonts/BrotliDecoderTest.php`. Expected: FAIL, `PurePhpBrotliDecoder` not found.

- [ ] **Step 5: Implement the decoders.**

```php
final class PurePhpBrotliDecoder implements BrotliDecoder
{
    public function decode(string $compressed, int $maxOutput): string
    {
        try {
            // The vendored decoder's entry point, patched to stop past $maxOutput (PROVENANCE.md).
            return \Thallo\Core\Content\Fonts\VendorBrotli\Decoder::decompress($compressed, $maxOutput);
        } catch (\Thallo\Core\Content\Fonts\VendorBrotli\OutputLimitExceeded) {
            throw new UnreadableFont('This font is too large to read');
        } catch (\Throwable $e) {
            throw new UnreadableFont('Couldn\'t read this font\'s data', previous: $e);
        }
    }
}

final class ExtBrotliDecoder implements BrotliDecoder
{
    private const CHUNK = 16384;

    public function decode(string $compressed, int $maxOutput): string
    {
        $ctx = \brotli_uncompress_init();
        $out = '';
        $length = strlen($compressed);
        for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
            $mode = $offset + self::CHUNK >= $length ? \BROTLI_FINISH : \BROTLI_PROCESS;
            $piece = @\brotli_uncompress_add($ctx, substr($compressed, $offset, self::CHUNK), $mode);
            if (!is_string($piece)) {
                throw new UnreadableFont('Couldn\'t read this font\'s data');
            }
            $out .= $piece;
            if (strlen($out) > $maxOutput) {
                throw new UnreadableFont('This font is too large to read');
            }
        }
        return $out;
    }
}

final class BrotliDecoders
{
    public static function best(): BrotliDecoder
    {
        return function_exists('brotli_uncompress_init') ? new ExtBrotliDecoder() : new PurePhpBrotliDecoder();
    }
}
```

(An empty input still calls `brotli_uncompress_add($ctx, '', BROTLI_FINISH)` once; handle `$length === 0` with that single call.) If the extension's incremental mode can emit one chunk far larger than its input (a 16 KiB chunk of the 64 MiB stream inflates hugely), that is why the probe exists: if it fails for the extension, `best()` must not select it — change the condition to `false` and record the ruling.

- [ ] **Step 6: Run it.** Expected: PASS (extension cases skip where absent). If any criterion fails for every pure-PHP candidate, stop and ask the user.

- [ ] **Step 7: Write the failing reader test** `tests/Unit/Fonts/Woff2FaceReaderTest.php`:

```php
final class Woff2FaceReaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts';

    private function reader(int $max = 33554432): Woff2FaceReader
    {
        return new Woff2FaceReader(BrotliDecoders::best(), $max);
    }

    public function testAVariableFontReportsItsWeightRange(): void
    {
        $face = $this->reader()->read(self::DIR . '/variable.woff2');
        self::assertSame([true, false, 300, 900], [$face->variable, $face->italic, $face->weightMin, $face->weightMax]);
    }

    public function testAVariableItalicIsItalic(): void
    {
        self::assertTrue($this->reader()->read(self::DIR . '/variable-italic.woff2')->italic);
    }

    public function testAStaticFaceReportsOneWeight(): void
    {
        $face = $this->reader()->read(self::DIR . '/static-700.woff2');
        self::assertSame([false, 700, 700], [$face->variable, $face->weightMin, $face->weightMax]);
        self::assertTrue($this->reader()->read(self::DIR . '/static-400-italic.woff2')->italic);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'truncated' => ['truncated.woff2', 'Couldn\'t read this font\'s data'];
        yield 'not a font' => ['not-a-font.woff2', 'Not a WOFF2 font'];
        yield 'woff 1' => ['wrong-flavour.woff', 'Not a WOFF2 font'];
        yield 'bomb' => ['bomb.woff2', 'This font is too large to read'];
    }

    /** @dataProvider refused */
    public function testAnUnsupportedFileIsRefusedWithItsReason(string $file, string $reason): void
    {
        try {
            $this->reader()->read(self::DIR . '/' . $file);
            self::fail('read');
        } catch (UnreadableFont $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
```

The bomb is refused because the reader caps decoding at the **directory's own** decompressed size (Σ `transformLength ?? origLength`, which the real directory keeps small), not at the file's claim; the decoder's bounded contract does the rest.

- [ ] **Step 8: Run it.** Expected: FAIL, `Woff2FaceReader` not found.

- [ ] **Step 9: Implement `Woff2FaceReader::read()`.**
  - Header (48 bytes): signature `wOF2` (`0x774F4632`) else `Not a WOFF2 font`; read `numTables`, `totalSfntSize`, `totalCompressedSize`.
  - Table directory: per entry a flags byte (tag index 0–62 into the WOFF2 known-tag table, or 63 = a 4-byte tag follows), `origLength` (UIntBase128), and `transformLength` (UIntBase128) when transformed (`glyf`/`loca` with transform version 0; `hmtx` with version 1). UIntBase128 must have no leading zero byte, ≤ 5 bytes, and no overflow past 2^32 − 1; otherwise `Couldn't read this font's data`.
  - `$expected = Σ (transformLength ?? origLength)`; if `$expected > maxDecompressed` → `This font is too large to read`. Decode the stream (offset = end of directory, length `totalCompressedSize`) with `$maxOutput = $expected`; a decoded length different from `$expected` → `Couldn't read this font's data`.
  - Walk tables in directory order to locate `OS/2`, `head`, `fvar` (never transformed). `OS/2`: `usWeightClass` (uint16 at 4, clamped 1–1000), `fsSelection` bit 0 = italic (uint16 at 62). Without `OS/2`, `head.macStyle` bit 1 (uint16 at 44). Neither → `Couldn't read this font's weight table`.
  - `fvar`: axis `wght` (20-byte axis records at `axesArrayOffset`: tag, min/default/max as Fixed 16.16) → rounded min/max, `variable = true`. Static: `weightMin = weightMax = usWeightClass`.

- [ ] **Step 10: Run both tests.** Expected: PASS.

- [ ] **Step 11: Commit.** Ledger: `Task 1: Ruling: decoder = <chosen> (measured: variable <t1>s, 1 MiB <t2>s; probe refused in <t3>s); extension <selected|not selected, why> — cost if wrong: swap behind BrotliDecoder`.

```bash
git add scripts/build-font-fixtures.py tests/fixtures/fonts tests/Support/Fonts core/src/Content/Fonts tests/Unit/Fonts phpcs.xml
git commit -m "feat(fonts): read a WOFF2 face's weight, style and range from the file, with bounded decoding"
```

---

### Task 2: Font IDs, the library tables, the repository, and its locking

**Files:**
- Create: `core/src/Content/Fonts/{FontId,FontLibrary,FontLibrarySnapshot,FontBlobFiles,StorageFontBlobFiles}.php`, `packages/thallo-contracts/src/Fonts/{FontLibraryReader,FontLibrarySnapshotView,FontFamilyView}.php`, `packages/thallo-contracts/src/Style/FontStacks.php`, `core/database/migrations/042_CreateFontLibraryTables.php`, `tests/Support/Fonts/FixtureFontBlobFiles.php`, `tests/Unit/Fonts/FontIdTest.php`, `tests/Integration/Content/Fonts/FontLibraryTest.php`, `tests/Integration/Content/Fonts/FontLibraryRacesTest.php`
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php`, `packages/thallo-render/src/Theme/ThemeDesign.php` (stacks read from `FontStacks`, no behaviour change), `core/src/Providers/CoreServiceProvider.php`

**Interfaces:**
- Consumes: `Woff2FaceReader`, `FaceMetadata`, `UnreadableFont` (Task 1).
- Produces:
  - `FontId::RESERVED = ['theme','serif','humanist','geometric','slab','mono','system']`; `FontId::isValid(string $id): bool` (reserved, or `/\A[A-Za-z0-9]{12}\z/`); `FontId::isReserved(string $id): bool`.
  - `FontStacks`: constants `SERIF`, `SYSTEM`, `HUMANIST`, `GEOMETRIC`, `SLAB`, `MONO` (moved verbatim from `ThemeDesign`); `named(string $id): ?string` for the six device built-ins; `forFallback(string $generic): string` — `sans-serif` → `FontStacks::SYSTEM` with its trailing generic replaced by `sans-serif`; `serif` → `SERIF`; `monospace` → `MONO`; `cursive` → `"Snell Roundhand","Segoe Script","Brush Script MT",cursive`; `system-ui` → `SYSTEM`; `FALLBACKS = ['sans-serif','serif','monospace','cursive','system-ui']`.
  - `interface FontBlobFiles { public function localPath(string $blobUuid): string; }` — `StorageFontBlobFiles` copies the stored blob to a temp file the way `UploadController::readToTempFile()` does; it refuses (`UnreadableFont('That file isn\'t in the media library')`) a blob that is missing, `status != 'active'`, `deleted_at` set, not `font/woff2`, or belonging to another workspace (`blobs.tenant_uuid` checked through the workspace scope). `FixtureFontBlobFiles(array<string,string> $uuidToPath)` for tests.
  - Contracts: `FontLibraryReader { public function snapshot(): FontLibrarySnapshotView; }`; `FontLibrarySnapshotView { public function generation(): int; public function family(string $id): ?FontFamilyView; public function active(): list<FontFamilyView>; public function resolution(string $id): string /* 'builtin'|'uploaded'|'missing' */; }`; `FontFamilyView { public string $id; public string $name; public string $fallback; public bool $removed; /** @var list<array{blob_uuid:string,url:string,weight_min:int,weight_max:int,italic:bool,variable:bool,unknown:bool}> */ public array $faces; }`.
  - `FontLibrary`: `create(string $name, string $fallback, list<string> $blobUuids): string`; `rename(string $id, string $name): void`; `setFallback(string $id, string $fallback): void`; `addFace(string $id, string $blobUuid): void`; `removeFace(string $id, string $blobUuid): void`; `remove(string $id): void`; `restore(string $id): void`; `purge(string $id): void`; `readAgain(string $id): void`; `snapshot(): FontLibrarySnapshot`; `isLibraryBlob(string $blobUuid): bool` (current or removed families); `lockBlob(string $blobUuid): void` (shared with media deletion, below).

**Schema** (`042_CreateFontLibraryTables.php`, the `037_CreateLayoutsTable` pattern):
- `font_families`: `id` string(12) primary, `tenant_uuid` string(12) nullable + index, `name` string(120), `fallback` string(16), `removed_at` timestamp nullable, `created_at`, `updated_at`.
- `font_faces`: `id` string(12) primary, `tenant_uuid`, `family_id` string(12) indexed, `blob_uuid` string(12) indexed, `weight_min` int, `weight_max` int, `italic` bool, `variable` bool, `unknown` bool default false, `created_at`; unique `(COALESCE(tenant_uuid,''), family_id, blob_uuid)` by raw PDO as `037` does.
- The widened-state `tenant_uuid SET NOT NULL` step as in `037`.
- Generation: the settings key `thallo.fonts.generation` (workspace-scoped, `settings` table), incremented inside every mutation's transaction.

**Locking (one order everywhere):**
1. **Blob row first** — `SELECT … FROM blobs WHERE uuid = ? FOR UPDATE` (`FontLibrary::lockBlob`), when a mutation involves a blob (create, addFace, removeFace, purge) and in `MediaAdminController::destroy` (Task 8).
2. **Family row second** — `SELECT … FROM font_families WHERE id = ? FOR UPDATE`, for every mutation of that family.
3. Then faces. All in one transaction (`Connection::transaction`), with the generation bump last.
- Blob checks happen **after** the lock: active, not deleted, `font/woff2`, this workspace. Face-count checks happen after the family lock: `removeFace` counts faces under the lock, so two concurrent removals cannot both see two faces. Reading the file (slow) happens **before** the transaction, then the blob's state is re-checked under the lock (a blob deleted meanwhile refuses the mutation).
- Multi-blob `create` locks blobs in sorted `uuid` order to avoid deadlocks between two creates.

- [ ] **Step 1: Write the failing tests.**
  - `FontIdTest`: reserved names valid; `Ab3dE5fG7hJ9` valid; `abc`, `inherit`, `reset`, `ab_cdefghijk`, `Ab3dE5fG7hJ9x`, `<script>` invalid.
  - `FontLibraryTest` (AppTestCase; inserts `blobs` rows the way `FieldValidatorMediaDiskTest::insertBlob()` does, mime `font/woff2`, visibility `public`, and binds `FixtureFontBlobFiles` to fixture paths): `testCreateReadsFacesFromTheFiles`; `testAnUnreadableFileRefusesTheWholeCreate` (no rows; reason surfaced); `testRenameKeepsTheId`; `testTheLastFaceCannotBeRemoved`; `testTheSameFaceTwiceIsRefused`; `testRemoveRestorePurge`; `testEveryMutationBumpsTheGeneration`; `testADeletedBlobIsRefused` (`status='deleted'`); `testAnotherWorkspacesBlobIsRefused` (opt-in harness `RetrofittedTenantTestCase`); `testIsLibraryBlobCoversRemovedFamilies`.
  - `FontLibraryRacesTest` (uses `tests/Support/Search/RowLockHolder` — a second connection holding a row lock — to interleave):
    - `testTwoConcurrentRemovalsCannotLeaveAFamilyWithoutFaces`: family with faces A and B; connection 2 holds the family row lock and removes A; connection 1's `removeFace(B)` blocks, then sees one face and refuses ("A family keeps at least one face").
    - `testABlobDeletedWhileReadingIsRefused`: read completes, the blob is marked deleted before the transaction, `addFace` refuses under the lock.
- [ ] **Step 2: Run them.** Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** the migration; `ThalloTenantTables` entries (`'font_families' => self::row($inst, [])`, `'font_faces' => self::row($inst, [['uniq_font_faces_blob', ['tenant_uuid','family_id','blob_uuid']]])`); `FontStacks` (moved constants; `ThemeDesign` references them); `FontId`; `FontBlobFiles` implementations; `FontLibrary` with the lock order above; `FontLibrarySnapshot` (immutable, built from one read at one generation).
- [ ] **Step 4: Run them, plus** `vendor/bin/phpunit tests/Unit/Render tests/Integration/Render/AppearanceDesignTest.php` (stacks unchanged) and `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit tests/Integration/Content/Fonts/FontLibraryTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(fonts): the workspace font library — stable IDs, faces read from the file, remove and restore, one lock order`.

---

### Task 3: `typography.family` in the contract, validator and admin schema

**Files:**
- Modify: `packages/thallo-contracts/src/Style/ValueKind.php`, `StyleSchema.php` (VERSION 11), `core/src/Content/Style/SettingsValidator.php`, `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php`, `admin/src/style/schema.ts`, `admin/src/style/types.ts` (the value union gains `| { type: 'font'; value: string }`), the resolver fixtures (`resolver-fixtures/v1` — regenerate per its README)
- Test: `tests/Unit/Style/StyleSchemaTest.php`, `tests/Integration/Content/Style/SettingsValidatorFontTest.php`, `admin/src/__tests__/styleSchema.spec.ts`

**Interfaces:**
- Consumes: `FontId::isValid` (Task 2).
- Produces: `ValueKind::Font` (`'font'`); `new PropertyDefinition('typography.family', 'typography', [ValueKind::Font, ValueKind::Reset], false)`; validated value `['type' => 'font', 'value' => '<id>']`; schema endpoint emits `{"path":"typography.family","kinds":["font","reset"],"responsive":false}`.

- [ ] **Step 1: Failing tests.** Validator: `{"typography":{"family":{"type":"font","value":"serif"}}}` valid on a block with typography; a 12-char ID valid without existing; `inherit`/`reset`/`My Font`/`Ab3`/a breakpoint map (`{"base":{…}}` → `is not responsive`) invalid; `{"type":"reset"}` valid; `{"type":"token","value":"serif"}` → `expects a font`; a block without typography capability → refused as for other paths; style-class snapshot validation accepts it. Schema: `StyleSchema::VERSION === 11`; `pathsInGroup('typography')` contains `typography.family`. Admin: `propertyDefinition('typography.family')` has `kinds: ['font','reset']`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** `ValueKind::Font = 'font'`; schema definition after `typography.line_height`; validator `validateSingle()` branch: `ValueKind::Font => FontId::isValid((string) $value['value']) ? ['type'=>'font','value'=>$value['value']] : error 'is not a typeface ID'`; bump `VERSION` and add the version-history line; mirror in `schema.ts` with a `font(path, group, responsive)` helper; regenerate resolver fixtures.
- [ ] **Step 4: Run** the tests plus `tests/Unit/Style`, `tests/Integration/Content/Style`, admin `pnpm exec vitest run src/__tests__/styleSchema.spec.ts`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(style): typography.family, a font value kind validated by shape`.

---

### Task 4: Built-in utilities, theme face metadata, and the Theme face always declared

**Files:**
- Modify: `packages/thallo-render/src/Style/ClassNames.php`, `StyleCompiler.php` (VERSION 13), `ThemeVocabulary.php`, `themes/default/theme.json`, `RenderContextExtension.php` (`fontFacesStyle`), `TemplatePolicy` cache version if the function signature changes
- Test: `tests/Unit/Render/StyleCompilerFontTest.php`, `tests/Integration/Render/ThemeFaceTest.php`

**Interfaces:**
- Consumes: `FontStacks` (Task 2), `ValueKind::Font` (Task 3).
- Produces: `ThemeVocabulary::face(): ?array{family: string, stack: string, files: list<array{src: string, weight: string, style: string}>}` (from optional `theme.json` `"face": {"family": "Figtree", "stack": "\"Figtree\", \"Figtree Fallback\", system-ui, …", "files": [{"src": "fonts/figtree-roman-latin.woff2", "weight": "300 900", "style": "normal"}, {"src": "fonts/figtree-italic-latin.woff2", "weight": "300 900", "style": "italic"}]}`; `files` feed the admin's Theme specimen, Task 8); `ThemeVocabulary::themeFaceStack(): string` (declared stack or `FontStacks::SYSTEM`); `ClassNames::STEMS['typography.family'] = 'font'`; `ClassNames::forFont(string $id): string` → `t-font-<id>`; compiled rules `.t-font-{serif|humanist|geometric|slab|mono|system|theme}`, `.t-font-inherit{font-family:inherit;font-synthesis:inherit}`, `.t-font-reset{font-family:revert-layer;font-synthesis:revert-layer}`.

- [ ] **Step 0 (before ANY rendering change — this is the first step of the release's rendering work):** with the code as it is at the start of Task 4, capture every pre-change reference into `tests/fixtures/render/pre-typeface/`, with a small script `scripts/capture-pre-typeface.php` committed beside them:
  - the default theme's rendered home page `<head>` (font faces, preload, stylesheets, appearance `<style>`) for `theme_font` = `sans`, `serif`, `custom`;
  - a custom theme without `face` metadata: a page with unset Heading and Rich text blocks (body markup);
  - the appearance `<style>` block for the Custom configurations Task 7 compares against: Text-only, Headings-only, both (different files), shared file, and Custom unselected with saved uploads.
  Later tasks compare against these files; nothing re-captures them.
- [ ] **Step 1: Failing tests.**
  - Compiler: `.t-font-serif{font-family:"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif;font-synthesis:weight style}` present; `.t-font-theme` uses the declared stack; without `face`, `.t-font-theme` uses `FontStacks::SYSTEM`; reset and inherit rules exact; rules sit inside `@layer settings`; the hash changes when `face` changes.
  - `ThemeFaceTest`: default theme on a Serif-bodied site (`theme_font=serif`) renders the Figtree `@font-face` **without** the preload `<link>`; on a Sans site both appear; a theme whose declared face files are missing declares none and errors nothing; a custom theme without `face` renders an unset Heading byte-identical to before (compare against a stored snapshot captured before the change in Step 0).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** `theme.json` gains `face`; `ThemeVocabulary::fromThemeJson` parses it optionally (strings only; otherwise ignored, never an error); compiler emits the built-in rules after the typography rules; `fontFacesStyle()` always returns the `@font-face` block and adds the preload `<link>` only when `usesThemeFace(...)`.
- [ ] **Step 4: Run** plus `tests/Integration/Render` (shard time check). Expected: PASS.
- [ ] **Step 5: Commit** `feat(render): built-in typeface utilities, optional theme face metadata, Theme face always declared`.

---

### Task 5: Resolving a target's typeface — targets and parts, removed and unknown inherit

**The real model.** A style path maps to exactly one target per block (`StyleTargets` `map`), so a block never has two targets that both take typography. Independent text styling inside one block comes from a **target plus a part**: a part (`settings.parts.<name>`) is a separate style record holding only the part's capabilities, and block style classes never reach it. The fixture is the shipped **Links** block: its `title` target (optional) takes `typography`, and its `link` part declares `typography` too (`core/src/Content/Blocks/StarterBlockTypes.php:375-390`). A part's typeface is stored at `settings.parts.<name>.typography.family` (no `.style` segment).

**Files:**
- Modify: `packages/thallo-render/src/Style/BlockStyleEmitter.php`, `RenderContextExtension.php` (inject the snapshot; `resetPerRenderState()` clears its memo), `RenderServiceProvider.php`
- Test: `tests/Integration/Render/TypefaceResolutionTest.php`

**Interfaces:**
- Consumes: `FontLibraryReader::snapshot()` (Task 2), `t-font-*` names (Task 4).
- Produces: in `BlockStyleEmitter::classesFor()`, a winning `font` resolution maps through `snapshot->resolution($id)`: `builtin`/`uploaded` → `ClassNames::forFont($id)`; `missing` → `t-font-inherit`; a reset → `t-font-reset`. `RenderContextExtension::fontSnapshot(): FontLibrarySnapshotView`, memoised per render.

- [ ] **Step 1: Failing tests**, rendering real block templates through `RenderContextExtension::blocks()` (as `tests/Integration/Search/SearchBlockTest::render()` does), never hand-written markup:
  - `testATargetAndAPartUseDifferentFamilies`: a Links block with `settings.style.typography.family = serif` (lands on `title`) and `settings.parts.link.typography.family = <uploaded id>` → the title element carries `t-font-serif`, every link carries `t-font-<id>`.
  - `testABlockClassDoesNotReachAPart`: a style class setting Mono applied to the Links block; the part sets nothing → links carry no `t-font-*`, title carries `t-font-mono`.
  - `testAMissingOptionalTargetEmitsNothingAndBreaksNothing`: Links with no `title` and a family set → no title element, no stray class, links render.
  - `testANestedBlocksTypefaceStaysItsOwn`: a Container holding a Links block, both setting different families on their own targets → each element carries only its block's utility.
  - `testARemovedFamilyInheritsAndKeepsItsId` (stored document unchanged; `t-font-inherit`).
  - `testAnUnknownIdFromAnotherWorkspaceInherits`.
  - `testAClassRemovedFamilyBeatsAnOlderClassFamily` (classes [Serif, removed] → `t-font-inherit`).
  - `testResetEmitsResetAndSuppressesClasses` (class Serif + block reset → `t-font-reset` only).
  - `testAbsentEmitsNothing`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** the `font` branch in the emitter (block targets and the part path both), and bind the snapshot.
- [ ] **Step 4: Run** plus `tests/Integration/Render`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(render): typeface utilities for targets and parts; a removed or unknown family inherits`.

---

### Task 6: The immutable `fonts-{hash}.css` artifact

**Files:**
- Create: `packages/thallo-render/src/Style/FontsArtifact.php`, `FontsArtifacts.php`
- Modify: `RenderController.php` (`themeAsset`, `previewAsset`), `RenderContextExtension.php` (`fonts_stylesheet_url`), `themes/default/templates/layout.twig`, `ThemeAppearanceSource.php` (fingerprint segment), `TemplatePolicy` (allow the function; bump `CACHE_VERSION`), the admin Twig completions mirror
- Test: `tests/Unit/Render/FontsArtifactTest.php`, `tests/Integration/Render/FontsArtifactServingTest.php`

**Interfaces:**
- Consumes: `FontLibrarySnapshotView` (Task 2), `FontStacks::forFallback` (Task 2).
- Produces: `FontsArtifact::compile(FontLibrarySnapshotView $s): string`, `FontsArtifact::hash(FontLibrarySnapshotView $s): string` (16 hex, over the CSS); `FontsArtifacts::forSnapshot(FontLibrarySnapshotView): array{hash,css}` (publish-then-return), `read(string $hash): ?string`, `fileName($hash)` = `fonts-{hash}.css`, `hashFromFileName()`; Twig `fonts_stylesheet_url()`; fingerprint segment `l<hash8>`.

CSS per active uploaded family:

```css
@font-face{font-family:"thallo-font-<id>";src:url("<blob url>") format("woff2");font-weight:<min> <max>;font-style:<normal|italic>;font-display:swap}
@layer settings{.t-font-<id>{font-family:"thallo-font-<id>",<FontStacks::forFallback(fallback)>;font-synthesis:style}}
```

A face with `unknown = true` keeps the compatibility declaration `font-weight:100 900` and `font-style:normal`.

- [ ] **Step 1: Failing tests.** `testADisplayNameNeverReachesCss` (name `Brand"}</style><b>`); `testOneSnapshotDrivesCssLinkAndFingerprint`; `testRemovedFamiliesAreNotDeclared`; `testAnOldHashServesItsOwnVersionAfterAnEdit` (render page → hash A; add a family → hash B; GET `fonts-A.css` returns A's bytes); `testPublishBeforeLink` (the file exists before `fonts_stylesheet_url()` returns); `testRetention` (newest three + younger than a day kept); serving headers `text/css`, `immutable`; preview serving `no-store`; stage and public page link the same hash.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, reusing `CompiledStyleArtifacts`' publish/prune logic (extract a shared `HashedArtifactStore` if it stays small; otherwise duplicate the 30 lines with a comment). Link in `layout.twig` immediately after `settings_stylesheet_url()`.
- [ ] **Step 4: Run** plus `tests/Integration/Render` and `BlocksRenderingTest` (cache version pin). Expected: PASS.
- [ ] **Step 5: Commit** `feat(render): an immutable per-workspace fonts stylesheet`.

---

### Task 7: Appearance Custom from the library — settings, preview, synthesis tokens, and the upgrade

**Files:**
- Modify (every place that still carries media IDs for fonts):
  - `core/src/Settings/GeneralSettings.php` — new keys `theme_font_text_family` → `thallo.theme.font_text_family`, `theme_font_headings_family` → `thallo.theme.font_headings_family`; a raw `storedValue(string $key): ?string` that returns `null` when the row is **absent** and `''` when an empty value is **stored**.
  - `core/src/Http/DTOs/UpdateGeneralSettingsData.php` — `theme_font_text_family`, `theme_font_headings_family` (nullable strings; `theme_font_body`/`theme_font_display` removed from the request once the upgrade has run).
  - `core/src/Http/Controllers/GeneralSettingsController.php` — validation (each family ID `FontId::isValid` and, when non-empty, a built-in or an active uploaded family; otherwise `unknown typeface`), save under the appearance lock (below), `ThemeAppearanceChanged` dispatched on a family change.
  - `packages/thallo-contracts/src/Settings/ThemeAppearanceProvider.php` — `fontFaces()` replaced by `fontFamilies(): array{text?: string, headings?: string}` (IDs).
  - `core/src/Settings/EngineThemeAppearanceProvider.php`, `packages/thallo-render/src/ThemeAppearanceSource.php` (fingerprint: `f<text>.<headings>` family IDs instead of blob IDs), `packages/thallo-render/src/Theme/ThemeDesign.php` (`css()` takes resolved roles), `RenderContextExtension.php` (`themeColorsStyle()`, preview override keys, `effectiveFontFaces()` replaced by `effectiveFontFamilies()` resolving through the snapshot).
  - Preview: `core/src/Content/Http/DTOs/MintPreviewData.php` (`font_text_family`, `font_headings_family`; `none` clears a saved one), `core/src/Content/Http/Controllers/PreviewController.php` (validates them like the save does; the token carries them), the token's design payload reader in `RenderContextExtension::setThemeAppearanceOverride()`.
  - Admin: `admin/src/queries/generalSettings.ts` (types), `admin/src/pages/appearance/components/AppearancePreview.vue` (sends the family IDs), `admin/src/api/schema.d.ts` (regenerated in Task 12, typed by hand here if Task 12 has not run).
  - `packages/thallo-render/themes/default/assets/site.css` — `body { … font-synthesis: var(--font-synthesis-body, weight style) }`; the `h1…h4` rule gains `font-synthesis: var(--font-synthesis-display, weight style)`.
  - `core/src/Setup/Console/ProvisionCommand.php` — runs `FontLibraryUpgrade` in every workspace.
- Create: `core/src/Content/Fonts/FontLibraryUpgrade.php`
- Test: `tests/Integration/Setup/FontLibraryUpgradeTest.php`, `tests/Integration/Render/AppearanceCustomFamiliesTest.php`, `tests/Integration/Http/AppearanceFamiliesPreviewTest.php`, `admin/src/__tests__/appearancePreviewFamilies.spec.ts`

**Interfaces:**
- Consumes: `FontLibrary`, the snapshot, `FontStacks` (Task 2); the Step 0 captures (Task 4).
- Produces: `ThemeDesign::css(string $radius, string $font, string $background, string $neutral, ?array $text = null, ?array $headings = null)` with each role `array{stack: string, synthesis: string}|null`; tokens `--font-body`, `--font-display`, `--font-synthesis-body`, `--font-synthesis-display`; `FontLibraryUpgrade::run(): array{created: int, assigned: array{text: bool, headings: bool}}`; per-workspace marker setting `thallo.fonts.custom_migrated`.

**Rendering rules:** Custom with an active Text family → `--font-body` its stack and `--font-synthesis-body` its policy (`style` uploaded, `weight style` built-in); Headings active → `--font-display` likewise; Headings absent or removed → follows Text; Text absent or removed → the theme's face (no body tokens).

**The appearance lock.** Both the upgrade and `GeneralSettingsController::update()` take the same per-workspace lock before reading or writing any font assignment: `SELECT … FROM settings WHERE key = 'thallo.fonts.appearance_lock' FOR UPDATE` (the row is created on first use, `INSERT … ON CONFLICT DO NOTHING` then the `SELECT … FOR UPDATE`, inside the same transaction). Order relative to the library: appearance lock → blob rows → family rows.

**The upgrade, exactly:**
- **Every workspace.** `ProvisionCommand` iterates workspaces with the tenancy context runner (`TenantContextRunner::forEachTenant()` when enforcement is active; otherwise once, single-store), and runs `FontLibraryUpgrade::run()` inside each.
- **Marker per workspace.** `thallo.fonts.custom_migrated` lives in that workspace's `settings`; present → the step does nothing.
- **One transaction per workspace**, under the appearance lock: read the legacy keys (`theme_font_body`, `theme_font_display`) and the new keys' **raw** states; create or reuse families; write assignments; write the marker. A crash before commit leaves no families, no assignments and no marker, so a retry starts clean. Files are read **before** the transaction (their blobs re-checked under the lock, per Task 2).
- **Untouched-only.** A new assignment key is written only when its stored value is **absent** (`storedValue() === null`). A stored empty string means someone deliberately cleared it — never overwritten. `theme_font` is never changed.
- **Shared file → one family**; names `Site text`, `Site headings`, or `Site font` when shared; fallback `system-ui` (today's stack ends in the system stack). Unreadable → a face with `unknown = true`, declared `100 900` normal.
- **Unselected Custom** (`theme_font != custom`): families and assignments are created, inactive because `theme_font` is unchanged.
- After the marker exists, the legacy keys are ignored by rendering and refused by the request DTO.

- [ ] **Step 1: Failing tests.**
  - Upgrade: repeatable (a second run creates nothing); shared file → one family; Text-only, Headings-only, both, unselected — each assignment as specified; **explicit clear** (new key stored as `''` before the run → left `''`); an assignment changed after the marker is never restored; **concurrent save** (a `RowLockHolder` connection holds the appearance lock while saving a new Text family; the upgrade waits, then sees a stored value and leaves it); **interrupted retry** (throw inside the transaction after family creation; no families, assignments or marker remain; the next run completes); unreadable → unknown face; every workspace visited, each with its own marker (opt-in harness).
  - Rendering, against the Step 0 captures from Task 4: for Text-only, Headings-only, both and shared, the sources and role assignments are identical; the declarations differ exactly by the recorded list — family names `thallo-font-<id>`, weights and styles from the file, `font-synthesis` (now `style` for uploaded roles) — and the test asserts that list, synthesis included. A removed Text family falls back to the theme face; removed Headings follow Text.
  - Re-keying: changing Text from family A to family B changes the page fingerprint (a cached page is not served) while `fonts-{hash}.css` stays the same hash (the library didn't change).
  - Preview (`AppearanceFamiliesPreviewTest`): minting a preview with an unsaved `font_text_family` renders that family's tokens; `none` previews the fallback (theme face) for a saved Text family; an invalid ID is a 422; saving then reloading `GET /settings/general` returns the saved IDs.
  - Admin (`appearancePreviewFamilies.spec.ts`): the preview payload carries the selected family IDs, `none` after clearing a saved one.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, in this order: settings keys and `storedValue()`; contract change and both providers; `ThemeDesign::css()` and `themeColorsStyle()`; DTOs, controller and preview; `site.css`; `FontLibraryUpgrade` and its provision wiring; admin types and `AppearancePreview.vue`.
- [ ] **Step 4: Run** the tests plus `tests/Integration/Render`, `tests/Integration/Http/GeneralSettings*`, `tests/Integration/Content/Preview*`, admin vitest. Expected: PASS.
- [ ] **Step 5: Commit** `feat(appearance): Custom picks Text and Headings from the font library; uploads migrate once per workspace`, with the changelog Upgrade Note describing the deliberate rendering change.

---

### Task 8: Usage scan, the admin API, and protected media

**Files:**
- Create: `core/src/Content/Fonts/FontUsage.php`, `core/src/Content/Fonts/Http/FontLibraryController.php`
- Modify: `core/routes/admin.php`, `core/src/Http/Controllers/MediaAdminController.php`, `CoreServiceProvider.php`
- Test: `tests/Integration/Http/FontLibraryApiTest.php`, `tests/Integration/Content/Fonts/FontUsageTest.php`, `tests/Integration/Content/Fonts/MediaFontGuardRaceTest.php`

**Interfaces:**
- Consumes: `FontLibrary` (incl. `lockBlob`, `isLibraryBlob`), `BlockDocumentSources`, `GeneralSettings`.
- Produces routes (all inside the admin group with `auth`, `tenant_profile:admin`, `tenant_bootstrap`, `admin_tenant_binding`):
  - `GET /fonts` — `content_permission:content.edit,content.manage,templates.manage,styles.manage` → `{families: [{id, name, kind: 'builtin'|'uploaded', fallback, removed, faces: [{blob_uuid?, url, weight_min, weight_max, italic, variable, unknown}]}], theme_face: {declared: bool, family: ?string, files: [{url, weight, style}]}}` — built-ins first; removed families included (`removed: true`) so editors can label stored values; **no usage**. For the Theme built-in, `files` come from the theme's optional `face.files` (Task 4) filtered to files that exist.
  - `GET /fonts/{id}/usage` — `content.manage` → `{entries: [{uuid, title, locale, draft: bool, published: bool}], regions: [slug], layouts: [{id, name}], saved_sections: [{id, name}], style_classes: [{id, name}], appearance: {text: bool, headings: bool}}`.
  - `POST /fonts` `{name, fallback, blob_uuids[]}`, `PATCH /fonts/{id}` `{name?, fallback?}`, `POST /fonts/{id}/faces` `{blob_uuid}`, `DELETE /fonts/{id}/faces/{blob_uuid}`, `DELETE /fonts/{id}`, `POST /fonts/{id}/restore`, `DELETE /fonts/{id}/permanent`, `POST /fonts/{id}/read-again` — all `content.manage`; a reader refusal is a 422 carrying `UnreadableFont::reason` and the file name.
  - `MediaAdminController::destroy()` — inside one transaction: `FontLibrary::lockBlob($uuid)` (the blob row, first in the shared lock order), then `isLibraryBlob($uuid)`; true → 409 `This file is a font in the library (<family name>); remove it there first.`; false → the existing soft delete. A concurrent `addFace` of that blob blocks on the same blob lock, then sees `status = 'deleted'` and refuses.
- `FontUsage::of(string $id): array` (the usage shape) scanning, in every block-document source, `block.settings.style.typography.family` and **`block.settings.parts.<name>.typography.family`**, plus `style_classes.style.typography.family` and the two Appearance keys — workspace-scoped through the sources' own scoping.

- [ ] **Step 1: Failing tests.**
  - Access: picker read 200 for a user holding **only** `content.edit`; only `content.manage`; only `templates.manage` (no `content.edit`); only `styles.manage` (no `content.edit`) — using `GrantsPermissions`; 403 with none; the picker response has no usage keys; usage and every mutation 403 without `content.manage`.
  - Lifecycle: create from blobs; 422 with the reason for `not-a-font.woff2`; rename; remove (usage still listed); restore; permanent delete only when removed; attaching another workspace's blob refused (opt-in harness); attaching a deleted blob refused.
  - Usage: one fixture per source — draft, published, version, region, layout, saved section, style class, a Links **part** (`settings.parts.link.typography.family`), Appearance text and headings — each found; another workspace's documents never counted (opt-in harness).
  - Media guard: deleting a library family's blob → 409; after permanent delete → allowed.
  - `MediaFontGuardRaceTest` (`RowLockHolder`): (a) a second connection holds the blob lock mid-`destroy` (blob already marked deleted, not committed); `addFace` waits, then refuses; (b) a second connection holds the blob lock mid-`addFace` (face inserted, not committed); `destroy` waits, then sees a library blob and answers 409.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run**, including once with `API_USE_PREFIX=true` for `FontLibraryApiTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(fonts): the font library API, usage scan, and protected font files`.

---

### Task 9: The Typeface control, faces line, Weight marks, removed/unknown states

**Files:**
- Create: `admin/src/queries/fontLibrary.ts`, `admin/src/fonts/loadFamilyFaces.ts`, `admin/src/editor/inspector/controls/FontFamilyControl.vue`
- Modify: `admin/src/editor/inspector/StyleTab.vue` (`LABELS['typography.family'] = 'Typeface'`, before Size), `controls/ResponsiveField.vue` (font kind → `FontFamilyControl` through the `control` slot), `classFieldState.ts` (font branch), `controls/ChoiceControl.vue` (a `marks` prop for Weight)
- Test: `admin/src/__tests__/fontFamilyControl.spec.ts`, `styleTabTypeface.spec.ts`, `classFieldStateFont.spec.ts`, `loadFamilyFaces.spec.ts`

**Interfaces:**
- Consumes: `GET /fonts` (Task 8), schema kind `font` (Task 3).
- Produces: `useFontLibrary()` (Pinia Colada query `['fonts']`); `facesLabel(family): string`; `suppliedWeights(family): Set<number> | null` (null for built-ins and unknown faces); `loadFamilyFaces(family, themeFace): Promise<void>`; `FontFamilyControl` props `{value: {type, value} | null, context: 'block' | 'part' | 'class', computed?: {weight: number, style: string} | null}`, emits `pick`.

**Font loading for specimens — one mechanism: the `FontFace` API.**
- Each uploaded **face** registers its own `FontFace` with its descriptors: `new FontFace('thallo-font-<id>', `url("<url>") format("woff2")`, { weight: min === max ? `${min}` : `${min} ${max}`, style: italic ? 'italic' : 'normal', display: 'swap' })`, added to `document.fonts` lazily when the picker opens; a face with `unknown` registers `weight: '100 900'`, `style: 'normal'` (its compatibility declaration). No stylesheet link is used in the admin.
- **Theme's specimen** registers `theme_face.files` the same way under the family `thallo-theme-face`; with no declared files, the specimen uses the theme's stack as-is.
- Registration is idempotent per face (a module-level `Set` of `<id>:<blob_uuid>`); a failed load leaves the option set in its stack's fallback.

**Behaviour:**
- Groups **Built-in** / **Your fonts**; each option's text set in its own face.
- Faces line: "Faces: 400, 700, 400 italic" / "300–900 variable" / "Unknown faces" / "Provided by the visitor's device" / "Supplied by the theme" (Theme, declared files present) / "This theme declares no face; Theme uses the system stack".
- Weight control marks unsupplied weights "— not in this family", still selectable; no marks for built-ins or unknown faces.
- Removed/unknown: a first disabled option "Removed typeface: <name>" / "Unknown typeface (<id>)", the line "Renders inheriting the enclosing font.", actions Choose another / Clear / (with `content.manage`) "Restore in Site › Appearance".
- "Use theme default" (reset) with help "Returns this target to its contextual default."; Theme option help "The theme's original face."
- Class context: the same list and faces line; no computed notice; keeps "Use theme default", "Remove", "Not set in this class".

- [ ] **Step 1: Failing specs** for every behaviour above (mock `useFontLibrary` and `document.fonts`), including: one `FontFace` per face with exact descriptors; Theme files registered under `thallo-theme-face`; idempotent registration; the `classFieldState` font branch (`set` for a valid ID, `invalid` for a malformed one, `set` with the removed label for a removed one).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `pnpm type-check`, the new and inspector specs, `pnpm lint`, `pnpm exec oxfmt` on touched files. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): the Typeface control on every typography target and in style classes`.

---

### Task 10: Stage-only target markers, computed typography, and the not-supplied notice

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (`styleClasses()` appends stage-only marker classes when `annotateBlocks` is true), `packages/thallo-render/assets/preview/preview-bridge.js`, `admin/src/composables/useCanvasBridge.ts`, `admin/src/editor/inspector/controls/FontFamilyControl.vue`
- Test: `tests/Integration/Render/StageTargetMarkersTest.php`, `tools/runtime-browser/tests/typography-bridge.spec.js` (with a fixture **rendered by the real renderer**: `scripts/build-runtime-fixtures` or the existing runtime fixture builder, extended to write a stage render of a page holding a Links block inside a Container and a Links block with no title), `admin/src/__tests__/canvasBridgeTypography.spec.ts`

**Markers.** The renderer emits no target or part markers today (only the block wrapper's `data-thallo-block`), and part elements call `style_classes('<part>')` without `style_attrs()`. So the markers are **classes**, added by `style_classes()` itself, **only on the stage** (`annotateBlocks`): `thallo-stage-target--<target>` for a target, `thallo-stage-part--<part>` for a part. `TargetLint` already requires every target to go through `style_classes()`, so every target and part element gets its marker; public pages never do.

**Interfaces:**
- Produces messages: parent → stage `thallo:typography-request {id, target, seq}` (`target` is a target or part name); stage → parent `thallo:typography-state {id, target, seq, weight: number, style: 'normal'|'italic'|'oblique'}` from `getComputedStyle` of the **first element inside the block's own wrapper** carrying `.thallo-stage-target--<target>` or `.thallo-stage-part--<target>` whose nearest `[data-thallo-block]` ancestor is that wrapper (so a nested block's same-named target is never read); no matching element (an absent optional target) → no reply.
- `useCanvasBridge().requestTypography(id, target): Promise<{weight, style} | null>` — resolves only for the latest `seq` per `(id, target)`; older or mismatched replies are dropped; resolves `null` after 1 s without a reply; re-requested after each stage render and on selection change.

Notice text (block/part context, only when `suppliedWeights` is non-null): weight not supplied → "<weight> isn't supplied by <name>; the browser will select an available face."; italic requested with no italic face → "There's no italic face; the browser may slant the text."

- [ ] **Step 1: Failing tests.**
  - `StageTargetMarkersTest` (real templates): on the stage, a Links block's title carries `thallo-stage-target--title` and each link `thallo-stage-part--link`; a Links block without a title has no title marker; a nested Links inside a Container carries markers inside its own wrapper only; the public render carries none.
  - Runtime-browser (the real stage render): a request for `(linksId, title)` reports the title's computed weight; `(linksId, link)` reports the first link's; `(outerId, title)` for a Container never reads the nested block's title; a missing optional title sends nothing.
  - Admin: replies keyed by `(id, target, seq)`; a stale `seq` is ignored; a selection change drops pending replies; the 1 s timeout resolves `null`; notice wording exact; no notice for built-ins, unknown faces or class context.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Integration/Render/StageTargetMarkersTest.php`, `cd tools/runtime-browser && npm test -- typography-bridge`, admin vitest. Expected: PASS.
- [ ] **Step 5: Commit** `feat(stage): stage-only target markers, computed weight and style, and the not-supplied notice`.

---

### Task 11: The Typefaces card, Appearance pickers, and keeping open stages fresh

**Files:**
- Create: `admin/src/pages/appearance/components/{TypefacesCard,FontFamilyDialog,FontUsageDialog,FontFamilyPicker}.vue`, `admin/src/composables/useAppearanceChanges.ts`
- Modify: `admin/src/pages/appearance/index.vue` (card; Custom's Text/Headings use `FontFamilyPicker`; `FontFaceField` removed), `admin/src/queries/fontLibrary.ts` (mutations), `admin/src/editor/stage/useStageEditor.ts` (freshness and `refreshAfterAppearanceChange()`), the regions and layout stage editors that share it, `packages/thallo-render` stage/session responses (add `appearance_fingerprint` beside `style_generation`)
- Test: `admin/src/__tests__/typefacesCard.spec.ts`, `appearanceCustomFamilies.spec.ts`, `appearanceChanges.spec.ts`, `stageAppearanceFreshness.spec.ts`, e2e `admin/e2e/tests/typeface.spec.ts`

**Card behaviour:** Built-in list with specimens; Your fonts rows (name escaped, specimen, faces line, fallback, usage count fetched lazily); Add family dialog (name, fallback, `.woff2` drop; each file uploaded via `useUploadMedia`, then `POST /fonts`; per-file reasons from the 422; duplicate face flagged); Edit; Delete → `FontUsageDialog` grouped with links, the copy distinguishing "Blocks using it inherit their parent's font" from "Appearance falls back: Text to the theme's face, Headings to Text"; Removed (collapsed) with Restore / Delete permanently; Read again on a family with unknown faces, warning "If this succeeds, the font may render differently." Appearance Custom: Text/Headings `FontFamilyPicker` (library families + "Add a font…" opening `FontFamilyDialog` and selecting the new family on save).

**Change notification across tabs, and freshness:**
- `useAppearanceChanges()` owns a `BroadcastChannel('thallo-appearance')` (falling back to the `storage` event on a `localStorage` key where `BroadcastChannel` is absent). After any successful library mutation or Appearance save, the tab posts `{kind: 'fonts' | 'appearance', at: Date.now()}`.
- **Freshness check for changes made elsewhere** (another browser, another admin): every stage render response already carries `style_generation`; it now also carries `appearance_fingerprint` (the `ThemeAppearanceSource::fingerprint()` the render used, which includes the fonts artifact hash and theme). The stage editor remembers the last value; on window focus and every 60 s while the editor is visible it asks the session for the current fingerprint (`GET` the existing session endpoint, now including it). A different value means a change happened elsewhere.
- **Reload, coordinated with applies:** `refreshAfterAppearanceChange()` (on a broadcast or a freshness mismatch) waits for the in-flight apply to settle (the editor's existing apply queue), then performs a **full iframe reload of the working copy** (the in-place patch cannot replace head stylesheet links). Edits made while the reload is in progress are queued by the existing edit-end re-arm and applied after the stage reports ready; the working copy and undo history live in the admin and are untouched.

- [ ] **Step 1: Failing specs.**
  - Card and pickers: each behaviour above.
  - `appearanceChanges.spec.ts`: a mutation posts on the channel; another instance receives it; the `storage` fallback path works.
  - `stageAppearanceFreshness.spec.ts`: (a) **two tabs** — Appearance saved in tab A (broadcast) while tab B's stage has an unsaved edit: tab B waits for its pending apply, reloads once, keeps the edit and the undo stack; (b) **an edit arriving during the reload** is applied after the stage reports ready, exactly once; (c) **a change made elsewhere** (no broadcast): a focus event fetches a different `appearance_fingerprint` and reloads; an unchanged fingerprint does nothing; (d) removal then restoration of the selected family each trigger one reload and keep the selection.
  - E2E (`typeface.spec.ts`; the fixture builder seeds one family from `tests/fixtures/fonts/variable.woff2` and captures `api/fonts.json`; `admin/e2e/helpers.ts` routes `/fonts`; a `typeface-applied` regions scenario): choose the uploaded family on the header Links title and see `t-font-<id>` on the stage; the style-class editor shows the control without a computed notice.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** admin gates; rebuild e2e fixtures (`CACHE_DRIVER=array`); `pnpm --dir admin/e2e test --workers=8`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): the Typefaces card, library pickers for Custom, and fresh stages across tabs`.

---

### Task 12: Docs, changelog, and the full gates

**Files:**
- Modify: `docs/reference/05-style-settings.md` (Typeface row, the `font` kind, `t-font-*`, reset wording), `docs/guides/01-appearance.md` (Typefaces card, Custom from the library), `docs/guides/13-make-a-theme.md` (optional `face`, synthesis tokens), `docs/guides/05-style-classes.md`, `docs/guides/08-media.md` (font files protected), `docs/concepts/04-themes.md`, `CHANGELOG.md` (`[Unreleased]`: Added/Changed/Upgrade Notes with the deliberate rendering change), and the API references: admin routes **are** in `docs/openapi.json` — regenerate it for the `/fonts` routes and the changed settings/preview DTOs (`CACHE_DRIVER=array php glueful docs:openapi`, then hand-splice only the changed operations, per the OpenAPI note), then `pnpm --dir admin gen:api` to regenerate `admin/src/api/schema.d.ts`, and drop any hand-written types Task 7 added in its place.

- [ ] **Step 1: Write the docs** in the guides' voice.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Unit/Docs`. Expected: PASS.
- [ ] **Step 3: Gates, never concurrently:** phpcs (exit 0), boundaries, suite in parts from a fresh reset (unprefixed, then prefixed — routes changed), `composer test:distribution`, `composer test:skeleton`, admin type-check/lint/fmt/vitest, e2e on fresh fixtures, runtime-browser, tenancy harness (one file per process). Time each shard; keep under ~5 minutes locally (move a directory to a lighter shard if not, recording the move).
- [ ] **Step 4: Commit** `docs(fonts): per-block typeface, the font library, and upgrade notes`.

---

## Self-review

| Spec section | Task(s) |
|---|---|
| §2.1 identity | 2, 3 |
| §2.2 built-ins, Theme always declared | 2, 4 |
| §2.2.1 themes without metadata | 4, 11 (theme switch refresh) |
| §2.3 faces read from file, stack, synthesis | 1, 2, 6 |
| §2.4 fallback cases | 6 (`font-display: swap`, stacks), 10 (notices) |
| §2.5 unreadable files | 1, 7, 11 |
| §2.6 lifecycle, protected media | 2, 8, 11 |
| §2.7 upgrade | 7 |
| §2.8 Appearance assignments | 7, 11 |
| §3.1–3.3 setting, reset, per-target emission | 3, 4, 5 |
| §3.4 layers, site-wide tokens, precedence | 4, 6, 7 |
| §3.5 immutable artifact | 6 |
| §3.6 loading | 4, 6 |
| §4.1–4.4 inspector | 9 |
| §4.2 computed typography | 10 |
| §4.5 stage refresh | 11 |
| §4.6–4.7 card, pickers | 11 |
| §4.8 access | 8 |
| §5 testing | each task |
| §6 docs | 12 |
