<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Http\Controllers\RedirectController;
use App\Content\Http\DTOs\CreateRedirectData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Seo\RedirectRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RedirectApiTest extends AppTestCase
{
    private ContentTypeRepository $types;
    private EntryRepository $entries;
    private RouteRepository $routes;
    private RedirectRepository $redirects;
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->types = new ContentTypeRepository($this->connection());
        $this->entries = new EntryRepository($this->connection(), $this->appContext(), $this->types);
        $this->redirects = new RedirectRepository($this->connection());
        $this->routes = new RouteRepository($this->connection(), $this->redirects);
        $this->type = $this->types->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    public function testCreateExternalRedirect(): void
    {
        $resp = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['url' => 'https://example.com/new'], 302),
            new Request(),
            'post'
        );

        self::assertSame(201, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data']['redirect'];
        self::assertSame('old', $data['source_slug']);
        self::assertSame('https://example.com/new', $data['target_url']);
        self::assertSame(302, $data['status']);
    }

    public function testCreateRejectsUnsafeUrl(): void
    {
        $resp = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['url' => 'javascript:alert(1)'], 301),
            new Request(),
            'post'
        );

        self::assertSame(422, $resp->getStatusCode());
    }

    public function testCreateRejectsSourceThatCollidesWithLiveRoute(): void
    {
        $entry = $this->draft('en');
        $this->routes->assign($entry, $this->type, 'en', 'live');

        $resp = $this->controller()->store(
            new CreateRedirectData('en', 'live', ['url' => '/other'], 301),
            new Request(),
            'post'
        );

        self::assertSame(409, $resp->getStatusCode());
    }

    public function testCreateRejectsInternalTargetWithNoRouteForTriple(): void
    {
        $entry = $this->draft('en');

        $resp = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['entry_uuid' => $entry], 301),
            new Request(),
            'post'
        );

        self::assertSame(422, $resp->getStatusCode());
    }

    public function testCreateAllowsUnpublishedRoutedTargetAsBrokenInList(): void
    {
        $entry = $this->draft('en');
        $this->routes->assign($entry, $this->type, 'en', 'target');

        $created = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['entry_uuid' => $entry], 301),
            new Request(),
            'post'
        );
        self::assertSame(201, $created->getStatusCode());

        $list = $this->controller()->index(new Request(), 'post');
        $data = json_decode((string) $list->getContent(), true)['data']['redirects'];

        self::assertSame('broken', $data[0]['target_state']);
    }

    public function testCreateInternalRedirectToPublishedTargetIsLive(): void
    {
        $entry = $this->publish('en', 'target');

        $resp = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['entry_uuid' => $entry], 308),
            new Request(),
            'post'
        );

        self::assertSame(201, $resp->getStatusCode());
        $redirect = json_decode((string) $resp->getContent(), true)['data']['redirect'];
        self::assertSame('live', $redirect['target_state']);
        self::assertSame('target', $redirect['target']['slug']);
    }

    public function testDeleteRemovesRedirect(): void
    {
        $created = $this->controller()->store(
            new CreateRedirectData('en', 'old', ['url' => '/new'], 301),
            new Request(),
            'post'
        );
        $uuid = json_decode((string) $created->getContent(), true)['data']['redirect']['uuid'];

        $resp = $this->controller()->destroy(new Request(), $uuid);

        self::assertSame(200, $resp->getStatusCode());
        self::assertNull($this->redirects->findByUuid($uuid));
    }

    private function controller(): RedirectController
    {
        return new RedirectController(
            $this->types,
            $this->redirects,
            $this->routes,
            new DeliveryRepository($this->connection())
        );
    }

    private function draft(string $locale): string
    {
        return $this->entries->createEntry($this->type, $locale, 1, 'user00000001');
    }

    private function publish(string $locale, string $slug): string
    {
        $entry = $this->draft($locale);
        $this->entries->saveDraft($entry, $locale, ['title' => 'Target'], 1, 0, 'user00000001');
        $this->routes->assign($entry, $this->type, $locale, $slug);
        (new PublishService(
            $this->appContext(),
            $this->entries,
            new VersionRepository($this->connection()),
            $this->types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');

        return $entry;
    }
}
