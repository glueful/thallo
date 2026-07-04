<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;

/**
 * The root-mounted URL grammar (root-mounted-types spec §2/§4/§8): root hits,
 * locale behavior, type precedence, canonical 301s, rename redirects, and the
 * flag lifecycle — all through resolvePath, the real parser.
 * (phpunit.xml sets LEMMA_PUBLIC_URL_BASE=https://site.test.)
 */
final class RootMountResolutionTest extends LemmaTestCase
{
    private const BASE = 'https://site.test';

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    /** @return array{type:string, entry:string} a published root-mounted pages/about (en) + a-propos (fr) */
    private function seedRootPage(bool $public = true, bool $root = true): array
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $type = $types->create([
            'slug' => 'pages', 'name' => 'Pages',
            'public_delivery' => $public, 'mount_at_root' => $root,
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

    public function testRootEntryResolvesWithLocaleBehavior(): void
    {
        $this->seedRootPage();

        // Default locale at the bare root path.
        $hit = $this->resolver()->resolvePath('/about');
        self::assertSame('content', $hit['kind']);
        self::assertSame('pages', $hit['type']);
        self::assertSame('en', $hit['locale']);

        // Non-default locale keeps its prefix.
        $fr = $this->resolver()->resolvePath('/fr/a-propos');
        self::assertSame('content', $fr['kind']);
        self::assertSame('fr', $fr['locale']);
    }

    public function testTypePrecedenceBeatsRootEntries(): void
    {
        $this->seedRootPage();
        // Repositories bypass the write-time guard — simulating the raced/legacy
        // state the resolve-time precedence rule must still handle honestly.
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'about', 'name' => 'About Type', 'public_delivery' => true, 'schema' => [],
        ]);

        // /about now parses as the TYPE (listing; not allowlisted -> not_found),
        // never the root entry.
        self::assertSame('not_found', $this->resolver()->resolvePath('/about')['kind']);
    }

    public function testPrefixedPathCanonicalizes301ToRoot(): void
    {
        $this->seedRootPage();

        $result = $this->resolver()->resolvePath('/pages/about');
        self::assertSame('redirect', $result['kind']);
        self::assertSame(self::BASE . '/about', $result['redirect']['location']);
        self::assertSame(301, $result['redirect']['status']);

        $fr = $this->resolver()->resolvePath('/fr/pages/a-propos');
        self::assertSame('redirect', $fr['kind']);
        self::assertSame(self::BASE . '/fr/a-propos', $fr['redirect']['location']);
    }

    public function testRenameRedirectsWorkAtRootAndPrefixedInOneHop(): void
    {
        $seeded = $this->seedRootPage();
        $this->container()->get(RouteRepository::class)
            ->assign($seeded['entry'], $seeded['type'], 'en', 'team');

        // Old ROOT url -> new ROOT canonical.
        $root = $this->resolver()->resolvePath('/about');
        self::assertSame('redirect', $root['kind']);
        self::assertSame(self::BASE . '/team', $root['redirect']['location']);

        // Old PREFIXED url follows the redirect descriptor straight to the
        // root canonical of the NEW slug — one hop, never /pages/about -> /about.
        $prefixed = $this->resolver()->resolvePath('/pages/about');
        self::assertSame('redirect', $prefixed['kind']);
        self::assertSame(self::BASE . '/team', $prefixed['redirect']['location']);
    }

    public function testNonPublicRootTypeIs404AtRoot(): void
    {
        $this->seedRootPage(public: false);
        self::assertSame('not_found', $this->resolver()->resolvePath('/about')['kind']);
    }

    public function testFlagOffRestoresPrefixedGrammar(): void
    {
        $seeded = $this->seedRootPage();
        $this->container()->get(ContentTypeRepository::class)
            ->updateMeta($seeded['type'], ['mount_at_root' => false]);

        // Root path stops resolving; the prefixed canonical takes back over (no 301).
        self::assertSame('not_found', $this->resolver()->resolvePath('/about')['kind']);
        self::assertSame('content', $this->resolver()->resolvePath('/pages/about')['kind']);
    }

    public function testPreviewSessionOverlaysAtTheRootPath(): void
    {
        $seeded = $this->seedRootPage();
        // A newer draft on the published entry: the session's own entry shows
        // the draft at its canonical (root) URL.
        $this->container()->get(EntryRepository::class)
            ->saveDraft($seeded['entry'], 'en', ['title' => 'About v2 draft'], 1, 1, 'user00000001');

        $token = $this->container()->get(PreviewMinter::class)->mint($seeded['entry'], 'en');
        $session = $this->container()->get(PreviewSessionVerifier::class)->verify($token);
        self::assertNotNull($session);

        $hit = $this->resolver()->resolvePath('/about', $session);
        self::assertSame('content', $hit['kind']);
        self::assertTrue($hit['preview']);
        self::assertSame('About v2 draft', $hit['content']['fields']['title'] ?? null);
    }
}
