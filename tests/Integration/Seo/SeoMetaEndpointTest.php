<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Seo\Http\Controllers\AdminSeoMetaController;
use Thallo\Seo\Http\Controllers\SeoMetaController;
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
                'lemma_permission:seo.manage',
                (array) ($route['middleware'] ?? []),
                "admin meta {$method} must require seo.manage",
            );
        }
    }
}
