<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\DTOs\CreateContentTypeData;
use App\Content\Http\DTOs\UpdateContentTypeSchemaData;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeApiTest extends AppTestCase
{
    private function controller(): ContentTypeController
    {
        // Resolve from the container so the autowired QueueManager + ApplicationContext
        // (used to enqueue the filter-index reconciliation job) are injected.
        return $this->container()->get(ContentTypeController::class);
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it. (Lemma DTOs use only built-in rules, so no registry.)
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    public function testStoreCreatesType(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'post', 'name' => 'Post',
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        self::assertSame(201, $resp->getStatusCode());
        self::assertNotNull((new ContentTypeRepository($this->connection()))->findBySlug('post'));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape(
            $data,
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData::class
        );
        self::assertDataMatchesDtoShape(
            $data['content_type'],
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeData::class
        );
        foreach ($data['content_type']['schema'] as $field) {
            self::assertDataMatchesDtoShape(
                $field,
                \App\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData::class,
                exact: false   // toArray() omits falsy keys
            );
        }
    }

    public function testIndexListsTypes(): void
    {
        // Seed one content type so the schema loop has data.
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'article', 'name' => 'Article',
                'schema' => [['name' => 'headline', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        $resp = $this->controller()->index(new Request());
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape(
            $data,
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeListData::class
        );
        self::assertNotEmpty($data['content_types']);
        foreach ($data['content_types'] as $item) {
            self::assertDataMatchesDtoShape(
                $item,
                \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeData::class
            );
        }
    }

    public function testStorePersistsOptionalDeliveryCacheTtl(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'cached_page',
                'name' => 'Cached Page',
                'cache_ttl' => 300,
                'public_delivery' => true,
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );

        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getContent());

        $data = json_decode((string) $resp->getContent(), true)['data']['content_type'];
        self::assertSame(300, $data['cache_ttl']);
        self::assertTrue($data['public_delivery']);
        self::assertSame(
            300,
            (new ContentTypeRepository($this->connection()))->findBySlug('cached_page')['cache_ttl']
        );
        $row = (new ContentTypeRepository($this->connection()))->findBySlug('cached_page');
        self::assertTrue($row['public_delivery']);
    }

    public function testStoreRejectsBadSchema(): void
    {
        // Structural hydration passes (each field def has name + type); the semantic rule
        // (a filterable field must declare filter_type) is the domain SchemaParseException → 422.
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'post', 'name' => 'Post',
                'schema' => [['name' => 'price', 'type' => 'number', 'filterable' => true]], // missing filter_type
            ]),
            new Request(),
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testStoreRejectsBadSlugAtHydration(): void
    {
        // Structural validation now lives in the DTO: a non-lowercase slug fails hydration
        // with a standard 422 before the controller runs.
        $this->expectException(ValidationException::class);
        $this->hydrate(CreateContentTypeData::class, ['slug' => 'Bad Slug', 'name' => 'X', 'schema' => []]);
    }

    public function testShowNotFound(): void
    {
        self::assertSame(404, $this->controller()->show(new Request(), 'nope')->getStatusCode());
    }

    public function testShowReturnsType(): void
    {
        // Seed a content type with a schema field so the drift assertion covers both layers.
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'page', 'name' => 'Page',
                'schema' => [['name' => 'body', 'type' => 'text', 'required' => true]],
            ]),
            new Request(),
        );
        $resp = $this->controller()->show(new Request(), 'page');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape(
            $data,
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData::class
        );
        self::assertDataMatchesDtoShape(
            $data['content_type'],
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeData::class
        );
        foreach ($data['content_type']['schema'] as $field) {
            self::assertDataMatchesDtoShape(
                $field,
                \App\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData::class,
                exact: false
            );
        }
    }

    public function testUpdateMetaFlipsPublicDelivery(): void
    {
        // The headline gap: public_delivery was creation-only — no path to make
        // an existing type publicly deliverable.
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'landing', 'name' => 'Landing',
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        $resp = $this->controller()->update(
            $this->hydrate(\App\Content\Http\DTOs\UpdateContentTypeData::class, [
                'public_delivery' => true,
                'description' => 'Now public',
            ]),
            new Request(),
            'landing',
        );
        self::assertSame(200, $resp->getStatusCode());
        $type = json_decode((string) $resp->getContent(), true)['data']['content_type'];
        self::assertTrue($type['public_delivery']);
        self::assertSame('Now public', $type['description']);
        self::assertSame('landing', $type['slug']); // slug immutable, untouched

        // Empty name -> 422; unknown slug -> 404.
        $bad = $this->controller()->update(
            $this->hydrate(\App\Content\Http\DTOs\UpdateContentTypeData::class, ['name' => '  ']),
            new Request(),
            'landing',
        );
        self::assertSame(422, $bad->getStatusCode());
        $missing = $this->controller()->update(
            $this->hydrate(\App\Content\Http\DTOs\UpdateContentTypeData::class, ['public_delivery' => true]),
            new Request(),
            'nope',
        );
        self::assertSame(404, $missing->getStatusCode());
    }

    public function testMountAtRootRoundTripsThroughCreateAndPatch(): void
    {
        // Create with the flag on, hydrated rows carry it, PATCH flips it off.
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'pages', 'name' => 'Pages',
                'public_delivery' => true,
                'mount_at_root' => true,
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getContent());
        $type = json_decode((string) $resp->getContent(), true)['data']['content_type'];
        self::assertTrue($type['mount_at_root']);
        $row = (new ContentTypeRepository($this->connection()))->findBySlug('pages');
        self::assertTrue($row['mount_at_root']);

        $resp = $this->controller()->update(
            $this->hydrate(\App\Content\Http\DTOs\UpdateContentTypeData::class, ['mount_at_root' => false]),
            new Request(),
            'pages',
        );
        self::assertSame(200, $resp->getStatusCode());
        $type = json_decode((string) $resp->getContent(), true)['data']['content_type'];
        self::assertFalse($type['mount_at_root']);
        self::assertTrue($type['public_delivery']); // untouched by the partial PATCH
    }

    public function testUpdateSchemaReturnsType(): void
    {
        // Seed a content type, then replace its schema, and assert the 200 payload shape.
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'event', 'name' => 'Event',
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        $resp = $this->controller()->updateSchema(
            $this->hydrate(UpdateContentTypeSchemaData::class, [
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'starts_at', 'type' => 'datetime'],
                ],
            ]),
            new Request(),
            'event',
        );
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape(
            $data,
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData::class
        );
        self::assertDataMatchesDtoShape(
            $data['content_type'],
            \App\Content\Http\DTOs\Responses\ContentTypes\ContentTypeData::class
        );
        foreach ($data['content_type']['schema'] as $field) {
            self::assertDataMatchesDtoShape(
                $field,
                \App\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData::class,
                exact: false
            );
        }
    }

    public function testUpdateSchemaRejectsDestructiveChangesUntilBackfilled(): void
    {
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'product',
                'name' => 'Product',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'price', 'type' => 'number'],
                ],
            ]),
            new Request(),
        );

        $resp = $this->controller()->updateSchema(
            $this->hydrate(UpdateContentTypeSchemaData::class, [
                'schema' => [
                    ['name' => 'title', 'type' => 'text', 'required' => true],
                ],
            ]),
            new Request(),
            'product',
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertStringContainsString('destructive', (string) $resp->getContent());
    }

    public function testDestroySoftDeletesContentType(): void
    {
        $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'old_page', 'name' => 'Old Page', 'schema' => [],
            ]),
            new Request(),
        );

        $resp = $this->controller()->destroy(new Request(), 'old_page');

        self::assertSame(200, $resp->getStatusCode());
        self::assertNull((new ContentTypeRepository($this->connection()))->findBySlug('old_page'));
    }
}
