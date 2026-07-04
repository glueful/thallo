<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Services\PublishService;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;

/**
 * Every canonical href surface, root-collapsed for a mount_at_root type AND
 * unchanged for a prefixed type IN THE SAME RUN (root-mounted-types spec §5/§8):
 * nav targets, sitemap, search index, SEO canonical + hreflang alternates.
 * All surfaces go through the one CanonicalPathBuilder — this test is the
 * drift alarm. (phpunit.xml sets LEMMA_PUBLIC_URL_BASE=https://site.test.)
 *
 * Mixed-type hreflang alternates are NOT representable in fixtures — an
 * entry's publication pins always carry its own type — so the per-pin flag
 * lookup is exercised with same-type pins (en + fr of the root type).
 */
final class RootMountCanonicalSurfacesTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    private const BASE = 'https://site.test';

    /** @return array{type:string, entry:string} bilingual published root-mounted pages/about */
    private function seedRootPage(): array
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $type = $types->create([
            'slug' => 'pages', 'name' => 'Pages',
            'public_delivery' => true, 'mount_at_root' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $routes = $this->container()->get(RouteRepository::class);
        $publish = $this->container()->get(PublishService::class);

        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'About'], 1, 0, 'user00000001');
        $routes->assign($entry, $type, 'en', 'about');
        $publish->publish($entry, 'en', 'user00000001');

        $entries->createLocaleDraft($entry, 'fr', 1, 'user00000001');
        $entries->saveDraft($entry, 'fr', ['title' => 'À propos'], 1, 0, 'user00000001');
        $routes->assign($entry, $type, 'fr', 'a-propos');
        $publish->publish($entry, 'fr', 'user00000001');

        return ['type' => $type, 'entry' => $entry];
    }

    public function testAllHrefSurfacesAgreeOnRootAndPrefixedCanonicals(): void
    {
        $root = $this->seedRootPage();
        $blogEntry = $this->seedBilingualPublishedEntry(); // prefixed 'blog' type, en+fr

        // 1. Nav/menu target paths.
        $targets = $this->container()->get(EntryTargetResolver::class);
        self::assertSame(self::BASE . '/about', $targets->resolve($root['entry'], 'en')['path']);
        self::assertSame(self::BASE . '/fr/a-propos', $targets->resolve($root['entry'], 'fr')['path']);
        self::assertSame(self::BASE . '/blog/hello', $targets->resolve($blogEntry, 'en')['path']);

        // 2. Sitemap enumeration.
        $sitemap = $this->container()->get(ContentDeliveryReader::class)
            ->enumeratePublishedForSitemap(1000, 0);
        $hrefs = array_column($sitemap['items'], 'href');
        self::assertContains(self::BASE . '/about', $hrefs);
        self::assertContains(self::BASE . '/fr/a-propos', $hrefs);
        self::assertContains(self::BASE . '/blog/hello', $hrefs);
        self::assertNotContains(self::BASE . '/pages/about', $hrefs);
        self::assertNotContains(self::BASE . '/en/pages/about', $hrefs);

        // 3. Search index hrefs.
        $search = $this->container()->get(IndexableContentReader::class);
        self::assertSame(
            self::BASE . '/about',
            $search->getIndexablePublished($root['entry'], 'en')?->href,
        );
        self::assertSame(
            self::BASE . '/blog/hello',
            $search->getIndexablePublished($blogEntry, 'en')?->href,
        );

        // 4. SEO canonical + hreflang alternates (per-pin flag lookup).
        $types = $this->container()->get(ContentTypeRepository::class);
        $projector = $this->container()->get(CanonicalProjector::class);
        $seo = $projector->project($root['entry'], $root['type'], 'pages', 'en');
        self::assertSame(self::BASE . '/about', $seo['canonical']['href']);
        self::assertSame(self::BASE . '/about', $seo['x_default']['href']);
        $byLocale = array_column($seo['alternates'], 'href', 'locale');
        self::assertSame(self::BASE . '/about', $byLocale['en']);
        self::assertSame(self::BASE . '/fr/a-propos', $byLocale['fr']);

        // The prefixed type's projection is untouched in the same run.
        $blogType = (string) $types->findBySlug('blog')['uuid'];
        $blogSeo = $projector->project($blogEntry, $blogType, 'blog', 'en');
        self::assertSame(self::BASE . '/blog/hello', $blogSeo['canonical']['href']);
        self::assertSame(self::BASE . '/fr/blog/bonjour', array_column($blogSeo['alternates'], 'href', 'locale')['fr']);
    }
}
