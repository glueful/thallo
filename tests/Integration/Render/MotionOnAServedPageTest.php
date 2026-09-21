<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Motion;

/**
 * MotionEmissionTest holds what finish() does; this holds that a page a visitor is actually
 * served has been through it — the flag is in the real theme's head, once, ahead of the block it
 * protects, and a page with no entrance is served without it.
 */
final class MotionOnAServedPageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    /** @param array<string,mixed> $style */
    private function publish(string $type, string $slug, array $style): void
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $types->create([
            'slug' => $type,
            'name' => ucfirst($type),
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entry = $entries->createEntry($uuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Motion',
            'body' => [[
                'id' => 'head00000001', 'type' => 'heading',
                'data' => ['text' => 'MOTION-MARKER', 'level' => 'h2'],
                'settings' => ['style' => $style],
            ]],
        ], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $uuid, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
    }

    public function testAServedPageCarriesTheFlagInItsHeadAheadOfTheBlock(): void
    {
        $this->publish('motiona', 'enters', ['motion' => ['entrance' => ['type' => 'choice', 'value' => 'fade-up']]]);
        $res = $this->handle(Request::create('/motiona/enters', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertSame(1, substr_count($html, Motion::tags()));
        $flag = strpos($html, Motion::tags());
        self::assertLessThan(stripos($html, '</head>'), $flag);
        self::assertSame(1, preg_match('~<h2[^>]*t-enter-fade-up[^>]*>MOTION-MARKER~', $html), $html);

        // Served again from the page cache, it is the same page.
        $again = (string) $this->handle(Request::create('/motiona/enters', 'GET'))->getContent();
        self::assertSame(1, substr_count($again, Motion::tags()));
    }

    public function testAServedPageWithNoEntranceCarriesNone(): void
    {
        $this->publish('motionb', 'still', []);
        $html = (string) $this->handle(Request::create('/motionb/still', 'GET'))->getContent();
        self::assertStringContainsString('MOTION-MARKER', $html);
        self::assertStringNotContainsString('block-motion.js', $html);
        self::assertStringNotContainsString('data-thallo-motion', $html);
    }
}
