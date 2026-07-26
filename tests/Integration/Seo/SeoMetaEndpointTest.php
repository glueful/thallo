<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Seo\Http\Controllers\AdminSeoMetaController;
use Thallo\Seo\Http\Controllers\SeoMetaController;
use Thallo\Seo\Meta\SeoMetaRepository;
use Thallo\Seo\Meta\SeoMetaResolver;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class SeoMetaEndpointTest extends AppTestCase
{
    use SeedsPublishedContent;

    public function testCapabilityAndTableExist(): void
    {
        // CapabilityRegistry declares register()/all()/enabled()/isEnabled() — no find().
        // The pack is enabled by default in the test env (config/extensions.php), so
        // isEnabled() being true also proves it was registered.
        $registry = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($registry->isEnabled('thallo.seo'), 'thallo.seo registered + enabled');

        $table = $this->connection()->getPDO()
            ->query("SELECT to_regclass('public.seo_meta')")->fetchColumn();
        self::assertNotNull($table, 'seo_meta table exists after migrations');
    }

    public function testAdministratorIsGrantedSeoManage(): void
    {
        $granted = $this->connection()->getPDO()->query(
            "SELECT COUNT(*) FROM role_permissions rp
               JOIN roles r ON r.uuid = rp.role_uuid
               JOIN permissions p ON p.uuid = rp.permission_uuid
              WHERE r.slug = 'administrator' AND p.slug = 'seo.manage'"
        )->fetchColumn();
        self::assertSame(1, (int) $granted, 'administrator holds seo.manage');
    }

    public function testPublicMetaReturnsResolvedFields(): void
    {
        $this->seedBilingualPublishedEntry(); // blog/hello (en)
        $controller = $this->container()->get(SeoMetaController::class);

        $resp = $controller->show(new Request(['locale' => 'en']), 'blog', 'hello');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertSame('index', $data['robots']);
        self::assertNotEmpty($data['title']);
        self::assertArrayHasKey('og', $data);
        self::assertArrayHasKey('twitter', $data);
    }

    public function testPublicMetaUnknownTypeIs404(): void
    {
        $controller = $this->container()->get(SeoMetaController::class);
        $resp = $controller->show(new Request(), 'nope', 'hello');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testPublicMetaForNonPublicTypeIs404(): void
    {
        // A published entry of a NON-public type must not leak its SEO meta on this anonymous
        // endpoint — it 404s exactly like an unknown type (no existence disclosure).
        $this->seedPublishedEntryInType('secret-doc', false, 'en', 'classified', 'Classified');

        $controller = $this->container()->get(SeoMetaController::class);
        $resp = $controller->show(new Request(['locale' => 'en']), 'secret-doc', 'classified');
        self::assertSame(404, $resp->getStatusCode(), 'non-public type must not expose SEO meta');
    }

    public function testPublicMetaUnknownSlugIs404(): void
    {
        $this->seedBilingualPublishedEntry();
        $controller = $this->container()->get(SeoMetaController::class);
        $resp = $controller->show(new Request(['locale' => 'en']), 'blog', 'does-not-exist');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testAdminUpsertRoundTrips(): void
    {
        $controller = $this->container()->get(AdminSeoMetaController::class);

        $put = Request::create(
            '/v1/admin/seo/meta/e-1?locale=en',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['title' => 'Custom', 'robots' => 'noindex']),
        );
        $resp = $controller->update($put, 'e-1');
        self::assertSame(200, $resp->getStatusCode());

        $get = $controller->show(new Request(['locale' => 'en']), 'e-1');
        $data = json_decode((string) $get->getContent(), true)['data'];
        self::assertSame('Custom', $data['title']);
        self::assertSame('noindex', $data['robots']);
    }

    public function testAdminUpsertRejectsInvalidBodyWith422(): void
    {
        $controller = $this->container()->get(AdminSeoMetaController::class);

        // Each previously reached the database and surfaced as a driver-level 500.
        $bad = [
            'array title' => ['locale' => 'en', 'body' => ['title' => ['not', 'a', 'string']]],
            'overlong title' => ['locale' => 'en', 'body' => ['title' => str_repeat('x', 256)]],
            'unknown robots' => ['locale' => 'en', 'body' => ['robots' => 'follow-me']],
            'unknown twitter card' => ['locale' => 'en', 'body' => ['twitter_card' => 'gallery']],
            'overlong locale' => ['locale' => 'much-too-long-locale', 'body' => ['title' => 'ok']],
        ];
        foreach ($bad as $label => $case) {
            $put = Request::create(
                '/v1/admin/seo/meta/e-1?locale=' . $case['locale'],
                'PUT',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode($case['body']),
            );
            try {
                $controller->update($put, 'e-1');
                self::fail("{$label}: expected ValidationException");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAdminUpsertPartialUpdatePreservesOtherFields(): void
    {
        $controller = $this->container()->get(AdminSeoMetaController::class);
        $put = function (array $body) use ($controller): void {
            $req = Request::create(
                '/v1/admin/seo/meta/e-partial?locale=en',
                'PUT',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode($body),
            );
            self::assertSame(200, $controller->update($req, 'e-partial')->getStatusCode());
        };

        $put(['title' => 'Custom', 'robots' => 'noindex']);
        $put(['og_title' => 'OG only']); // second write hits the ON CONFLICT update path

        $data = json_decode(
            (string) $controller->show(new Request(['locale' => 'en']), 'e-partial')->getContent(),
            true,
        )['data'];
        self::assertSame('Custom', $data['title'], 'absent key must not be touched');
        self::assertSame('noindex', $data['robots']);
        self::assertSame('OG only', $data['og_title']);
    }

    public function testEmptyStringOgOverrideFallsBackToResolvedTitle(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $admin = $this->container()->get(AdminSeoMetaController::class);
        $put = Request::create(
            "/v1/admin/seo/meta/{$entry}?locale=en",
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['title' => 'Custom', 'og_title' => '']),
        );
        self::assertSame(200, $admin->update($put, $entry)->getStatusCode());

        $resp = $this->container()->get(SeoMetaController::class)
            ->show(new Request(['locale' => 'en']), 'blog', 'hello');
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertSame('Custom', $data['og']['title'], "empty-string og_title must fall back, not emit ''");
    }

    public function testUnmappedTypeDerivesTitleFromTheTitleFieldNotBareSiteName(): void
    {
        // 'post' has NO seo.fallbacks entry (the test env maps no types at all) — the
        // conventional `title` field must feed the templated title, not the bare site name.
        $this->seedPublishedEntryInType('post', true, 'en', 'hello-post', 'Hello Post');

        $resp = $this->container()->get(SeoMetaController::class)
            ->show(new Request(['locale' => 'en']), 'post', 'hello-post');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];

        self::assertSame($this->expectedTemplatedTitle('Hello Post'), $data['title']);
        self::assertNotSame(
            $this->seoDefaults()['site_name'],
            $data['title'],
            'unmapped type must not fall straight to the bare site name',
        );
    }

    public function testUnmappedTypeWithoutTitleFieldStillFallsToSiteName(): void
    {
        // Unmapped type whose schema carries no `title` field at all → site name (unchanged).
        $this->seedPublishedEntryWithSchema(
            'note',
            [['name' => 'body', 'type' => 'string', 'required' => true]],
            ['body' => 'A note without any title field'],
            'en',
            'just-a-note',
        );

        $resp = $this->container()->get(SeoMetaController::class)
            ->show(new Request(['locale' => 'en']), 'note', 'just-a-note');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];

        self::assertSame($this->seoDefaults()['site_name'], $data['title']);
    }

    public function testMappedTitleFieldStillWinsOverTheTitleConvention(): void
    {
        $this->seedPublishedEntryWithSchema(
            'press',
            [
                ['name' => 'headline', 'type' => 'string', 'required' => true],
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
            ['headline' => 'Big Scoop', 'title' => 'Conventional Title'],
            'en',
            'big-scoop',
        );

        // The shared resolver is wired from config (no fallbacks in the test env); build one
        // with a mapped title_field over the SAME container reader/repo to prove the mapping
        // still outranks the `title` convention through the endpoint.
        $repo = $this->container()->get(SeoMetaRepository::class);
        $resolver = new SeoMetaResolver(
            $this->container()->get(ContentDeliveryReader::class),
            static fn (string $entryUuid, string $locale): ?array => $repo->find($entryUuid, $locale),
            fallbacks: ['press' => ['title_field' => 'headline']],
            defaults: $this->seoDefaults(),
        );
        $controller = new SeoMetaController($resolver, $this->container()->get(ContentTypeReader::class));

        $resp = $controller->show(new Request(['locale' => 'en']), 'press', 'big-scoop');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];

        self::assertSame($this->expectedTemplatedTitle('Big Scoop'), $data['title']);
    }

    public function testPublicMetaRouteIsRegistered(): void
    {
        self::assertNotNull(
            $this->findRoute('GET', '/v1/seo/meta/{type}/{slug}'),
            'public meta route must be registered',
        );
    }

    public function testAdminMetaRoutesRequireSeoManage(): void
    {
        foreach (['GET', 'PUT'] as $method) {
            $route = $this->findRoute($method, '/v1/admin/seo/meta/{entryUuid}');
            self::assertNotNull($route, "admin meta {$method} route must be registered");
            self::assertContains(
                'content_permission:seo.manage',
                (array) ($route['middleware'] ?? []),
                "admin meta {$method} must require seo.manage",
            );
        }
    }

    /**
     * The env's SEO defaults, normalized exactly like SeoServiceProvider::makeSeoMetaResolver().
     *
     * @return array{site_name:string,default_og_image:string,title_template:string}
     */
    private function seoDefaults(): array
    {
        $defaults = (array) config($this->appContext(), 'seo.defaults', []);
        return [
            'site_name' => (string) ($defaults['site_name'] ?? 'Thallo'),
            'default_og_image' => (string) ($defaults['default_og_image'] ?? ''),
            'title_template' => (string) ($defaults['title_template'] ?? '{title} — {site_name}'),
        ];
    }

    private function expectedTemplatedTitle(string $title): string
    {
        $defaults = $this->seoDefaults();
        return strtr($defaults['title_template'], [
            '{title}' => $title,
            '{site_name}' => $defaults['site_name'],
        ]);
    }

    /**
     * Seed a published public-delivery type with a CUSTOM schema + field values (the shared
     * concern's helpers hardcode a `title` field; the title-convention cases need types with
     * and without one). Returns the entry uuid.
     *
     * @param list<array<string,mixed>> $schema
     * @param array<string,mixed>       $fields
     */
    private function seedPublishedEntryWithSchema(
        string $typeSlug,
        array $schema,
        array $fields,
        string $locale,
        string $routeSlug,
    ): string {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $type = $types->create([
            'slug' => $typeSlug,
            'name' => ucfirst($typeSlug),
            'public_delivery' => true,
            'schema' => $schema,
        ]);
        $entry = $entries->createEntry($type, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $type, $locale, $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');
        return $entry;
    }
}
