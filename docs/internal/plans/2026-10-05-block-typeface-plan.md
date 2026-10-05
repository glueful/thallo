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

## Rulings made while planning (from the code)

- **Library lives in core**, not the render pack: it owns settings, media and workspace tables, and the admin API sits beside `GeneralSettingsController`. The render pack consumes a neutral contract `Thallo\Contracts\Fonts\FontLibraryReader` (snapshot + theme-free stacks), so render never imports core. Cost if wrong: a contract move.
- **Media protection is new.** `MediaAdminController::destroy()` has no usage guard today and settings blobs are never counted in `media_usage`. The guard added here covers font-library files only (current and removed families); it does not change other media. Cost if wrong: none for other media.
- **Usage scan reuses `BlockDocumentSources`**, the same sources `StyleClassUsage` walks (`entry_drafts`, `entry_versions`, published, `regions`, `saved_sections`, `layouts`), plus `style_classes.style` and the two Appearance assignments.
- **Picker read route** uses the existing any-of middleware: `content_permission:content.edit,content.manage,templates.manage,styles.manage` (`RequirePermission` splits on commas; any-of).
- **Fonts artifact serving** reuses `RenderController::themeAsset()` / `previewAsset()` (they already branch on artifact file names), adding a `fonts-{hash}.css` branch.
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
3. **A 10 MB `.woff2` or a decompression bomb on upload** — the reader enforces a decompressed-size cap and a time budget and refuses with a reason, never exhausting memory. Pinned in Task 1 (`testABombIsRefused`).
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

**Admin** — `src/style/schema.ts`, `src/queries/fontLibrary.ts`, `src/editor/inspector/controls/FontFamilyControl.vue`, `src/editor/inspector/StyleTab.vue`, `src/editor/inspector/controls/ResponsiveField.vue`, `src/editor/inspector/classFieldState.ts`, `src/composables/useCanvasBridge.ts`, `src/pages/appearance/index.vue`, `src/pages/appearance/components/TypefacesCard.vue`, `FontFamilyDialog.vue`, `FontUsageDialog.vue`, `FontFamilyPicker.vue` (replaces `FontFaceField.vue` for Custom).

**Tests** — `tests/fixtures/fonts/` + `scripts/build-font-fixtures.py`; `tests/Unit/Fonts/*`, `tests/Integration/Content/Fonts/*`, `tests/Integration/Render/Typeface*`, `tests/Integration/Http/FontLibraryApiTest.php`, `tests/Integration/Setup/FontLibraryUpgradeTest.php`; admin `src/__tests__/fontFamilyControl.spec.ts`, `typefacesCard.spec.ts`, `canvasBridgeTypography.spec.ts`; e2e `admin/e2e/tests/typeface.spec.ts` (+ scenario in `regions-scenarios.json`).

---

### Task 1: The WOFF2 reader, its dependency, and the fixture proof

**Files:**
- Create: `scripts/build-font-fixtures.py`, `tests/fixtures/fonts/*` (generated, committed), `core/src/Content/Fonts/{Woff2FaceReader,FaceMetadata,UnreadableFont}.php`, `core/src/Content/Fonts/Brotli/{BrotliDecoder,ExtBrotliDecoder,PurePhpBrotliDecoder,BrotliDecoders}.php`, `core/src/Content/Fonts/vendor-brotli/` (if selected), `tests/Unit/Fonts/BrotliDecoderTest.php`, `tests/Unit/Fonts/Woff2FaceReaderTest.php`
- Modify: `composer.json` (only if a Packagist decoder is selected)

**Interfaces:**
- Produces: `interface BrotliDecoder { public function decode(string $compressed, int $maxOutput): string; }` (throws `UnreadableFont` on corrupt input or output beyond `$maxOutput`); `BrotliDecoders::best(): BrotliDecoder`; `final class FaceMetadata { public function __construct(public int $weightMin, public int $weightMax, public bool $italic, public bool $variable) }`; `final class Woff2FaceReader { public function __construct(BrotliDecoder $brotli, int $maxDecompressed = 33554432) ; public function read(string $path): FaceMetadata }`; `final class UnreadableFont extends \RuntimeException` with `public readonly string $reason` (one of the user-facing strings below).

**Decision procedure (record the outcome as a ledger ruling):**
1. `ExtBrotliDecoder` uses `brotli_uncompress()` when `extension_loaded('brotli')`; never required.
2. The universal decoder must be **pure PHP** (no extension, no `proc_open`/`exec`, no FFI) because shared hosts lack all three. Candidates, in order: (a) the MIT PHP output of BrotliHaxe, vendored under `core/src/Content/Fonts/vendor-brotli/` with its license and a provenance note (commit and generator command); (b) any pure-PHP decoder on Packagist found at selection time that meets the criteria. Exec-based packages (`n5s/brotli`, `vdechenaux/brotli`) are excluded.
3. **Acceptance criteria** (all, proven by the tests below): decodes every Brotli vector byte-exact; decodes every WOFF2 fixture; rejects truncated/corrupt streams with `UnreadableFont`, never a PHP error; enforces `$maxOutput` (stops decoding at the cap); decodes `variable.woff2` (≈20 KB) in under 2 s and a 1 MB font in under 20 s on the CI runner; MIT/BSD/Apache-compatible license.
4. **If no pure-PHP candidate passes: STOP and ask the user** (spec §2.3 makes this a pre-implementation gate). Do not fall back to trusting labels.

- [ ] **Step 1: Write the fixture generator**

`scripts/build-font-fixtures.py` (dev-only; uses `fontTools` and `brotli`):

```python
#!/usr/bin/env python3
"""Build tests/fixtures/fonts/ — the WOFF2 reader's proof set (block typeface plan, Task 1).
Sources: the default theme's Figtree (OFL), so the fixtures carry its licence."""
import os, random, brotli
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'packages/thallo-render/themes/default/assets/fonts')
OUT = os.path.join(ROOT, 'tests/fixtures/fonts')
os.makedirs(OUT, exist_ok=True)

def save(font, name):
    font.flavor = 'woff2'
    font.save(os.path.join(OUT, name))

roman = TTFont(os.path.join(SRC, 'figtree-roman-latin.woff2'))
save(TTFont(os.path.join(SRC, 'figtree-roman-latin.woff2')), 'variable.woff2')        # fvar wght range
save(TTFont(os.path.join(SRC, 'figtree-italic-latin.woff2')), 'variable-italic.woff2')
bold = instancer.instantiateVariableFont(TTFont(os.path.join(SRC, 'figtree-roman-latin.woff2')), {'wght': 700})
save(bold, 'static-700.woff2')                                                          # usWeightClass 700, no fvar
regular_italic = instancer.instantiateVariableFont(TTFont(os.path.join(SRC, 'figtree-italic-latin.woff2')), {'wght': 400})
save(regular_italic, 'static-400-italic.woff2')

data = open(os.path.join(OUT, 'variable.woff2'), 'rb').read()
open(os.path.join(OUT, 'truncated.woff2'), 'wb').write(data[: len(data) // 2])
open(os.path.join(OUT, 'not-a-font.woff2'), 'wb').write(b'<html>not a font</html>')
roman.flavor = 'woff'
roman.save(os.path.join(OUT, 'wrong-flavour.woff'))                                     # WOFF 1, refused

# A bomb: a valid WOFF2 header whose Brotli stream inflates past the cap.
# The header's totalSfntSize claims 64 MiB, and the stream is 64 MiB of zeros compressed.
zeros = brotli.compress(b'\0' * (64 * 1024 * 1024), quality=5)
header = bytearray(data[:48])
header[16:20] = (64 * 1024 * 1024).to_bytes(4, 'big')        # totalSfntSize
open(os.path.join(OUT, 'bomb.woff2'), 'wb').write(bytes(header) + zeros)

# Brotli conformance vectors: (raw, compressed) pairs across qualities and window sizes.
random.seed(7)
vec = os.path.join(OUT, 'brotli')
os.makedirs(vec, exist_ok=True)
samples = {
    'empty': b'',
    'text': open(os.path.join(ROOT, 'LICENSE'), 'rb').read(),
    'random-4k': bytes(random.getrandbits(8) for _ in range(4096)),
    'repeat-256k': b'thallo ' * 37449,
    'font-tables': data,
}
for name, raw in samples.items():
    for q in (0, 5, 11):
        open(os.path.join(vec, f'{name}.q{q}.br'), 'wb').write(brotli.compress(raw, quality=q))
    open(os.path.join(vec, f'{name}.raw'), 'wb').write(raw)
print('fixtures written to', OUT)
```

Run: `python3 scripts/build-font-fixtures.py`. Expected: `fixtures written to …`. Commit the generated files; record `fontTools` and `brotli` versions in `tests/fixtures/fonts/PROVENANCE.md` with the OFL notice copied from the theme's `OFL.txt`.

- [ ] **Step 2: Write the failing decoder test**

`tests/Unit/Fonts/BrotliDecoderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\ExtBrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder;
use Thallo\Core\Content\Fonts\UnreadableFont;

/** Every Brotli decoder Thallo may use decodes the conformance vectors byte-exact (spec §2.3). */
final class BrotliDecoderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts/brotli';

    /** @return iterable<string, array{BrotliDecoder}> */
    public static function decoders(): iterable
    {
        yield 'pure php' => [new PurePhpBrotliDecoder()];
        if (extension_loaded('brotli')) {
            yield 'extension' => [new ExtBrotliDecoder()];
        }
    }

    /** @dataProvider decoders */
    public function testEveryVectorDecodesByteExact(BrotliDecoder $decoder): void
    {
        $vectors = glob(self::DIR . '/*.br') ?: [];
        self::assertNotEmpty($vectors);
        foreach ($vectors as $file) {
            $raw = (string) file_get_contents(preg_replace('/\.q\d+\.br$/', '.raw', $file));
            self::assertSame($raw, $decoder->decode((string) file_get_contents($file), 64 * 1024 * 1024), basename($file));
        }
    }

    /** @dataProvider decoders */
    public function testACorruptStreamIsUnreadableNotAnError(BrotliDecoder $decoder): void
    {
        $this->expectException(UnreadableFont::class);
        $decoder->decode(substr((string) file_get_contents(self::DIR . '/text.q11.br'), 0, 40), 1 << 20);
    }

    /** @dataProvider decoders */
    public function testOutputBeyondTheCapStops(BrotliDecoder $decoder): void
    {
        $this->expectException(UnreadableFont::class);
        $decoder->decode((string) file_get_contents(self::DIR . '/repeat-256k.q5.br'), 1024);
    }

    public function testThePurePhpDecoderIsFastEnoughForAFont(): void
    {
        $start = hrtime(true);
        (new PurePhpBrotliDecoder())->decode((string) file_get_contents(self::DIR . '/font-tables.q11.br'), 1 << 24);
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }
}
```

- [ ] **Step 3: Run it.** `vendor/bin/phpunit tests/Unit/Fonts/BrotliDecoderTest.php`. Expected: FAIL, class `PurePhpBrotliDecoder` not found.

- [ ] **Step 4: Implement the decoders.** Vendor the selected pure-PHP decoder under `core/src/Content/Fonts/vendor-brotli/` (namespace it `Thallo\Core\Content\Fonts\VendorBrotli\…`, keep `LICENSE` and `PROVENANCE.md`; add the directory to `phpcs.xml` exclusions). Wrap it:

```php
final class PurePhpBrotliDecoder implements BrotliDecoder
{
    public function decode(string $compressed, int $maxOutput): string
    {
        try {
            $out = \Thallo\Core\Content\Fonts\VendorBrotli\Decoder::decompress($compressed, $maxOutput);
        } catch (\Throwable $e) {
            throw new UnreadableFont('Couldn\'t read this font\'s data', previous: $e);
        }
        if (strlen($out) > $maxOutput) {
            throw new UnreadableFont('This font is too large to read');
        }
        return $out;
    }
}

final class ExtBrotliDecoder implements BrotliDecoder
{
    public function decode(string $compressed, int $maxOutput): string
    {
        $out = @\brotli_uncompress($compressed, $maxOutput + 1);
        if (!is_string($out)) {
            throw new UnreadableFont('Couldn\'t read this font\'s data');
        }
        if (strlen($out) > $maxOutput) {
            throw new UnreadableFont('This font is too large to read');
        }
        return $out;
    }
}

final class BrotliDecoders
{
    public static function best(): BrotliDecoder
    {
        return extension_loaded('brotli') ? new ExtBrotliDecoder() : new PurePhpBrotliDecoder();
    }
}
```

If the vendored decoder has no output cap, add one in its output loop (a counter checked per emitted block; throw past `$maxOutput`) and note the patch in `PROVENANCE.md`.

- [ ] **Step 5: Run it.** Expected: PASS (both decoders where the extension exists). If any criterion fails for every pure-PHP candidate, stop and ask the user.

- [ ] **Step 6: Write the failing reader test** `tests/Unit/Fonts/Woff2FaceReaderTest.php`:

```php
final class Woff2FaceReaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/fonts';

    private function reader(): Woff2FaceReader
    {
        return new Woff2FaceReader(BrotliDecoders::best());
    }

    public function testAVariableFontReportsItsWeightRange(): void
    {
        $face = $this->reader()->read(self::DIR . '/variable.woff2');
        self::assertTrue($face->variable);
        self::assertFalse($face->italic);
        self::assertSame([300, 900], [$face->weightMin, $face->weightMax]);
    }

    public function testAVariableItalicIsItalic(): void
    {
        self::assertTrue($this->reader()->read(self::DIR . '/variable-italic.woff2')->italic);
    }

    public function testAStaticFaceReportsOneWeight(): void
    {
        $face = $this->reader()->read(self::DIR . '/static-700.woff2');
        self::assertFalse($face->variable);
        self::assertSame([700, 700], [$face->weightMin, $face->weightMax]);
        self::assertTrue($this->reader()->read(self::DIR . '/static-400-italic.woff2')->italic);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'truncated' => ['truncated.woff2', 'Couldn\'t read this font\'s data'];
        yield 'not a font' => ['not-a-font.woff2', 'Not a WOFF2 font'];
        yield 'woff 1' => ['wrong-flavour.woff', 'Not a WOFF2 font'];
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

    public function testABombIsRefused(): void
    {
        $start = hrtime(true);
        try {
            (new Woff2FaceReader(BrotliDecoders::best(), 8 * 1024 * 1024))->read(self::DIR . '/bomb.woff2');
            self::fail('read');
        } catch (UnreadableFont $e) {
            self::assertSame('This font is too large to read', $e->reason);
        }
        self::assertLessThan(10.0, (hrtime(true) - $start) / 1e9);
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true));
    }
}
```

- [ ] **Step 7: Run it.** Expected: FAIL, `Woff2FaceReader` not found.

- [ ] **Step 8: Implement `Woff2FaceReader::read()`.**
  - Header (48 bytes): signature `wOF2` (`0x774F4632`) else `Not a WOFF2 font`; `numTables`, `totalSfntSize` (> `maxDecompressed` → `This font is too large to read`), `totalCompressedSize`.
  - Table directory: per entry a flags byte (tag index 0–62 into the known-tag table, or 63 = 4-byte tag follows), `origLength` (UIntBase128), `transformLength` (UIntBase128) when the table is transformed (`glyf`/`loca` with transform version 0, `hmtx` with version 1). Validate UIntBase128 (no leading zero, ≤ 5 bytes, no overflow) → `Couldn't read this font's data`.
  - Decode the single Brotli stream (offset = header + directory, length `totalCompressedSize`) with `$maxOutput = min(totalSfntSize, maxDecompressed)`.
  - Walk tables in directory order summing `transformLength ?? origLength` to find `OS/2`, `head`, `fvar` (none of these is transformed).
  - `OS/2`: `usWeightClass` at offset 4 (uint16; clamp 1–1000); `fsSelection` at 62 bit 0 = italic. `head`: `macStyle` at 44 bit 1 = italic (used when `OS/2` absent). Missing both → `Couldn't read this font's weight table`.
  - `fvar`: if present, find axis `wght` (axes at `axesArrayOffset`, 20-byte records: tag, min Fixed 16.16, default, max); `weightMin/Max` = rounded min/max, `variable = true`; also axis `ital` max ≥ 1 or `slnt` ≠ 0 does not change `italic` (italic comes from the flags only).
  - Static: `weightMin = weightMax = usWeightClass`.

- [ ] **Step 9: Run both tests.** Expected: PASS.

- [ ] **Step 10: Commit.** Ledger: `Task 1: Ruling: decoder = <chosen> — <why, measured times> — cost if wrong: swap behind BrotliDecoder`.

```bash
git add scripts/build-font-fixtures.py tests/fixtures/fonts core/src/Content/Fonts tests/Unit/Fonts phpcs.xml
git commit -m "feat(fonts): read a WOFF2 face's weight, style and range from the file"
```

---

### Task 2: Font IDs, the library tables, and the repository

**Files:**
- Create: `core/src/Content/Fonts/{FontId,FontLibrary,FontLibrarySnapshot}.php`, `packages/thallo-contracts/src/Fonts/{FontLibraryReader,FontFamilyView}.php`, `packages/thallo-contracts/src/Style/FontStacks.php`, `core/database/migrations/042_CreateFontLibraryTables.php`, `tests/Unit/Fonts/FontIdTest.php`, `tests/Integration/Content/Fonts/FontLibraryTest.php`
- Modify: `packages/thallo-tenancy/src/ThalloTenantTables.php`, `packages/thallo-render/src/Theme/ThemeDesign.php` (stacks read from `FontStacks`), `core/src/Providers/CoreServiceProvider.php` (bindings)

**Interfaces:**
- Consumes: `Woff2FaceReader`, `FaceMetadata`, `UnreadableFont` (Task 1).
- Produces:
  - `FontId::isValid(string $id): bool`, `FontId::isReserved(string $id): bool`, `FontId::RESERVED = ['theme','serif','humanist','geometric','slab','mono','system']`.
  - `FontStacks::named(string $id): ?string` for the six device built-ins; `FontStacks::forFallback(string $generic): string` (`sans-serif`→`SYSTEM`'s list minus generic then `sans-serif`; `serif`→SERIF; `monospace`→MONO; `cursive`→`"Snell Roundhand","Segoe Script","Brush Script MT",cursive`; `system-ui`→SYSTEM), `FontStacks::SYSTEM`.
  - Contract `FontLibraryReader { public function snapshot(): FontLibrarySnapshotView; }`; `FontFamilyView { id, name, fallback, removed (bool), faces: list<array{blob_uuid, url, weight_min, weight_max, italic, variable, unknown}> }`; `FontLibrarySnapshotView { public function generation(): int; public function family(string $id): ?FontFamilyView /* includes removed */; public function active(): list<FontFamilyView>; public function resolution(string $id): string /* 'builtin'|'uploaded'|'missing' (removed or unknown) */; }`.
  - `interface FontBlobFiles { public function localPath(string $blobUuid): string; }` (throws `UnreadableFont('That file isn\'t in the media library')`); `StorageFontBlobFiles` (production), `tests/Support/Fonts/FixtureFontBlobFiles` (`__construct(array<string,string> $uuidToPath)`).
  - `FontLibrary`: `create(string $name, string $fallback, list<string> $blobUuids): string` (returns ID; reads each file; throws `UnreadableFont` naming the file), `rename(string $id, string $name)`, `setFallback(string $id, string $fallback)`, `addFace(string $id, string $blobUuid)`, `removeFace(string $id, string $blobUuid)` (refuses the last), `remove(string $id)`, `restore(string $id)`, `purge(string $id)` (removed only), `readAgain(string $id): void`, `snapshot(): FontLibrarySnapshot`, `usedBlobUuids(): list<string>` (current + removed).

**Schema** (`042_CreateFontLibraryTables.php`, the `037` pattern: `string('id',12)->primary()`, `tenant_uuid` nullable + index, widened-state `SET NOT NULL`):
- `font_families`: `id`, `tenant_uuid`, `name` string(120), `fallback` string(16), `removed_at` timestamp nullable, `created_at`, `updated_at`.
- `font_faces`: `id` string(12) primary, `tenant_uuid`, `family_id` string(12) indexed, `blob_uuid` string(12), `weight_min` int, `weight_max` int, `italic` bool, `variable` bool, `unknown` bool default false, `created_at`; unique `(tenant_uuid, family_id, blob_uuid)` via the COALESCE pattern.
- Generation: `SystemChannel` key `fonts.generation` (per workspace through settings? no — store as a row-free counter in `settings` table `thallo.fonts.generation`, workspace-scoped like other settings), bumped in the same transaction as every mutation.

- [ ] **Step 1: Write the failing tests.** `FontIdTest`: reserved names valid; `Ab3dE5fG7hJ9` valid; `abc`, `inherit`, `reset`, `ab_cdefghijk`, `Ab3dE5fG7hJ9x`, `<script>` invalid. `FontLibraryTest` (AppTestCase; inserts `blobs` rows for `static-700.woff2`/`variable.woff2` and binds `FixtureFontBlobFiles` to them):
  - `testCreateReadsFacesFromTheFiles` (faces 700 static, 300–900 variable; name stored; ID 12 chars).
  - `testAnUnreadableFileRefusesTheWholeCreate` (no rows written; `UnreadableFont::reason` surfaced).
  - `testRenameKeepsTheId`, `testTheLastFaceCannotBeRemoved`, `testTheSameFaceTwiceIsRefused`.
  - `testRemoveRestorePurge` (removed family absent from `active()`, present in `family()` with `removed=true`, `resolution()` = `missing`; restore → `uploaded`; purge only after remove; purge deletes faces).
  - `testEveryMutationBumpsTheGeneration`.
  - `testTwoWorkspacesNeverSeeEachOther` (opt-in retrofit harness, `RetrofittedTenantTestCase`).
- [ ] **Step 2: Run them.** Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** the migration, `ThalloTenantTables` entries (`'font_families' => self::row($inst, [])`, `'font_faces' => self::row($inst, [['uniq_font_faces_blob', ['tenant_uuid','family_id','blob_uuid']]])`), `FontStacks` (move the six constants out of `ThemeDesign`; `ThemeDesign` now references `FontStacks::SERIF` etc. — no behaviour change), `FontId`, `FontLibrary`, `FontLibrarySnapshot` (immutable arrays; `generation` read once).
- [ ] **Step 4: Run them, plus** `vendor/bin/phpunit tests/Unit/Render tests/Integration/Render/AppearanceDesignTest.php` (stacks unchanged). Expected: PASS.
- [ ] **Step 5: Commit** `feat(fonts): the workspace font library — stable IDs, faces read from the file, remove and restore`.

---

### Task 3: `typography.family` in the contract, validator and admin schema

**Files:**
- Modify: `packages/thallo-contracts/src/Style/ValueKind.php`, `StyleSchema.php` (VERSION 11), `core/src/Content/Style/SettingsValidator.php`, `packages/thallo-render/src/Http/Controllers/StyleSchemaController.php`, `admin/src/style/schema.ts`, the resolver fixtures (`resolver-fixtures/v1` — regenerate per its README)
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
- Produces: `ThemeVocabulary::face(): ?array{family: string, stack: string}` (from optional `theme.json` `"face": {"family": "Figtree", "stack": "\"Figtree\", \"Figtree Fallback\", system-ui, …"}`); `ThemeVocabulary::themeFaceStack(): string` (declared stack or `FontStacks::SYSTEM`); `ClassNames::STEMS['typography.family'] = 'font'`; `ClassNames::forFont(string $id): string` → `t-font-<id>`; compiled rules `.t-font-{serif|humanist|geometric|slab|mono|system|theme}`, `.t-font-inherit{font-family:inherit;font-synthesis:inherit}`, `.t-font-reset{font-family:revert-layer;font-synthesis:revert-layer}`.

- [ ] **Step 1: Failing tests.**
  - Compiler: `.t-font-serif{font-family:"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif;font-synthesis:weight style}` present; `.t-font-theme` uses the declared stack; without `face`, `.t-font-theme` uses `FontStacks::SYSTEM`; reset and inherit rules exact; rules sit inside `@layer settings`; the hash changes when `face` changes.
  - `ThemeFaceTest`: default theme on a Serif-bodied site (`theme_font=serif`) renders the Figtree `@font-face` **without** the preload `<link>`; on a Sans site both appear; a theme whose declared face files are missing declares none and errors nothing; a custom theme without `face` renders an unset Heading byte-identical to before (compare against a stored snapshot captured before the change in Step 0).
- [ ] **Step 0 (before the change):** capture the default theme's rendered home page `<head>` font section and a custom-theme page body into `tests/fixtures/render/pre-typeface/*.html` for the byte-identical assertion.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** `theme.json` gains `face`; `ThemeVocabulary::fromThemeJson` parses it optionally (strings only; otherwise ignored, never an error); compiler emits the built-in rules after the typography rules; `fontFacesStyle()` always returns the `@font-face` block and adds the preload `<link>` only when `usesThemeFace(...)`.
- [ ] **Step 4: Run** plus `tests/Integration/Render` (shard time check). Expected: PASS.
- [ ] **Step 5: Commit** `feat(render): built-in typeface utilities, optional theme face metadata, Theme face always declared`.

---

### Task 5: Resolving a target's typeface — per target, removed and unknown inherit

**Files:**
- Modify: `packages/thallo-render/src/Style/BlockStyleEmitter.php`, `RenderContextExtension.php` (inject the snapshot), `RenderServiceProvider.php`, `PageStyle.php` if page roots carry typography
- Test: `tests/Integration/Render/TypefaceResolutionTest.php`

**Interfaces:**
- Consumes: `FontLibraryReader::snapshot()` (Task 2), the `t-font-*` names (Task 4).
- Produces: `BlockStyleEmitter::classesFor()` maps a winning `font` resolution through `snapshot->resolution($id)`: `builtin`/`uploaded` → `t-font-<id>`; `missing` → `t-font-inherit`; reset → `t-font-reset`. One snapshot per render (`RenderContextExtension::fontSnapshot()` memoised per request, reset in `resetPerRenderState()`).

- [ ] **Step 1: Failing tests** (render blocks through `RenderContextExtension::blocks()` as `SearchBlockTest::render()` does):
  - `testTwoIndependentlyStyledTargets` (a feature card: title Serif, text uploaded family → two different utilities on two elements).
  - `testABlockClassDoesNotReachAPart` (class sets Serif; the part sets Mono → part `t-font-mono`, block root `t-font-serif`).
  - `testARemovedFamilyInheritsAndKeepsItsId` (stored ID unchanged in the document; output `t-font-inherit`).
  - `testAnUnknownIdFromAnotherWorkspaceInherits`.
  - `testAClassRemovedFamilyBeatsAnOlderClassFamily` (classes [Serif, removed] → `t-font-inherit`).
  - `testResetEmitsResetAndSuppressesClasses` (class Serif + block reset → `t-font-reset` only).
  - `testAbsentEmitsNothing`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** the substitution in the emitter's `font` branch; bind the snapshot.
- [ ] **Step 4: Run** plus `tests/Integration/Render`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(render): per-target typeface utilities; a removed or unknown family inherits`.

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

### Task 7: Appearance Custom from the library, synthesis tokens, and the upgrade

**Files:**
- Modify: `core/src/Settings/GeneralSettings.php` (`theme_font_text_family` → `thallo.theme.font_text_family`, `theme_font_headings_family` → `thallo.theme.font_headings_family`), `GeneralSettingsController.php`, `EngineThemeAppearanceProvider.php`, `packages/thallo-render/src/ThemeAppearanceSource.php`, `Theme/ThemeDesign.php`, `RenderContextExtension.php` (`themeColorsStyle`, `effectiveFontFaces` removed in favour of families), `themes/default/assets/site.css`, `core/src/Setup/Console/ProvisionCommand.php`
- Create: `core/src/Content/Fonts/FontLibraryUpgrade.php`
- Test: `tests/Integration/Setup/FontLibraryUpgradeTest.php`, `tests/Integration/Render/AppearanceCustomFamiliesTest.php`

**Interfaces:**
- Consumes: `FontLibrary` (Task 2), the snapshot and stacks.
- Produces: `ThemeDesign::css($radius, $font, $background, $neutral, ?array $text, ?array $headings)` where each role is `array{stack: string, synthesis: string}|null`; tokens `--font-body`, `--font-display`, `--font-synthesis-body`, `--font-synthesis-display`; `FontLibraryUpgrade::run(): array{created: int, assigned: bool}`; marker setting `thallo.fonts.custom_migrated` = JSON `{"at": "...", "body": "<uuid|''>", "display": "<uuid|''>"}`.

Rules:
- Custom + Text family active → `--font-body` its stack, `--font-synthesis-body` its policy; Headings active → `--font-display`; Headings absent/removed → follows Text; Text absent/removed → theme face (no body tokens).
- `site.css`: `body { font-synthesis: var(--font-synthesis-body, weight style) }`, headings rule gains `font-synthesis: var(--font-synthesis-display, weight style)`.
- Upgrade (inside `thallo:provision`, after migrations): if the marker exists → do nothing. Else, per distinct non-empty `theme_font_body` / `theme_font_display` blob: find a family holding that blob or create one (name `Site text` / `Site headings` / `Site font` when shared; fallback `system-ui`), reading faces; unreadable → face with `unknown=true`, `100–900 normal`. Set `theme_font_text_family` / `theme_font_headings_family` only if those keys are empty (untouched); never change `theme_font`. Write the marker in the same transaction. Unselected Custom (theme_font ≠ custom) still gets families and assignments, inactive because `theme_font` stays as it was.

- [ ] **Step 0:** record, before any change, the rendered `<style>` appearance block for Text-only, Headings-only, both, shared-file and unselected configurations into `tests/fixtures/render/pre-typeface/appearance-*.css`.
- [ ] **Step 1: Failing tests.** Upgrade: repeatable (second run creates nothing); shared file → one family; each configuration's assignments; unselected keeps `theme_font`; an assignment changed after the marker is never restored; untouched-only (pre-set new keys left alone); unreadable → unknown face; workspace-scoped. Rendering: for each configuration, compare against the Step 0 capture — sources and role assignments identical; the declarations differ only as recorded (weights from the file, `font-style`, family names `thallo-font-<id>`, synthesis tokens) — the test asserts that exact difference list. A removed Text family falls back to the theme face; removed Headings follows Text; synthesis tokens present for an uploaded family and `weight style` for built-ins.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**; controller validation: family IDs must be `FontId::isValid` and, when non-empty, an active uploaded family or built-in; legacy face keys stay readable until the marker exists, then are ignored.
- [ ] **Step 4: Run** plus `tests/Integration/Render`, `tests/Integration/Http/GeneralSettings*`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(appearance): Custom picks Text and Headings from the font library; uploads migrate once`, with the changelog Upgrade Note (deliberate rendering change).

---

### Task 8: Usage scan, the admin API, and protected media

**Files:**
- Create: `core/src/Content/Fonts/FontUsage.php`, `core/src/Content/Fonts/Http/FontLibraryController.php`
- Modify: `core/routes/admin.php`, `core/src/Http/Controllers/MediaAdminController.php`, `CoreServiceProvider.php`
- Test: `tests/Integration/Http/FontLibraryApiTest.php`, `tests/Integration/Content/Fonts/FontUsageTest.php`

**Interfaces:**
- Consumes: `FontLibrary`, `BlockDocumentSources`, `StyleClassUsage` (pattern), `GeneralSettings`.
- Produces routes:
  - `GET /fonts` — `content_permission:content.edit,content.manage,templates.manage,styles.manage` → `{families: [{id, name, kind: 'builtin'|'uploaded', fallback, removed, faces:[{weight_min, weight_max, italic, variable, unknown, url}], theme_face?: {declared: bool, family: ?string, faces_present: bool}}]}` (built-ins first; removed included with `removed: true` so editors can label stored values; **no usage**).
  - `GET /fonts/{id}/usage` — `content.manage` → `{entries:[{uuid,title,locale,draft,published}], regions:[slug], layouts:[{id,name}], saved_sections:[{id,name}], style_classes:[{id,name}], appearance:{text:bool, headings:bool}}`.
  - `POST /fonts` `{name, fallback, blob_uuids[]}`, `PATCH /fonts/{id}` `{name?, fallback?}`, `POST /fonts/{id}/faces` `{blob_uuid}`, `DELETE /fonts/{id}/faces/{blob_uuid}`, `DELETE /fonts/{id}`, `POST /fonts/{id}/restore`, `DELETE /fonts/{id}/permanent`, `POST /fonts/{id}/read-again` — all `content.manage`; 422 with the reader's reason for an unreadable file.
  - `MediaAdminController::destroy`: 409 `This file is a font in the library (Brand script); remove it there first.` when `FontLibrary::usedBlobUuids()` contains it (current or removed).
- `FontUsage::of(string $id): array` (the usage shape above), scanning `block.settings.style.typography.family` and `block.settings.parts.*.style.typography.family` in every block-document source, `style_classes.style`, and the two Appearance keys.

- [ ] **Step 1: Failing tests.** Access: picker read 200 for users holding only `content.edit`, only `content.manage`, only `templates.manage`, only `styles.manage` (each without `content.edit` where applicable; use `GrantsPermissions`), 403 with none; picker response has no usage keys; usage and every mutation 403 without `content.manage`. Lifecycle: create from uploaded blobs; 422 reason for `not-a-font.woff2`; rename; remove → usage still listed; restore; permanent delete only when removed. Usage: one fixture per source kind (draft, published, version, region, layout, saved section, style class, part target, Appearance text/headings) each found; another workspace's usage never counted (harness). Media: deleting a family's blob → 409, after permanent delete → allowed.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS. Prefixed run of `tests/Integration/Http/FontLibraryApiTest.php` with `API_USE_PREFIX=true`.
- [ ] **Step 5: Commit** `feat(fonts): the font library API, usage scan, and protected font files`.

---

### Task 9: The Typeface control, faces line, Weight marks, removed/unknown states

**Files:**
- Create: `admin/src/queries/fontLibrary.ts`, `admin/src/editor/inspector/controls/FontFamilyControl.vue`
- Modify: `admin/src/editor/inspector/StyleTab.vue` (`LABELS['typography.family'] = 'Typeface'`, order before Size), `controls/ResponsiveField.vue` (font kind → `FontFamilyControl`), `classFieldState.ts` (font validity), `controls/ChoiceControl.vue` usage for Weight marks via a `marks` prop
- Test: `admin/src/__tests__/fontFamilyControl.spec.ts`, `styleTabTypeface.spec.ts`, `classFieldStateFont.spec.ts`

**Interfaces:**
- Consumes: `GET /fonts` (Task 8), schema kind `font` (Task 3).
- Produces: `useFontLibrary()` (Pinia Colada query `['fonts']`), `facesLabel(family): string`, `suppliedWeights(family): Set<number> | null` (null for built-ins and unknown faces), `FontFamilyControl` props `{value: {type,value}|null, context: 'block'|'part'|'class', computed?: {weight:number, style:string}|null}` emits `pick`.

Behaviour:
- Groups **Built-in** / **Your fonts**, each option `style="font-family: <stack>"` (uploaded options load their face via the `fonts-{hash}.css` link the admin injects once from the picker read's URLs — a `FontFace` per family, lazily on open).
- Faces line: "Faces: 400, 700, 400 italic" / "300–900 variable" / "Unknown faces" / "Provided by the visitor's device" / "Supplied by the theme" (Theme with `theme_face.declared && faces_present`) / "This theme declares no face; Theme uses the system stack".
- Weight control marks unsupplied weights "— not in this family", still selectable; none for built-ins or unknown faces.
- Removed/unknown: first disabled option "Removed typeface: <name>" / "Unknown typeface (<id>)", line "Renders inheriting the enclosing font.", actions Choose another / Clear / (with `content.manage`) "Restore in Site › Appearance" link.
- Reset label: "Use theme default" with help "Returns this target to its contextual default."; Theme option help "The theme's original face."
- Class context: same list and faces line; no computed notice; keeps "Use theme default", "Remove", "Not set in this class".

- [ ] **Step 1: Failing specs** for each behaviour above (mock `useFontLibrary`), including class context hiding computed notices and the font-kind branch in `classFieldState` (`set` for a valid ID, `invalid` for a malformed one, `set` for a removed one with the removed label).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `pnpm type-check`, `pnpm exec vitest run` (the new specs and the inspector suite), `pnpm lint`, `pnpm exec oxfmt` on touched files. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): the Typeface control on every typography target and in style classes`.

---

### Task 10: Computed typography from the stage, and the not-supplied notice

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js`, `admin/src/composables/useCanvasBridge.ts`, `FontFamilyControl.vue`
- Test: `tools/runtime-browser/tests/typography-bridge.spec.js` (+ fixture page), `admin/src/__tests__/canvasBridgeTypography.spec.ts`

**Interfaces:**
- Produces messages: parent → stage `thallo:typography-request {id, target, seq}`; stage → parent `thallo:typography-state {id, target, seq, weight: number, style: 'normal'|'italic'|'oblique'}` (from `getComputedStyle` of the target element: the block root for `target === 'root'`, else `[data-thallo-part="<target>"]` within the block); `useCanvasBridge().requestTypography(id, target): Promise<{weight, style} | null>` resolving only for the latest `seq` per `(id, target)`; stale or mismatched replies dropped; re-requested after each stage render and on selection change.

Notice text (FontFamilyControl, block/part context, only when `suppliedWeights` is non-null): weight not supplied → "<weight> isn't supplied by <name>; the browser will select an available face."; italic requested and no italic face → "There's no italic face; the browser may slant the text."

- [ ] **Step 1: Failing tests.** Runtime-browser: a fixture page with a heading at weight 700 posts the right state for `(id, root)` and for a part; an unknown id posts nothing. Admin: replies keyed by `(id,target,seq)`; an older `seq` arriving after a newer one is ignored; changing selection drops pending replies; notice wording exact; no notice for built-ins/unknown faces/class context.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** (`post('typography-state', …)` in the bridge; the inbound handler in its dispatch table; admin publisher/listener following `publishGridFill` / `onMessage`).
- [ ] **Step 4: Run** `cd tools/runtime-browser && npm test -- typography-bridge`, admin vitest. Expected: PASS.
- [ ] **Step 5: Commit** `feat(stage): report a target's computed weight and style; the not-supplied notice`.

---

### Task 11: The Typefaces card, Appearance pickers, and stage refresh on library or theme change

**Files:**
- Create: `admin/src/pages/appearance/components/{TypefacesCard,FontFamilyDialog,FontUsageDialog,FontFamilyPicker}.vue`
- Modify: `admin/src/pages/appearance/index.vue` (card; Custom uses `FontFamilyPicker` for Text/Headings; remove `FontFaceField` usage), `admin/src/queries/fontLibrary.ts` (mutations), `admin/src/editor/stage/useStageEditor.ts` (`refreshAfterLibraryChange()`: a full stage reload of the working copy, never the in-place patch), and the regions/layout stage editors that share it
- Test: `admin/src/__tests__/typefacesCard.spec.ts`, `appearanceCustomFamilies.spec.ts`, `stageLibraryRefresh.spec.ts`, e2e `admin/e2e/tests/typeface.spec.ts`

**Behaviour:**
- Card: Built-in list with specimens; Your fonts rows (name escaped, specimen, faces line, fallback, usage count from `GET /fonts/{id}/usage` lazily); Add family dialog (name, fallback select, file drop accepting `.woff2`, each file uploaded via `useUploadMedia`, then `POST /fonts` — per-file reasons shown from 422; duplicate face flagged); Edit; Delete → `FontUsageDialog` grouped with links, copy distinguishing "Blocks inherit their parent's font" from "Appearance falls back: Text to the theme's face, Headings to Text"; Removed (collapsed) with Restore / Delete permanently; Read again on a family with unknown faces, warning "If this succeeds, the font may render differently."
- Appearance Custom: Text/Headings `FontFamilyPicker` (library families + "Add a font…" opening `FontFamilyDialog`, selecting the new family on save).
- Stage refresh: after any library mutation succeeds (the `['fonts']` query invalidation is the signal), and when the theme changes (the existing appearance-save event), open stages call `refreshAfterLibraryChange()`: a full iframe reload of the working copy — the in-place patch cannot replace head stylesheet links — keeping the document and undo history in the admin; the reloaded markup and `fonts_stylesheet_url` come from one snapshot by construction.

- [ ] **Step 1: Failing specs** for each behaviour, plus `stageLibraryRefresh`: removal then restoration while the family is selected — the stage re-renders twice, undo stack length unchanged, the selected block stays selected. E2E (`typeface.spec.ts`, fixtures with Search-on-style override pattern extended to seed a library family via the fixture builder): choose an uploaded family on a header heading, see it applied on the stage; open style class editor and see the control without a computed notice.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**; extend `scripts/build-builder-proof-fixtures` to seed one family (from `tests/fixtures/fonts/variable.woff2`) and capture `api/fonts.json`; add the `/fonts` route to `admin/e2e/helpers.ts`; add a `typeface-applied` regions scenario.
- [ ] **Step 4: Run** admin gates, rebuild e2e fixtures (`CACHE_DRIVER=array`), `pnpm --dir admin/e2e test --workers=8`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): the Typefaces card, library pickers for Custom, and stage refresh on library change`.

---

### Task 12: Docs, changelog, and the full gates

**Files:**
- Modify: `docs/reference/05-style-settings.md` (Typeface row, the `font` kind, `t-font-*`, reset wording), `docs/guides/01-appearance.md` (Typefaces card, Custom from the library), `docs/guides/13-make-a-theme.md` (optional `face`, synthesis tokens), `docs/guides/05-style-classes.md`, `docs/guides/08-media.md` (font files protected), `docs/concepts/04-themes.md`, `CHANGELOG.md` (`[Unreleased]`: Added/Changed/Upgrade Notes with the deliberate rendering change), regenerate `docs/openapi.json` for the new admin routes only if admin routes are in it (they are not by convention — record the check).

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
