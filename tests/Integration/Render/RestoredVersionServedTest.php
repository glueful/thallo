<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Make live (a rollback) is a publish for the page cache: the live page serves the restored
 * version on the next request, not the one it replaced. Drives the real kernel, services and
 * listeners, so the cache purge is under test too.
 */
final class RestoredVersionServedTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    public function testTheLivePageServesARestoredVersion(): void
    {
        $entry = $this->seedBilingualPublishedEntry();                 // v1 "Hello", published
        $publish = $this->container()->get(PublishService::class);
        $entries = $this->container()->get(EntryRepository::class);
        $versions = new VersionRepository($this->connection());

        $page = fn (): string => (string) $this->handle(Request::create('/blog/hello'))->getContent();
        self::assertStringContainsString('Hello', $page());

        $lock = (int) $this->connection()->table('entry_drafts')
            ->where('entry_uuid', $entry)->where('locale', 'en')->first()['lock_version'];
        $entries->saveDraft($entry, 'en', ['title' => 'Edited title'], 1, $lock, 'user00000001');
        $publish->publish($entry, 'en', 'user00000001');                // v2 "Edited title"
        self::assertStringContainsString('Edited title', $page());

        $list = $versions->versionsFor($entry, 'en');
        $v1 = array_values(array_filter($list, static fn (array $v): bool => (int) $v['version'] === 1))[0]['uuid'];
        $publish->rollback($entry, 'en', (string) $v1, 'user00000001');

        $html = $page();
        self::assertStringNotContainsString('Edited title', $html);
        self::assertStringContainsString('Hello', $html);
    }
}
