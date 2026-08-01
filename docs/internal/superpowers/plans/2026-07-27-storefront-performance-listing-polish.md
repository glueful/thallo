# Storefront Performance & Listing Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Real responsive images behind an honest capability seam, at-most-one priority
image per page, editorial listing rows, restrained content-visibility, a root-crossfade
view transition, and a CI budget pinning the single-runtime asset posture.

**Architecture:** A batch `MediaVariantUrlResolver` contract (thallo-contracts) implemented
app-side over the existing blob query; `RenderContextExtension` gains the unified
`media_image()` helper and a per-render `claim_priority_image()` whose reset joins a new
combined `resetPerRenderState()` swapped into all six render boundaries. Theme templates
adopt the per-template image discipline; archive/listing/terms share a new `_listing_rows`
partial. CSS carries the listing rows, content-visibility, and the reduced-motion-wrapped
`@view-transition` crossfade.

**Tech Stack:** PHP 8.3 (Glueful), Twig, plain CSS (default theme), PHPUnit.

## Global Constraints

- Work on thallo `dev` directly; commit per task; **never push**; no AI attribution.
- Nothing under `docs/` or `.superpowers/` staged in any commit (spec + this plan stay held).
- Stage exact files only. Never use a directory-wide `git add`; inspect
  `git diff --name-only` and name every intended file explicitly.
- Test runs: `set -o pipefail && vendor/bin/phpunit <paths> 2>&1 | tail -5` — NEVER grep.
- phpcs PSR12 on every touched PHP file.
- Spec: `docs/superpowers/specs/2026-07-27-storefront-performance-listing-polish-design.md`.
- Pinned values (verbatim from the spec): runtime budget `strlen(gzencode($src, 9)) <= 12_288`;
  listing rows `content-visibility: auto; contain-intrinsic-size: auto 110px;`; footer
  `contain-intrinsic-size: auto 300px`; crossfade ~150ms wrapped in
  `@media (prefers-reduced-motion: no-preference)`; contract return
  `?array{src: string, srcset: ?string}` with the three outcomes (candidates /
  clamp-exhausted `{src, srcset: null}` / invalid-non-image-unservable `null`);
  at most ONE priority image, first eligible in render order, region renders never claim,
  brand imagery never claims; listing thumbs `alt=""`, 160×110 desktop / 96×72 mobile,
  no-cover removes the media column, routeless rows get no link affordance.

---

### Task 1: MediaVariantUrlResolver contract + engine implementation + app binding

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/MediaVariantUrlResolver.php`
- Create: `app/Content/Delivery/EngineMediaVariantUrlResolver.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (services() entry ~line 1056 beside
  `MediaUrlResolver::class`, new `makeMediaVariantUrlResolver()` factory, `use` import)
- Test: `tests/Integration/Content/MediaVariantUrlResolverTest.php` (new; if
  `tests/Integration/Content/` does not exist, use `tests/Integration/Delivery/` — match
  wherever the existing `EngineMediaUrlResolver` tests live; find them with
  `grep -rl EngineMediaUrlResolver tests/`)

**Interfaces:**
- Consumes: `EngineMediaUrlResolver::anonymousRetrievalAllowed()` (existing, public static),
  the `blobs` table (`uuid`, `visibility`, `status`, `deleted_at`, `mime_type` — the same
  row shape `UploadController` reads `$blob['mime_type']` from).
- Produces: `Thallo\Contracts\Delivery\MediaVariantUrlResolver` with
  `variants(string $uuid, array $widths): ?array{src: string, srcset: ?string}` — Task 2's
  extension consumes it soft-bound.

- [ ] **Step 1: Write the contract** (no test needed for an interface):

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * OPTIONAL responsive-variant companion at the generic render boundary
 * (storefront-performance spec §3). The Thallo app always binds its MIME-aware
 * implementation; other hosts may omit it. Batch by design: ONE blob/access/MIME lookup
 * produces the base src and every srcset candidate — per-width calls would be N+1 reads.
 *
 * Three pinned outcomes:
 *  - Valid image with surviving candidates: {src, srcset: string}.
 *  - Valid image with NO surviving candidates (the implementation is bound but resizing
 *    is unavailable/disabled, or every width fell to the server clamp):
 *    {src, srcset: null} — the base URL stands; null stays reserved for invalid media.
 *  - Missing, private, deleted, unservable, or non-image blob: null (the caller omits
 *    the image element — never an <img> pointing at a non-image).
 *
 * Implementations must never emit ?width= candidate URLs unless real resizing will serve
 * them (a candidate the server would ignore or clamp lies to the browser's selection
 * algorithm and multiplies cache keys for zero payload win).
 */
interface MediaVariantUrlResolver
{
    /**
     * @param list<int> $widths candidate widths in px, ascending
     * @return array{src: string, srcset: ?string}|null
     */
    public function variants(string $uuid, array $widths): ?array;
}
```

- [ ] **Step 2: Write the failing tests.** Mirror the existing `EngineMediaUrlResolver`
  test file's construction/fixture style (read it first). The tests construct the engine
  resolver DIRECTLY (no config reboots) and seed `blobs` rows via `$this->connection()`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineMediaVariantUrlResolver;
use App\Tests\Support\AppTestCase;

/**
 * Storefront-performance spec §3: the batch variant resolver's three pinned outcomes,
 * clamp filtering, and single-lookup composition.
 */
final class MediaVariantUrlResolverTest extends AppTestCase
{
    private function seedBlob(string $uuid, string $mime, string $visibility = 'public'): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'variant-test-' . $uuid,
            'mime_type' => $mime,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => $visibility,
            'status' => 'active',
            // Copy any further NOT NULL columns from the EngineMediaUrlResolver test's
            // own seed helper — reuse its exact insert shape.
        ]);
    }

    private function resolver(bool $capable = true, int $maxWidth = 2048): EngineMediaVariantUrlResolver
    {
        return new EngineMediaVariantUrlResolver(
            $this->connection(),
            '/api/blobs',
            uploadsEnabled: true,
            accessMode: false,
            maxWidth: $maxWidth,
            resizingCapable: $capable,
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->table('blobs')->where('name', 'LIKE', 'variant-test-%')->forceDelete();
        parent::tearDown();
    }

    public function testValidImageProducesSrcAndCandidatesFromOneLookup(): void
    {
        $this->seedBlob('variantimg01', 'image/jpeg');
        $result = $this->resolver()->variants('variantimg01', [320, 640]);

        self::assertNotNull($result);
        self::assertSame('/api/blobs/variantimg01', $result['src']);
        self::assertSame('/api/blobs/variantimg01?width=320 320w, /api/blobs/variantimg01?width=640 640w', $result['srcset']);
    }

    public function testNonImageBlobIsNullNeverAFallbackUrl(): void
    {
        $this->seedBlob('variantpdf01', 'application/pdf');
        self::assertNull($this->resolver()->variants('variantpdf01', [320]));
    }

    public function testMissingAndPrivateBlobsAreNull(): void
    {
        self::assertNull($this->resolver()->variants('variantnope1', [320]));
        $this->seedBlob('variantpriv1', 'image/png', visibility: 'private');
        self::assertNull($this->resolver()->variants('variantpriv1', [320]));
    }

    public function testClampDropsOversizedWidthsAndExhaustionKeepsSrc(): void
    {
        $this->seedBlob('variantimg02', 'image/webp');
        $partial = $this->resolver(maxWidth: 500)->variants('variantimg02', [320, 640]);
        self::assertSame('/api/blobs/variantimg02?width=320 320w', $partial['srcset']);

        $exhausted = $this->resolver(maxWidth: 100)->variants('variantimg02', [320, 640]);
        self::assertNotNull($exhausted, 'clamp exhaustion keeps the valid image');
        self::assertNull($exhausted['srcset'], 'null srcset — base src stands');
    }

    public function testIncapableResolverStillDistinguishesValidFromInvalid(): void
    {
        $this->seedBlob('variantimg03', 'image/png');
        $result = $this->resolver(capable: false)->variants('variantimg03', [320]);
        self::assertSame(['src' => '/api/blobs/variantimg03', 'srcset' => null], $result);

        $this->seedBlob('variantpdf02', 'application/pdf');
        self::assertNull($this->resolver(capable: false)->variants('variantpdf02', [320]));
    }
}
```

- [ ] **Step 3: Run to verify failure** —
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Content/MediaVariantUrlResolverTest.php 2>&1 | tail -5`
  Expected: error, class `EngineMediaVariantUrlResolver` not found.

- [ ] **Step 4: Implement the engine resolver:**

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;

/**
 * Batch variant resolver over the SAME blob-servability query as
 * {@see EngineMediaUrlResolver} (storefront-performance spec §3): one lookup yields the
 * base src, the MIME gate, and every ?width= candidate.
 *
 * `resizingCapable` is the runtime half of the capability gate (the processor binding +
 * `uploads.image_processing.enabled`, computed in the provider factory — a compiled
 * container cannot make REGISTRATION conditional on runtime config): incapable means a
 * valid image degrades to {src, srcset: null} — never fabricated ?width= URLs, and null
 * stays reserved for invalid media, exactly the contract's three outcomes.
 */
final class EngineMediaVariantUrlResolver implements MediaVariantUrlResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
        private readonly int $maxWidth,
        private readonly bool $resizingCapable,
    ) {
    }

    public function variants(string $uuid, array $widths): ?array
    {
        if (!$this->uploadsEnabled || !EngineMediaUrlResolver::anonymousRetrievalAllowed($this->accessMode)) {
            return null;
        }
        $blob = $this->db->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        if ($blob === null || !str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/')) {
            return null;
        }

        $src = rtrim($this->blobUrlBase, '/') . '/' . $uuid;
        if (!$this->resizingCapable) {
            return ['src' => $src, 'srcset' => null];
        }

        $candidates = [];
        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width > 0 && $width <= $this->maxWidth) {
                $candidates[] = "{$src}?width={$width} {$width}w";
            }
        }
        return ['src' => $src, 'srcset' => $candidates === [] ? null : implode(', ', $candidates)];
    }
}
```

- [ ] **Step 5: Run the tests to verify pass** (same command). If the insert fails on a
  NOT NULL column, copy the full insert shape from the `EngineMediaUrlResolver` test.

- [ ] **Step 6: Always bind the Thallo app implementation in
  `ThalloServiceProvider`.** The contract remains optional to generic `thallo-render`
  consumers, but Thallo needs the MIME-aware valid-image-vs-invalid-media distinction
  even when resizing is disabled. Beside the existing
  `MediaUrlResolver::class` entry (~line 1056):

```php
MediaVariantUrlResolver::class => [
    'shared' => true,
    'factory' => [self::class, 'makeMediaVariantUrlResolver'],
],
```

Factory (place near `makeMediaUrlResolver()` — read it first and reuse its exact
`blobUrlBase`/`uploadsEnabled`/`accessMode` derivations verbatim):

```php
public static function makeMediaVariantUrlResolver(ContainerInterface $container): EngineMediaVariantUrlResolver
{
    $context = $container->get(ApplicationContext::class);
    // Candidate-generation gate mirrors UploadController::serveBlob's own check
    // (spec §3): a processor is bound AND uploads.image_processing.enabled. The resolver
    // itself stays bound so its MIME gate still omits invalid media. Incapable → valid
    // images degrade to {src, srcset: null}; never fabricate ?width= URLs.
    $capable = $container->has(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
        && (bool) config($context, 'uploads.image_processing.enabled', true);

    return new EngineMediaVariantUrlResolver(
        $container->get(Connection::class),
        /* blobUrlBase */ ...,   // ← copy makeMediaUrlResolver()'s exact expression
        /* uploadsEnabled */ ..., // ← copy
        /* accessMode */ ...,     // ← copy
        (int) config($context, 'uploads.image_processing.max_width', 2048),
        $capable,
    );
}
```

Add `use Thallo\Contracts\Delivery\MediaVariantUrlResolver;` (short names in the provider —
standing rule). Replace the three `...` with `makeMediaUrlResolver()`'s literal
expressions — do not re-derive them.

- [ ] **Step 7: Full-file verification** —
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Content/MediaVariantUrlResolverTest.php 2>&1 | tail -5`
  then a container smoke:
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render 2>&1 | tail -5` (boot must
  stay green with the new binding). phpcs PSR12 on the three PHP files.

- [ ] **Step 8: Commit** —
  `git add packages/thallo-contracts/src/Delivery/MediaVariantUrlResolver.php app/Content/Delivery/EngineMediaVariantUrlResolver.php app/Providers/ThalloServiceProvider.php tests/Integration/Content/MediaVariantUrlResolverTest.php`
  `git commit -m "feat(delivery): batch MediaVariantUrlResolver seam for responsive images"`

---

### Task 2: media_image() + priority claim + combined per-render reset

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (new ctor param, two Twig
  functions, claim state + `resetPriorityImageClaim()` + `resetPerRenderState()`)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php`
  (`makeRenderContextExtension()` passes the soft-bound variant resolver)
- Modify (the six reset boundaries — swap the adjacent
  `resetBlockDepth(); resetBlockFrames();` pair for `resetPerRenderState()`, keeping every
  other site-specific call exactly where it is):
  - `packages/thallo-render/src/Http/Controllers/RenderController.php` (~:801)
  - `packages/thallo-render/src/EntryBlocksRenderer.php` (~:68)
  - `packages/thallo-commerce/src/Http/Shop/ShopCartController.php` (~:283)
  - `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php` (~:365)
  - `packages/thallo-commerce/src/Http/Shop/ShopCheckoutController.php` (~:375)
  - `app/Http/Controllers/RegionAdminController.php` (~:151)
- Modify: test helpers that hand-roll the reset pair
  (`grep -rn "resetBlockDepth" tests/ | grep -v vendor`) — switch them to
  `resetPerRenderState()` so per-test claim state cannot leak. The currently verified
  affected helpers are:
  - `tests/Integration/Commerce/StorefrontInertnessTest.php`
  - `tests/Integration/Commerce/ShopBlocksTest.php`
  - `tests/Integration/Commerce/NoJsAddToCartTest.php`
  Re-grep before editing; leave tests intentionally exercising `resetBlockDepth()` alone.
- Test: `tests/Integration/Render/MediaImageAndPriorityClaimTest.php` (new)
- Test: `tests/Integration/Render/PriorityClaimRenderBoundaryTest.php` (new; real
  controller/fragment reset integration)

**Interfaces:**
- Consumes: Task 1's `MediaVariantUrlResolver::variants(uuid, widths): ?array{src, srcset: ?string}`.
- Produces (Tasks 3–4 rely on these exact names):
  - Twig `media_image(uuid, widths)` → `null` or `{src: string, srcset: ?string}`.
  - Twig `claim_priority_image()` → bool, `needs_context`, false in region renders.
  - PHP `resetPerRenderState(): void` = `resetBlockDepth()` + `resetBlockFrames()` +
    `resetPriorityImageClaim()`.

- [ ] **Step 1: Write the failing tests:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Render\RenderContextExtension;

/**
 * Storefront-performance spec §3/§4: the unified media_image() helper's outcome table and
 * the at-most-one, first-eligible, region-excluded priority claim with its per-render
 * reset.
 */
final class MediaImageAndPriorityClaimTest extends AppTestCase
{
    /** Extension with FAKE resolvers — media_image()'s branching is what's under test. */
    private function extension(?bool $variantResolver): RenderContextExtension
    {
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return $uuid === 'missing00000' ? null : '/api/blobs/' . $uuid;
            }
        };
        $variants = $variantResolver === null ? null : new class implements MediaVariantUrlResolver {
            public function variants(string $uuid, array $widths): ?array
            {
                if ($uuid === 'nonimage0000' || $uuid === 'missing00000') {
                    return null;
                }
                if ($uuid === 'clamped00000') {
                    return ['src' => '/api/blobs/' . $uuid, 'srcset' => null];
                }
                return ['src' => '/api/blobs/' . $uuid, 'srcset' => '/api/blobs/' . $uuid . '?width=320 320w'];
            }
        };
        return new RenderContextExtension(
            null,
            $this->container()->get(\Thallo\Render\EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            mediaVariants: $variants,
        );
    }

    public function testMediaImageWithoutResolverFallsBackToPlainMedia(): void
    {
        $ext = $this->extension(variantResolver: null);
        self::assertSame(['src' => '/api/blobs/plainimg0001', 'srcset' => null], $ext->mediaImage('plainimg0001', [320]));
        self::assertNull($ext->mediaImage('missing00000', [320]));
    }

    public function testMediaImageWithResolverHonorsTheThreeOutcomes(): void
    {
        $ext = $this->extension(variantResolver: true);
        $candidate = $ext->mediaImage('goodimg00001', [320]);
        self::assertNotNull($candidate);
        self::assertSame('/api/blobs/goodimg00001', $candidate['src']);
        self::assertSame('/api/blobs/goodimg00001?width=320 320w', $candidate['srcset']);
        self::assertNull($ext->mediaImage('nonimage0000', [320]), 'non-image omitted, never a media() fallback');
        self::assertSame(['src' => '/api/blobs/clamped00000', 'srcset' => null], $ext->mediaImage('clamped00000', [320]));
    }

    public function testPriorityClaimIsAtMostOncePerRenderAndRegionExcluded(): void
    {
        $ext = $this->extension(variantResolver: null);
        $ext->resetPerRenderState();

        self::assertFalse($ext->claimPriorityImage(['region_slug' => 'footer']), 'region renders never claim');
        self::assertTrue($ext->claimPriorityImage(['region_slug' => null]), 'first body claimant wins');
        self::assertFalse($ext->claimPriorityImage(['region_slug' => null]), 'second body claimant loses');

        $ext->resetPerRenderState();
        self::assertTrue($ext->claimPriorityImage([]), 'a fresh render gets a fresh claim');
    }
}
```

- [ ] **Step 1b: Write reset-boundary integration tests** in
  `PriorityClaimRenderBoundaryTest.php`. Do not call `resetPerRenderState()` directly in
  these tests; they must prove the real callers own the boundary:
  - Render two consecutive full responses through `RenderController` using the same
    container-shared `RenderContextExtension`; each response contains an eligible image
    and each independently emits exactly one `fetchpriority="high"`.
  - First consume a claim, then render an eligible image through
    `EntryBlocksRenderer`; the fragment must receive a fresh claim. Follow with a full
    controller render and assert it also receives a fresh claim. This pins both sides of
    fragment isolation and catches a missing reset at either boundary.
  Reuse the nearest existing full-page and entry-block fixture helpers so these are
  controller/renderer integration tests, not a second unit test of the reset method.

- [ ] **Step 2: Run to verify failure** —
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/MediaImageAndPriorityClaimTest.php tests/Integration/Render/PriorityClaimRenderBoundaryTest.php 2>&1 | tail -5`
  Expected: unknown named argument `mediaVariants` / undefined method `mediaImage`.

- [ ] **Step 3: Implement in `RenderContextExtension`.**
  Constructor: add after the `$mediaUrls` param:

```php
/** Soft-bound (storefront-performance spec §3): null → media_image() has no MIME
    knowledge and degrades to media()'s plain URL with srcset null. */
private readonly ?MediaVariantUrlResolver $mediaVariants = null,
```

(`use Thallo\Contracts\Delivery\MediaVariantUrlResolver;` import.) NOTE: the constructor
has many later positional params — adding mid-list breaks positional callers. Place it
LAST in the parameter list instead, and have callers use the named argument.

  State + methods (beside `resetBlockDepth()`):

```php
private bool $priorityImageClaimed = false;

/** Spec §4: the at-most-one LCP claim; reset at every render boundary. */
public function resetPriorityImageClaim(): void
{
    $this->priorityImageClaimed = false;
}

/**
 * The single per-render reset list (spec §4): the verbs EVERY render boundary shares.
 * Site-specific resets (tags, asset base, locale, appearance) stay at their call sites —
 * they genuinely differ per boundary and folding them would change behavior.
 */
public function resetPerRenderState(): void
{
    $this->resetBlockDepth();
    $this->resetBlockFrames();
    $this->resetPriorityImageClaim();
}

/**
 * needs_context (spec §4): region-rendered blocks never claim (region_slug non-null in
 * the block context); the first body caller wins, everyone after gets false. Templates
 * call this ONLY after media_image() resolved non-null.
 *
 * @param array<string,mixed> $context
 */
public function claimPriorityImage(array $context): bool
{
    if (($context['region_slug'] ?? null) !== null || $this->priorityImageClaimed) {
        return false;
    }
    $this->priorityImageClaimed = true;
    return true;
}

/**
 * The ONE image-slot helper (spec §3). Resolver absent → media()'s plain URL (no MIME
 * knowledge; today's behavior). Resolver present → its three pinned outcomes verbatim,
 * including NULL for non-image blobs (never a media() fallback that would emit
 * <img src="…pdf">).
 *
 * @param list<int> $widths
 * @return array{src: string, srcset: ?string}|null
 */
public function mediaImage(string $uuid, array $widths): ?array
{
    if ($this->mediaVariants === null) {
        $src = $this->media($uuid);
        return $src === null ? null : ['src' => $src, 'srcset' => null];
    }
    return $this->mediaVariants->variants($uuid, $widths);
}
```

  Registrations in `getFilters()`'s sibling `getFunctions()` (beside `media`):

```php
new TwigFunction('media_image', $this->mediaImage(...)),
new TwigFunction('claim_priority_image', $this->claimPriorityImage(...), ['needs_context' => true]),
```

- [ ] **Step 4: Wire the factory.** In `RenderServiceProvider::makeRenderContextExtension()`,
  pass (named argument, matching the soft-bind idiom of `MediaUrlResolver` directly above):

```php
mediaVariants: $container->has(MediaVariantUrlResolver::class)
    ? $container->get(MediaVariantUrlResolver::class)
    : null,
```

with the `use Thallo\Contracts\Delivery\MediaVariantUrlResolver;` import.

- [ ] **Step 5: Swap the six boundaries.** At each listed site, replace the ADJACENT pair

```php
$this->extension->resetBlockDepth();
$this->extension->resetBlockFrames();
```

with

```php
$this->extension->resetPerRenderState();
```

(`$ext->` at RegionAdminController). Every surrounding call (resetTags, setAssetBase,
setLocale, setBlockAnnotations, setThemeAppearanceOverride) stays untouched. Re-grep
afterwards: `grep -rn "resetBlockDepth()" packages app tests | grep -v vendor` — the only
remaining callers must be `resetPerRenderState()` itself and any test explicitly testing
`resetBlockDepth` in isolation. Update test helpers that used the pair.

- [ ] **Step 6: Run** both new test files, then the render + commerce suites:
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/MediaImageAndPriorityClaimTest.php tests/Integration/Render/PriorityClaimRenderBoundaryTest.php 2>&1 | tail -5`
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render tests/Integration/Commerce 2>&1 | tail -5`
  Expected: green (the swap is behavior-preserving; claim state now resets everywhere).

- [ ] **Step 7: phpcs + commit** —
  `git add packages/thallo-render/src/RenderContextExtension.php packages/thallo-render/src/RenderServiceProvider.php packages/thallo-render/src/Http/Controllers/RenderController.php packages/thallo-render/src/EntryBlocksRenderer.php packages/thallo-commerce/src/Http/Shop/ShopCartController.php packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php packages/thallo-commerce/src/Http/Shop/ShopCheckoutController.php app/Http/Controllers/RegionAdminController.php tests/Integration/Render/MediaImageAndPriorityClaimTest.php tests/Integration/Render/PriorityClaimRenderBoundaryTest.php tests/Integration/Commerce/StorefrontInertnessTest.php tests/Integration/Commerce/ShopBlocksTest.php tests/Integration/Commerce/NoJsAddToCartTest.php`
  (verify with `git status` that ONLY intended files are staged; nothing under docs/)
  `git commit -m "feat(render): media_image() helper + at-most-one priority-image claim with combined per-render reset"`

---

### Task 3: Template adoption — hero, image, blog_posts, logos, logo, layout

**Files:**
- Modify: `packages/thallo-render/themes/default/templates/blocks/hero.twig`
- Modify: `packages/thallo-render/themes/default/templates/blocks/image.twig`
- Modify: `packages/thallo-render/themes/default/templates/blocks/blog_posts.twig` (~:34)
- Modify: `packages/thallo-render/themes/default/templates/blocks/logos.twig` (~:24, :34)
- Modify: `packages/thallo-render/themes/default/templates/blocks/logo.twig`
- Modify: `packages/thallo-render/themes/default/templates/layout.twig` (~:106-117 site logo)
- Modify: `packages/thallo-render/themes/default/assets/site.css` (hero media aspect-ratio)
- Test: `tests/Integration/Render/ImageDisciplineRenderTest.php` (new)
- Test: extend `tests/Integration/Render/BlogPostsRenderTest.php` (nested-region macro
  propagation)

**Interfaces:**
- Consumes: Task 2's `media_image(uuid, widths)` and `claim_priority_image()` exactly as
  produced; the block context's `region_slug` variable (already passed by `blocks()`).
- Produces: nothing consumed later — Task 4's listing rows repeat the same idiom inline.

- [ ] **Step 1: Write the failing render tests.** Use the `ShopBlocksTest::renderBlock()`
  idiom (container TwigFactory env + container extension, `resetPerRenderState()` before
  each render). Seed one real public image blob and one PDF blob exactly as Task 1's test
  seeds them (copy its `seedBlob()` helper):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * Storefront-performance spec §4/§5: the per-template image discipline — at most one
 * fetchpriority image, first eligible wins, logos/regions never claim, plain-image parity
 * when no variants exist, and non-image assets are omitted.
 */
final class ImageDisciplineRenderTest extends AppTestCase
{
    private function renderBlocks(array $list, ?string $regionSlug = null): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        /** @var RenderContextExtension $ext */
        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        $ext->setBlockAnnotations(false);
        $ext->setLocale('en');
        return $ext->blocks(
            $env,
            ['entry' => null, 'site' => ['name' => 'T'], 'region_slug' => $regionSlug],
            $list,
        );
    }

    // Seed helper: copy Task 1's seedBlob() verbatim (image/jpeg + application/pdf rows).

    public function testTwoHerosYieldExactlyOneFetchpriorityHigh(): void
    {
        $this->seedBlob('discipimg001', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'A', 'image' => 'discipimg001']],
            ['id' => 'h2', 'type' => 'hero', 'data' => ['title' => 'B', 'image' => 'discipimg001']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
        self::assertStringContainsString('loading="lazy" decoding="async"', $html);
        // Plain-image parity (spec §9): the suite env has no processor bound, so the
        // resolver's incapable path yields srcset:null — templates must then emit NO
        // srcset attribute at all (not an empty one).
        self::assertStringNotContainsString('srcset', $html);
    }

    public function testImageBlockFirstClaimsWhenNoHeroExists(): void
    {
        $this->seedBlob('discipimg002', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipimg002']],
            ['id' => 'i2', 'type' => 'image', 'data' => ['image' => 'discipimg002']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
    }

    public function testRegionRenderedImageBlockNeverClaims(): void
    {
        $this->seedBlob('discipimg003', 'image/jpeg');
        $html = $this->renderBlocks(
            [['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipimg003']]],
            regionSlug: 'footer',
        );
        self::assertStringNotContainsString('fetchpriority', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testUnresolvableFirstImageDoesNotConsumeTheClaim(): void
    {
        $this->seedBlob('discipimg004', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipnope99']],
            ['id' => 'i2', 'type' => 'image', 'data' => ['image' => 'discipimg004']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
    }

    public function testNonImageAssetIsOmittedEntirely(): void
    {
        $this->seedBlob('discippdf001', 'application/pdf');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discippdf001']],
        ]);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testLogoBlockLoadingIsPositional(): void
    {
        // Requires a site logo to be configured — follow the SiteLogoProvider seeding used
        // by the existing logo/site-identity tests (grep tests/ for site_logo). Body render:
        $body = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]]);
        self::assertStringContainsString('loading="lazy"', $body);
        self::assertStringNotContainsString('fetchpriority', $body);
        // Header region render:
        $header = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]], regionSlug: 'header');
        self::assertStringContainsString('loading="eager"', $header);
        self::assertStringNotContainsString('fetchpriority', $header);
        // Footer is explicitly the lazy branch too.
        $footer = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]], regionSlug: 'footer');
        self::assertStringContainsString('loading="lazy"', $footer);
        self::assertStringNotContainsString('fetchpriority', $footer);
    }
}
```

  Extend `BlogPostsRenderTest` using its existing `createPostType()` /
  `publishPost()` fixture. Let its `render()` helper accept an optional `$regionSlug` and
  pass `'region_slug' => $regionSlug` into the Twig context. Seed the covered post with a
  real public image blob, then add:

```php
public function testNestedBlogPostsInHeaderRegionNeverClaimsPriority(): void
{
    $this->createPostType();
    $cover = Utils::generateNanoID();
    $this->seedPublicImageBlob($cover);
    $this->publishPost(['title' => 'Nested cover', 'cover' => $cover], 'nested-cover');

    $out = $this->render([[
        'id' => 'container-region',
        'type' => 'container',
        'data' => ['content' => [[
            'id' => 'nested-posts',
            'type' => 'blog_posts',
            'data' => ['type' => 'post', 'limit' => 1],
        ]]],
    ]], regionSlug: 'header');

    self::assertStringContainsString('thallo-block-blog_posts__image', $out);
    self::assertStringNotContainsString('fetchpriority', $out);
    self::assertStringContainsString('loading="lazy"', $out);
}
```

  Add `seedPublicImageBlob()` by copying Task 1's exact blob insert shape. This test is
  intentionally integration-level: region validation constrains only top-level palette
  entries, so a nested block can still reach `blog_posts.twig`; the macro must not erase
  its region ancestry.

  NOTE for the implementer: if no variant resolver capability exists in the test env
  (`MediaProcessorInterface` unbound), `media_image()` still resolves via the REAL
  `EngineMediaVariantUrlResolver` (bound by Task 1) whose incapable path returns
  `{src, srcset: null}` for the seeded image — the claim tests work regardless. The
  `testNonImageAssetIsOmittedEntirely` test is the one that REQUIRES the Task 1 binding
  (MIME knowledge).

- [ ] **Step 2: Run to verify failure** (hero/image templates don't emit `fetchpriority`
  yet).

- [ ] **Step 3: Adopt in templates.** The shared idiom — resolve first, claim only on
  non-null, attributes from the claim:

  `hero.twig` — replace `{% set img = data.image ? media(data.image) : null %}` and the
  `__media` line with:

```twig
{% set img = data.image ? media_image(data.image, [640, 960, 1280, 1920]) : null %}
...
{% if img %}
  {% set priority = claim_priority_image() %}
  <div class="thallo-block-hero__media">
    <img src="{{ img.src }}"
         {%- if img.srcset %} srcset="{{ img.srcset }}" sizes="(max-width: 48rem) 100vw, 40rem"{% endif %}
         alt=""
         {%- if priority %} loading="eager" fetchpriority="high"{% else %} loading="lazy" decoding="async"{% endif %}>
  </div>
{% endif %}
```

  (Check site.css's actual hero media rendered width and correct the `sizes` fallback
  literal to match; 100vw-under-48rem is the pinned mobile half.)

  `image.twig` — same resolve/claim shape with widths `[480, 768, 1024, 1536]`; keep the
  authored width/height `style` EXACTLY as today; `sizes`: when `data.width` is set,
  `sizes="{{ data.width }}px"`, else by size modifier
  (`normal: '(max-width: 48rem) 100vw, 40rem'`, `wide: '(max-width: 64rem) 100vw, 60rem'`,
  `full: '100vw'`). No aspect-ratio wrapper — natural ratio pinned.

  `blog_posts.twig` (~:34) — Twig macros have isolated context. First thread region
  ancestry through the existing call and signature:

```twig
{% for post in posts %}{{ bp.card(post, region_slug|default(null)) }}{% endfor %}
...
{% macro card(post, region_slug) %}
```

  Then replace the inline `media(cover)` inside `card()` with the resolved guard. Because
  the macro now has a local `region_slug`, the `needs_context` claim helper sees the
  correct region even when `blog_posts` is nested inside a region container:

```twig
{% set coverImg = cover ? media_image(cover, [320, 640]) : null %}
{% if coverImg %}
  {% set priority = claim_priority_image() %}
  <a class="thallo-block-blog_posts__image" href="{{ url|default('#') }}"><img src="{{ coverImg.src }}"
    {%- if coverImg.srcset %} srcset="{{ coverImg.srcset }}" sizes="(max-width: 48rem) 100vw, 20rem"{% endif %}
    alt=""{% if priority %} loading="eager" fetchpriority="high"{% else %} loading="lazy" decoding="async"{% endif %}></a>
{% endif %}
```

  `logos.twig` — keep `media(uuid)` (brand assets, no srcset pinned) but add
  ` loading="lazy" decoding="async"` to both `<img>` tags. Never claims.

  `logo.twig` — positional (spec §4): before `{% set inner %}` add
  `{% set logoLoading = region_slug|default(null) == 'header' ? 'eager' : 'lazy' %}`, then
  each `<img>` gets
  ` loading="{{ logoLoading }}"{% if logoLoading == 'lazy' %} decoding="async"{% endif %}`.
  No fetchpriority ever.

  `layout.twig` (~:113-116) — the hardcoded `site_logo()` imgs each get
  ` loading="eager"` (no fetchpriority — spec §4 brand-imagery rule).

  `site.css` — hero media reservation (spec §5): add to the hero rules

```css
.thallo-block-hero__media img {
  width: 100%;
  aspect-ratio: 16 / 9;
  object-fit: cover;
}
```

  (If the theme already sizes `.thallo-block-hero__media img`, MERGE — keep existing
  width/radius rules, add only the ratio + object-fit.)

- [ ] **Step 4: Run the new test file to green**, then the full Render suite:
  `set -o pipefail && vendor/bin/phpunit tests/Integration/Render 2>&1 | tail -5`.
  Fix any existing test pinning the old `<img src="{{ media(...) }}"` markup (block
  library render tests may assert hero/image markup — update their expectations to the new
  attributes, keeping their original intent).

- [ ] **Step 5: phpcs on the test; commit** —
  Inspect `git diff --name-only`, then stage the exact intended files:
  `git add packages/thallo-render/themes/default/templates/blocks/hero.twig packages/thallo-render/themes/default/templates/blocks/image.twig packages/thallo-render/themes/default/templates/blocks/blog_posts.twig packages/thallo-render/themes/default/templates/blocks/logos.twig packages/thallo-render/themes/default/templates/blocks/logo.twig packages/thallo-render/themes/default/templates/layout.twig packages/thallo-render/themes/default/assets/site.css tests/Integration/Render/ImageDisciplineRenderTest.php tests/Integration/Render/BlogPostsRenderTest.php`
  If an existing assertion file required an intentional markup update, append that exact
  file path after reviewing its diff; never stage the theme or test directory wholesale.
  `git commit -m "feat(theme): responsive image discipline with at-most-one priority image"`

---

### Task 4: Editorial listing rows — archive, listing, terms

**Files:**
- Create: `packages/thallo-render/themes/default/templates/_listing_rows.twig`
- Modify: `packages/thallo-render/themes/default/templates/archive.twig`
- Modify: `packages/thallo-render/themes/default/templates/listing.twig`
- Modify: `packages/thallo-render/themes/default/templates/terms.twig`
- Modify: `packages/thallo-render/themes/default/assets/site.css`
- Test: extend `tests/Integration/Render/ListingArchivePagesTest.php` (reuse its fixtures)

**Interfaces:**
- Consumes: `media_image()` / `claim_priority_image()` (Task 2), listing items
  (`item.href`, `item.fields.title`, `item.fields.cover`, `item.fields.excerpt`,
  `item.published_at` — shaped by `DeliveryItemShaper`), terms items
  (`term.href`, `term.slug`, `term.count`).
- Produces: the `_listing_rows.twig` partial (items-context include).

- [ ] **Step 1: Write the failing tests** as new methods in `ListingArchivePagesTest`
  (read its fixture setup first; publish entries through its existing helpers, adding
  `cover`/`excerpt` fields where the test needs them; seed a real image blob with Task 1's
  `seedBlob()` shape for the resolvable-cover case):

```php
public function testListingRowsDegradeAcrossAllPinnedStates(): void
{
    // Publish four entries through this class's existing publish helper:
    //   A: cover (seeded image blob) + excerpt   → media column + excerpt
    //   B: cover only                            → media column, no excerpt <p>
    //   C: excerpt only                          → no media column
    //   D: neither                               → no media column, no excerpt
    //   E: cover uuid set to an UNSEEDED uuid    → no media column (resolved, not present)
    $html = (string) $this->handle(Request::create('/post', 'GET'))->getContent();

    // A + B are the only resolvable covers: exactly TWO media columns; C, D and the
    // unresolvable E must not add one (resolved-media gate, spec §8).
    self::assertSame(2, substr_count($html, 'listing-row__media'));
    self::assertSame(1, substr_count($html, 'fetchpriority="high"'),
        'listing thumbnails participate in the page-wide first-eligible claim');
    self::assertStringContainsString('loading="lazy" decoding="async"', $html,
        'the second resolvable listing thumbnail is not another priority image');
    self::assertStringContainsString('listing-row__excerpt', $html);
    self::assertStringContainsString('alt=""', $html);
    self::assertStringContainsString('listing-row--linked', $html);
    self::assertStringContainsString('<time', $html);
}

public function testRoutelessListingItemGetsNoLinkAffordance(): void
{
    // Publish an entry WITHOUT a route (this suite's fixtures distinguish routed/routeless
    // — reuse the same mechanism its existing catch-all tests use) and assert its row
    // renders without listing-row--linked and without an <a>.
}
```

  (The exact fixture calls come from the file's own helpers — the implementer copies the
  nearest existing test's publish sequence and varies fields. The assertions above are the
  contract; adjust counts to the fixture set actually built.)

- [ ] **Step 2: Run to verify failure** (no `listing-row` markup exists yet).

- [ ] **Step 3: Write the partial** `_listing_rows.twig`:

```twig
{# _listing_rows.twig — editorial listing rows (storefront-performance spec §8).
   Context: `items` (delivery-shaped). All degradation is server-side: resolved-media
   gates the column (unresolvable cover == absent cover, never a placeholder), missing
   excerpt is omitted, routeless rows get NO link affordance, thumbs are alt="" because
   the adjacent title carries the accessible identity. #}
<ul class="listing-rows">
  {% for item in items %}
    {% set thumb = item.fields.cover|default(null) ? media_image(item.fields.cover, [160, 320]) : null %}
    <li class="listing-row{% if item.href %} listing-row--linked{% endif %}">
      {% if thumb %}
        {% set priority = claim_priority_image() %}
        <div class="listing-row__media">
          <img src="{{ thumb.src }}"
               {%- if thumb.srcset %} srcset="{{ thumb.srcset }}" sizes="(max-width: 48rem) 96px, 160px"{% endif %}
               alt=""
               {%- if priority %} loading="eager" fetchpriority="high"{% else %} loading="lazy" decoding="async"{% endif %}>
        </div>
      {% endif %}
      <div class="listing-row__content">
        {% if item.href %}
          <a class="listing-row__title" href="{{ item.href }}">{{ item.fields.title|default(item.uuid) }}</a>
        {% else %}
          <span class="listing-row__title">{{ item.fields.title|default(item.uuid) }}</span>
        {% endif %}
        {% if item.published_at|default(null) %}
          <time class="listing-row__date" datetime="{{ item.published_at|date('Y-m-d') }}">{{ item.published_at|date('M j, Y') }}</time>
        {% endif %}
        {% if item.fields.excerpt|default('') != '' %}
          <p class="listing-row__excerpt">{{ item.fields.excerpt }}</p>
        {% endif %}
      </div>
    </li>
  {% else %}
    <li class="listing-rows__empty">Nothing here yet.</li>
  {% endfor %}
</ul>
```

  `listing.twig` and `archive.twig`: replace their `<ul class="listing">…</ul>` blocks with
  `{% include '_listing_rows.twig' %}` (keep the `<h1>` and `{% include '_pagination.twig' %}`
  exactly where they are). `terms.twig`: matching typographic rows WITHOUT media:

```twig
<ul class="listing-rows listing-rows--terms">
  {% for term in terms %}
    <li class="listing-row{% if term.href %} listing-row--linked{% endif %}">
      <div class="listing-row__content">
        {% if term.href %}<a class="listing-row__title" href="{{ term.href }}">{{ term.slug ?? term.uuid }}</a>
        {% else %}<span class="listing-row__title">{{ term.slug ?? term.uuid }}</span>{% endif %}
        <span class="listing-row__meta">({{ term.count }})</span>
      </div>
    </li>
  {% else %}
    <li class="listing-rows__empty">No terms yet.</li>
  {% endfor %}
</ul>
```

- [ ] **Step 4: CSS** (append to site.css; unframed rows, separators, stretched link,
  pinned thumbnail sizes and content-visibility):

```css
/* ── Editorial listing rows (storefront-performance spec §8) ─────────────── */
.listing-rows { margin: 0; padding: 0; list-style: none; }
.listing-row {
  display: flex;
  gap: 1.25rem;
  padding: 1.25rem 0;
  border-bottom: 1px solid var(--line);
  position: relative;
  content-visibility: auto;
  contain-intrinsic-size: auto 110px;
}
.listing-row__media { flex: 0 0 auto; }
.listing-row__media img {
  width: 160px;
  aspect-ratio: 160 / 110;
  object-fit: cover;
  border-radius: 0.5rem;
  display: block;
}
.listing-row__content { min-width: 0; display: grid; gap: 0.25rem; align-content: start; }
.listing-row__title { font-weight: 650; color: var(--ink); text-decoration: none; }
.listing-row__date, .listing-row__meta { font-size: 0.85rem; color: var(--muted); }
.listing-row__excerpt {
  margin: 0;
  color: var(--muted);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
/* Whole-row affordance ONLY for linked rows (spec §8): stretched link keeps the
   semantic title anchor; routeless rows get no hover/focus treatment. */
.listing-row--linked .listing-row__title::after { content: ""; position: absolute; inset: 0; }
.listing-row--linked:hover .listing-row__title { text-decoration: underline; }
.listing-row--linked:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
@media (max-width: 48rem) {
  .listing-row { gap: 0.875rem; }
  .listing-row__media img { width: 96px; aspect-ratio: 96 / 72; }
}
@media (max-width: 20rem) {
  .listing-row { flex-direction: column; }
}
```

- [ ] **Step 5: Run** the extended `ListingArchivePagesTest`, then the Render suite, to
  green. Adjust any existing test asserting the old `<ul class="listing">` markup.

- [ ] **Step 6: Commit** —
  `git add packages/thallo-render/themes/default/templates/_listing_rows.twig packages/thallo-render/themes/default/templates/archive.twig packages/thallo-render/themes/default/templates/listing.twig packages/thallo-render/themes/default/templates/terms.twig packages/thallo-render/themes/default/assets/site.css tests/Integration/Render/ListingArchivePagesTest.php`
  `git commit -m "feat(theme): editorial listing rows for archive/listing/terms"`

---

### Task 5: View transitions, footer relief, runtime budget, CHANGELOG, full gates

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/site.css`
- Create: `tests/Integration/Render/RuntimeSizeBudgetTest.php`
- Modify: `CHANGELOG.md` (`[Unreleased]` → new Added bullet)

**Interfaces:**
- Consumes: nothing from earlier tasks (independent surfaces).
- Produces: nothing.

- [ ] **Step 1: Write the failing budget test:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * Storefront-performance spec §2: the single-runtime posture's visibility gate. The
 * budget is NOT a hard architectural ceiling — it exists so growth is a conscious
 * decision. If this fails: either the growth is optional-module weight that now
 * materially dominates the payload (THEN revisit splitting, per the spec's receipts) or
 * it is shared-core weight (raise the budget in the same commit, with reasoning).
 */
final class RuntimeSizeBudgetTest extends TestCase
{
    public function testRuntimeStaysWithinItsCompressedBudget(): void
    {
        $path = dirname(__DIR__, 3) . '/packages/thallo-render/runtime/runtime.js';
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source);

        $compressed = strlen((string) gzencode($source, 9));
        self::assertLessThanOrEqual(
            12_288,
            $compressed,
            "runtime.js is {$compressed} bytes at gzip -9 against a 12KB budget. "
            . 'Growth is fine when it is shared-core weight (raise the budget here, with '
            . 'reasoning); if optional modules now dominate the payload, revisit the '
            . 'splitting decision recorded in the storefront-performance spec §2.',
        );
    }
}
```

- [ ] **Step 2: Run it** — expected: PASS immediately (current ~9KB). Verify it can fail:
  temporarily assert `<= 1` and watch it fail with the message, then restore. (This test's
  red is a mutation check, not TDD — the budget documents current reality.)

- [ ] **Step 3: CSS — crossfade + footer relief** (append to site.css):

```css
/* ── Cross-document view transitions (storefront-performance spec §7): root crossfade
   only — no named elements, no morphs, no JS. The opt-in itself sits inside the
   reduced-motion guard so `reduce` users get instant navigation, not a shorter fade.
   Unsupported browsers ignore the whole rule. Custom themes remove or override this in
   their own CSS. ── */
@media (prefers-reduced-motion: no-preference) {
  @view-transition { navigation: auto; }
  ::view-transition-old(root),
  ::view-transition-new(root) {
    animation-duration: 150ms;
  }
}

/* Below-the-fold relief (spec §6): the footer is the ONLY other content-visibility
   surface (listing rows carry their own). 300px ≈ the default footer's scale; `auto`
   retains the learned size after first paint. */
.site-footer {
  content-visibility: auto;
  contain-intrinsic-size: auto 300px;
}
```

  (Verify `.site-footer` is the footer shell class in site.css — the namespaced shell
  selectors comment at the top of the file names it. If regions render a different footer
  wrapper class, use THAT class.)

- [ ] **Step 4: CHANGELOG** — add to `[Unreleased]` → `### Added`, above the existing
  bullets:

```markdown
- **Storefront performance & listing polish** (`packages/thallo-render` + delivery seam):
  responsive images behind a new optional `MediaVariantUrlResolver` render contract (the
  Thallo app always binds its MIME-aware implementation while candidate generation stays
  capability-gated; without real resizing, templates emit a plain `<img>` and non-image
  assets are omitted instead of ever rendering as broken images); at most one
  priority (LCP) image per page — the first eligible body image claims
  `fetchpriority="high"`, everything else lazy-loads; editorial listing rows for
  archive/listing/terms pages (cover thumbnail, clamped excerpt, date, whole-row hover
  with a semantic title link, server-side degradation when fields are absent);
  `content-visibility` relief on listing rows and the footer; a cross-document root
  crossfade via `@view-transition` (reduced-motion disabled); and a CI budget test
  pinning the single-runtime asset posture at 12KB gzipped.
```

- [ ] **Step 5: Full gates** —
  `set -o pipefail && vendor/bin/phpunit 2>&1 | tail -5` (full suite green),
  `node --check packages/thallo-render/runtime/runtime.js` (untouched, sanity),
  phpcs PSR12 on the new test file.

- [ ] **Step 6: Commit** —
  `git add packages/thallo-render/themes/default/assets/site.css tests/Integration/Render/RuntimeSizeBudgetTest.php CHANGELOG.md`
  `git commit -m "feat(theme): view-transition crossfade, footer relief, runtime size budget"`

---

## Self-Review Notes

- Spec coverage: §2 → Task 5 budget + posture (receipts live in the spec itself); §3 →
  Tasks 1–2; §4 → Tasks 2–3 (claim, positional logo, six boundaries); §5 → Tasks 3–4
  (per-template table incl. container-bg no-op — deliberately no task: backgrounds are
  recorded as out of srcset reach, nothing to change); §6 → Tasks 4 (rows) + 5 (footer);
  §7 → Task 5; §8 → Task 4; §9 → distributed per task.
- The `testMediaImageWithResolverHonorsTheThreeOutcomes` sanity line flagged in drafting
  was cleaned to plain assertions; Task 1's Step 6 `...` placeholders are deliberate
  copy-from-neighbor instructions naming the exact source method, not open TODOs.
- Resolver binding is intentionally two-layered: optional at the reusable render
  contract, always bound by the Thallo app for MIME-safe omission, with only candidate
  generation capability-gated.
- Reset coverage crosses the actual `RenderController` and `EntryBlocksRenderer`
  boundaries; template coverage carries `region_slug` explicitly through the
  `blog_posts` macro; listing coverage proves its two thumbnails share one page-wide
  priority claim.
- Every commit recipe names exact files. Directory-wide staging is prohibited so
  unrelated worktree changes cannot enter these commits.
- Type consistency: `media_image`/`mediaImage`, `claim_priority_image`/`claimPriorityImage`,
  `resetPerRenderState` used identically across Tasks 2–4; the contract shape
  `{src: string, srcset: ?string}` matches Tasks 1–3.
