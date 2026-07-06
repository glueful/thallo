<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\RouteRepository;
use App\Content\Seo\RedirectRepository;
use App\Tests\Support\AppTestCase;

final class RouteRepositoryTest extends AppTestCase
{
    private function repo(): RouteRepository
    {
        return new RouteRepository($this->connection());
    }

    private function seoRepo(): RouteRepository
    {
        return new RouteRepository($this->connection(), new RedirectRepository($this->connection()));
    }

    public function testAssignThenLookup(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        $row = $this->repo()->findBySlug('type0000001', 'en', 'hello');
        self::assertSame('entry0000001', $row['entry_uuid']);
    }

    public function testDuplicateSlugInSameTypeLocaleRejected(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        self::assertFalse($this->repo()->isSlugAvailable('type0000001', 'en', 'hello'));
        self::assertTrue($this->repo()->isSlugAvailable('type0000001', 'fr', 'hello'));
    }

    public function testChangingSlugCreatesAutoRedirectToCurrentEntry(): void
    {
        $repo = $this->seoRepo();
        $redirects = new RedirectRepository($this->connection());

        $repo->assign('entry0000001', 'type0000001', 'en', 'old');
        $repo->assign('entry0000001', 'type0000001', 'en', 'new');

        $redirect = $redirects->findBySource('type0000001', 'en', 'old');

        self::assertNotNull($redirect);
        self::assertSame('entry0000001', $redirect['target_entry_uuid']);
        self::assertSame('type0000001', $redirect['target_content_type_uuid']);
        self::assertSame('en', $redirect['target_locale']);
        self::assertSame(301, $redirect['status']);
        self::assertSame('auto', $redirect['origin']);
    }

    public function testRepeatedSlugChangesDoNotCreateChains(): void
    {
        $repo = $this->seoRepo();
        $redirects = new RedirectRepository($this->connection());

        $repo->assign('entry0000001', 'type0000001', 'en', 'a');
        $repo->assign('entry0000001', 'type0000001', 'en', 'b');
        $repo->assign('entry0000001', 'type0000001', 'en', 'c');

        self::assertSame('entry0000001', $redirects->findBySource('type0000001', 'en', 'a')['target_entry_uuid']);
        self::assertSame('entry0000001', $redirects->findBySource('type0000001', 'en', 'b')['target_entry_uuid']);
        self::assertNull($redirects->findBySource('type0000001', 'en', 'c'));
    }

    public function testLiveSlugClearsPriorRedirectSource(): void
    {
        $repo = $this->seoRepo();
        $redirects = new RedirectRepository($this->connection());
        $redirects->create([
            'content_type_uuid' => 'type0000001',
            'locale' => 'en',
            'source_slug' => 'new',
            'target_url' => '/elsewhere',
            'status' => 301,
            'origin' => 'manual',
        ]);

        $repo->assign('entry0000001', 'type0000001', 'en', 'old');
        $repo->assign('entry0000001', 'type0000001', 'en', 'new');

        self::assertNull($redirects->findBySource('type0000001', 'en', 'new'));
    }
}
