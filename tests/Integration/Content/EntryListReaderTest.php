<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineEntryListReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Delivery\EntryListReader;

final class EntryListReaderTest extends AppTestCase
{
    private string $postType;
    private string $categoryType;
    private string $userUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        $this->userUuid = $this->seedUser();

        $types = new ContentTypeRepository($this->connection());
        $this->categoryType = $types->create([
            'slug' => 'category', 'name' => 'Category', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
            ],
        ]);
        $this->postType = $types->create([
            'slug' => 'post', 'name' => 'Post', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text'],
                ['name' => 'cover', 'type' => 'asset'],
                ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                    'multiple' => true, 'filterable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        parent::tearDown();
    }

    private function reader(): EntryListReader
    {
        return $this->container()->get(EntryListReader::class);
    }

    // ── Gate / binding / clamp (no seeding needed) ────────────────────────────

    public function testListReaderIsBoundToEngineImpl(): void
    {
        self::assertInstanceOf(EngineEntryListReader::class, $this->reader());
    }

    public function testUnknownTypeReturnsEmpty(): void
    {
        $out = $this->reader()->list('does-not-exist', ['limit' => 3], 'en');
        self::assertSame([], $out['items']);
        self::assertSame([], $out['cache_tags']);
    }

    // ── Data-bearing ──────────────────────────────────────────────────────────

    public function testListsPublishedPostsWithBroadTypeTag(): void
    {
        $a = $this->publishPost($this->postType, ['title' => 'First'], 'first');
        $b = $this->publishPost($this->postType, ['title' => 'Second'], 'second');
        // A THIRD post outside a limit-1 window — the broad tag must still cover it.
        $c = $this->publishPost($this->postType, ['title' => 'Third'], 'third');

        $out = $this->reader()->list('post', ['limit' => 12, 'order' => 'newest'], 'en');
        $titles = array_map(static fn(array $i): string => (string) ($i['fields']['title'] ?? ''), $out['items']);

        self::assertCount(3, $out['items']);
        self::assertEqualsCanonicalizing(['First', 'Second', 'Third'], $titles);
        // Broad listing dependency present (correctness mechanism) …
        self::assertContains('thallo:type:post', $out['cache_tags']);
        // … plus each returned entry's own tag.
        self::assertContains('thallo:entry:' . $a, $out['cache_tags']);
        self::assertContains('thallo:entry:' . $b, $out['cache_tags']);
        self::assertContains('thallo:entry:' . $c, $out['cache_tags']);

        // Both orders return the full set without error (sequence not asserted —
        // same-second published_at ties make exact order non-deterministic).
        $oldest = $this->reader()->list('post', ['limit' => 12, 'order' => 'oldest'], 'en');
        self::assertCount(3, $oldest['items']);
    }

    public function testLimitApplies(): void
    {
        $this->publishPost($this->postType, ['title' => 'One'], 'one');
        $this->publishPost($this->postType, ['title' => 'Two'], 'two');

        $out = $this->reader()->list('post', ['limit' => 1], 'en');
        self::assertCount(1, $out['items']);
        // Broad tag still present even though only 1 of 2 is returned.
        self::assertContains('thallo:type:post', $out['cache_tags']);
    }

    public function testCategoryFilterRestrictsToMembersAndUnknownReturnsEmpty(): void
    {
        $cat = $this->publishPost($this->categoryType, ['title' => 'News', 'slug' => 'news'], 'news');
        $this->publishPost($this->postType, ['title' => 'In news', 'categories' => [$cat]], 'in-news');
        $this->publishPost($this->postType, ['title' => 'No category'], 'no-cat');

        $filtered = $this->reader()->list('post', ['limit' => 12, 'category' => 'news'], 'en');
        $titles = array_map(static fn(array $i): string => (string) ($i['fields']['title'] ?? ''), $filtered['items']);
        self::assertSame(['In news'], $titles);
        // Term tags rode along.
        self::assertContains('thallo:type:post', $filtered['cache_tags']);
        self::assertContains('thallo:entry:' . $cat, $filtered['cache_tags']);

        $unknown = $this->reader()->list('post', ['limit' => 12, 'category' => 'does-not-exist'], 'en');
        self::assertSame([], $unknown['items']);
        self::assertSame([], $unknown['cache_tags']);
    }

    // ── Seeding helpers (modeled on DeliveryFlowTest) ─────────────────────────

    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'list_' . substr($uuid, 0, 6),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /** @param array<string,mixed> $fields */
    private function publishPost(string $typeUuid, array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($typeUuid, 'en', 1, $this->userUuid);
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, $this->userUuid);
        (new RouteRepository($this->connection()))->assign($uuid, $typeUuid, 'en', $slug);
        $this->publishService()->publish($uuid, 'en', $this->userUuid);
        // The published-reference projection is normally driven by an event listener
        // (ProjectPublishedReferencesListener); drive it directly here so membership
        // filtering has data — mirrors ListingArchivePagesTest.
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($uuid, $typeUuid, 'en');
        return $uuid;
    }
}
