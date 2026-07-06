<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryShowQuery;
use App\Content\Http\DeliveryEtag;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Services\PublishService;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Validation\FieldValidator;
use App\Content\Repositories\VersionRepository;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Support\FieldSelection\Projector;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller-level coverage for the published-only delivery API.
 *
 * Drives {@see DeliveryController} directly with the real repositories (same pattern as
 * the admin EntryApiTest), publishing entries through the real {@see PublishService} so
 * the leak-proof read path is exercised end to end.
 */
final class DeliveryApiTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text'],
                ['name' => 'priority', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
            ],
        ]);
    }

    private function controller(LocaleManagerInterface $locales = new FakeLocaleManager()): DeliveryController
    {
        $repo = new DeliveryRepository($this->connection());
        $types = new ContentTypeRepository($this->connection());
        $routes = new RouteRepository($this->connection(), new RedirectRepository($this->connection()));
        $paths = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');
        return new DeliveryController(
            $this->appContext(),
            $repo,
            $types,
            $this->container()->get(FilterCompiler::class),
            new SortCompiler(),
            new ReferenceResolver($repo),
            new Projector(),
            new DeliveryEtag(),
            $locales,
            new RouteResolver(
                $repo,
                new RedirectRepository($this->connection()),
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
            ),
            new CanonicalProjector(
                $repo,
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
                'en',
            ),
        );
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

    /** Create + save a draft + publish an entry; returns its uuid. */
    private function publish(array $fields): string
    {
        return $this->publishInLocale('en', $fields);
    }

    /** Create + save a draft + publish an entry in a locale; returns its uuid. */
    private function publishInLocale(string $locale, array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, $locale, 1, 'user00000001');
        $entries->saveDraft($uuid, $locale, $fields, 1, 0, 'user00000001');
        $this->publishService()->publish($uuid, $locale, 'user00000001');
        return $uuid;
    }

    /** Create + save a draft but do NOT publish; returns its uuid. */
    private function draftOnly(array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    private function get(array $query = [], array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        return new Request($query, [], [], [], [], $server);
    }

    /** Hydrate the list-query DTO through the REAL framework hydrator (same source as the router). */
    private function listQuery(array $query = []): DeliveryListQuery
    {
        return (new RequestDataHydrator())->hydrate(DeliveryListQuery::class, [], [], $query);
    }

    /** Hydrate the show-query DTO through the REAL framework hydrator. */
    private function showQuery(array $query = []): DeliveryShowQuery
    {
        return (new RequestDataHydrator())->hydrate(DeliveryShowQuery::class, [], [], $query);
    }

    public function testIndexReturnsPublishedAndOmitsDraft(): void
    {
        $this->publish(['title' => 'Published one', 'priority' => 5]);
        $this->draftOnly(['title' => 'Draft hidden', 'priority' => 9]);

        $resp = $this->controller()->index($this->get(), $this->listQuery(), 'post');
        self::assertSame(200, $resp->getStatusCode());

        $items = json_decode($resp->getContent(), true)['data']['items'];
        $titles = array_column(array_column($items, 'fields'), 'title');
        self::assertContains('Published one', $titles);
        self::assertNotContains('Draft hidden', $titles);
    }

    public function testIndexUnknownTypeReturns404(): void
    {
        $resp = $this->controller()->index($this->get(), $this->listQuery(), 'nope');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testPresentationIsStrippedFromTheShowPayload(): void
    {
        // The contract (modern-default-theme spec §5a): _presentation is
        // draft/version state, NOT public content — the schema-allowlist
        // projection strips it from every delivery payload.
        $uuid = $this->publish([
            'title' => 'Presented',
            'priority' => 1,
            '_presentation' => ['show_title' => false, 'layout' => 'full'],
        ]);
        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertSame('Presented', $data['fields']['title']);
        self::assertArrayNotHasKey('_presentation', $data['fields']);
        self::assertStringNotContainsString('_presentation', (string) $resp->getContent());
    }

    public function testShowReturnsPublishedFields(): void
    {
        $uuid = $this->publish(['title' => 'Hello show', 'priority' => 1]);

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('Hello show', $data['fields']['title']);
    }

    public function testShowAddsSeoBlockForRoutedPublishedEntry(): void
    {
        $uuid = $this->publish(['title' => 'SEO show', 'priority' => 1]);
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'seo-show');

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', 'seo-show');
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode($resp->getContent(), true)['data'];
        // Canonical href collapses the default locale.
        self::assertSame('/post/seo-show', $data['seo']['canonical']['href']);
        self::assertSame('/post/seo-show', $data['seo']['x_default']['href']);
        self::assertSame(['en'], array_column($data['seo']['alternates'], 'locale'));
    }

    public function testShowReturnsRedirectDescriptorForMovedSlug(): void
    {
        $uuid = $this->publish(['title' => 'Moved', 'priority' => 1]);
        $routes = new RouteRepository($this->connection(), new RedirectRepository($this->connection()));
        $routes->assign($uuid, $this->type, 'en', 'old');
        $routes->assign($uuid, $this->type, 'en', 'new');

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', 'old');
        self::assertSame(200, $resp->getStatusCode());

        $body = json_decode($resp->getContent(), true);
        self::assertArrayHasKey('redirect', $body['data']);
        self::assertArrayNotHasKey('fields', $body['data']);
        // Redirect targets are CANONICAL: the default locale collapses (the
        // raw /en/... form was the pre-CanonicalPathBuilder off-canonical bug).
        self::assertSame('/post/new', $body['data']['redirect']['to']);
        self::assertSame(301, $body['data']['redirect']['status']);
        self::assertSame('max-age=60, public', $resp->headers->get('Cache-Control'));
        self::assertStringContainsString('lemma:entry:' . $uuid, (string) $resp->headers->get('Cache-Tag'));
    }

    public function testShowReturns404ForBrokenInternalRedirectTarget(): void
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'draft-target');
        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $this->type,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => $this->type,
            'target_locale' => 'en',
            'target_entry_uuid' => $uuid,
            'status' => 301,
            'origin' => 'manual',
        ]);

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', 'old');

        self::assertSame(404, $resp->getStatusCode());
    }

    public function testShowFallsBackThroughI18nLocaleChainByUuid(): void
    {
        $uuid = $this->publishInLocale('en', ['title' => 'English fallback', 'priority' => 1]);

        $resp = $this->controller(new FakeLocaleManager())->show(
            $this->get(['locale' => 'fr']),
            $this->showQuery(['locale' => 'fr']),
            'post',
            $uuid
        );

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('en', $data['locale']);
        self::assertSame('English fallback', $data['fields']['title']);
    }

    public function testShowFallsBackThroughI18nLocaleChainByRouteSlug(): void
    {
        $uuid = $this->publishInLocale('en', ['title' => 'English route fallback', 'priority' => 1]);
        $this->connection()->table('entry_routes')->insert([
            'entry_uuid' => $uuid,
            'content_type_uuid' => $this->type,
            'locale' => 'en',
            'slug' => 'hello',
        ]);

        $resp = $this->controller(new FakeLocaleManager())->show(
            $this->get(['locale' => 'fr']),
            $this->showQuery(['locale' => 'fr']),
            'post',
            'hello'
        );

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('en', $data['locale']);
        self::assertSame('English route fallback', $data['fields']['title']);
    }

    public function testShowUnpublishedReturns404(): void
    {
        $uuid = $this->draftOnly(['title' => 'Not yet']);
        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testFieldSelectionTrimsOutput(): void
    {
        $this->publish(['title' => 'Trim me', 'body' => 'long body text', 'priority' => 3]);

        $resp = $this->controller()->index(
            $this->get(['fields' => 'title']),
            $this->listQuery(['fields' => 'title']),
            'post'
        );
        $items = json_decode($resp->getContent(), true)['data']['items'];

        self::assertArrayHasKey('title', $items[0]['fields']);
        self::assertArrayNotHasKey('body', $items[0]['fields']);
    }

    public function testFilterByFilterableField(): void
    {
        $this->publish(['title' => 'Low', 'priority' => 1]);
        $this->publish(['title' => 'High', 'priority' => 100]);

        $resp = $this->controller()->index(
            $this->get(['filter' => ['priority' => ['gte' => '50']]]),
            $this->listQuery(['filter' => ['priority' => ['gte' => '50']]]),
            'post'
        );
        self::assertSame(200, $resp->getStatusCode());

        $items = json_decode($resp->getContent(), true)['data']['items'];
        $titles = array_column(array_column($items, 'fields'), 'title');
        self::assertSame(['High'], $titles);
    }

    public function testNonFilterableFilterReturns422(): void
    {
        $this->publish(['title' => 'X', 'priority' => 1]);

        $resp = $this->controller()->index(
            $this->get(['filter' => ['title' => ['eq' => 'X']]]),
            $this->listQuery(['filter' => ['title' => ['eq' => 'X']]]),
            'post'
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testEtagPresentAndMatchingIfNoneMatchReturns304(): void
    {
        $uuid = $this->publish(['title' => 'Etag me', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        $etag = $resp->headers->get('ETag');
        self::assertNotNull($etag);
        // Symfony normalizes Cache-Control directives alphabetically.
        self::assertSame('max-age=60, public', $resp->headers->get('Cache-Control'));

        $cond = $this->controller()->show(
            $this->get([], ['If-None-Match' => $etag]),
            $this->showQuery(),
            'post',
            $uuid
        );
        self::assertSame(304, $cond->getStatusCode());
        self::assertSame('', (string) $cond->getContent());
    }

    public function testPerContentTypeCacheTtlOverridesGlobalDeliveryTtl(): void
    {
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->type)
            ->update(['cache_ttl' => 300]);

        $uuid = $this->publish(['title' => 'Custom cache', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('max-age=300, public', $resp->headers->get('Cache-Control'));

        $etag = (string) $resp->headers->get('ETag');
        $cond = $this->controller()->show(
            $this->get([], ['If-None-Match' => $etag]),
            $this->showQuery(),
            'post',
            $uuid
        );

        self::assertSame(304, $cond->getStatusCode());
        self::assertSame('max-age=300, public', $cond->headers->get('Cache-Control'));
    }

    public function testPaginationBranchReturnsPaginatedEnvelope(): void
    {
        $this->publish(['title' => 'P1', 'priority' => 1]);
        $this->publish(['title' => 'P2', 'priority' => 2]);

        $resp = $this->controller()->index(
            $this->get(['page' => '1', 'perPage' => '1']),
            $this->listQuery(['page' => '1', 'perPage' => '1']),
            'post'
        );
        self::assertSame(200, $resp->getStatusCode());

        $body = json_decode($resp->getContent(), true);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['per_page']);
        self::assertCount(1, $body['data']);
    }

    /**
     * Drift guard: cursor-mode list payload matches DeliveryListData; each item
     * matches DeliveryItemData. No page/perPage — exercises the documented DTO shape.
     */
    public function testIndexCursorModeDtoShape(): void
    {
        $this->publish(['title' => 'Drift item', 'priority' => 1]);

        // Cursor mode: no page/perPage query params.
        $resp = $this->controller()->index($this->get(), $this->listQuery(), 'post');
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Delivery\DeliveryListData::class);
        foreach ($data['items'] as $item) {
            self::assertDataMatchesDtoShape($item, \App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData::class);
        }
    }

    /**
     * Drift guard: the single-entry show payload matches DeliveryShowItemData.
     */
    public function testShowDtoShape(): void
    {
        $uuid = $this->publish(['title' => 'Drift show', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), $this->showQuery(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Delivery\DeliveryShowItemData::class);
    }

    /**
     * Linchpin: the real framework hydrator preserves the nested bracket `filter` array
     * untouched and coerces page/perPage to ints — so the DTO parses identically to the
     * previous `$request->query` reads.
     */
    public function testListQueryHydratesBracketFilter(): void
    {
        $dto = $this->listQuery([
            'filter' => ['price' => ['gte' => '10']],
            'sort' => 'title:asc',
            'page' => '2',
            'perPage' => '5',
        ]);

        self::assertSame(['price' => ['gte' => '10']], $dto->filter);
        self::assertSame('title:asc', $dto->sort);
        self::assertSame(2, $dto->page);
        self::assertSame(5, $dto->perPage);
        self::assertTrue($dto->wantsPagination());

        $empty = $this->listQuery([]);
        self::assertSame([], $empty->filter);
        self::assertNull($empty->page);
        self::assertFalse($empty->wantsPagination());
    }

    /**
     * Regression: an array-valued `fields`/`expand` param on the public delivery path must not 500.
     * FieldSelector::fromRequest and selectionKey() (ETag time) both read these; InputBag::get()
     * throws BadRequestException on an array, so both must read via all() and treat it as absent.
     */
    public function testArrayValuedFieldsParamDoesNotThrowOnShow(): void
    {
        $uuid = $this->publish(['title' => 'Hello']);

        $resp = $this->controller()->show(
            $this->get(['fields' => ['title'], 'expand' => ['author']]),
            $this->showQuery(),
            'post',
            $uuid,
        );

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testArrayValuedFieldsParamDoesNotThrowOnIndex(): void
    {
        $this->publish(['title' => 'Hello']);

        $resp = $this->controller()->index(
            $this->get(['fields' => ['title'], 'sort' => ['title:asc']]),
            $this->listQuery(),
            'post',
        );

        self::assertSame(200, $resp->getStatusCode());
    }
}
