<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\IconInventoryController;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class IconInventoryApiTest extends AppTestCase
{
    private function controller(): IconInventoryController
    {
        return $this->container()->get(IconInventoryController::class);
    }

    public function testLucideInventoryMatchesTheVendoredDirectory(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'lucide']));
        self::assertSame(200, $resp->getStatusCode());
        $icons = json_decode((string) $resp->getContent(), true)['data']['icons'];
        self::assertContains('activity', $icons);
        self::assertGreaterThan(1500, count($icons));
        // Glob parity with the vendored directory, not a pinned literal.
        $dir = $this->appContext()->getBasePath()
            . '/packages/thallo-render/resources/icons/lucide/*.svg';
        self::assertCount(count(glob($dir) ?: []), $icons);
        $sorted = $icons;
        sort($sorted);
        self::assertSame($sorted, $icons);
    }

    public function testBrandsInventoryIsTheCuratedSetBareNames(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'brands']));
        $icons = json_decode((string) $resp->getContent(), true)['data']['icons'];
        self::assertContains('github', $icons);
        self::assertNotContains('brand:github', $icons); // BARE names
        self::assertCount(27, $icons);
    }

    public function testIncludeSvgShipsTheVendoredMarkupForPreviews(): void
    {
        $resp = $this->controller()->index(
            Request::create('/x', 'GET', ['set' => 'brands', 'include' => 'svg']),
        );
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertArrayHasKey('svgs', $data);
        self::assertStringStartsWith('<svg', $data['svgs']['github']);
        self::assertStringContainsString('fill="currentColor"', $data['svgs']['github']);
        // Without the flag, no svg payload.
        $plain = json_decode((string) $this->controller()->index(
            Request::create('/x', 'GET', ['set' => 'brands']),
        )->getContent(), true)['data'];
        self::assertArrayNotHasKey('svgs', $plain);
    }

    public function testUnknownSetIs422(): void
    {
        $resp = $this->controller()->index(Request::create('/x', 'GET', ['set' => 'emoji']));
        self::assertSame(422, $resp->getStatusCode());
    }
}
