<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\TemplatesAdminController;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /custom.css (custom-css spec §3): serves the ACTIVE theme's DB row with
 * immutable cache headers; absent or empty → 404. DB-only — the route never
 * touches theme directories.
 */
final class CustomCssServingTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function saveCss(string $source): void
    {
        $req = Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'source' => $source,
        ]));
        $req->attributes->set('user', ['uuid' => 'user00000001']);
        $res = $this->container()->get(TemplatesAdminController::class)->save($req, 'custom.css');
        self::assertSame(200, $res->getStatusCode());
    }

    public function testServesTheRowWithImmutableHeaders(): void
    {
        $this->saveCss('.lemma-block-hero { padding: 2rem; }');

        $res = $this->handle(Request::create('/custom.css?v=abc123', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        self::assertStringContainsString('.lemma-block-hero', (string) $res->getContent());
    }

    public function testMissingOrEmptyCustomCssIs404(): void
    {
        self::assertSame(404, $this->handle(Request::create('/custom.css', 'GET'))->getStatusCode());

        // An all-whitespace row is "disabled": still 404.
        $this->saveCss("  \n  ");
        self::assertSame(404, $this->handle(Request::create('/custom.css', 'GET'))->getStatusCode());
    }
}
