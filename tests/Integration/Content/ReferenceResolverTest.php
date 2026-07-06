<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

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
use App\Tests\Support\AppTestCase;

final class ReferenceResolverTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        // A self-referential type: `author` references another entry of the same type;
        // `tags` is a list-valued reference.
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            // Public type: these tests exercise expansion mechanics on the legit path.
            // Cross-type visibility gating has its own test below.
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'author', 'type' => 'reference'],
                ['name' => 'tags', 'type' => 'reference'],
                ['name' => 'cover', 'type' => 'asset'],
            ],
        ]);
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    /** @param array<string,mixed> $fields */
    private function createPublished(array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /** @param array<string,mixed> $fields */
    private function createDraftOnly(array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    private function archive(string $entryUuid): void
    {
        $this->connection()->table('entries')
            ->where('uuid', '=', $entryUuid)
            ->update(['status' => 'archived']);
    }

    private function repo(): DeliveryRepository
    {
        return new DeliveryRepository($this->connection());
    }

    private function resolver(): ReferenceResolver
    {
        return new ReferenceResolver($this->repo());
    }

    private function schema(): ContentTypeSchema
    {
        return (new ContentTypeRepository($this->connection()))->schemaFor($this->type);
    }

    public function testResolvesReferenceToTargetPublishedFields(): void
    {
        $b = $this->createPublished(['title' => 'Target B'], 'b');
        $a = $this->createPublished(['title' => 'Source A', 'author' => $b], 'a');

        $rootA = $this->repo()->findPublishedByUuid($this->type, 'en', $a);
        self::assertNotNull($rootA);

        $expanded = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2);

        self::assertCount(1, $expanded);
        $author = $expanded[0]['fields']['author'];
        self::assertIsArray($author);
        self::assertSame('Target B', $author['fields']['title']);
    }

    public function testReferenceToNonPublicTypeIsGatedByCallerScopes(): void
    {
        // A published entry of a NON-public type, referenced by a public post's author.
        $secretType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'secret', 'name' => 'Secret', 'public_delivery' => false,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->entries();
        $secret = $entries->createEntry($secretType, 'en', 1, 'user00000001');
        $entries->saveDraft($secret, 'en', ['title' => 'Top Secret'], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($secret, $secretType, 'en', 'top-secret');
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($secret, 'en', 'user00000001');

        $a = $this->createPublished(['title' => 'Public Post', 'author' => $secret], 'public-post');
        $rootA = $this->repo()->findPublishedByUuid($this->type, 'en', $a);
        self::assertNotNull($rootA);

        // Anonymous: the referenced non-public type must resolve to null, not leak its fields.
        $anon = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2, null);
        self::assertNull($anon[0]['fields']['author'], 'anonymous caller must not see a non-public reference');

        // A key scoped for the secret type expands it normally.
        $scoped = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2, ['read:content:secret']);
        self::assertIsArray($scoped[0]['fields']['author']);
        self::assertSame('Top Secret', $scoped[0]['fields']['author']['fields']['title']);
    }

    public function testUnpublishedTargetResolvesToNull(): void
    {
        $b = $this->createDraftOnly(['title' => 'Draft B']);
        $a = $this->createPublished(['title' => 'Source A', 'author' => $b], 'a');

        $rootA = $this->repo()->findPublishedByUuid($this->type, 'en', $a);
        self::assertNotNull($rootA);

        $expanded = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2);

        self::assertNull($expanded[0]['fields']['author']);
    }

    public function testArchivedButPublishedTargetResolvesToNull(): void
    {
        // B is published, THEN archived. The main read path filters e.status='active',
        // so an archived target must not surface through another entry's reference.
        $b = $this->createPublished(['title' => 'Archived B'], 'b');
        $a = $this->createPublished(['title' => 'Source A', 'author' => $b], 'a');
        $this->archive($b);

        $rootA = $this->repo()->findPublishedByUuid($this->type, 'en', $a);
        self::assertNotNull($rootA);

        $expanded = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2);

        self::assertNull($expanded[0]['fields']['author']);
    }

    public function testCircularReferenceIsBoundedByDepth(): void
    {
        // A references itself. Resolution must terminate (depth-bounded), not loop.
        $a = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');
        $this->entries()->saveDraft($a, 'en', ['title' => 'Self A', 'author' => $a], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($a, $this->type, 'en', 'self-a');
        (new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($a, 'en', 'user00000001');

        $rootA = $this->repo()->findPublishedByUuid($this->type, 'en', $a);
        self::assertNotNull($rootA);

        // depth=2: root -> author(A) -> author(A); the third level is not expanded.
        $expanded = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2);

        $level1 = $expanded[0]['fields']['author'];
        self::assertIsArray($level1);
        $level2 = $level1['fields']['author'];
        self::assertIsArray($level2);
        // At the depth limit the nested author is left as the raw uuid (not expanded).
        self::assertSame($a, $level2['fields']['author']);
    }

    public function testListValuedReferenceResolvesEachElement(): void
    {
        $t1 = $this->createPublished(['title' => 'Tag 1'], 't1');
        $t2 = $this->createPublished(['title' => 'Tag 2'], 't2');
        $unpub = $this->createDraftOnly(['title' => 'Tag Draft']);

        // The FieldValidator (publish path) only accepts a single-uuid reference; a
        // list-valued reference is a delivery-read concern. Drive the resolver against a
        // hydrated row built directly (the same shape DeliveryRepository::hydrate emits).
        $rootA = ['fields' => ['title' => 'Source A', 'tags' => [$t1, $t2, $unpub]]];

        $expanded = $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2);

        $tags = $expanded[0]['fields']['tags'];
        self::assertIsArray($tags);
        self::assertCount(3, $tags);
        self::assertSame('Tag 1', $tags[0]['fields']['title']);
        self::assertSame('Tag 2', $tags[1]['fields']['title']);
        // The unpublished element resolves to null (never a draft).
        self::assertNull($tags[2]);
    }

    public function testBatchResolvesTargetsAcrossMultipleRootRowsInOneSet(): void
    {
        // Three published targets, referenced by three distinct root rows. A correct
        // batch resolver collects all target uuids into ONE set and resolves them in a
        // single publishedByEntryUuids() call (one query for the whole level), not a
        // per-entry fetch. We prove the set-wide collection by resolving all three at
        // once and checking every reference is spliced.
        $b1 = $this->createPublished(['title' => 'B1'], 'b1');
        $b2 = $this->createPublished(['title' => 'B2'], 'b2');
        $b3 = $this->createPublished(['title' => 'B3'], 'b3');

        $roots = [
            ['fields' => ['title' => 'R1', 'author' => $b1]],
            ['fields' => ['title' => 'R2', 'author' => $b2]],
            ['fields' => ['title' => 'R3', 'author' => $b3]],
        ];

        $expanded = $this->resolver()->expand($roots, $this->schema(), null, 'en', 1);

        self::assertSame('B1', $expanded[0]['fields']['author']['fields']['title']);
        self::assertSame('B2', $expanded[1]['fields']['author']['fields']['title']);
        self::assertSame('B3', $expanded[2]['fields']['author']['fields']['title']);
    }

    public function testSelectorScopesWhichReferencesExpand(): void
    {
        $b = $this->createPublished(['title' => 'Author B'], 'b');
        $t1 = $this->createPublished(['title' => 'Tag 1'], 't1');

        // List-valued `tags` is built directly (see list test for why); `author` is a
        // single uuid reference. Only `author` is requested → only it expands.
        $rootA = ['fields' => ['title' => 'A', 'author' => $b, 'tags' => [$t1]]];

        // Request only `author`; `tags` must stay as raw uuids.
        $selector = \Glueful\Support\FieldSelection\FieldSelector::fromRequest(
            new \Symfony\Component\HttpFoundation\Request(['fields' => 'title,author'])
        );

        $expanded = $this->resolver()->expand([$rootA], $this->schema(), $selector, 'en', 2);

        self::assertIsArray($expanded[0]['fields']['author']);
        self::assertSame([$t1], $expanded[0]['fields']['tags']);
    }

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
        // List-valued `tags` built directly (same reason as the selector/list tests:
        // a single-valued reference field can't publish an array value).
        $rootA = ['fields' => ['title' => 'Source', 'author' => $target, 'tags' => [$draft]]];

        $expanded = new ExpandedTargets();
        $this->resolver()->expand([$rootA], $this->schema(), null, 'en', 2, null, $expanded);

        // The published target is recorded WITH its version identity; the
        // unresolved draft contributes nothing (spec §4 privacy pin).
        self::assertSame([$target], $expanded->entryUuids());
        self::assertCount(1, $expanded->versionIdentities());
        self::assertStringStartsWith($target . ':', $expanded->versionIdentities()[0]);
        self::assertStringNotContainsString($draft, implode('|', $expanded->versionIdentities()));
    }
}
