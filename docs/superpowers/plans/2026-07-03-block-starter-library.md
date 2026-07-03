# Block Starter Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ten seedable starter block types (Layout/Content/Media) with matching default-theme templates, a `media()` blob-URL helper mirroring the full blob-route access stack, and a `safe_url` link-safety filter.

**Architecture:** Data + templates + two small Twig surfaces on finished machinery. `StarterBlockTypes::definitions()` is the one source of truth, seeded through `BlockTypeRepository::create()` by an idempotent opt-in CLI. `MediaUrlResolver` (new contract) mirrors `path()`'s architecture — core impl over the `blobs` table with the ROUTE-STACK access predicate, soft-bound into the render extension. Both new surfaces join `TemplatePolicy` with one `CACHE_VERSION` bump to 4 (the sanitizer shipped first at 3).

**Tech Stack:** unchanged. **Spec:** `docs/superpowers/specs/2026-07-03-block-starter-library-design.md` — read it first.

## Global Constraints

- **Commit gate:** STAGE at the end; commit only on explicit authorization. No attribution trailers. phpcs via `-q; echo $?`; `composer boundaries` after pack changes.
- **Spec pins (verbatim):** seeding is CLI opt-in, idempotent by slug, existing slugs (any active state) SKIPPED, no `--force`; per-slug output lines + `Created N, skipped M.`; `media()` returns a URL only when blob exists+active+not-deleted AND `visibility === 'public'` AND `uploads.enabled` truthy AND the FULL-STACK anonymous predicate holds — `$access !== 'private' && $access !== true && $access !== 'true' && $access !== 1` (the route middleware attaches `auth` for truthy-true forms before the controller's looser check); null otherwise; host-relative via `api_prefix()`; `safe_url` allows only `/`-relative (not `//`), `https://`, `http://`, `mailto:` — else null; `media` joins `FUNCTIONS`, `safe_url` joins `FILTERS`, **`CACHE_VERSION = 4`** (one bump, both surfaces; the sanitizer shipped first and took 3 with `safe_html`); template root class `lemma-block lemma-block-{slug}` + enum modifier classes; no `reference` fields in starters; ships after the nesting amendment (c13fe62 — already on dev).
- **House patterns:** app command in `App\Content\Console`, `#[AsCommand]`, extends `Glueful\Console\BaseCommand` (`$this->context`, `getService()`, `info/warning/success` helpers); registered in BOTH `LemmaServiceProvider::consoleCommandServices()` AND the `commands([...])` list. Command NAME follows the repo's `lemma:` namespace: **`lemma:blocks:seed` with alias `blocks:seed`** (the spec's invocation keeps working). After adding a command, regenerate the console manifest if CLI boot acts stale (`php glueful commands:cache` — known manifest gotcha).

## File Map

| File | Responsibility |
|---|---|
| `packages/lemma-contracts/src/Delivery/MediaUrlResolver.php` | contract |
| `app/Content/Delivery/EngineMediaUrlResolver.php` | route-stack-parity URL resolution |
| `packages/lemma-render/src/RenderContextExtension.php` (modify) | `media()` fn + `safe_url` filter |
| `packages/lemma-render/src/Templates/TemplatePolicy.php` (modify) | FUNCTIONS/FILTERS + CACHE_VERSION=4 |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` (modify) | soft-bind resolver into the extension |
| `app/Providers/LemmaServiceProvider.php` (modify) | resolver binding + command registration |
| `app/Content/Blocks/StarterBlockTypes.php` | the ten definitions |
| `app/Content/Console/SeedBlockTypesCommand.php` | `lemma:blocks:seed` |
| `packages/lemma-render/themes/default/templates/blocks/*.twig` (10) + `themes/default/assets/site.css` (append) | templates + starter CSS |
| Tests: `tests/Unit/Content/MediaAccessPredicateTest.php`, `tests/Integration/Content/MediaUrlResolverTest.php`, `tests/Integration/Content/SeedBlockTypesTest.php`, `tests/Integration/Render/StarterTemplatesTest.php`, extend `tests/Integration/Render/BlocksRenderingTest.php` (policy) | |

---

### Task 1: `media()` — contract, resolver, extension function, policy

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/MediaUrlResolver.php`, `app/Content/Delivery/EngineMediaUrlResolver.php`, `tests/Unit/Content/MediaAccessPredicateTest.php`, `tests/Integration/Content/MediaUrlResolverTest.php`
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Templates/TemplatePolicy.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`, `app/Providers/LemmaServiceProvider.php`

**Interfaces:**
- Produces: `MediaUrlResolver::url(string $uuid): ?string`; `EngineMediaUrlResolver::__construct(Connection $db, string $blobUrlBase, bool $uploadsEnabled, mixed $accessMode)` (**scalars injected by the factory — tests construct variants directly, no config reboots**); static `EngineMediaUrlResolver::anonymousRetrievalAllowed(mixed $access): bool` (the pure predicate, unit-matrix-testable); Twig `media(uuid)`; `TemplatePolicy::CACHE_VERSION = 4`.

- [ ] **Step 1: Failing tests**

`tests/Unit/Content/MediaAccessPredicateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Delivery\EngineMediaUrlResolver;
use PHPUnit\Framework\TestCase;

final class MediaAccessPredicateTest extends TestCase
{
    public function testMirrorsTheFullRouteStackNotJustTheController(): void
    {
        // DENY: the route middleware attaches auth for ALL of these (spec §3).
        foreach (['private', true, 'true', 1] as $denied) {
            self::assertFalse(
                EngineMediaUrlResolver::anonymousRetrievalAllowed($denied),
                'expected denied for ' . var_export($denied, true),
            );
        }
        // ALLOW: anonymous retrieval modes.
        foreach (['upload_only', 'public', false, 'false'] as $allowed) {
            self::assertTrue(
                EngineMediaUrlResolver::anonymousRetrievalAllowed($allowed),
                'expected allowed for ' . var_export($allowed, true),
            );
        }
    }
}
```

`tests/Integration/Content/MediaUrlResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineMediaUrlResolver;
use App\Tests\Support\LemmaTestCase;
use Glueful\Helpers\Utils;

final class MediaUrlResolverTest extends LemmaTestCase
{
    /** Insert a blobs row directly (the framework table; media uploads are out of scope). */
    private function seedBlob(string $visibility = 'public', string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/pic.jpg',
            'visibility' => $visibility,
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function resolver(bool $enabled = true, mixed $access = 'upload_only'): EngineMediaUrlResolver
    {
        return new EngineMediaUrlResolver($this->connection(), '/api/v1/blobs', $enabled, $access);
    }

    public function testPublicRetrievableBlobResolvesToTheBlobRoute(): void
    {
        $uuid = $this->seedBlob();
        self::assertSame('/api/v1/blobs/' . $uuid, $this->resolver()->url($uuid));
    }

    public function testEveryDenyConditionReturnsNull(): void
    {
        $public = $this->seedBlob();

        // Route-parity matrix: public blob, gated access modes (incl. the default install).
        foreach (['private', true, 'true', 1] as $gated) {
            self::assertNull($this->resolver(access: $gated)->url($public), var_export($gated, true));
        }
        // Uploads disabled entirely.
        self::assertNull($this->resolver(enabled: false)->url($public));
        // Private blob.
        self::assertNull($this->resolver()->url($this->seedBlob(visibility: 'private')));
        // Deleted blob.
        self::assertNull($this->resolver()->url($this->seedBlob(status: 'deleted')));
        // Missing blob.
        self::assertNull($this->resolver()->url('nope00000000'));
    }
}
```

NOTE: verify the `blobs` table's NOT NULL columns before finalizing `seedBlob()` (`\d blobs` via the framework migration `vendor/glueful/framework/migrations/uploads/*`); include any additional required columns with benign values, and add `'deleted_at' => null` only if the column rejects omission. Add `'blobs'` to `LemmaTestCase::TABLES` **only if** blob rows would otherwise leak across tests (they will — add it, child-position anywhere since blobs has no FK ordering here).

- [ ] **Step 2: Verify fail** — both files: classes not found.

- [ ] **Step 3: Contract + resolver**

`packages/lemma-contracts/src/Delivery/MediaUrlResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Resolves an uploaded-media blob uuid to a publicly retrievable URL for RENDERED
 * pages (starter-library spec §3). Null when the blob cannot be served anonymously —
 * private, deleted, missing, uploads disabled, or the global uploads access mode
 * requires auth. Rendered pages are cached, so expiring signed URLs are NEVER
 * emitted; templates skip the element on null.
 */
interface MediaUrlResolver
{
    public function url(string $uuid): ?string;
}
```

`app/Content/Delivery/EngineMediaUrlResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Database\Connection;
use Glueful\Lemma\Contracts\Delivery\MediaUrlResolver;

/**
 * Blob-route parity resolver (starter-library spec §3): emits a URL ONLY when the
 * framework blob route would serve it anonymously. The access predicate mirrors the
 * FULL route stack — framework/routes/blobs.php attaches `auth` middleware for
 * uploads.access ∈ {true, 'true', 1} BEFORE UploadController::checkBlobAccess()'s
 * looser `!== 'private'` comparison runs — so URL emission and servability never
 * diverge. Scalars are injected by the provider factory; tests construct variants
 * directly (no config reboots).
 */
final class EngineMediaUrlResolver implements MediaUrlResolver
{
    public function __construct(
        private readonly Connection $db,
        /** api_prefix($context) . '/blobs' — host-relative (spec §3). */
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
    ) {
    }

    /** The route-stack anonymous-retrieval predicate (spec §3, pinned verbatim). */
    public static function anonymousRetrievalAllowed(mixed $access): bool
    {
        return $access !== 'private'
            && $access !== true
            && $access !== 'true'
            && $access !== 1;
    }

    public function url(string $uuid): ?string
    {
        if (!$this->uploadsEnabled || !self::anonymousRetrievalAllowed($this->accessMode)) {
            return null;
        }
        $blob = $this->db->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        return $blob === null ? null : rtrim($this->blobUrlBase, '/') . '/' . $uuid;
    }
}
```

`app/Providers/LemmaServiceProvider.php` — bind the contract (mirror the other contract bindings' style; `use` imports):

```php
            MediaUrlResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaUrlResolver'],
            ],
```

```php
    public static function makeMediaUrlResolver(ContainerInterface $container): EngineMediaUrlResolver
    {
        $context = $container->get(ApplicationContext::class);
        return new EngineMediaUrlResolver(
            $container->get(Connection::class),
            api_prefix($context) . '/blobs',
            (bool) config($context, 'uploads.enabled', true),
            config($context, 'uploads.access', 'private'),
        );
    }
```

(Place them following the file's existing grouping; verify `api_prefix()` is available as a global helper the way `MediaAdminController` uses it.)

- [ ] **Step 4: Extension + policy**

`RenderContextExtension` — ctor gains (after `bool $debug = false`):

```php
        /** Soft-bound (starter-library spec §3): null = media() always returns null. */
        private readonly ?\Glueful\Lemma\Contracts\Delivery\MediaUrlResolver $mediaUrls = null,
```

(use a `use` import + short name per house style), `getFunctions()` gains:

```php
            new TwigFunction('media', $this->media(...)),
```

and the method:

```php
    /**
     * Uploaded-media URL for templates (starter-library spec §3): public +
     * anonymously retrievable blobs only (cached pages must never embed expiring
     * signed URLs). Null-safe on every failure — templates skip the element.
     */
    public function media(string $uuid): ?string
    {
        return $this->mediaUrls?->url($uuid);
    }
```

`LemmaRenderServiceProvider::makeRenderContextExtension()` — append the soft binding:

```php
            $container->has(MediaUrlResolver::class)
                ? $container->get(MediaUrlResolver::class)
                : null,
```

(with `use Glueful\Lemma\Contracts\Delivery\MediaUrlResolver;`).

`TemplatePolicy`: `'media',` joins `FUNCTIONS`; `CACHE_VERSION` becomes:

```php
    public const CACHE_VERSION = 4; // bumped: 'media' + 'safe_url' joined the allowlists (starter-library spec §5)
```

Extend `tests/Integration/Render/BlocksRenderingTest.php`'s policy test: the `CACHE_VERSION` assertion becomes `self::assertSame(4, TemplatePolicy::CACHE_VERSION);` and add `self::assertContains('media', TemplatePolicy::FUNCTIONS);` + a lint-clean check for `{{ media(data.image) }}`.

- [ ] **Step 5: Verify pass** — the two new test files + `tests/Integration/Render/BlocksRenderingTest.php` + full `tests/Integration/Render/`. Gates: phpcs, boundaries.

---

### Task 2: `safe_url` filter

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Templates/TemplatePolicy.php`
- Test: extend `tests/Integration/Render/BlocksRenderingTest.php`

**Interfaces:**
- Produces: Twig filter `safe_url` → `RenderContextExtension::safeUrl(mixed $value): ?string`; `'safe_url'` in `TemplatePolicy::FILTERS`.

- [ ] **Step 1: Failing test** (add to `BlocksRenderingTest`):

```php
    public function testSafeUrlAllowsOnlyApprovedSchemes(): void
    {
        $ext = $this->container()->get(RenderContextExtension::class);
        // Allow (spec §4).
        self::assertSame('/about', $ext->safeUrl('/about'));
        self::assertSame('https://example.com/x', $ext->safeUrl('https://example.com/x'));
        self::assertSame('http://example.com', $ext->safeUrl(' http://example.com '));
        self::assertSame('mailto:x@y.z', $ext->safeUrl('mailto:x@y.z'));
        // Deny (spec §4 security matrix).
        foreach (
            [
                'javascript:alert(1)',
                'JAVASCRIPT:alert(1)',
                '//evil.com',
                'data:text/html,x',
                'ftp://example.com',
                '',
                '   ',
                123,
                null,
            ] as $bad
        ) {
            self::assertNull($ext->safeUrl($bad), var_export($bad, true));
        }

        self::assertContains('safe_url', TemplatePolicy::FILTERS);
        // A DB template using the filter lints clean.
        self::assertSame(
            [],
            $this->container()->get(TemplateLinter::class)->lint('{{ data.cta_url|safe_url }}'),
        );
    }
```

- [ ] **Step 2: Verify fail.**

- [ ] **Step 3: Implement**

`RenderContextExtension` — APPEND to the existing `getFilters()` (the sanitizer created it with `safe_html`):

```php
    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            new TwigFilter('safe_url', $this->safeUrl(...)),
        ];
    }

    /**
     * Scheme-allowlisted link value (starter-library spec §4): Twig autoescape does
     * NOT make href="javascript:…" safe. Allows site-relative paths (never //
     * protocol-relative — they smuggle a host), https, http, and mailto; everything
     * else nulls and templates render the label as plain text instead of a link.
     */
    public function safeUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $url = trim($value);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        return preg_match('#\A(?:https://|http://|mailto:)#i', $url) === 1 ? $url : null;
    }
```

`TemplatePolicy::FILTERS` gains `'safe_url',` (CACHE_VERSION already 3 from Task 1).

- [ ] **Step 4: Verify pass** — `BlocksRenderingTest` + full render suite. Gates.

---

### Task 3: StarterBlockTypes + `lemma:blocks:seed`

**Files:**
- Create: `app/Content/Blocks/StarterBlockTypes.php`, `app/Content/Console/SeedBlockTypesCommand.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (consoleCommandServices + commands list + imports)
- Test: `tests/Integration/Content/SeedBlockTypesTest.php`

**Interfaces:**
- Produces: `StarterBlockTypes::definitions(): list<array{slug: string, label: string, icon: string, category: string, description: string, schema: list<array<string,mixed>>}>`; command `lemma:blocks:seed` (alias `blocks:seed`) with `run(): array{created: list<string>, skipped: list<string>}` seeding logic on the command or inline in `execute()`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Console\SeedBlockTypesCommand;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedBlockTypesTest extends LemmaTestCase
{
    private function run(): CommandTester
    {
        $command = $this->container()->get(SeedBlockTypesCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);
        return $tester;
    }

    public function testFirstRunCreatesAllDefinitionsThroughTheRepository(): void
    {
        $tester = $this->run();
        $repo = new BlockTypeRepository($this->connection());
        $expected = count(StarterBlockTypes::definitions()); // not a literal (spec §8)
        self::assertCount($expected, $repo->all());
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("Created {$expected}, skipped 0.", $tester->getDisplay());
        self::assertStringContainsString('created hero', $tester->getDisplay());

        // Every definition passed create() → §2 rules validated the starters themselves.
        self::assertSame('Layout', $repo->findBySlug('section')['category']);
        self::assertSame('blocks', $repo->findBySlug('section')['schema'][2]['type'] ?? $repo->findBySlug('section')['schema'][1]['type']);
    }

    public function testRerunSkipsEverythingAndPreservesAdminEdits(): void
    {
        $this->run();
        $repo = new BlockTypeRepository($this->connection());
        // Admin edits hero…
        $hero = $repo->findBySlug('hero');
        $repo->updateSchema((string) $hero['uuid'], [['name' => 'headline', 'type' => 'string']],
            'My Hero', null, null, 'Custom');
        // …and deactivates quote (also an admin decision the seeder must respect).
        $repo->setActive((string) $repo->findBySlug('quote')['uuid'], false);

        $tester = $this->run();
        $expected = count(StarterBlockTypes::definitions());
        self::assertStringContainsString("Created 0, skipped {$expected}.", $tester->getDisplay());
        self::assertStringContainsString('skipped hero (exists)', $tester->getDisplay());

        $after = $repo->findBySlug('hero');
        self::assertSame('My Hero', $after['label']);                      // byte-identical edit survives
        self::assertSame('headline', $after['schema'][0]['name']);
        self::assertSame(0, (int) $repo->findBySlug('quote')['active']);   // deactivation survives
    }
}
```

(Fix the clumsy section-schema index assertion while implementing: assert `array_column($repo->findBySlug('section')['schema'], 'type')` contains `'blocks'`.) NOTE the known harness caveat: `CommandTester` runs the command object directly — it does not prove console-manifest registration; the registration is code-reviewed (provider diff) and covered by the `commands:cache` note.

- [ ] **Step 2: Verify fail.**

- [ ] **Step 3: The definitions** — `app/Content/Blocks/StarterBlockTypes.php` (spec §1 table, verbatim):

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * The starter block library (starter-library spec §1) — DATA ONLY, the one source of
 * truth for `lemma:blocks:seed`. Every schema passes BlockTypeRepository::create()'s
 * §2 rules (the seeder goes through it, so the starters validate themselves). No
 * `reference` fields: reference_type targets site-specific content types.
 */
final class StarterBlockTypes
{
    /**
     * @return list<array{slug: string, label: string, icon: string, category: string,
     *   description: string, schema: list<array<string,mixed>>}>
     */
    public static function definitions(): array
    {
        return [
            ['slug' => 'section', 'label' => 'Section', 'icon' => 'i-lucide-rows-3',
                'category' => 'Layout', 'description' => 'A titled band of content with a background style.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'background', 'type' => 'enum', 'enum' => ['none', 'subtle', 'emphasis']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'columns', 'label' => 'Columns', 'icon' => 'i-lucide-columns-3',
                'category' => 'Layout', 'description' => 'Two or three columns of blocks.',
                'schema' => [
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['2', '3']],
                    ['name' => 'col_1', 'type' => 'blocks'],
                    ['name' => 'col_2', 'type' => 'blocks'],
                    ['name' => 'col_3', 'type' => 'blocks'],
                ]],
            ['slug' => 'divider', 'label' => 'Divider', 'icon' => 'i-lucide-minus',
                'category' => 'Layout', 'description' => 'A horizontal rule or visual break.',
                'schema' => [
                    ['name' => 'style', 'type' => 'enum', 'enum' => ['line', 'space']],
                ]],
            ['slug' => 'spacer', 'label' => 'Spacer', 'icon' => 'i-lucide-move-vertical',
                'category' => 'Layout', 'description' => 'Vertical breathing room.',
                'schema' => [
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                ]],
            ['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-sparkles',
                'category' => 'Content', 'description' => 'Big heading, optional image and call to action.',
                'schema' => [
                    ['name' => 'heading', 'type' => 'string', 'required' => true],
                    ['name' => 'subheading', 'type' => 'string'],
                    ['name' => 'image', 'type' => 'asset'],
                    ['name' => 'alignment', 'type' => 'enum', 'enum' => ['left', 'center']],
                    ['name' => 'cta_label', 'type' => 'string'],
                    ['name' => 'cta_url', 'type' => 'string'],
                ]],
            ['slug' => 'rich_text', 'label' => 'Rich text', 'icon' => 'i-lucide-text',
                'category' => 'Content', 'description' => 'Free-form formatted text.',
                'schema' => [
                    ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ]],
            ['slug' => 'quote', 'label' => 'Quote', 'icon' => 'i-lucide-quote',
                'category' => 'Content', 'description' => 'A pull quote with attribution.',
                'schema' => [
                    ['name' => 'text', 'type' => 'text', 'required' => true],
                    ['name' => 'attribution', 'type' => 'string'],
                ]],
            ['slug' => 'cta', 'label' => 'Call to action', 'icon' => 'i-lucide-megaphone',
                'category' => 'Content', 'description' => 'Heading, supporting text and a button.',
                'schema' => [
                    ['name' => 'heading', 'type' => 'string', 'required' => true],
                    ['name' => 'body', 'type' => 'text'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['primary', 'secondary']],
                ]],
            ['slug' => 'image', 'label' => 'Image', 'icon' => 'i-lucide-image',
                'category' => 'Media', 'description' => 'A single image with caption.',
                'schema' => [
                    ['name' => 'image', 'type' => 'asset', 'required' => true],
                    ['name' => 'alt', 'type' => 'string'],
                    ['name' => 'caption', 'type' => 'string'],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                ]],
            ['slug' => 'gallery', 'label' => 'Gallery', 'icon' => 'i-lucide-layout-grid',
                'category' => 'Media', 'description' => 'A grid of images.',
                'schema' => [
                    ['name' => 'images', 'type' => 'asset', 'multiple' => true],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                ]],
        ];
    }
}
```

- [ ] **Step 4: The command** — `app/Content/Console/SeedBlockTypesCommand.php` (mirror `PruneVersionsCommand`'s shape):

```php
<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opt-in starter seeding (starter-library spec §2): idempotent by slug — an existing
 * slug is SKIPPED in ANY active state (a deactivated starter is still an admin
 * decision), so reruns never overwrite edits. Deliberately no --force.
 */
#[AsCommand(
    name: 'lemma:blocks:seed',
    description: 'Seed the starter block types (skips any slug that already exists)',
    aliases: ['blocks:seed'],
)]
final class SeedBlockTypesCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BlockTypeRepository $repo */
        $repo = $this->getService(BlockTypeRepository::class);
        $created = 0;
        $skipped = 0;
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) !== null) {
                $this->line("skipped {$definition['slug']} (exists)");
                $skipped++;
                continue;
            }
            $repo->create($definition);
            $this->line("created {$definition['slug']}");
            $created++;
        }
        $this->success("Created {$created}, skipped {$skipped}.");
        return self::SUCCESS;
    }
}
```

(Verify `BaseCommand` exposes `line()`/`success()` — check its helpers; substitute `$this->info()`/`$output->writeln()` in the same shape if named differently, keeping the pinned output strings.)

Provider: `SeedBlockTypesCommand::class` entry in `consoleCommandServices()` (mirror `PruneVersionsCommand`'s entry) + the `commands([...])` list + `use` import.

- [ ] **Step 5: Verify pass** — `SeedBlockTypesTest` + `vendor/bin/phpunit tests/Integration/Content/`. Gates.

---

### Task 4: Templates + starter CSS + render smokes

**Files:**
- Create: `packages/lemma-render/themes/default/templates/blocks/{section,columns,divider,spacer,hero,rich_text,quote,cta,image,gallery}.twig`
- Modify: `packages/lemma-render/themes/default/assets/site.css` (append)
- Test: `tests/Integration/Render/StarterTemplatesTest.php`

**Interfaces:**
- Consumes: `blocks()` (containers), `media()` (Task 1), `safe_url` (Task 2); starter schemas (Task 3 fixture data).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\StarterBlockTypes;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;

final class StarterTemplatesTest extends LemmaTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** Representative data per starter slug (media uuids resolve to null harmlessly). */
    private function fixture(string $slug): array
    {
        return match ($slug) {
            'section' => ['title' => 'Band', 'background' => 'subtle',
                'content' => [['id' => 'x1', 'type' => 'quote', 'data' => ['text' => 'Inner']]]],
            'columns' => ['layout' => '2',
                'col_1' => [['id' => 'x2', 'type' => 'quote', 'data' => ['text' => 'Left']]],
                'col_2' => [['id' => 'x3', 'type' => 'quote', 'data' => ['text' => 'Right']]],
                'col_3' => []],
            'divider' => ['style' => 'line'],
            'spacer' => ['size' => 'large'],
            'hero' => ['heading' => 'Big', 'subheading' => 'Sub', 'image' => 'blob00000000',
                'alignment' => 'center', 'cta_label' => 'Go', 'cta_url' => '/start'],
            'rich_text' => ['body' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>'],
            'quote' => ['text' => 'Wise words', 'attribution' => 'Someone'],
            'cta' => ['heading' => 'Act now', 'body' => 'Because.', 'button_label' => 'Do it',
                'button_url' => 'https://example.com', 'variant' => 'primary'],
            'image' => ['image' => 'blob00000000', 'alt' => 'A pic', 'caption' => 'Cap', 'width' => 'wide'],
            'gallery' => ['images' => ['blob00000000', 'blob00000001'], 'columns' => '3'],
            default => [],
        };
    }

    public function testEveryStarterRendersWithRootAndModifierClasses(): void
    {
        $env = $this->env();
        foreach (StarterBlockTypes::definitions() as $definition) {
            $slug = $definition['slug'];
            $out = $env->createTemplate("{{ blocks(list) }}")->render(['list' => [
                ['id' => 'b1', 'type' => $slug, 'data' => $this->fixture($slug)],
            ]]);
            self::assertNotSame('', trim($out), "empty render for {$slug}");
            self::assertStringContainsString("lemma-block-{$slug}", $out, $slug);
        }
        // rich_text renders SANITIZED through safe_html — markup survives, attacks
        // never reach output (the no-|raw pin, now with the sanitizer shipped).
        $rich = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'rt', 'type' => 'rich_text', 'data' => $this->fixture('rich_text')]]]);
        self::assertStringContainsString('<strong>world</strong>', $rich);
        self::assertStringNotContainsString('<script', $rich);

        // Spot-check modifier classes (the style-convention pin).
        $section = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 's', 'type' => 'section', 'data' => $this->fixture('section')]]]);
        self::assertStringContainsString('lemma-block-section--subtle', $section);
        self::assertStringContainsString('Inner', $section); // children composed
    }

    public function testColumnsRendersPerLayoutEnum(): void
    {
        $env = $this->env();
        $two = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c', 'type' => 'columns', 'data' => $this->fixture('columns')]]]);
        self::assertStringContainsString('Left', $two);
        self::assertStringContainsString('Right', $two);
        self::assertStringContainsString('lemma-block-columns--2', $two);

        $data = $this->fixture('columns');
        $data['layout'] = '3';
        $data['col_3'] = [['id' => 'x4', 'type' => 'quote', 'data' => ['text' => 'Third']]];
        $three = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c3', 'type' => 'columns', 'data' => $data]]]);
        self::assertStringContainsString('Third', $three);
        self::assertStringContainsString('lemma-block-columns--3', $three);
    }

    public function testUnsafeCtaUrlsRenderNoLinkThroughTheRealTemplates(): void
    {
        $env = $this->env();
        foreach (['javascript:alert(1)', '//evil.com'] as $bad) {
            foreach (['hero' => 'cta_url', 'cta' => 'button_url'] as $slug => $field) {
                $data = $this->fixture($slug);
                $data[$field] = $bad;
                $out = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
                    ['id' => 'u', 'type' => $slug, 'data' => $data]]]);
                self::assertStringNotContainsString('<a href', $out, "{$slug} with {$bad}");
            }
        }
    }

    public function testMediaTemplatesSkipTheImageOnUnresolvableBlobs(): void
    {
        // The suite's uploads.access default is private → media() nulls; the image
        // element must be absent while the block still renders.
        $out = $this->env()->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'i', 'type' => 'image', 'data' => $this->fixture('image')]]]);
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('lemma-block-image', $out);
    }
}
```

- [ ] **Step 2: Verify fail** — templates missing (debug placeholders render → class assertions fail).

- [ ] **Step 3: The ten templates** (each root carries `lemma-block lemma-block-{slug}` + enum modifiers; media via `media()` + null-skip; links via `safe_url`):

`blocks/section.twig`:
```twig
<section class="lemma-block lemma-block-section lemma-block-section--{{ data.background|default('none') }}">
  {% if data.title %}<h2 class="lemma-block-section__title">{{ data.title }}</h2>{% endif %}
  {{ blocks(data.content) }}
</section>
```

`blocks/columns.twig`:
```twig
{% set layout = data.layout|default('2') %}
<div class="lemma-block lemma-block-columns lemma-block-columns--{{ layout }}">
  <div class="lemma-block-columns__col">{{ blocks(data.col_1) }}</div>
  <div class="lemma-block-columns__col">{{ blocks(data.col_2) }}</div>
  {% if layout == '3' %}<div class="lemma-block-columns__col">{{ blocks(data.col_3) }}</div>{% endif %}
</div>
```

`blocks/divider.twig`:
```twig
{% if data.style|default('line') == 'line' %}<hr class="lemma-block lemma-block-divider lemma-block-divider--line">
{% else %}<div class="lemma-block lemma-block-divider lemma-block-divider--space" role="presentation"></div>{% endif %}
```

`blocks/spacer.twig`:
```twig
<div class="lemma-block lemma-block-spacer lemma-block-spacer--{{ data.size|default('medium') }}" role="presentation"></div>
```

`blocks/hero.twig`:
```twig
{% set img = data.image ? media(data.image) : null %}
{% set url = data.cta_url|default('')|safe_url %}
<header class="lemma-block lemma-block-hero lemma-block-hero--{{ data.alignment|default('left') }}">
  {% if img %}<img class="lemma-block-hero__image" src="{{ img }}" alt="">{% endif %}
  <h1 class="lemma-block-hero__heading">{{ data.heading }}</h1>
  {% if data.subheading %}<p class="lemma-block-hero__subheading">{{ data.subheading }}</p>{% endif %}
  {% if data.cta_label %}
    {% if url %}<a class="lemma-block-hero__cta" href="{{ url }}">{{ data.cta_label }}</a>
    {% else %}<span class="lemma-block-hero__cta">{{ data.cta_label }}</span>{% endif %}
  {% endif %}
</header>
```

`blocks/rich_text.twig` (still NO `|raw` — `safe_html` is the ONLY rich render surface):
```twig
{# Rendered through safe_html (sanitizer spec §4): the value was sanitized at
   save, and the filter re-sanitizes at output (fail-closed to escaped text when
   no sanitizer is bound). Starter templates never use |raw. #}
<div class="lemma-block lemma-block-rich_text">{{ data.body|default('')|safe_html }}</div>
```

`blocks/quote.twig`:
```twig
<blockquote class="lemma-block lemma-block-quote">
  <p>{{ data.text }}</p>
  {% if data.attribution %}<cite>{{ data.attribution }}</cite>{% endif %}
</blockquote>
```

`blocks/cta.twig`:
```twig
{% set url = data.button_url|default('')|safe_url %}
<aside class="lemma-block lemma-block-cta lemma-block-cta--{{ data.variant|default('primary') }}">
  <h2 class="lemma-block-cta__heading">{{ data.heading }}</h2>
  {% if data.body %}<p class="lemma-block-cta__body">{{ data.body }}</p>{% endif %}
  {% if data.button_label %}
    {% if url %}<a class="lemma-block-cta__button" href="{{ url }}">{{ data.button_label }}</a>
    {% else %}<span class="lemma-block-cta__button">{{ data.button_label }}</span>{% endif %}
  {% endif %}
</aside>
```

`blocks/image.twig`:
```twig
{% set img = data.image ? media(data.image) : null %}
<figure class="lemma-block lemma-block-image lemma-block-image--{{ data.width|default('normal') }}">
  {% if img %}<img src="{{ img }}" alt="{{ data.alt|default('') }}">{% endif %}
  {% if data.caption %}<figcaption>{{ data.caption }}</figcaption>{% endif %}
</figure>
```

`blocks/gallery.twig`:
```twig
<div class="lemma-block lemma-block-gallery lemma-block-gallery--{{ data.columns|default('3') }}">
  {% for uuid in data.images|default([]) %}
    {% set img = media(uuid) %}
    {% if img %}<img class="lemma-block-gallery__item" src="{{ img }}" alt="">{% endif %}
  {% endfor %}
</div>
```

`site.css` — append a clearly-delimited starter section (grid for columns/gallery per modifier, spacer sizes, section backgrounds, hero alignment, cta variants, divider space; ~60 lines of minimal intentional CSS — write it in full at implementation, keeping selectors exactly on the `lemma-block-*` classes above; no resets, no variables beyond what site.css already uses).

- [ ] **Step 4: Verify pass** — `StarterTemplatesTest` + full `tests/Integration/Render/`. Gates (phpcs ignores twig/css; boundaries).

---

### Task 5: Docs + full verification + STAGE

- [ ] **Step 1: README** (render pack) — extend "Blocks in templates":

```markdown
`php glueful lemma:blocks:seed` (alias `blocks:seed`) seeds ten starter block types
(Layout/Content/Media) with matching default-theme templates — idempotent, skips any
existing slug, never overwrites admin edits. Media blocks render through `media(uuid)`
(public, anonymously retrievable blobs only — set `UPLOADS_ACCESS=upload_only` or
`public`; private/gated blobs render nothing). Link fields render through the
`safe_url` filter (relative, https, http, mailto only). Style enums map to
`lemma-block-{slug}--{value}` modifier classes — restyle by targeting them.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]`** — append to the block-builder bullet:

```markdown
  Follow-up (same day): **starter block library** — `lemma:blocks:seed` (idempotent,
  opt-in, never overwrites) seeds 10 starter types with default-theme templates and
  style-convention modifier classes; new `media(uuid)` helper (MediaUrlResolver
  contract — public + anonymously-retrievable blobs only, full blob-route-stack
  parity) and `safe_url` filter (scheme-allowlisted hrefs); both joined the
  DB-template sandbox allowlists (CACHE_VERSION → 3).
```

- [ ] **Step 3: Full verification + STAGE** *(commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..   # untouched, but keep the gate honest
git add packages/lemma-contracts packages/lemma-render app/Content app/Providers/LemmaServiceProvider.php \
        CHANGELOG.md tests/Support/LemmaTestCase.php tests/Unit/Content tests/Integration/Content tests/Integration/Render
```

Expected: green (single pre-existing skip). STOP — when authorized:

```bash
git commit -m "feat(content): starter block library — seedable types, default templates, media()/safe_url helpers

lemma:blocks:seed (alias blocks:seed): idempotent opt-in seeding of 10 starter
block types (Layout/Content/Media) through BlockTypeRepository::create() —
existing slugs skipped in any active state, per-slug output + summary, no
--force. New MediaUrlResolver contract + EngineMediaUrlResolver with FULL
blob-route-stack parity (visibility=public AND uploads.enabled AND access not
in {private,true,'true',1}) so cached pages never embed 401 or expiring URLs;
soft-bound media() Twig function. safe_url filter allowlists /-relative (not
//), https, http, mailto — hero/cta render plain text otherwise (tested with
javascript:/data:/protocol-relative payloads through the real templates). Ten
default-theme blocks/*.twig with lemma-block-{slug} + enum modifier classes and
starter CSS. media + safe_url join TemplatePolicy (CACHE_VERSION=3)."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 set (Task 3 data, verbatim incl. icons/categories/descriptions); §2 CLI semantics + pinned output + registration in BOTH provider spots + `lemma:` naming with the spec's alias; §3 media contract/impl/soft-binding/predicate VERBATIM + scalars-injected testability + route-parity deny matrix incl. all three truthy forms; §4 filter + full security matrix at filter AND template level; §5 one CACHE_VERSION bump to 3 with both memberships asserted + lint-clean checks; §6 all ten templates with the class convention, `columns` per-layout regions, media null-skip, CSS append; §7 error rows all covered by tests; §8 test list fully mapped (counts derived from `definitions()`, not literals).
- **Type consistency:** `EngineMediaUrlResolver` ctor scalars match the factory and every test construction; `anonymousRetrievalAllowed` static referenced identically in unit test and impl; fixture slugs match `definitions()` slugs; template class names match the test assertions.
- **Verify-don't-guess (flagged inline):** `blobs` NOT NULL columns for `seedBlob()` (+ TABLES addition); `BaseCommand`'s output-helper names (`line`/`success`); `api_prefix()` availability in the provider.
- **Resolved in review (supersedes an earlier planning call):** NO `|raw` in starter templates, period — the `rich_text` starter was REPLACED by an escaped `copy` block (plain text + `nl2br`). The safe pipeline (editor HTML → server-side allowlist sanitization on save/publish → templates render the sanitized value, e.g. a future `safe_html` filter) is the "rich HTML sanitization/rendering" follow-up; a rich starter joins only after that contract exists. The Notion-like `BlocksField` UX (slash menu, inline insertion, drag handles, keyboard movement) is likewise a separate SPA sub-project, out of this plan.
