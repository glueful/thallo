<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\TemplatesAdminController;

/**
 * The admin's theme gallery: the themes endpoint describes each selectable theme, and the site
 * serves a theme's screenshot — that one file and nothing else of a theme, only for a theme an
 * operator could switch to.
 */
final class ThemeGalleryApiTest extends AppTestCase
{
    public function testTheThemesEndpointDescribesEachSelectableTheme(): void
    {
        $res = $this->container()->get(TemplatesAdminController::class)->themes();
        $data = ((array) json_decode((string) $res->getContent(), true))['data'];

        self::assertSame('default', $data['active']);
        self::assertContains('default', $data['themes'], 'the names the Theme editor switches between');
        $cards = array_column($data['cards'], null, 'name');
        self::assertSame(array_values($data['themes']), array_keys($cards), 'a card for each, in the same order');
        self::assertSame('Default', $cards['default']['title']);
        self::assertStringStartsWith('/_thallo/theme-screenshot/default?v=', $cards['default']['screenshot_url']);
    }

    public function testTheSiteServesASelectableThemesScreenshot(): void
    {
        $res = $this->handle(Request::create('/_thallo/theme-screenshot/default', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('image/jpeg', $res->headers->get('Content-Type'));
        self::assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        // The gallery asks for it by a URL versioned with the file's mtime, so it caches hard.
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        self::assertStringStartsWith("\xFF\xD8", (string) $res->getContent(), 'a JPEG');
    }

    public function testNothingElseIsServedFromIt(): void
    {
        foreach (['nope', 'default.json', '..%2Fdefault', 'DEFAULT%00'] as $name) {
            $res = $this->handle(Request::create('/_thallo/theme-screenshot/' . $name, 'GET'));
            self::assertSame(404, $res->getStatusCode(), $name);
        }
    }
}
