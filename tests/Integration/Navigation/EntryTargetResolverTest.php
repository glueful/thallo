<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\EntryTargetResolver;

final class EntryTargetResolverTest extends AppTestCase
{
    use SeedsPublishedContent;

    private function resolver(): EntryTargetResolver
    {
        return $this->container()->get(EntryTargetResolver::class);
    }

    public function testPublishedEntryResolvesToPath(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // blog/hello (en), blog/bonjour (fr)
        $r = $this->resolver()->resolve($entry, 'en');
        self::assertSame('published', $r['status']);
        self::assertStringContainsString('/blog/hello', (string) $r['path']);
    }

    public function testDraftOnlyEntryIsUnpublished(): void
    {
        $this->seedBilingualPublishedEntry();
        $entries = $this->container()->get(EntryRepository::class);
        $types = $this->container()->get(ContentTypeRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        $r = $this->resolver()->resolve($draft, 'en');
        self::assertSame('unpublished', $r['status']);
        self::assertNull($r['path']);
    }

    public function testSoftDeletedEntryIsDeleted(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->container()->get(EntryRepository::class)->softDelete($entry);
        self::assertSame('deleted', $this->resolver()->resolve($entry, 'en')['status']);
    }

    public function testUnknownEntryIsMissing(): void
    {
        self::assertSame('missing', $this->resolver()->resolve('nope00000000', 'en')['status']);
    }

    public function testPublishedWithoutRouteIsRouteless(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        // Remove the public route while the publication pin remains.
        $this->container()->get(RouteRepository::class)->remove($entry, 'en');

        $r = $this->resolver()->resolve($entry, 'en');
        self::assertSame('routeless', $r['status']);
        self::assertNull($r['path']);
    }
}
