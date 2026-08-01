# Package-Contributed Templates in the Admin Theme Editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Contributed package templates (thallo-account `account/*`, thallo-commerce `shop/*`, both packs' `blocks/*`) get the same admin Theme-editor UX as theme templates — listed, viewable, editable via DB override, history/restore/delete — without weakening the template sandbox.

**Architecture:** The admin `TemplateCatalog` consumes the same frozen `RenderContributionRegistry` snapshot the runtime `ThemeLocator` uses (one immutable snapshot, two accessors). Contributed files surface with a new generic origin `package` in the precedence `db → theme → package → default`. The template sandbox (`TemplatePolicy` + `TemplateLinter`) gains twelve reviewed functions and one new safe-JSON emitter, removes both unbounded range forms, and makes every shipped template round-trip through the editor under an exception-free CI lint gate.

**Tech Stack:** PHP 8.3 (Glueful framework, NOT Laravel), Twig 3, PHPUnit 10 (consumer-app tests extend `App\Tests\Support\AppTestCase`), Vue 3 + Nuxt UI admin SPA, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-01-admin-contributed-templates-design.md` — read it first; its pinned rules override any improvisation.

## Global Constraints

- Precedence everywhere: **DB override → active theme → ordered package contribution → render default.** Package files are immutable baselines; no code path writes them.
- Public API origin vocabulary: `db | theme | package | default`. NO per-package field, NO contributor id in responses (internal diagnostics only).
- `raw`, `constant`, `range(...)`, and `1..N` stay denied in `TemplatePolicy`. Task 5 removes both the `range` function and `RangeBinary` node: either form can allocate an unbounded array before a callee such as `media_image()` can normalize its input, and no shipped template uses them.
- Exactly ONE `TemplatePolicy::CACHE_VERSION` bump for this whole feature: 16 → 17 (do it in Task 5; no other task touches it).
- Honest capability-off behavior: with a pack disabled, its package baselines vanish from the catalog but existing active DB overrides remain listed as `origin: "db"` and resolvable; deleting one then leaves no fallback. Do NOT build provenance/dormancy.
- The lint-all-shipped gate is **exception-free** — no allowlist-of-failures.
- Run PHP tests with `vendor/bin/phpunit --filter <TestClass>` from the repo root; admin tests with `npm test -- --run <spec>` from `admin/`.
- Commit after every task. Stage each new/modified file by exact path with `git add <paths>`, then commit the same exact path set with `git commit --only <paths>`; never use `git add -A` — unrelated working-tree changes exist. (`git commit --only` cannot add an untracked test file by itself.)

---

### Task 1: `frozenTemplateContributions()` registry accessor

**Files:**
- Modify: `packages/thallo-render/src/Contribution/RenderContributionRegistry.php`
- Test: `tests/Integration/Render/RenderContributionTest.php`

**Interfaces:**
- Produces: `RenderContributionRegistry::frozenTemplateContributions(): list<array{contributor_id: string, dir: string}>` (ordered rows) and the unchanged `frozenTemplatePaths(): list<string>` now projected from the same frozen snapshot. Task 3 consumes `frozenTemplateContributions()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Render/RenderContributionTest.php` using its existing `templateContributor(string $id, array $dirs, int $priority = 0)` helper:

```php
public function testFrozenTemplateContributionsReturnsOrderedRowsAndPathsProjectSameSnapshot(): void
{
    $registry = new RenderContributionRegistry();
    $registry->registerTemplatePaths($this->templateContributor('b-pack', ['/tmp/b'], priority: 10));
    $registry->registerTemplatePaths($this->templateContributor('a-pack', ['/tmp/a'], priority: 10));
    $registry->registerTemplatePaths($this->templateContributor('z-first', ['/tmp/z'], priority: 0));

    // Ordered (priority, contributorId) — same ordering rule as frozenTemplatePaths().
    self::assertSame([
        ['contributor_id' => 'z-first', 'dir' => '/tmp/z'],
        ['contributor_id' => 'a-pack', 'dir' => '/tmp/a'],
        ['contributor_id' => 'b-pack', 'dir' => '/tmp/b'],
    ], $registry->frozenTemplateContributions());

    // The dirs projection is the SAME snapshot, not a second freeze.
    self::assertSame(['/tmp/z', '/tmp/a', '/tmp/b'], $registry->frozenTemplatePaths());
}

public function testFrozenTemplateContributionsFreezesTheRegistry(): void
{
    $registry = new RenderContributionRegistry();
    $registry->frozenTemplateContributions();

    $this->expectException(\RuntimeException::class);
    $registry->registerTemplatePaths($this->templateContributor('late', ['/tmp/late']));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RenderContributionTest`
Expected: the two new tests FAIL with "Call to undefined method … frozenTemplateContributions()".

- [ ] **Step 3: Implement**

In `RenderContributionRegistry.php`:

1. Change the snapshot property (line 32-33) to hold rows:

```php
/** @var list<array{contributor_id: string, dir: string}>|null */
private ?array $frozenTemplateSnapshot = null;
```

2. Replace `frozenTemplatePaths()` (lines 64-71) with the projection + new accessor:

```php
/** @return list<string> */
public function frozenTemplatePaths(): array
{
    return array_column($this->frozenTemplateContributions(), 'dir');
}

/**
 * The same frozen snapshot as {@see frozenTemplatePaths()}, with contributor ids
 * (admin-contributed-templates spec §1) — ids are for deterministic resolution and
 * diagnostics only, never for public API responses.
 *
 * @return list<array{contributor_id: string, dir: string}>
 */
public function frozenTemplateContributions(): array
{
    $this->freeze();
    /** @var list<array{contributor_id: string, dir: string}> $snapshot */
    $snapshot = $this->frozenTemplateSnapshot;
    return $snapshot;
}
```

3. Make `buildTemplateSnapshot()` (lines 126-141) emit rows:

```php
/** @return list<array{contributor_id: string, dir: string}> */
private function buildTemplateSnapshot(): array
{
    $rows = [];
    $seen = [];
    foreach ($this->ordered($this->templates) as $contributor) {
        foreach ($contributor->templatePaths() as $dir) {
            if (isset($seen[$dir])) {
                throw new \LogicException("Duplicate contributed template path '{$dir}'.");
            }
            $seen[$dir] = true;
            $rows[] = ['contributor_id' => $contributor->contributorId(), 'dir' => $dir];
        }
    }
    return $rows;
}
```

- [ ] **Step 4: Run the whole contribution suite**

Run: `vendor/bin/phpunit --filter RenderContributionTest`
Expected: ALL tests PASS (the pre-existing frozen-path/freeze-semantics tests prove backward compatibility).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/Contribution/RenderContributionRegistry.php tests/Integration/Render/RenderContributionTest.php
git commit --only packages/thallo-render/src/Contribution/RenderContributionRegistry.php tests/Integration/Render/RenderContributionTest.php -m "feat(render): frozenTemplateContributions() — contributor rows off the one frozen snapshot"
```

---

### Task 2: Contribution-aware `TemplateCatalog`

**Files:**
- Modify: `packages/thallo-render/src/Templates/TemplateCatalog.php`
- Test: Create `tests/Integration/Render/TemplateCatalogContributionsTest.php`

**Interfaces:**
- Consumes: nothing new (constructor rows match Task 1's shape but are plain arrays here).
- Produces: `TemplateCatalog::__construct(TemplateRepository $repo, string $appThemesDir, string $packThemesDir, array $contributions = [])` where `$contributions` is `list<array{contributor_id: string, dir: string}>` in registry order (highest precedence first). `list()` rows may now carry `origin: "package"`; `readFile()` may return `origin: "package"`. Tasks 3, 8, 9 rely on this.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/TemplateCatalogContributionsTest.php`. Pattern: direct construction with tmp dirs (mirrors `RenderContributionTest`'s tmp-dir + `rmrf` discipline); `TemplateRepository` comes from the app container so DB-override interplay is real.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Templates\TemplateRepository;

final class TemplateCatalogContributionsTest extends AppTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/thallo-catalog-contrib-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/contribA/shop', 0755, true);
        mkdir($this->tmp . '/contribB', 0755, true);
        mkdir($this->tmp . '/appthemes/mytheme/templates', 0755, true);
        file_put_contents($this->tmp . '/contribA/shop/checkout.twig', 'CONTRIB-A-CHECKOUT');
        file_put_contents($this->tmp . '/contribA/probe.twig', 'CONTRIB-A-PROBE');
        file_put_contents($this->tmp . '/contribB/probe.twig', 'CONTRIB-B-PROBE');
        // Also present in the pack default: entry.twig — contribution must NOT shadow it
        // in the catalog when only the default ships it… but a contributed copy MUST win:
        file_put_contents($this->tmp . '/contribA/entry.twig', 'CONTRIB-A-ENTRY');
        file_put_contents($this->tmp . '/appthemes/mytheme/templates/probe.twig', 'APP-THEME-PROBE');
        file_put_contents(
            $this->tmp . '/appthemes/mytheme/theme.json',
            (string) json_encode(['name' => 'mytheme', 'version' => '1.0.0']),
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** @param list<array{contributor_id: string, dir: string}> $contributions */
    private function catalog(array $contributions): TemplateCatalog
    {
        return new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/appthemes',
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes',
            $contributions,
        );
    }

    /** @return list<array{contributor_id: string, dir: string}> */
    private function contributions(): array
    {
        return [
            ['contributor_id' => 'a-pack', 'dir' => $this->tmp . '/contribA'],
            ['contributor_id' => 'b-pack', 'dir' => $this->tmp . '/contribB'],
        ];
    }

    public function testContributedTemplatesListWithPackageOrigin(): void
    {
        $byPath = array_column($this->catalog($this->contributions())->list('default'), null, 'path');

        self::assertSame('package', $byPath['shop/checkout.twig']['origin']);
        self::assertFalse($byPath['shop/checkout.twig']['overridden']);
        // Contributed copy of a pack-default name wins the listing (contribution > default).
        self::assertSame('package', $byPath['entry.twig']['origin']);
        // Pack-default-only names keep origin 'default'.
        self::assertSame('default', $byPath['layout.twig']['origin']);
    }

    public function testPrecedenceThemeOverContributionOverDefaultAndFirstContributorWins(): void
    {
        $byPath = array_column($this->catalog($this->contributions())->list('mytheme'), null, 'path');
        // App theme beats both contributions.
        self::assertSame('theme', $byPath['probe.twig']['origin']);

        // Without the app theme, first-registered contribution wins (a-pack over b-pack).
        $read = $this->catalog($this->contributions())->readFile('default', 'probe.twig');
        self::assertSame(['source' => 'CONTRIB-A-PROBE', 'origin' => 'package'], $read);
    }

    public function testReadFileLadderSeedsPackageSource(): void
    {
        $catalog = $this->catalog($this->contributions());
        self::assertSame(
            ['source' => 'CONTRIB-A-CHECKOUT', 'origin' => 'package'],
            $catalog->readFile('default', 'shop/checkout.twig'),
        );
        // App theme still wins the ladder.
        self::assertSame(
            ['source' => 'APP-THEME-PROBE', 'origin' => 'theme'],
            $catalog->readFile('mytheme', 'probe.twig'),
        );
        // Contribution beats pack default in the ladder.
        self::assertSame(
            ['source' => 'CONTRIB-A-ENTRY', 'origin' => 'package'],
            $catalog->readFile('default', 'entry.twig'),
        );
    }

    public function testZeroContributionsIsByteIdenticalToPreFeature(): void
    {
        $with = $this->catalog([])->list('default');
        $without = new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/appthemes',
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes',
        );
        self::assertSame($without->list('default'), $with);
        self::assertNull($this->catalog([])->readFile('default', 'shop/checkout.twig'));
    }

    public function testCapabilityOffHonestBehaviorOverrideSurvivesBaselineRemoval(): void
    {
        /** @var TemplateRepository $repo */
        $repo = $this->container()->get(TemplateRepository::class);
        $repo->save('default', 'shop/checkout.twig', 'DB-OVERRIDE', 'user00000001');

        // Capability on: DB override wins the row, origin db.
        $on = array_column($this->catalog($this->contributions())->list('default'), null, 'path');
        self::assertSame('db', $on['shop/checkout.twig']['origin']);
        self::assertTrue($on['shop/checkout.twig']['overridden']);

        // Capability off (no contributions): the override REMAINS listed as db —
        // the honest behavior pinned in spec §Pinned rules 5 — but the filesystem
        // baseline is gone.
        $off = array_column($this->catalog([])->list('default'), null, 'path');
        self::assertSame('db', $off['shop/checkout.twig']['origin']);
        self::assertNull($this->catalog([])->readFile('default', 'shop/checkout.twig'));
    }
}
```

`TemplateRepository::save(string $theme, string $path, string $source, ?string $createdBy): array` is the verified live signature used above.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TemplateCatalogContributionsTest`
Expected: FAIL — constructor rejects the 4th argument / `package` origins missing.

- [ ] **Step 3: Implement**

In `TemplateCatalog.php`:

1. Constructor (lines 15-20) gains the contributions param and the class docblock precedence line becomes `db > theme > package > default — loader precedence`:

```php
public function __construct(
    private readonly TemplateRepository $repo,
    private readonly string $appThemesDir,
    private readonly string $packThemesDir,
    /** @var list<array{contributor_id: string, dir: string}> registry order = precedence order */
    private readonly array $contributions = [],
) {
}
```

2. `list()` (lines 23-48): between the pack-default walk and the theme walk, add the contributed walks in REVERSE registry order (so earlier-registered contributors overwrite later ones, matching first-match-wins in the loader chain):

```php
foreach (array_reverse($this->contributions) as $contribution) {
    foreach ($this->walk(rtrim($contribution['dir'], '/')) as $p) {
        $files[$p] = 'package';
    }
}
```

(Contributed dirs are template roots themselves — `packages/thallo-commerce/templates` — no `/templates` suffix.)

3. `readFile()` (lines 51-64): between the theme branch and the default read, add the ladder step in registry order:

```php
foreach ($this->contributions as $contribution) {
    $file = rtrim($contribution['dir'], '/') . '/' . $path;
    if (is_file($file)) {
        return ['source' => (string) file_get_contents($file), 'origin' => 'package'];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "TemplateCatalogContributionsTest|TemplatesAdminApiTest"`
Expected: ALL PASS (the existing admin API tests prove the default-constructed catalog is unchanged).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/Templates/TemplateCatalog.php tests/Integration/Render/TemplateCatalogContributionsTest.php
git commit --only packages/thallo-render/src/Templates/TemplateCatalog.php tests/Integration/Render/TemplateCatalogContributionsTest.php -m "feat(render): TemplateCatalog learns contributed template dirs (origin: package)"
```

---

### Task 3: Provider wiring + controller vocabulary + admin API round-trip

**Files:**
- Modify: `packages/thallo-render/src/RenderServiceProvider.php:214-222` (`makeTemplateCatalog`)
- Modify: `packages/thallo-render/src/Http/Controllers/TemplatesAdminController.php` (docblocks only)
- Test: `tests/Integration/Render/RenderContributionTest.php`, `tests/Integration/Render/TemplatesAdminApiTest.php`

**Interfaces:**
- Consumes: Task 1's `frozenTemplateContributions()`, Task 2's catalog constructor.
- Produces: the live container wiring; API origin vocabulary documented as `db|theme|package|default`.

- [ ] **Step 1: Write the production-factory test first, then the controller round-trip characterization**

The existing `RenderContributionTest::testContributionRegisteredAfterAllProvidersHaveBootedIsHonoredUntilFirstConsumption()` already runs the real production factories against an isolated, unfrozen registry via `containerWithFreshRegistry()`. Immediately after its `makeThemeLocator()` assertion, add the catalog half:

```php
$catalog = RenderServiceProvider::makeTemplateCatalog($container);
self::assertSame(
    ['source' => 'PACK-CONTRIB', 'origin' => 'package'],
    $catalog->readFile('default', '__contribution_probe.twig'),
);
```

This is the red-first authority for Task 3: before the factory is wired, the catalog returns `null` even though `ThemeLocator` sees the same frozen contribution. It proves the live factory consumes the snapshot; a hand-built catalog cannot prove that.

Then add the controller contract characterization to `TemplatesAdminApiTest.php`. The shared app harness intentionally has zero real contributors, so this part uses a hand-built catalog while every other controller dependency comes from the container:

```php
/** Controller wired exactly like production EXCEPT the catalog carries a contributed dir. */
private function apiWithContribution(string $contribDir): TemplatesAdminController
{
    $c = $this->container();
    return new TemplatesAdminController(
        $c->get(TemplateRepository::class),
        $c->get(\Thallo\Render\Templates\TemplateLinter::class),
        new \Thallo\Render\Templates\TemplateCatalog(
            $c->get(TemplateRepository::class),
            sys_get_temp_dir() . '/thallo-no-app-themes-' . bin2hex(random_bytes(4)),
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes',
            [['contributor_id' => 'test-pack', 'dir' => $contribDir]],
        ),
        $c->get(\Thallo\Contracts\Delivery\PreviewThemeValidator::class),
        $c->get(\Thallo\Render\ThemeLocator::class),
        $c->get(\Glueful\Events\EventService::class),
        $c->get(\Glueful\Bootstrap\ApplicationContext::class),
        $c->get(\Thallo\Render\Templates\ThemeCloner::class),
    );
}

public function testContributedTemplateFullAdminRoundTrip(): void
{
    $dir = sys_get_temp_dir() . '/thallo-contrib-api-' . bin2hex(random_bytes(4));
    mkdir($dir . '/shop', 0755, true);
    file_put_contents($dir . '/shop/checkout.twig', 'PACKAGE-BASELINE');
    $api = $this->apiWithContribution($dir);

    // Listed with origin package.
    $list = $this->json($api->index(Request::create('/x', 'GET')))['data']['templates'];
    $row = array_column($list, null, 'path')['shop/checkout.twig'];
    self::assertSame(['origin' => 'package', 'overridden' => false],
        ['origin' => $row['origin'], 'overridden' => $row['overridden']]);

    // GET seeds the package source, editable (not readonly).
    $shown = $this->json($api->show(Request::create('/x', 'GET'), 'shop/checkout.twig'))['data'];
    self::assertSame('PACKAGE-BASELINE', $shown['source']);
    self::assertSame('package', $shown['origin']);

    // PUT creates a DB override; the package file is untouched.
    $api->save($this->putReq('OVERRIDE {{ entry.fields.title }}'), 'shop/checkout.twig');
    self::assertSame('PACKAGE-BASELINE', file_get_contents($dir . '/shop/checkout.twig'));
    $shown = $this->json($api->show(Request::create('/x', 'GET'), 'shop/checkout.twig'))['data'];
    self::assertSame('db', $shown['origin']);

    // DELETE reveals the package baseline again.
    $api->delete(Request::create('/x', 'DELETE'), 'shop/checkout.twig');
    $shown = $this->json($api->show(Request::create('/x', 'GET'), 'shop/checkout.twig'))['data'];
    self::assertSame(['PACKAGE-BASELINE', 'package'], [$shown['source'], $shown['origin']]);

    // Cleanup.
    @unlink($dir . '/shop/checkout.twig');
    @rmdir($dir . '/shop');
    @rmdir($dir);
}
```

The calls above use the verified controller signatures: `show(Request, string)` and `delete(Request, string)`. Follow the test file's existing import style.

- [ ] **Step 2: Run both tests and verify the production factory fails red**

Run: `vendor/bin/phpunit --filter "testContributionRegisteredAfterAllProvidersHaveBootedIsHonoredUntilFirstConsumption|testContributedTemplateFullAdminRoundTrip"`

Expected: the provider/factory test FAILS because `makeTemplateCatalog()` drops the frozen contributions. The hand-built controller round-trip PASSES after Task 2, confirming that the controller needs no behavior change. Do not proceed unless the factory assertion is red for the expected reason.

- [ ] **Step 3: Wire production + docblocks**

1. `RenderServiceProvider::makeTemplateCatalog()` (lines 214-222):

```php
public static function makeTemplateCatalog(ContainerInterface $container): TemplateCatalog
{
    $context = $container->get(ApplicationContext::class);
    return new TemplateCatalog(
        $container->get(TemplateRepository::class),
        $context->getBasePath() . '/themes',
        dirname(__DIR__) . '/themes',
        // The SAME frozen snapshot ThemeLocator consumes (spec §1) — catalog and
        // runtime can never disagree about what is contributed.
        $container->get(RenderContributionRegistry::class)->frozenTemplateContributions(),
    );
}
```

(`RenderContributionRegistry` is already imported/registered by this provider.)

2. In `TemplatesAdminController.php`, update every docblock that enumerates origins `db|theme|default` (the `index()` docblock near line 89 and the `show()` docblock near line 131) to `db|theme|package|default`.

- [ ] **Step 4: Run the render admin suite**

Run: `vendor/bin/phpunit --filter "TemplatesAdminApiTest|RenderContributionTest|TemplateCatalogContributionsTest"`
Expected: ALL PASS. The freeze-ordering guard in `RenderContributionTest` must stay green — `makeTemplateCatalog` reads the frozen snapshot, which is legal at any post-boot point.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/RenderServiceProvider.php packages/thallo-render/src/Http/Controllers/TemplatesAdminController.php tests/Integration/Render/RenderContributionTest.php tests/Integration/Render/TemplatesAdminApiTest.php
git commit --only packages/thallo-render/src/RenderServiceProvider.php packages/thallo-render/src/Http/Controllers/TemplatesAdminController.php tests/Integration/Render/RenderContributionTest.php tests/Integration/Render/TemplatesAdminApiTest.php -m "feat(render): admin template catalog consumes the frozen contribution snapshot"
```

---

### Task 4: `json_script()` — the safe JSON-in-script emitter

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (function registration near line 216 + new method)
- Test: `tests/Integration/Render/JsonScriptFunctionTest.php` (create)

**Interfaces:**
- Produces: Twig function `json_script(value)` returning `Twig\Markup`; PHP method `RenderContextExtension::jsonScript(mixed $value): Markup`. Task 5 allowlists it; Task 6 uses it in `shop/product.twig`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

final class JsonScriptFunctionTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testEncodesJsonLdWithHexProtections(): void
    {
        $out = (string) $this->ext()->jsonScript(['@type' => 'Product', 'name' => 'A "B" & C']);
        self::assertStringContainsString('\\u0022B\\u0022', $out); // JSON_HEX_QUOT
        self::assertStringNotContainsString('"B"', $out);          // no bare quotes in values
        self::assertStringNotContainsString('&', $out);            // JSON_HEX_AMP → \\u0026
        // Round-trips to the same data.
        self::assertSame('A "B" & C', json_decode($out, true)['name']);
    }

    public function testScriptBreakoutIsUnrepresentable(): void
    {
        $out = (string) $this->ext()->jsonScript(['x' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $out);    // JSON_HEX_TAG
        self::assertStringNotContainsString('<script', $out);
    }

    public function testFailClosedOnUnencodableInput(): void
    {
        $this->expectException(\JsonException::class);
        $this->ext()->jsonScript(['bad' => "\xB1\x31"]); // invalid UTF-8
    }

    public function testReturnsMarkupSoAutoescapeDoesNotDoubleEncode(): void
    {
        self::assertInstanceOf(\Twig\Markup::class, $this->ext()->jsonScript([]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter JsonScriptFunctionTest`
Expected: FAIL — "Call to undefined method … jsonScript()".

- [ ] **Step 3: Implement**

In `RenderContextExtension.php`:

1. Register the function directly after the `shop_index_url` line (line 218), keeping the shop-function grouping intact:

```php
new TwigFunction('json_script', $this->jsonScript(...)),
```

2. Add the method (near `media()`/`mediaImage()` or another sensible grouping; ensure `use Twig\Markup;` is imported — the `icon()` comment at line 192 implies it already is):

```php
/**
 * Safe JSON-for-<script> emitter (admin-contributed-templates spec §3): the ONE
 * sanctioned way to put structured data inside a script element. JSON_HEX_TAG makes
 * a literal "</script>" unrepresentable in the output — breakout is impossible —
 * and hex-encoded quotes/ampersands keep the payload inert. Fail-closed: an
 * unencodable value throws (JsonException) into the render error ladder; this never
 * emits partial or unsafe output. Returns Markup — safety travels in the value, so
 * templates write {{ json_script(data) }} with no |raw.
 */
public function jsonScript(mixed $value): Markup
{
    $json = json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES,
    );
    return new Markup($json, 'UTF-8');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter JsonScriptFunctionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/RenderContextExtension.php tests/Integration/Render/JsonScriptFunctionTest.php
git commit --only packages/thallo-render/src/RenderContextExtension.php tests/Integration/Render/JsonScriptFunctionTest.php -m "feat(render): json_script() — fail-closed JSON-LD emitter with HEX protections"
```

---

### Task 5: Policy expansion (twelve functions), deny both range forms, `media_image` width cap, CACHE_VERSION 17

**Files:**
- Modify: `packages/thallo-render/src/Templates/TemplatePolicy.php:35-150`
- Modify: `packages/thallo-render/src/RenderContextExtension.php:592-599` (`mediaImage`)
- Test: `tests/Integration/Render/TemplateLinterTest.php` (extend), `tests/Integration/Render/AllowlistedFunctionBoundsTest.php` (create), `tests/Integration/Render/DbTemplateLoaderTest.php` (extend)

**Interfaces:**
- Consumes: Task 4's `json_script`.
- Produces: the final `TemplatePolicy::FUNCTIONS` list (`range` removed), `NODE_CLASSES` list (`RangeBinary` removed), and `CACHE_VERSION = 17`; `RenderContextExtension::normalizeWidths(array $widths): list<int>` (public static, so the cap is directly testable). Tasks 6 and 8 depend on the expanded policy.

- [ ] **Step 1: Write the failing tests**

Extend `TemplateLinterTest.php`:

```php
public function testNewlyAllowlistedFunctionsLintClean(): void
{
    $source = <<<'TWIG'
    {{ json_script({'a': 1}) }}
    <a href="{{ shop_product_url('slug') }}">{{ shop_category_url('c') }}{{ shop_index_url() }}</a>
    {% set posts = entries('posts', {limit: 3}) %}
    {% if is_preview() %}preview{% endif %}
    {% set img = media_image('u-1', [320, 640]) %}
    {{ claim_priority_image() ? 'first' : 'later' }}
    {% if color_mode_enabled() %}{{ color_mode_script() }}{% endif %}
    {{ theme_colors_style() }}
    {% set scope = theme_style_scope('blue', 'slate') %}{{ scope.class }}{{ scope.style }}
    TWIG;
    self::assertSame([], $this->linter()->lint($source));
}

public function testRawConstantAndRangeStayDenied(): void
{
    self::assertNotSame([], $this->linter()->lint("{{ {'a':1}|json_encode|raw }}"));
    self::assertNotSame([], $this->linter()->lint("{{ constant('JSON_HEX_TAG') }}"));
    self::assertNotSame([], $this->linter()->lint("{{ range(1, 1000000000)|length }}"));
    self::assertNotSame([], $this->linter()->lint("{{ (1..1000000000)|length }}"));
}
```

The linter proves vocabulary only; it cannot catch wrong PHP arity, missing Twig context, or a runtime return-shape error. Extend `DbTemplateLoaderTest.php` with the required execution proof for the six state/output functions (the existing private `repo()` and `env()` helpers are the exact APIs):

```php
public function testNewlyAllowlistedStateFunctionsExecuteThroughDbOverride(): void
{
    $source = <<<'TWIG'
    {% set preview = is_preview() %}
    {% set claimed = claim_priority_image() %}
    {% set colorMode = color_mode_enabled() %}
    {% set colorScript = color_mode_script() %}
    {% set globalColors = theme_colors_style() %}
    {% set scope = theme_style_scope('blue', 'slate') %}
    {{ colorScript }}{{ globalColors }}{{ scope.class }}{{ scope.style }}DB-RUNTIME-OK
    TWIG;

    $this->repo()->save('default', 'policy-v17-functions.twig', $source, null);
    self::assertStringContainsString(
        'DB-RUNTIME-OK',
        $this->env()->render('policy-v17-functions.twig'),
    );
}
```

This test must execute through `DatabaseTemplateLoader`; directly rendering an `ArrayLoader` template would not prove compile-time DB-policy integration.

`TemplateLinter::lint()` returns the verified `list<array{line:int,message:string}>`; `claim_priority_image()` takes no template argument and `theme_style_scope()` takes the two nullable color-family arguments shown above.

Create `tests/Integration/Render/AllowlistedFunctionBoundsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use App\Content\Delivery\EngineEntryListReader;
use Thallo\Render\RenderContextExtension;

/** Focused safety/bounds pins for the newly DB-template-callable functions (spec §3). */
final class AllowlistedFunctionBoundsTest extends AppTestCase
{
    public function testMediaImageWidthNormalizationDedupesFiltersAndCapsAtEight(): void
    {
        // Direct PHP callers can still pass a long array; resolver work remains capped.
        self::assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            RenderContextExtension::normalizeWidths(range(1, 10000)),
        );
        self::assertSame(
            [320, 640],
            RenderContextExtension::normalizeWidths([320, 640, 320, -1, 0, 'x', 3.5]),
        );
        self::assertSame([], RenderContextExtension::normalizeWidths([]));
    }

    public function testEntriesLimitIsServerClampedToTwelve(): void
    {
        // The clamp lives at the reader seam every template call crosses
        // (EngineEntryListReader::list()) — source-pin it so a refactor that
        // drops the clamp fails here, not in production.
        $src = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/app/Content/Delivery/EngineEntryListReader.php',
        );
        self::assertStringContainsString('max(1, min(12,', $src);
    }
}
```

(If a published-content fixture harness exists in `tests/Integration/Commerce` or `tests/Integration/Render` that makes a behavioral `entries()` clamp test cheap, prefer it over the source pin — but do not build new fixture machinery for this one assertion.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "TemplateLinterTest|AllowlistedFunctionBoundsTest|DbTemplateLoaderTest"`
Expected: `testNewlyAllowlistedFunctionsLintClean` FAILS (functions denied); `normalizeWidths` FAILS (undefined method); the DB-runtime test FAILS at compile-time policy enforcement; `raw` and `constant` are already denied while both new range assertions FAIL until Task 5 removes the function and node.

- [ ] **Step 3: Implement**

1. `TemplatePolicy.php` — replace lines 35-37 and the `FUNCTIONS` const:

```php
    // bumped: shop_wishlist_scope + shop_wishlist_url joined the allowlist (storefront-v1 spec §5)
    // bumped: shop_styles_url joined the function allowlist (head stylesheet link)
    // bumped: admin-contributed-templates spec §3 — shop URL trio + json_script + the eight
    //         render functions the shipped default theme uses (individually reviewed;
    //         media_image gated behind normalizeWidths()), while range()/RangeBinary are denied
    //         to prevent pre-call unbounded allocation. Every shipped template round-trips through the
    //         editor (exception-free lint gate).
    public const CACHE_VERSION = 17;
```

```php
    public const FUNCTIONS = [
        'menu', 'path', 'asset', 'facets', 'blocks', 'media', 'site_logo', 'video_embed', 'icon',
        'region_blocks', 'region_settings', 'site_favicon', 'custom_css', 'form_render',
        'runtime_script', 'seo_head', 'font_faces_style',
        'shop_wishlist_scope', 'shop_wishlist_url', 'shop_styles_url',
        'shop_product_url', 'shop_category_url', 'shop_index_url', 'json_script',
        'entries', 'is_preview', 'media_image', 'claim_priority_image',
        'color_mode_enabled', 'color_mode_script', 'theme_colors_style', 'theme_style_scope',
        'include', 'parent', 'block', 'cycle', 'date', 'min', 'max',
    ];
```

2. Remove `\Twig\Node\Expression\Binary\RangeBinary::class` from `TemplatePolicy::NODE_CLASSES`. Removing only the `range` function is insufficient: Twig's `1..N` operator compiles through this separately allowlisted node and has the same pre-call allocation risk.

3. Update the PRE-EXISTING representative-valid-template test (`TemplateLinterTest.php:38`) — it currently contains `{{ range(1, 3)|join(',') }}`, which is now correctly denied. Replace that expression with a still-allowlisted equivalent, e.g. `{{ [1, 2, 3]|join(',') }}`, keeping the rest of the representative template untouched.

4. `RenderContextExtension.php` — harden `mediaImage()` (lines 592-599) and add the static normalizer:

```php
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
        $widths = self::normalizeWidths($widths);
        if ($this->mediaVariants === null) {
            $src = $this->media($uuid);
            return $src === null ? null : ['src' => $src, 'srcset' => null];
        }
        return $this->mediaVariants->variants($uuid, $widths);
    }

    /**
     * Defensive width normalization (admin-contributed-templates spec §3): media_image is
     * DB-template-callable, so the width list is attacker-shaped — positive ints only,
     * deduplicated, at most 8 candidates BEFORE any resolver work. TemplatePolicy separately
     * denies both range() and RangeBinary, which could allocate an unbounded array before this
     * method is entered.
     *
     * @param array<mixed> $widths
     * @return list<int>
     */
    public static function normalizeWidths(array $widths): array
    {
        $clean = [];
        foreach ($widths as $w) {
            if (is_int($w) && $w > 0 && !in_array($w, $clean, true)) {
                $clean[] = $w;
                if (count($clean) === 8) {
                    break;
                }
            }
        }
        return $clean;
    }
```

- [ ] **Step 4: Run the policy/linter suites**

Run: `vendor/bin/phpunit --filter "TemplateLinterTest|AllowlistedFunctionBoundsTest|DbTemplateLoaderTest|DbTemplatesPipelineTest"`
Expected: ALL PASS — the Db* suites prove the CACHE_VERSION bump recompiles cleanly.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/src/Templates/TemplatePolicy.php packages/thallo-render/src/RenderContextExtension.php tests/Integration/Render/TemplateLinterTest.php tests/Integration/Render/AllowlistedFunctionBoundsTest.php tests/Integration/Render/DbTemplateLoaderTest.php
git commit --only packages/thallo-render/src/Templates/TemplatePolicy.php packages/thallo-render/src/RenderContextExtension.php tests/Integration/Render/TemplateLinterTest.php tests/Integration/Render/AllowlistedFunctionBoundsTest.php tests/Integration/Render/DbTemplateLoaderTest.php -m "feat(render): policy v17 — reviewed functions, range denial, media_image width cap"
```

---

### Task 6: Trusted-output boundaries — product.twig rewrite + enrichment Markup

**Files:**
- Modify: `packages/thallo-commerce/templates/shop/product.twig:30,219`
- Modify: `packages/thallo-render/src/EntryBlocksRenderer.php:44-55,85`
- Modify: `packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php:236-258` (docblock + no behavior change)
- Test: `tests/Integration/Render/PriorityClaimRenderBoundaryTest.php` (update), commerce product-page suite

**Interfaces:**
- Consumes: Task 4's `json_script`.
- Produces: `EntryBlocksRenderer::renderPublishedBlocks(ApplicationContext, string, string): ?\Twig\Markup` (was `?string`). `resolveEnrichment()`'s array shape becomes `array{entry_uuid: string, html: ?\Twig\Markup}|null`.

- [ ] **Step 1: Make the template changes**

`shop/product.twig` line 30:

```twig
  <script type="application/ld+json">{{ json_script(structuredData) }}</script>
```

`shop/product.twig` line 219:

```twig
      <div class="shop-product__enrichment">{{ enrichment_html }}</div>
```

- [ ] **Step 2: Change the renderer boundary**

`EntryBlocksRenderer.php` — return type and the wrap (the docblock sentence "this class returns a markup string" becomes "this class returns a Twig\Markup value"):

```php
    public function renderPublishedBlocks(
        ApplicationContext $context,
        string $tenantUuid,
        string $entryUuid,
    ): ?\Twig\Markup {
```

and the return at line 85:

```php
        $html = $this->extension->blocks($env, $blockContext, $result['fields']['body'] ?? null);

        // The ONE trusted wrapping point (admin-contributed-templates spec §3): blocks()
        // output is composed of escaped/sanitized fragments, so it is safe to mark — and
        // marking it HERE means consumer templates write {{ enrichment_html }} with no
        // |raw, which the template policy denies.
        return new \Twig\Markup($html, 'UTF-8');
    }
```

Update `renderPublishedBlocks()`'s own docblock: "…renders as an empty string" → "…renders as an empty Markup value ((string) cast === ''), not null".

`ShopCatalogController.php` — `resolveEnrichment()`'s docblock `@return array{entry_uuid: string, html: ?string}|null` becomes `@return array{entry_uuid: string, html: ?\Twig\Markup}|null` (the body needs no change — it passes the value through).

- [ ] **Step 3: Fix the callers/tests the type change breaks**

Run: `rg -n "renderPublishedBlocks" packages tests app && vendor/bin/phpunit --filter "PriorityClaimRenderBoundaryTest"`

`PriorityClaimRenderBoundaryTest` asserts on `renderPublishedBlocks()` output — update string assertions to cast: `self::assertSame('…', (string) $renderer->renderPublishedBlocks(...))` and null assertions stay. Inspect every `rg` hit and update any caller/test that declares or treats the return as `string`. This repository has no PHPStan binary/script, so do not hide a nonexistent static-analysis command behind `2>/dev/null`.

- [ ] **Step 4: Run the affected suites**

Run: `vendor/bin/phpunit --filter "PriorityClaimRenderBoundaryTest|ShopBlocksTest" && vendor/bin/phpunit tests/Integration/Commerce`
Expected: ALL PASS — the product page renders JSON-LD via `json_script` and enrichment without `|raw`. If a commerce test snapshots the JSON-LD output, update it for the HEX-encoded characters (`&` etc. — same data, new escaping).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-commerce/templates/shop/product.twig packages/thallo-render/src/EntryBlocksRenderer.php packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php tests/Integration/Render/PriorityClaimRenderBoundaryTest.php
git commit --only packages/thallo-commerce/templates/shop/product.twig packages/thallo-render/src/EntryBlocksRenderer.php packages/thallo-commerce/src/Http/Shop/ShopCatalogController.php tests/Integration/Render/PriorityClaimRenderBoundaryTest.php -m "feat(commerce): product.twig round-trips the save policy — json_script + Markup enrichment"
```

If Step 3 legitimately changes another caller/test, add that exact path to both the `git add` and `git commit --only` commands before committing; never leave a required fix unstaged or broaden staging to the whole tree.

---

### Task 7: Round-trip lint gate — every shipped template, exception-free

**Files:**
- Test: `tests/Integration/Render/ShippedTemplatesLintGateTest.php` (create)

**Interfaces:**
- Consumes: Task 5's policy, Task 6's template rewrites.
- Produces: the permanent CI release gate.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateLinter;

/**
 * Release gate (admin-contributed-templates spec §Testing): EVERY shipped template —
 * render default theme + every contributed pack dir — round-trips through the SAME
 * policy the admin save enforces. Exception-free by pinned decision: a template that
 * needs denied vocabulary is a bug in the template (or a reviewed policy addition),
 * never a lint-gate exception.
 */
final class ShippedTemplatesLintGateTest extends AppTestCase
{
    /** @return iterable<string, array{string}> */
    public static function shippedTemplates(): iterable
    {
        $repoRoot = \dirname(__DIR__, 3);
        $roots = [
            $repoRoot . '/packages/thallo-render/themes/default/templates',
            $repoRoot . '/packages/thallo-account/templates',
            $repoRoot . '/packages/thallo-commerce/templates',
        ];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    // Repo-relative key — unique across roots (basename($root) would
                    // collide: all three roots end in "templates").
                    $key = substr($file->getPathname(), strlen($repoRoot) + 1);
                    yield $key => [$file->getPathname()];
                }
            }
        }
    }

    /** @dataProvider shippedTemplates */
    public function testShippedTemplateLintsClean(string $path): void
    {
        /** @var TemplateLinter $linter */
        $linter = $this->container()->get(TemplateLinter::class);
        $violations = $linter->lint((string) file_get_contents($path));
        self::assertSame([], $violations, "Shipped template fails the save policy: {$path}");
    }
}
```

(Match the `lint()` return shape and dataProvider attribute style — PHPUnit 10 may want `#[DataProvider('shippedTemplates')]` — to what `TemplateLinterTest` already uses.)

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter ShippedTemplatesLintGateTest`
Expected: ALL PASS. Any failure names a real gap — fix the template or (only with a reviewed policy decision) the policy; NEVER add an exception. If an account/commerce template fails on vocabulary the spec didn't inventory, stop and surface it to the user before improvising.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Render/ShippedTemplatesLintGateTest.php
git commit --only tests/Integration/Render/ShippedTemplatesLintGateTest.php -m "test(render): exception-free lint gate over every shipped template"
```

---

### Task 8: Catalog/runtime parity test

**Files:**
- Test: `tests/Integration/Render/CatalogRuntimeParityTest.php` (create)

**Interfaces:**
- Consumes: Task 2's catalog, Task 3's wiring.
- Produces: the invariant that the editor edits what actually renders.

- [ ] **Step 1: Write the test**

Boundary per spec (P2): editable `.twig` rows only — skip `custom.css` and `readonly` rows. DB rows compare against the composite DB-first loader; filesystem rows against the selected theme's filesystem chain.

Build the test in two explicit branches. The filesystem branch uses the real account and commerce template roots plus two synthetic contributors that collide on one name; both `TemplateCatalog` and `ThemeLocator` receive projections of the **same** frozen registry snapshot. The DB branch uses the real composite loader and the repository's actual `findCurrentSource()` API.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\Contribution\TemplatePathContributor;
use Thallo\Render\Templates\RenderTemplateLoader;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Loader\FilesystemLoader;

/** The editor must seed the exact bytes the runtime precedence chain resolves. */
final class CatalogRuntimeParityTest extends AppTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/thallo-catalog-parity-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/a', 0755, true);
        mkdir($this->tmp . '/b', 0755, true);
        mkdir($this->tmp . '/themes/parity/templates', 0755, true);
        file_put_contents($this->tmp . '/a/__package_collision.twig', 'PACKAGE-A');
        file_put_contents($this->tmp . '/b/__package_collision.twig', 'PACKAGE-B');
        file_put_contents($this->tmp . '/themes/parity/theme.json', '{"name":"parity"}');
        file_put_contents($this->tmp . '/themes/parity/templates/__theme_only.twig', 'THEME');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** @param list<string> $dirs */
    private function contributor(string $id, array $dirs, int $priority): TemplatePathContributor
    {
        return new class ($id, $dirs, $priority) implements TemplatePathContributor {
            /** @param list<string> $dirs */
            public function __construct(
                private readonly string $id,
                private readonly array $dirs,
                private readonly int $priority,
            ) {
            }

            public function contributorId(): string
            {
                return $this->id;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function templatePaths(): array
            {
                return $this->dirs;
            }
        };
    }

    public function testEveryFilesystemRowIncludingPackagesMatchesTheRuntimeChain(): void
    {
        $root = dirname(__DIR__, 3);
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->contributor('probe-a', [$this->tmp . '/a'], 0));
        $registry->registerTemplatePaths($this->contributor('probe-b', [$this->tmp . '/b'], 1));
        $registry->registerTemplatePaths($this->contributor(
            'thallo-account',
            [$root . '/packages/thallo-account/templates'],
            10,
        ));
        $registry->registerTemplatePaths($this->contributor(
            'thallo-commerce',
            [$root . '/packages/thallo-commerce/templates'],
            20,
        ));

        $contributions = $registry->frozenTemplateContributions();
        $catalog = new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/themes',
            $root . '/packages/thallo-render/themes',
            $contributions,
        );
        $locator = new ThemeLocator(
            'parity',
            $this->tmp . '/themes',
            $root . '/packages/thallo-render/themes',
            $registry->frozenTemplatePaths(),
        );
        $runtime = new FilesystemLoader($locator->activePaths()['templates']);

        $rows = $catalog->list('parity');
        self::assertSame('package', array_column($rows, null, 'path')['__package_collision.twig']['origin']);
        self::assertSame('PACKAGE-A', $runtime->getSourceContext('__package_collision.twig')->getCode());

        foreach ($rows as $row) {
            if (!str_ends_with($row['path'], '.twig') || $row['origin'] === 'db') {
                continue;
            }
            $admin = $catalog->readFile('parity', $row['path']);
            self::assertNotNull($admin, "Catalog row has no readable baseline: {$row['path']}");
            self::assertSame(
                $runtime->getSourceContext($row['path'])->getCode(),
                $admin['source'],
                "Catalog/runtime divergence for {$row['path']}",
            );
        }
    }

    public function testDbRowMatchesTheCompositeRuntimeLoader(): void
    {
        /** @var TemplateRepository $repo */
        $repo = $this->container()->get(TemplateRepository::class);
        $repo->save('default', 'entry.twig', 'PARITY-DB {{ entry.fields.title }}', 'user00000001');

        $loader = $this->container()->get(TwigFactory::class)->environment()->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        $row = $repo->findCurrentSource('default', 'entry.twig');
        self::assertNotNull($row);
        self::assertSame($loader->getSourceContext('entry.twig')->getCode(), $row['source']);
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --filter CatalogRuntimeParityTest`
Expected: PASS. The first test must enumerate real account/commerce package rows and prove first-contributor-wins on the synthetic collision. The second must read the DB source through `RenderTemplateLoader`, not directly from an array loader.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Render/CatalogRuntimeParityTest.php
git commit --only tests/Integration/Render/CatalogRuntimeParityTest.php -m "test(render): catalog/runtime parity — the editor edits what renders"
```

---

### Task 9: Admin UI — package origin note + completions sync

**Files:**
- Modify: `admin/src/pages/templates/index.vue:375-378`
- Modify: `admin/src/pages/templates/components/twigCompletions.ts`
- Test: `admin/src/__tests__/templatesPage.spec.ts`

**Interfaces:**
- Consumes: Task 3's API (`origin: "package"` rows).
- Produces: nothing downstream.

- [ ] **Step 1: Write the failing test**

Extend `admin/src/__tests__/templatesPage.spec.ts`, following its existing mount/mock pattern (it already stubs `fetchTemplates`; add a row `{ path: 'shop/checkout.twig', origin: 'package', overridden: false, updated_at: null }` to the stubbed catalog):

```ts
it('groups package templates into their folder and badges the origin', async () => {
  // …existing mount helper with the extended fixture…
  // The shop/ folder exists and contains the row; badge text is the origin.
  expect(wrapper.text()).toContain('shop')
  await openFolder(wrapper, 'shop') // reuse the spec's existing folder-open helper/pattern
  expect(wrapper.text()).toContain('checkout.twig')
  expect(wrapper.find('[data-test="package-origin-note"]').exists()).toBe(false) // not selected yet
})

it('shows the immutable-baseline note for a selected package template', async () => {
  // …select shop/checkout.twig via the spec's existing selection pattern…
  const note = wrapper.find('[data-test="package-origin-note"]')
  expect(note.exists()).toBe(true)
  expect(note.text()).toContain('Package template')
  expect(note.text()).toContain('never modified')
})
```

(These are behavior sketches — bind them to the spec file's real helpers; the assertions are the contract.)

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && npm test -- --run templatesPage`
Expected: new cases FAIL (`package-origin-note` absent).

- [ ] **Step 3: Implement**

1. `index.vue` — insert a package branch before the generic fs note (line 375):

```html
          <p v-else-if="origin === 'package'" class="text-xs text-muted" data-test="package-origin-note">
            Package template — saving creates a database override; the package file is never
            modified.
          </p>
          <p v-else-if="origin !== 'db'" class="text-xs text-muted" data-test="fs-origin-note">
            Filesystem template ({{ origin }}) — saving creates a database override that shadows
            it.
          </p>
```

(The sidebar badge needs NO change — it already renders `t.origin` with neutral color for non-`db`.)

2. `twigCompletions.ts` — sync `FUNCTIONS` to the full policy v17 list (the file is a declared MIRROR of `TemplatePolicy` and is currently missing even pre-existing entries):

```ts
const FUNCTIONS = [
  'menu', 'path', 'asset', 'facets', 'blocks', 'media', 'site_logo', 'video_embed', 'icon',
  'region_blocks', 'region_settings', 'site_favicon', 'custom_css', 'form_render',
  'runtime_script', 'seo_head', 'font_faces_style',
  'shop_wishlist_scope', 'shop_wishlist_url', 'shop_styles_url',
  'shop_product_url', 'shop_category_url', 'shop_index_url', 'json_script',
  'entries', 'is_preview', 'media_image', 'claim_priority_image',
  'color_mode_enabled', 'color_mode_script', 'theme_colors_style', 'theme_style_scope',
  'include', 'parent', 'block', 'cycle', 'date', 'min', 'max',
]
```

(Keep the file's one-entry-per-line formatting if that's its style; the list contents are the contract.)

- [ ] **Step 4: Run the admin suite**

Run: `cd admin && npm test -- --run templatesPage && npm run type-check && npm run lint`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/templates/index.vue admin/src/pages/templates/components/twigCompletions.ts admin/src/__tests__/templatesPage.spec.ts
git commit --only admin/src/pages/templates/index.vue admin/src/pages/templates/components/twigCompletions.ts admin/src/__tests__/templatesPage.spec.ts -m "feat(admin): package-origin templates — folder listing, baseline note, completions sync"
```

---

### Task 10: Full-suite verification + docs touch-up

**Files:**
- Modify: `packages/thallo-render/README.md` (DB-edited templates section)

- [ ] **Step 1: Run everything the feature touches**

```bash
composer ci
composer boundaries
cd admin && npm test -- --run && npm run type-check && npm run lint && cd ..
```

Expected: green. `composer ci` is the repository's authoritative full PHP gate (`phpcs` + DB reset/migrate + all PHPUnit suites), not a pair of selected integration directories. Pay attention to `DbTemplatesPipelineTest`, `PreviewSessionTest`, `StarterTemplatesTest` (policy bump ripple) and any commerce JSON-LD snapshot. `composer boundaries` separately proves no package-boundary regression; the admin commands fail loudly with no stderr suppression or `|| true`.

- [ ] **Step 2: README**

In `packages/thallo-render/README.md`'s "DB-edited templates" section, add two sentences: contributed pack templates (thallo-account, thallo-commerce) now appear in the admin Templates screen with origin `package` and override exactly like theme templates (precedence `db → theme → package → default`); disabling a pack removes its baselines from the catalog while existing DB overrides stay listed and live (deleting one then leaves no fallback).

- [ ] **Step 3: Commit**

```bash
git add packages/thallo-render/README.md
git commit --only packages/thallo-render/README.md -m "docs(render): document package-origin templates in the admin editor"
```

---

## Verification (end-to-end)

1. `composer ci` — PHPCS and the complete PHP test suite are green, including the new `ShippedTemplatesLintGateTest`, `CatalogRuntimeParityTest`, `TemplateCatalogContributionsTest`, `AllowlistedFunctionBoundsTest`, `JsonScriptFunctionTest` and the DB-runtime allowlist test.
2. `composer boundaries` — package-boundary scan is green.
3. `cd admin && npm test -- --run && npm run type-check && npm run lint` — all admin tests, Vue/TypeScript checks and lint are green.
4. Manual smoke (optional but recommended): boot the app with commerce enabled, open Admin → Theme editor — `shop/` and `account/` folders appear; open `shop/checkout.twig` (source visible, note says package baseline); save a trivial edit → badge flips to `db`; visit the storefront checkout page → the edit renders; delete the override → baseline returns.
