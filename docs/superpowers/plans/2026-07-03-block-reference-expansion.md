# Block Reference Auto-Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand entry references inside block data in place (same contract as top-level references), make expansion targets purge and revalidate correctly (Cache-Tag + ETag), and fix the dormant top-level asset "expansion" bug.

**Architecture:** `ReferenceResolver` learns to descend `blocks` fields via the global block-type registry, collecting/splicing refs into the SAME per-level batch query. A new `ExpandedTargets` collector rides the resolver → `DeliveryItemShaper` → controllers, feeding `lemma:entry:{target}` cache tags everywhere and `entryUuid:versionUuid` identities into the delivery ETags. The render path carries collector-derived tags on the resolver result (`cache_tags`) into `mergeCacheTags`.

**Tech Stack:** PHP 8.4, PHPUnit 10 (PostgreSQL test DB), Glueful framework. Spec: `docs/superpowers/specs/2026-07-03-block-reference-expansion-design.md`.

## Global Constraints

- **Commit gate:** STAGE at the end (Task 6); commit ONLY on explicit authorization. No Claude/Anthropic attribution anywhere.
- phpcs via `vendor/bin/phpcs -q <files>; echo "PHPCS_EXIT=$?"` (never through pipes). `composer boundaries` after pack changes (checker greps the literal `App\` string — even comments trip it; the render pack must not mention it).
- **Asset boundary (spec §1):** `asset` fields NEVER expand and are never passed to `publishedByEntryUuids()` — any level, any depth.
- **Depth model (spec §1):** expansion depth (default 2) counts reference hops only; block structure is bounded separately by `BlockDepth::MAX` (3).
- **Privacy (spec §4):** expanded-target metadata never appears in public JSON or template context — it travels only through the `ExpandedTargets` object and the resolver-result `cache_tags` key.
- **Unresolved targets (spec §4):** contribute NO tag and NO ETag identity (surrogate-header leakage).
- **Tag strings:** byte-identical to the purge listeners: `'lemma:entry:' . $uuid`.
- Expanded item shape (verified): the hydrated row from `publishedByEntryUuids()` — keys `entry_uuid`, `version_uuid`, `fields` (decoded array), `version` (int). Templates link via `path(data.post.entry_uuid)`, read fields via `data.post.fields.title`.
- `BlockTypeRepository::schemasBySlug()` (verified) returns `array<string, ContentTypeSchema>` including DEACTIVATED types — stored content referencing a deactivated type still expands.
- Test DB: `composer run test:migrate` already applied; no new migrations in this work.

## File Structure

- Create: `app/Content/Delivery/ExpandedTargets.php` — the collector value object.
- Modify: `app/Content/Delivery/ReferenceResolver.php` — asset fix, collector, blocks descent.
- Modify: `app/Content/Http/DeliveryEtag.php` — expanded identities in validators/tags.
- Modify: `app/Content/Delivery/DeliveryItemShaper.php` — collector passthrough.
- Modify: `app/Content/Http/Controllers/DeliveryController.php`, `app/Content/Http/Controllers/TaxonomyController.php` — collector + headers.
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php` — `cache_tags` on results.
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` — merge result tags.
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (blocks() docblock), `packages/lemma-render/README.md`, `CHANGELOG.md`.
- Tests: `tests/Unit/Content/ExpandedTargetsTest.php` (new), `tests/Integration/Content/ReferenceResolverTest.php` (extend), `tests/Integration/Content/BlockReferenceExpansionTest.php` (new), `tests/Integration/Content/Delivery/ExpansionCacheValidatorsTest.php` (new), `tests/Integration/Render/PublicRouteResolverTest.php` + `tests/Integration/Render/RenderPipelineTest.php` (extend).

---

### Task 1: `ExpandedTargets` collector

**Files:**
- Create: `app/Content/Delivery/ExpandedTargets.php`
- Test: `tests/Unit/Content/ExpandedTargetsTest.php`

**Interfaces:**
- Produces: `ExpandedTargets::add(string $entryUuid, string $versionUuid): void` (dedupe by entry uuid, first wins, empty entry uuid ignored); `entryUuids(): list<string>` (insertion order, deduped); `versionIdentities(): list<string>` (SORTED `"{entryUuid}:{versionUuid}"` strings — the stable ETag input).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Delivery\ExpandedTargets;
use PHPUnit\Framework\TestCase;

final class ExpandedTargetsTest extends TestCase
{
    public function testCollectsDedupedEntriesAndSortedVersionIdentities(): void
    {
        $t = new ExpandedTargets();
        $t->add('entryB000001', 'verB00000001');
        $t->add('entryA000001', 'verA00000001');
        $t->add('entryB000001', 'verB00000002'); // dupe entry: first version wins
        $t->add('', 'ignored00001');             // empty entry uuid ignored

        self::assertSame(['entryB000001', 'entryA000001'], $t->entryUuids());
        // Sorted identities — stable regardless of splice order.
        self::assertSame(
            ['entryA000001:verA00000001', 'entryB000001:verB00000001'],
            $t->versionIdentities(),
        );
    }

    public function testEmptyCollector(): void
    {
        $t = new ExpandedTargets();
        self::assertSame([], $t->entryUuids());
        self::assertSame([], $t->versionIdentities());
    }
}
```

- [ ] **Step 2: Verify fail** — `vendor/bin/phpunit tests/Unit/Content/ExpandedTargetsTest.php` → class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

/**
 * Collects the reference targets ACTUALLY spliced in during expansion (spec §4):
 * entry uuids feed Cache-Tag (purge reaches pages embedding the target); sorted
 * entry:version identities feed the delivery ETag (a republished target must
 * change the embedding response's validator — tags alone can't fix a false 304).
 * Unresolved targets are never recorded: tagging them would leak hidden entry
 * uuids through surrogate headers. INTERNAL metadata — never serialized into a
 * public body or template context.
 */
final class ExpandedTargets
{
    /** @var array<string,string> entry uuid => version uuid (first splice wins) */
    private array $byEntry = [];

    public function add(string $entryUuid, string $versionUuid): void
    {
        if ($entryUuid === '' || isset($this->byEntry[$entryUuid])) {
            return;
        }
        $this->byEntry[$entryUuid] = $versionUuid;
    }

    /** @return list<string> deduped, insertion order */
    public function entryUuids(): array
    {
        return array_keys($this->byEntry);
    }

    /** @return list<string> SORTED "{entryUuid}:{versionUuid}" — stable ETag input */
    public function versionIdentities(): array
    {
        $out = [];
        foreach ($this->byEntry as $entry => $version) {
            $out[] = $entry . ':' . $version;
        }
        sort($out);
        return $out;
    }
}
```

- [ ] **Step 4: Verify pass + gates** — `vendor/bin/phpunit tests/Unit/Content/ExpandedTargetsTest.php`; `vendor/bin/phpcs -q app/Content/Delivery/ExpandedTargets.php tests/Unit/Content/ExpandedTargetsTest.php; echo "PHPCS_EXIT=$?"`.

---

### Task 2: Asset fix + collector in `ReferenceResolver` (top-level)

**Files:**
- Modify: `app/Content/Delivery/ReferenceResolver.php`
- Test: extend `tests/Integration/Content/ReferenceResolverTest.php`

**Interfaces:**
- Consumes: `ExpandedTargets` (Task 1).
- Produces: `expand(array $rootRows, ContentTypeSchema $schema, ?FieldSelector $selector, string $locale, int $depth = 2, ?array $grantedScopes = null, ?ExpandedTargets $expanded = null): array`; `referenceFieldNames()` no longer includes `'asset'`; `splice(mixed $value, array $resolved, ?ExpandedTargets $expanded = null): mixed` records each spliced row.

- [ ] **Step 1: Failing tests** — add to `ReferenceResolverTest` (its `post` type schema in `setUp()` gains one line: `['name' => 'cover', 'type' => 'asset'],` after the `tags` field):

```php
    public function testAssetFieldsNeverExpandAndPassThroughRaw(): void
    {
        // The dormant-bug pin (spec §5): asset values are BLOB uuids;
        // publishedByEntryUuids() can never match them, so pre-fix they spliced
        // to null. Post-fix: raw blob uuid passes through untouched.
        $a = $this->createPublished(['title' => 'A', 'cover' => 'blobcover001'], 'a');
        $rows = $this->resolver()->expand(
            [$this->repo()->findPublishedByUuid($this->type, 'en', $a)],
            $this->schema(),
            null,
            'en',
        );
        self::assertSame('blobcover001', $rows[0]['fields']['cover']);
    }

    public function testCollectorRecordsOnlyActuallySplicedTargets(): void
    {
        $target = $this->createPublished(['title' => 'Target'], 'target');
        $draft = $this->createDraftOnly(['title' => 'Hidden']);
        $source = $this->createPublished(
            ['title' => 'Source', 'author' => $target, 'tags' => [$draft]],
            'source',
        );

        $expanded = new ExpandedTargets();
        $this->resolver()->expand(
            [$this->repo()->findPublishedByUuid($this->type, 'en', $source)],
            $this->schema(),
            null,
            'en',
            2,
            null,
            $expanded,
        );

        // The published target is recorded WITH its version identity; the
        // unresolved draft contributes nothing (spec §4 privacy pin).
        self::assertSame([$target], $expanded->entryUuids());
        self::assertCount(1, $expanded->versionIdentities());
        self::assertStringStartsWith($target . ':', $expanded->versionIdentities()[0]);
        self::assertStringNotContainsString($draft, implode('|', $expanded->versionIdentities()));
    }
```

Add the import `use App\Content\Delivery\ExpandedTargets;` to the test file.

- [ ] **Step 2: Verify fail** — `vendor/bin/phpunit tests/Integration/Content/ReferenceResolverTest.php`. Expected: the asset test FAILS with `null` instead of `'blobcover001'` (this CONFIRMS the dormant bug is live — record the actual failure output in the task notes); the collector test fails on the unknown `$expanded` argument.

- [ ] **Step 3: Implement** — three edits in `ReferenceResolver.php`:

(a) `referenceFieldNames()` drops asset (and its docblock updates):

```php
    /**
     * The reference field names to expand, honouring the selector. Asset fields are
     * DELIBERATELY absent (spec §5): asset values are blob uuids — resolving them
     * against the published-entry spine is a category error (pre-fix it nulled
     * them). Assets stay raw at every level; media() consumes them at render.
     *
     * @return list<string>
     */
    private function referenceFieldNames(ContentTypeSchema $schema, ?FieldSelector $selector): array
    {
        $scoped = $selector !== null && !$selector->empty();
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type !== 'reference') {
                continue;
            }
            if ($scoped && !$selector->requested($field->name)) {
                continue;
            }
            $names[] = $field->name;
        }
        return $names;
    }
```

(b) `expand()` gains the trailing collector param, threads it into the recursion and `splice()`:

```php
    public function expand(
        array $rootRows,
        ContentTypeSchema $schema,
        ?FieldSelector $selector,
        string $locale,
        int $depth = 2,
        ?array $grantedScopes = null,
        ?ExpandedTargets $expanded = null,
    ): array {
```

…the recursive call becomes `$this->expand(array_values($resolved), $schema, $selector, $locale, $depth - 1, $grantedScopes, $expanded)` and the splice loop passes it: `$fields[$field] = $this->splice($fields[$field], $resolved, $expanded);`. Docblock gains: `@param ExpandedTargets|null $expanded records every target actually spliced (any depth) for Cache-Tag/ETag (spec §4); null = no collection`.

(c) `splice()` records what it splices:

```php
    private function splice(mixed $value, array $resolved, ?ExpandedTargets $expanded = null): mixed
    {
        if (is_string($value)) {
            return $this->resolveOne($value, $resolved, $expanded);
        }
        if (is_array($value)) {
            return array_map(
                fn(mixed $v): mixed => is_string($v) ? $this->resolveOne($v, $resolved, $expanded) : $v,
                array_values($value)
            );
        }
        return $value;
    }

    /** @param array<string,array<string,mixed>> $resolved */
    private function resolveOne(string $uuid, array $resolved, ?ExpandedTargets $expanded): mixed
    {
        $row = $resolved[$uuid] ?? null;
        if ($row !== null) {
            $expanded?->add((string) ($row['entry_uuid'] ?? ''), (string) ($row['version_uuid'] ?? ''));
        }
        return $row;
    }
```

Add `use` for nothing new (ExpandedTargets is same namespace). Update the CLASS docblock: replace "reference/asset target uuids" wording — reference only; asset never expands (spec §5).

- [ ] **Step 4: Verify pass** — `vendor/bin/phpunit tests/Integration/Content/ReferenceResolverTest.php` all green (pre-existing tests must survive: they don't mention asset fields, and the `$expanded` param is optional). Gates: phpcs on both files.

---

### Task 3: Blocks descent in `ReferenceResolver`

**Files:**
- Modify: `app/Content/Delivery/ReferenceResolver.php`
- Test: `tests/Integration/Content/BlockReferenceExpansionTest.php` (new)

**Interfaces:**
- Consumes: `BlockTypeRepository::schemasBySlug(): array<string, ContentTypeSchema>` (memoised, includes deactivated types); `BlockDepth::MAX` (3); Task 2's `splice()/resolveOne()`.
- Produces: constructor `__construct(DeliveryRepository $repo, ?BlockTypeRepository $blockTypes = null)` — `null` = no blocks descent (hand-constructed legacy tests keep compiling); blocks fields of the ENTRY schema act as descent roots under the same selector rule as reference fields.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\ExpandedTargets;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\HttpFoundation\Request;

final class BlockReferenceExpansionTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        // related: a single ref + a multi ref + an asset; nest: a container.
        $blocks->create(['slug' => 'related', 'label' => 'Related', 'schema' => [
            ['name' => 'post', 'type' => 'reference'],
            ['name' => 'more', 'type' => 'reference', 'multiple' => true],
            ['name' => 'image', 'type' => 'asset'],
        ]]);
        $blocks->create(['slug' => 'nest', 'label' => 'Nest', 'schema' => [
            ['name' => 'inner', 'type' => 'blocks'],
        ]]);

        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /** @param array<string,mixed> $fields */
    private function createPublished(array $fields, string $slug): string
    {
        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            // FULL validator: blocks fields REQUIRE the registry — a bare
            // `new FieldValidator()` rejects every blocks value with
            // "block types are unavailable" (FieldValidator.php:218).
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /**
     * A real blob row: with the full validator wired, validateAt() applies
     * assetExistsOnMediaDisk() to asset fields INSIDE blocks too, so the fixture's
     * asset uuid must exist on the configured media disk ('local' default).
     * Mirror MediaUrlResolverTest::seedBlob for the NOT NULL column set
     * (created_by is string(12) NOT NULL).
     */
    private function seedBlob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'storage_type' => 'local',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => 'user00000001',
            // + the remaining NOT NULL columns exactly as MediaUrlResolverTest
            //   seeds them (name/mime/size/created_at — copy that helper).
        ]);
    }

    private function resolver(): ReferenceResolver
    {
        return new ReferenceResolver(
            new DeliveryRepository($this->connection()),
            new BlockTypeRepository($this->connection()),
        );
    }

    private function schema(): ContentTypeSchema
    {
        return (new ContentTypeRepository($this->connection()))->schemaFor($this->type);
    }

    /** @return array<string,mixed> */
    private function expandOne(string $entryUuid, ?FieldSelector $selector = null, ?ExpandedTargets $t = null): array
    {
        $row = (new DeliveryRepository($this->connection()))
            ->findPublishedByUuid($this->type, 'en', $entryUuid);
        return $this->resolver()->expand([$row], $this->schema(), $selector, 'en', 2, null, $t)[0];
    }

    public function testBlockReferenceExpandsInPlaceWithTopLevelShape(): void
    {
        $this->seedBlob('blobimg00001');
        $target = $this->createPublished(['title' => 'Target', 'body' => []], 'target');
        $source = $this->createPublished(['title' => 'Source', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => [
                'post' => $target,
                'more' => [$target],
                'image' => 'blobimg00001',
            ]],
        ]], 'source');

        $expanded = new ExpandedTargets();
        $fields = $this->expandOne($source, null, $expanded)['fields'];
        $data = $fields['body'][0]['data'];

        // Single ref: hydrated-row shape, identical to top-level expansion.
        self::assertSame('Target', $data['post']['fields']['title']);
        self::assertSame($target, $data['post']['entry_uuid']);
        self::assertArrayHasKey('version_uuid', $data['post']);
        // Multi ref: ordered list of expanded items.
        self::assertSame('Target', $data['more'][0]['fields']['title']);
        // Asset: raw blob uuid, never expanded (spec §1 hard boundary).
        self::assertSame('blobimg00001', $data['image']);
        // Collector saw the target exactly once (deduped across post+more).
        self::assertSame([$target], $expanded->entryUuids());
    }

    public function testNestedBlocksExpandAndStructuralDepthIsNotReferenceDepth(): void
    {
        $target = $this->createPublished(['title' => 'Deep', 'body' => []], 'deep');
        // 3 structural levels (nest > nest > related) — the ref at the bottom MUST
        // expand: structure is layout, not graph hops (spec §1 depth model).
        $source = $this->createPublished(['title' => 'S', 'body' => [
            ['id' => 'n1', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'n2', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'r1', 'type' => 'related', 'data' => ['post' => $target]],
                ]]],
            ]]],
        ]], 's');

        $fields = $this->expandOne($source)['fields'];
        $bottom = $fields['body'][0]['data']['inner'][0]['data']['inner'][0]['data'];
        self::assertSame('Deep', $bottom['post']['fields']['title']);
    }

    public function testMalformedDataDirectlyThroughTheResolverIsLeftUntouched(): void
    {
        // Hand-built hydrated rows (publish validation would reject all of these —
        // the FULL validator rejects unknown slugs and malformed items): the
        // resolver's OWN guards must hold for data written around the API
        // (spec §2 robustness). Nothing throws; nothing is modified.
        $body = [
            'not-a-block',                                                    // non-array item
            ['id' => 'a', 'type' => 42, 'data' => ['post' => 'entryzzzz001']], // non-string type
            ['id' => 'g', 'type' => 'ghost', 'data' => ['post' => 'entryghost01']], // unknown slug
            ['id' => 'b', 'type' => 'related', 'data' => 'scalar-data'],      // non-array data
            ['id' => 'c', 'type' => 'related'],                               // data missing
        ];
        $row = ['entry_uuid' => 'handmade0001', 'version_uuid' => 'handmadev001',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => $body]];
        $out = $this->resolver()->expand([$row], $this->schema(), null, 'en')[0];
        self::assertSame($body, $out['fields']['body']);

        // Non-list blocks value: untouched.
        $row2 = ['entry_uuid' => 'handmade0002', 'version_uuid' => 'handmadev002',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => 'nope']];
        self::assertSame(
            'nope',
            $this->resolver()->expand([$row2], $this->schema(), null, 'en')[0]['fields']['body'],
        );

        // Structural cap: refs BELOW BlockDepth::MAX (4 nest levels) stay raw.
        $deep = ['id' => 'r', 'type' => 'related', 'data' => ['post' => 'entrydeep001']];
        $lvl = $deep;
        for ($i = 0; $i < 3; $i++) { // wrap 3x -> the ref sits at structural level 4
            $lvl = ['id' => "n{$i}", 'type' => 'nest', 'data' => ['inner' => [$lvl]]];
        }
        $row3 = ['entry_uuid' => 'handmade0003', 'version_uuid' => 'handmadev003',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => [$lvl]]];
        $out3 = $this->resolver()->expand([$row3], $this->schema(), null, 'en')[0];
        $bottom = $out3['fields']['body'][0]['data']['inner'][0]['data']['inner'][0]['data']['inner'][0];
        self::assertSame('entrydeep001', $bottom['data']['post']); // raw — walk capped
    }

    public function testSelectorScopesAtTheBlocksFieldLevel(): void
    {
        $target = $this->createPublished(['title' => 'T', 'body' => []], 't');
        $source = $this->createPublished(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 's3');

        // fields=title → body is not walked at all: raw uuid.
        $sel = FieldSelector::fromRequest(Request::create('/?fields=title'));
        $fields = $this->expandOne($source, $sel)['fields'];
        self::assertSame($target, $fields['body'][0]['data']['post']);

        // fields=body → block refs expand.
        $sel = FieldSelector::fromRequest(Request::create('/?fields=body'));
        $fields = $this->expandOne($source, $sel)['fields'];
        self::assertSame('T', $fields['body'][0]['data']['post']['fields']['title']);
    }

    public function testDepthCapLeavesRawUuidInsideBlocks(): void
    {
        // A -> (block ref) B -> (block ref) C with depth 2: B expands inside A,
        // B's own block ref to C expands (hop 2), and C's refs would be raw. Verify
        // the SIMPLER boundary directly: depth 1 leaves B's inner ref raw.
        $c = $this->createPublished(['title' => 'C', 'body' => []], 'c');
        $b = $this->createPublished(['title' => 'B', 'body' => [
            ['id' => 'x', 'type' => 'related', 'data' => ['post' => $c]],
        ]], 'b');
        $a = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'y', 'type' => 'related', 'data' => ['post' => $b]],
        ]], 'a');

        $row = (new DeliveryRepository($this->connection()))->findPublishedByUuid($this->type, 'en', $a);
        $fields = $this->resolver()->expand([$row], $this->schema(), null, 'en', 1)[0]['fields'];
        $bItem = $fields['body'][0]['data']['post'];
        self::assertSame('B', $bItem['fields']['title']);          // hop 1 expanded
        self::assertSame($c, $bItem['fields']['body'][0]['data']['post']); // hop 2 capped: raw
    }
}
```

- [ ] **Step 2: Verify fail** — `vendor/bin/phpunit tests/Integration/Content/BlockReferenceExpansionTest.php`. Expected: refs stay raw uuids (no blocks descent yet); constructor arg 2 unknown.

- [ ] **Step 3: Implement** — in `ReferenceResolver.php`:

Constructor + imports:

```php
use App\Content\Blocks\BlockDepth;
use App\Content\Blocks\BlockTypeRepository;
```

```php
    public function __construct(
        private readonly DeliveryRepository $repo,
        /** null = no blocks descent (block-refs stay raw); the container wires it. */
        private readonly ?BlockTypeRepository $blockTypes = null,
    ) {
    }
```

`expand()` — the two field-name lists + early-outs + splice loop:

```php
        $referenceFields = $this->referenceFieldNames($schema, $selector);
        $blocksFields = $this->blocksFieldNames($schema, $selector);
        if ($referenceFields === [] && $blocksFields === []) {
            return $rootRows;
        }

        // 1) Collect every target uuid across all rows (one set, one query per level).
        $targetUuids = $this->collectTargets($rootRows, $referenceFields, $blocksFields);
```

…and in the final loop, after the `$referenceFields` foreach:

```php
            foreach ($blocksFields as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $fields[$field] = $this->spliceBlocks($fields[$field], $resolved, 1, $expanded);
            }
```

New/changed private methods (complete):

```php
    /**
     * Entry-schema `blocks` fields — descent roots for block-ref expansion (spec §2),
     * under the SAME top-level selector rule as reference fields (spec §3: no
     * inner-block selectors). Empty when no registry is wired.
     *
     * @return list<string>
     */
    private function blocksFieldNames(ContentTypeSchema $schema, ?FieldSelector $selector): array
    {
        if ($this->blockTypes === null) {
            return [];
        }
        $scoped = $selector !== null && !$selector->empty();
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            if ($scoped && !$selector->requested($field->name)) {
                continue;
            }
            $names[] = $field->name;
        }
        return $names;
    }

    /**
     * Collect the distinct target uuids referenced across all rows — top-level
     * reference fields plus reference fields inside blocks (any structural depth).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @param list<string> $blocksFields
     * @return list<string>
     */
    private function collectTargets(array $rows, array $fields, array $blocksFields): array
    {
        $uuids = [];
        foreach ($rows as $row) {
            /** @var array<string,mixed> $rowFields */
            $rowFields = $row['fields'] ?? [];
            foreach ($fields as $field) {
                foreach ($this->uuidsIn($rowFields[$field] ?? null) as $uuid) {
                    $uuids[$uuid] = true;
                }
            }
            foreach ($blocksFields as $field) {
                $this->collectFromBlocks($rowFields[$field] ?? null, 1, $uuids);
            }
        }
        return array_keys($uuids);
    }

    /**
     * Walk a blocks value collecting reference-target uuids. Structural recursion is
     * bounded by BlockDepth::MAX (data written around the API must not unbound the
     * walk); malformed items and unknown slugs are skipped — delivery never explodes
     * over data (spec §2). Asset fields are never collected (spec §1).
     *
     * @param array<string,bool> $uuids
     */
    private function collectFromBlocks(mixed $value, int $structDepth, array &$uuids): void
    {
        if (!is_array($value) || !array_is_list($value) || $structDepth > BlockDepth::MAX) {
            return;
        }
        foreach ($value as $item) {
            [$blockSchema, $data] = $this->blockItem($item);
            if ($blockSchema === null) {
                continue;
            }
            foreach ($blockSchema->fields() as $field) {
                if ($field->type === 'reference') {
                    foreach ($this->uuidsIn($data[$field->name] ?? null) as $uuid) {
                        $uuids[$uuid] = true;
                    }
                } elseif ($field->type === 'blocks') {
                    $this->collectFromBlocks($data[$field->name] ?? null, $structDepth + 1, $uuids);
                }
            }
        }
    }

    /**
     * Mirror of collectFromBlocks: splice resolved targets back into block data,
     * same walk, same guards, same structural cap.
     *
     * @param array<string,array<string,mixed>> $resolved
     */
    private function spliceBlocks(
        mixed $value,
        array $resolved,
        int $structDepth,
        ?ExpandedTargets $expanded,
    ): mixed {
        if (!is_array($value) || !array_is_list($value) || $structDepth > BlockDepth::MAX) {
            return $value;
        }
        foreach ($value as $i => $item) {
            [$blockSchema, $data] = $this->blockItem($item);
            if ($blockSchema === null) {
                continue;
            }
            foreach ($blockSchema->fields() as $field) {
                if (!array_key_exists($field->name, $data)) {
                    continue;
                }
                if ($field->type === 'reference') {
                    $data[$field->name] = $this->splice($data[$field->name], $resolved, $expanded);
                } elseif ($field->type === 'blocks') {
                    $data[$field->name] = $this->spliceBlocks(
                        $data[$field->name],
                        $resolved,
                        $structDepth + 1,
                        $expanded,
                    );
                }
            }
            $value[$i]['data'] = $data;
        }
        return $value;
    }

    /**
     * A block item's registry schema + data, or [null, []] for anything malformed:
     * non-array item, non-string type, unknown slug (registry includes deactivated
     * types — stored content referencing one still expands), non-array data.
     *
     * @return array{0: ?ContentTypeSchema, 1: array<string,mixed>}
     */
    private function blockItem(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['type'] ?? null)) {
            return [null, []];
        }
        $schema = ($this->blockTypes?->schemasBySlug() ?? [])[$item['type']] ?? null;
        if ($schema === null || !is_array($item['data'] ?? null)) {
            return [null, []];
        }
        return [$schema, $item['data']];
    }
```

Update the CLASS docblock: expansion covers reference fields at top level AND inside blocks (global registry schemas, structural cap `BlockDepth::MAX`, depth counts reference hops only).

Container wiring: `ReferenceResolver` is registered `autowire: true` in `LemmaServiceProvider` (line ~401) and `BlockTypeRepository` is a registered service — autowiring injects it; NO provider change expected. Verify with the delivery suite (Step 4); if autowiring can't fill the nullable param, add an explicit factory mirroring `makeMediaUrlResolver`'s placement.

- [ ] **Step 4: Verify pass** — `vendor/bin/phpunit tests/Integration/Content/BlockReferenceExpansionTest.php tests/Integration/Content/ReferenceResolverTest.php` then the whole `tests/Integration/Content/`. Gates: phpcs on touched files.

---

### Task 4: Delivery validators — ETag + Cache-Tag carry expansion targets

**Files:**
- Modify: `app/Content/Http/DeliveryEtag.php`, `app/Content/Delivery/DeliveryItemShaper.php`, `app/Content/Http/Controllers/DeliveryController.php`, `app/Content/Http/Controllers/TaxonomyController.php`
- Test: `tests/Integration/Content/Delivery/ExpansionCacheValidatorsTest.php` (new)

**Interfaces:**
- Consumes: `ExpandedTargets` (Task 1), resolver collector (Tasks 2–3).
- Produces: `DeliveryEtag::forItem(string $versionUuid, string $selectionKey, array $expanded = [])`, `forList(array $versionUuids, string $selectionKey, array $expanded = [])`, `cacheTag(array $entryUuids, string $typeSlug, array $expandedEntryUuids = [])`; `DeliveryItemShaper::shape(..., ?ExpandedTargets $expanded = null)` and `shapePublic(array $row, string $typeUuid, string $typeSlug, ?ExpandedTargets $expanded = null)`.

- [ ] **Step 1: Failing test** — mirror the harness of `tests/Integration/Content/Delivery/ReferenceDeliveryFilterTest.php` (same `controller()` construction — pass `new ReferenceResolver($repo, new BlockTypeRepository($this->connection()))` — same seeding helpers `publishPost`/`seedPost` adapted to a `page` type with a `blocks` `body` field and the `related` block type from Task 3's setUp; same `RequestDataHydrator` invocation, plus a `show()` variant hydrating `DeliveryShowQuery`):

```php
    public function testShowCarriesTargetTagsAndTargetSensitiveEtag(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target');
        $source = $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source');

        $first = $this->deliverShow('page', 'source');
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $first->headers->get('Cache-Tag'),
        );
        $etagBefore = (string) $first->headers->get('ETag');

        // Body: expanded in place; NO collector residue anywhere in the JSON.
        $body = (string) $first->getContent();
        self::assertStringContainsString('"title":"T"', $body);
        self::assertStringNotContainsString('expanded_entry_uuids', $body);
        self::assertStringNotContainsString('versionIdentities', $body);

        // Republish the TARGET (source's own version unchanged) → source's ETag
        // MUST change (spec §4 P1: tags purge caches; validators stop false 304s).
        $this->republish($target, ['title' => 'T2']);
        $second = $this->deliverShow('page', 'source');
        self::assertNotSame($etagBefore, (string) $second->headers->get('ETag'));

        // And a conditional request with the OLD etag must NOT 304.
        $conditional = $this->deliverShow('page', 'source', ifNoneMatch: $etagBefore);
        self::assertSame(200, $conditional->getStatusCode());
    }

    public function testListEtagFoldsExpandedIdentities(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target2');
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source2');

        $first = $this->deliverList('page');
        $etagBefore = (string) $first->headers->get('ETag');
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $first->headers->get('Cache-Tag'),
        );

        $this->republish($target, ['title' => 'T2']);
        self::assertNotSame(
            $etagBefore,
            (string) $this->deliverList('page')->headers->get('ETag'),
        );
    }

    public function testUnresolvedTargetLeavesNoTraceInSurrogateHeaders(): void
    {
        $draft = $this->draftOnlyPage(['title' => 'Hidden']);
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $draft]],
        ]], 'source3');

        $resp = $this->deliverShow('page', 'source3');
        self::assertStringNotContainsString($draft, (string) $resp->headers->get('Cache-Tag'));
        self::assertStringNotContainsString($draft, (string) $resp->headers->get('ETag'));
        // The unresolved ref splices to null in the body.
        $body = json_decode((string) $resp->getContent(), true);
        self::assertNull($body['data']['fields']['body'][0]['data']['post']);
    }
```

Helpers to write in the test (full bodies mirroring `ReferenceDeliveryFilterTest`): `publishPage()` (create+draft+route+publish, returns entry uuid), `draftOnlyPage()`, `republish(string $entryUuid, array $fields)` (saveDraft with bumped base version + publish again), `deliverShow(string $type, string $slug, ?string $ifNoneMatch = null)` (hydrate `DeliveryShowQuery`, `Request::create('/', 'GET')` + optional `If-None-Match` header, call `$this->controller()->show(...)`, return the Response), `deliverList(string $type)` (hydrate `DeliveryListQuery`, call `index()`).

**Fixture rule (P1):** every helper that PUBLISHES content with a blocks field must
build the FULL validator — `new FieldValidator($this->connection(), $this->appContext(), new BlockTypeRepository($this->connection()))` — a bare `new FieldValidator()` rejects blocks
values with "block types are unavailable" (`FieldValidator.php:218`). These fixtures'
block data carries only `post` reference uuids (no assets), so no `blobs` seeding is
needed here.

- [ ] **Step 2: Verify fail** — target tag absent from `Cache-Tag`; ETag unchanged after target republish.

- [ ] **Step 3: Implement**

`DeliveryEtag` — optional expanded identities; EMPTY input produces byte-identical validators to today (no cache flush for pages without expansions):

```php
    /**
     * Build the ETag for a single published row. $expanded: the sorted
     * entry:version identities of expansion targets (spec §4 P1) — a republished
     * target must change the validator, or conditionals false-304.
     *
     * @param list<string> $expanded
     */
    public function forItem(string $versionUuid, string $selectionKey, array $expanded = []): string
    {
        return '"' . sha1($versionUuid . $this->expandedKey($expanded) . '|' . $selectionKey) . '"';
    }

    /**
     * Build the ETag for a list response from its members' version uuids.
     *
     * @param list<string> $versionUuids in result order
     * @param list<string> $expanded sorted expansion-target identities
     */
    public function forList(array $versionUuids, string $selectionKey, array $expanded = []): string
    {
        return '"' . sha1(implode('|', $versionUuids) . $this->expandedKey($expanded) . '|' . $selectionKey) . '"';
    }

    /** @param list<string> $expanded */
    private function expandedKey(array $expanded): string
    {
        return $expanded === [] ? '' : '|x:' . implode('|', $expanded);
    }
```

```php
    /**
     * Build the `Cache-Tag` header value: a per-entry tag for each member, each
     * expansion target (spec §4 — purge must reach embedding pages), plus the type
     * tag. Deduped, order preserved.
     *
     * @param list<string> $entryUuids
     * @param list<string> $expandedEntryUuids
     */
    public function cacheTag(array $entryUuids, string $typeSlug, array $expandedEntryUuids = []): string
    {
        $tags = [];
        foreach ([...$entryUuids, ...$expandedEntryUuids] as $uuid) {
            if ($uuid !== '') {
                $tags['lemma:entry:' . $uuid] = true;
            }
        }
        $tags['lemma:type:' . $typeSlug] = true;
        return implode(', ', array_keys($tags));
    }
```

`DeliveryItemShaper` — collector passthrough (signature only; body threads `$expanded` as the 7th `expand()` arg):

```php
    public function shape(
        array $rows,
        ContentTypeSchema $schema,
        FieldSelector $selector,
        string $locale,
        string $typeUuid,
        ?array $grantedScopes,
        ?ExpandedTargets $expanded = null,
    ): array {
```
…`$rows = $this->references->expand($rows, $schema, $selector->empty() ? null : $selector, $locale, 2, $grantedScopes, $expanded);`

```php
    public function shapePublic(
        array $row,
        string $typeUuid,
        string $typeSlug,
        ?ExpandedTargets $expanded = null,
    ): array {
```
…`$shaped = $this->shape([$row], $schema, $selector, (string) $row['locale'], $typeUuid, null, $expanded);`

`DeliveryController`:
- `index()`: `$expanded = new ExpandedTargets();` before the pagination branch; both `$this->shape(...)` calls gain `$expanded` as the trailing arg; both `withCacheHeaders(...)` calls gain `$expanded`.
- `withCacheHeaders(Request $request, Response $response, array $rows, array $typeRow, ExpandedTargets $expanded)`: `$etag = $this->etags->forList($versionUuids, $this->selectionKey($request), $expanded->versionIdentities());` and `$this->etags->cacheTag($entryUuids, $typeSlug, $expanded->entryUuids())`.
- `show()`: `$expanded = new ExpandedTargets();` before the `$this->shape(...)` call (note: shape runs BEFORE the ETag/If-None-Match check, so the collector is populated on the 304 path too — this ordering already exists, keep it); `$etag = $this->etags->forItem((string) $row['version_uuid'], $this->selectionKey($request), $expanded->versionIdentities());`; BOTH `cacheTag(...)` calls (304 + success) become `$this->etags->cacheTag([(string) $row['entry_uuid']], $type, $expanded->entryUuids())`.
- private `shape()` helper: trailing `?ExpandedTargets $expanded = null`, passed through to `$this->itemShaper()->shape(...)`.
- Import `use App\Content\Delivery\ExpandedTargets;`.

`TaxonomyController` (`archive()` method): one `$expanded = new ExpandedTargets();` covering the TERM and the rows — `shapePublic($termRow, (string) $targetRow['uuid'], $targetSlug, $expanded)`, both `shape(...)` calls gain `$expanded`, and `archiveCacheHeaders(...)` gains the collector:

```php
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request), $expanded->versionIdentities());
        $cacheTag = $this->etags->cacheTag($entryUuids, (string) $typeRow['slug'], $expanded->entryUuids());
```
(keep the existing `$targetSlug` type-tag append). Import `ExpandedTargets`.

- [ ] **Step 4: Verify pass** — new test file + `vendor/bin/phpunit tests/Integration/Content/` (all pre-existing delivery/taxonomy tests must stay green — empty collectors keep every validator byte-identical). Gates: phpcs on the four modified files + test.

---

### Task 5: Render path — resolver `cache_tags` + controller merge

**Files:**
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`, `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Test: extend `tests/Integration/Render/PublicRouteResolverTest.php` and `tests/Integration/Render/RenderPipelineTest.php`

**Interfaces:**
- Consumes: `DeliveryItemShaper::shapePublic(..., ?ExpandedTargets)` / `shape(..., ?ExpandedTargets)` (Task 4).
- Produces: resolver results for kinds `content` / `listing` / `archive` gain `'cache_tags' => list<string>` (full tag strings, `lemma:entry:{uuid}`); `RenderController` merges `$result['cache_tags'] ?? []` via `mergeCacheTags()`. Preview results carry NO tags (no-store pages). The render PACK reads only the array key — no `App\` import (boundary checker).

- [ ] **Step 1: Failing tests**

In `PublicRouteResolverTest` (reuse its existing seeding helpers; add the `related` block type + a blocks `body` field to its seeded type, or seed a dedicated type the way `BlockReferenceExpansionTest` does):

```php
    public function testContentResultCarriesExpansionTargetCacheTags(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target');
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source');

        $result = $this->resolver()->resolve('/page/source', null);
        self::assertSame('content', $result['kind']);
        self::assertContains('lemma:entry:' . $target, $result['cache_tags']);
        // Privacy: the tags never ride inside the content payload.
        self::assertArrayNotHasKey('cache_tags', $result['content']);
    }
```

In `RenderPipelineTest` (reuse its request/render harness; the page body template renders via the theme, tags come from the controller):

```php
    public function testRenderedPageCarriesExpansionTargetTag(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target');
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source');

        $response = $this->renderPath('/page/source');
        self::assertSame(200, $response->getStatusCode());
        // Byte-identical to InvalidateCacheTagsListener's purge string — a
        // republish of the target now reaches this cached page.
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $response->headers->get('Cache-Tag'),
        );
        self::assertStringNotContainsString('cache_tags', (string) $response->getContent());
    }
```

(Adapt helper names — `publishPage`/`resolver`/`renderPath` — to whatever those two test files actually provide; keep the assertions verbatim. If a helper for a blocks-typed page doesn't exist, add one alongside the file's existing seeding helpers — and any helper publishing a blocks field must use the FULL validator per Task 4's fixture rule.)

- [ ] **Step 2: Verify fail** — `cache_tags` key missing / header lacks the target tag.

- [ ] **Step 3: Implement**

`EnginePublicRouteResolver` — import `ExpandedTargets` (same namespace, no import needed); add a tiny helper:

```php
    /** @return list<string> full surrogate tag strings for the collected targets */
    private function expansionTags(ExpandedTargets $expanded): array
    {
        return array_map(
            static fn(string $uuid): string => 'lemma:entry:' . $uuid,
            $expanded->entryUuids(),
        );
    }
```

Then, per result site:
- `resolve()` content branch (line ~173) and `resolveEntry()` (line ~216): `$expanded = new ExpandedTargets();` before the return; `'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug, $expanded),` and add `'cache_tags' => $this->expansionTags($expanded),` to the result array.
- `resolveArchive()` (line ~417): one collector spanning the term + items — create `$expanded` before `$this->paginate(...)`; `listItems()` gains a trailing `?ExpandedTargets $expanded = null` threaded into its `$this->shaper->shape(..., null, $expanded)` call; the term line becomes `'term' => $this->shaper->shapePublic($termRow, $targetUuid, $targetSlug, $expanded),`; add `'cache_tags' => $this->expansionTags($expanded),`.
- The listing resolver (the other `listItems()` caller — locate via `grep -n "listItems(" app/Content/Delivery/EnginePublicRouteResolver.php`): same collector → `'cache_tags'`.
- `previewContent()`: UNCHANGED — no collector, no tags (preview responses are no-store; the render controller strips Cache-Tag).
- `notFound()`/`redirect()`/`resolveTerms()`: unchanged (`RenderController` defaults with `?? []`).

`RenderController` — two one-line merges (pure array access, no `App\` reference):
- `renderEntry()`: after `$this->tagResponse($response, $entry ?? [], $typeSlug);` add
  `$this->mergeCacheTags($response, array_values(array_map('strval', (array) ($result['cache_tags'] ?? []))));`
- `renderCollection()`: after `$this->tagCollection($response, $result);` add the same line.
- Update `renderEntry()`'s `@param` docblock shape comment to mention the optional `cache_tags` key.

- [ ] **Step 4: Verify pass** — `vendor/bin/phpunit tests/Integration/Render/` (the full render suite guards the preview/no-store and page-cache behaviors). Gates: phpcs on all touched files AND `composer boundaries` (render pack modified).

---

### Task 6: Docs + full verification + STAGE

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (blocks() docblock), `packages/lemma-render/README.md`, `CHANGELOG.md`

- [ ] **Step 1: `blocks()` docblock** — in `RenderContextExtension`, replace the sentence "Reference values inside `data` are raw uuids — use `path(uuid)` for links." in the class-level/blocks() docblock area (it lives in the README wording too) with:

```
Reference values inside `data` arrive EXPANDED (the target's published item — fields
under `.fields`, entry uuid under `.entry_uuid`; null when unpublished or gated; raw
uuid only at the expansion-depth cap). Link via path(data.post.entry_uuid). Asset
values stay raw blob uuids for media().
```

- [ ] **Step 2: README** (`packages/lemma-render/README.md`, "Blocks in templates" section) — replace "Reference values inside `data` are raw uuids — use `path(uuid)` for links." with:

```markdown
Reference values inside `data` arrive expanded (published item: `data.post.fields.title`,
`path(data.post.entry_uuid)`; `null` when the target is unpublished or gated; raw uuid
only at the expansion-depth cap). Asset values stay raw blob uuids for `media()`.
Pages embedding expanded targets carry the target's `lemma:entry:{uuid}` cache tag, so
they purge when the target republishes.
```

- [ ] **Step 3: CHANGELOG `[Unreleased]`** — append to the block-builder bullet family:

```markdown
  Follow-up: **block reference auto-expansion** — references inside block data now
  expand in place (same batch loading, depth-2 reference-hop budget, and scope gates
  as top-level references; block structure never consumes expansion depth; asset
  fields never expand). Expansion targets now feed cache correctness everywhere:
  `Cache-Tag` carries `lemma:entry:{target}` for every expanded target (delivery API
  and rendered pages purge when an embedded target republishes) and the delivery
  ETag folds in sorted target `entry:version` identities (no more false 304 after a
  target republish). Unresolved targets contribute neither (surrogate-header
  privacy). Also fixes the dormant top-level bug where `asset` fields were passed to
  entry expansion (splicing them to null): asset values now always pass through raw.
```

- [ ] **Step 4: Full verification**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..   # untouched, keep the gate honest
```

Expected: all green (single pre-existing Integration skip).

- [ ] **Step 5: STAGE** *(commit only when authorized)*

```bash
git add app/Content/Delivery app/Content/Http docs/superpowers/specs/2026-07-03-block-reference-expansion-design.md \
        docs/superpowers/plans/2026-07-03-block-reference-expansion.md \
        packages/lemma-render/src/Http/Controllers/RenderController.php \
        packages/lemma-render/src/RenderContextExtension.php packages/lemma-render/README.md \
        CHANGELOG.md tests/Unit/Content tests/Integration/Content tests/Integration/Render
```

STOP — when authorized:

```bash
git commit -m "feat(content): block reference auto-expansion + expansion-aware cache validators

References inside block data now expand in place with the exact top-level
contract: batched per level via publishedByEntryUuids(), depth counts
reference hops only (block structure is bounded separately by BlockDepth::MAX),
selector scoping at the top-level blocks field, scope-gated targets resolve
null, raw uuids only at the depth cap. Asset fields NEVER expand — fixes the
dormant top-level bug where asset blob uuids were passed to entry expansion
and spliced to null.

Cache correctness for expansion targets, both reference kinds: new
ExpandedTargets collector (resolver -> shaper -> controllers) adds
lemma:entry:{target} to Cache-Tag on the delivery API, taxonomy archives, and
rendered pages (resolver-result cache_tags -> mergeCacheTags), and folds
sorted target entry:version identities into delivery ETags so a target
republish can't false-304. Unresolved targets contribute neither tag nor
identity (surrogate-header privacy); empty collectors keep validators
byte-identical to before."
```

---

## Self-Review Notes (applied)

- **Spec coverage:** §1 contract → Tasks 2–3 tests (single/multi/depth-cap/asset); §2 descent → Task 3 (registry lookup incl. deactivated types, structural cap, robustness guards); §3 selector → Task 3 `testSelectorScopesAtTheBlocksFieldLevel`; §4 tags+ETag+privacy+unresolved pin → Tasks 4–5 (both P1 ETag round-trips, no-residue assertions, unresolved-target headers test); §5 asset bug → Task 2 (pin-then-fix); §6 docs → Task 6; §7 test matrix mapped across Tasks 1–5.
- **Type consistency:** `ExpandedTargets` API (`add/entryUuids/versionIdentities`) used identically in Tasks 2–5; `shape()`/`shapePublic()` trailing-param shape consistent across Task 4 call-site edits and Task 5 threading; expanded-item keys (`entry_uuid`, `version_uuid`, `fields`, `version`) match `DeliveryRepository::hydrate()` (verified).
- **Fixture safety (corrected in review):** Task 2's `ReferenceResolverTest` keeps its bare `new FieldValidator()` — its `post` type has NO blocks field, and `assetExistsOnMediaDisk()` returns `true` with no DB, so `blobcover001` publishes unseeded. Every fixture publishing a BLOCKS field (Tasks 3–5) must use the FULL validator (connection + context + `BlockTypeRepository`) — a bare validator rejects blocks with "block types are unavailable" — and with the full validator, asset uuids inside blocks hit the real media-disk check, so Task 3 seeds `blobs` row `blobimg00001` (`seedBlob()` mirrors `MediaUrlResolverTest`). Unknown-slug/malformed items can't publish through the full validator, so robustness is covered by the direct hand-built-row resolver test in Task 3.
- **Verify-don't-guess flags:** Task 2 Step 2 records the dormant bug's ACTUAL failure output before fixing; Task 3 Step 3 verifies autowiring fills the new ctor param (fallback: explicit factory); Task 5 Step 1 adapts helper names to the real harnesses in `PublicRouteResolverTest`/`RenderPipelineTest`; Task 5's `listItems()` second caller located by grep at implementation.
- **Compat invariants:** empty collector ⇒ byte-identical ETags/Cache-Tag to today (`expandedKey([]) === ''`; `cacheTag` third param defaults `[]`) — pre-existing delivery/render tests are the regression harness.
