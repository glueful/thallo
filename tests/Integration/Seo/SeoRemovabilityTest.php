<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves thallo-seo is cleanly disable-able: with lemma.seo disabled, the Task-4/5/6 boot
 * gate skips loadRoutesFrom() entirely, so every SEO surface (public meta, sitemap, robots,
 * admin meta) returns 404 — route unregistered, not a live-but-disabled handler.
 */
final class SeoRemovabilityTest extends AppTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$disabledApp ??= self::bootAppWithConfigOverride('lemma', [
            'capabilities' => ['lemma.seo' => false],
        ]);
    }

    private function hit(string $method, string $path): int
    {
        return (new Application(self::$disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();
    }

    public function testPublicMetaRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/v1/seo/meta/blog/hello'));
    }

    public function testSitemapRoutesAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/sitemap.xml'));
        self::assertSame(404, $this->hit('GET', '/sitemap/1.xml'));
    }

    public function testRobotsRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/robots.txt'));
    }

    public function testAdminMetaRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/v1/admin/seo/meta/e-1'));
    }
}
