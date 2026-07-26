<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\ThalloCanonicalPublicOriginResolver;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\CanonicalPathBuilder;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\EngineSeoHeadProvider;
use App\Content\Seo\PathRenderer;
use App\Settings\SettingsStore;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Seo\Meta\SeoMetaRepository;
use Thallo\Seo\Meta\SeoMetaResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 4 (seo-head spec §2): {@see EngineSeoHeadProvider} composes the pack
 * {@see SeoMetaResolver} + {@see CanonicalProjector} + the trusted-origin resolver into
 * the SeoHeadResolver wire shape. The origin fixture mirrors
 * {@see \App\Tests\Integration\Content\Delivery\CanonicalPublicOriginResolverTest}'s
 * single-store idiom: a fresh context with `app.urls.base` merged, read by the REAL
 * {@see ThalloCanonicalPublicOriginResolver} (enforcement off) — a non-absolute base makes
 * it throw, which the provider must fail-soft to "no absolute URLs at all".
 */
final class SeoHeadProviderTest extends AppTestCase
{
    use SeedsPublishedContent;

    // Distinct from the env's PUBLIC_URL_BASE (https://site.test) so an accidental
    // already-absolute projector href would surface as a visible doubled host.
    private const ORIGIN = 'https://origin.test';

    public function testComposesFullHeadWithAbsoluteUrls(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // blog/hello (en) + blog/bonjour (fr)
        $this->metaRepo()->upsert($entry, 'en', ['description' => 'Curated description']);

        $head = $this->provider(self::ORIGIN)->headFor($entry, 'en');

        self::assertNotNull($head);
        self::assertSame($this->expectedTemplatedTitle('Hello'), $head['title']);
        self::assertSame('Curated description', $head['description']);

        // Canonical/alternates/x_default are exactly the projector's RELATIVE hrefs
        // absolutized against the configured origin.
        $projected = $this->projector()->project($entry, $this->seedType, 'blog', 'en');
        self::assertSame(self::ORIGIN . $projected['canonical']['href'], $head['canonical']);
        self::assertSame(
            array_map(
                static fn (array $alt): array => [
                    'locale' => (string) $alt['locale'],
                    'href' => self::ORIGIN . $alt['href'],
                ],
                $projected['alternates'],
            ),
            $head['alternates'],
        );
        self::assertSame(self::ORIGIN . $projected['x_default']['href'], $head['x_default']);
        foreach ($head['alternates'] as $alt) {
            self::assertStringStartsWith(self::ORIGIN . '/', $alt['href']);
        }

        self::assertSame($head['canonical'], $head['og']['url']);
        self::assertSame('article', $head['og']['type']);
        self::assertNull($head['twitter_card'], 'twitter_card only when explicitly overridden');
        self::assertSame('index', $head['robots']);
    }

    public function testBlankOriginOmitsUrlBearingKeysButKeepsText(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->metaRepo()->upsert($entry, 'en', ['description' => 'Curated description']);

        $head = $this->provider(null)->headFor($entry, 'en');

        self::assertNotNull($head);
        self::assertNull($head['canonical']);
        self::assertSame([], $head['alternates']);
        self::assertNull($head['x_default']);
        self::assertNull($head['og']['url']);
        self::assertSame($this->expectedTemplatedTitle('Hello'), $head['title']);
        self::assertSame('Curated description', $head['description']);
    }

    public function testRelativeDefaultOgImageIsAbsolutized(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $meta = $this->metaResolverWithDefaults(['default_og_image' => '/media/x.png']);

        $head = $this->provider(self::ORIGIN, $meta)->headFor($entry, 'en');
        self::assertNotNull($head);
        self::assertSame(self::ORIGIN . '/media/x.png', $head['og']['image']);

        // An absolute og_image override passes through untouched.
        $this->metaRepo()->upsert($entry, 'en', ['og_image' => 'https://cdn.example/pic.png']);
        $head = $this->provider(self::ORIGIN, $meta)->headFor($entry, 'en');
        self::assertNotNull($head);
        self::assertSame('https://cdn.example/pic.png', $head['og']['image']);
    }

    public function testHomepageEntryGetsRootCanonicalAtBothIdentities(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        // The DB site setting is the homepage source (homepage-setting spec §0) — write it
        // the way the render pipeline's homepage tests do.
        $this->container()->get(SettingsStore::class)->putMany(['homepage_entry' => $entry]);

        $head = $this->provider(self::ORIGIN)->headFor($entry, 'en');

        self::assertNotNull($head);
        self::assertSame(self::ORIGIN . '/', $head['canonical']);
        self::assertSame(self::ORIGIN . '/', $head['og']['url']);
        self::assertSame([], $head['alternates']);
        self::assertNull($head['x_default']);
        self::assertSame('website', $head['og']['type']);

        // The homepage check is entry-level, not per-locale: the fr variant is the
        // homepage too.
        $fr = $this->provider(self::ORIGIN)->headFor($entry, 'fr');
        self::assertNotNull($fr);
        self::assertSame(self::ORIGIN . '/', $fr['canonical']);
        self::assertSame('website', $fr['og']['type']);
    }

    public function testUnroutedOrUnpublishedReturnsNull(): void
    {
        $entry = $this->seedBilingualPublishedEntry();

        // (a) no route row for the locale — never independently rendered.
        self::assertNull($this->provider(self::ORIGIN)->headFor($entry, 'de'));

        // (b) routed but unpublished variant.
        $this->seedEntries->createLocaleDraft($entry, 'de', 1, 'user00000001');
        $this->seedRoutes->assign($entry, $this->seedType, 'de', 'hallo');
        self::assertNull($this->provider(self::ORIGIN)->headFor($entry, 'de'));
    }

    public function testExplicitOgTitleOverrideWins(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->metaRepo()->upsert($entry, 'en', ['og_title' => 'Social!']);

        $head = $this->provider(self::ORIGIN)->headFor($entry, 'en');

        self::assertNotNull($head);
        self::assertSame('Social!', $head['og']['title']);
        self::assertSame($this->expectedTemplatedTitle('Hello'), $head['title']);
    }

    public function testContainerBindsSeoHeadResolverToTheEngineProvider(): void
    {
        self::assertInstanceOf(
            EngineSeoHeadProvider::class,
            $this->container()->get(SeoHeadResolver::class),
        );
    }

    // ---- fixtures --------------------------------------------------------------------

    /**
     * The provider under test, with the origin fixture. A null $base configures a
     * non-absolute `app.urls.base`, which makes the real origin resolver THROW — the
     * provider must fail-soft to the no-absolute-URLs posture.
     */
    private function provider(?string $base, ?SeoMetaResolver $meta = null): EngineSeoHeadProvider
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing');
        $context->setContainer($this->container());
        $context->mergeConfigDefaults('app', [
            'urls' => ['base' => $base ?? 'not-an-absolute-url'],
        ]);

        return new EngineSeoHeadProvider(
            $context,
            $meta ?? $this->container()->get(SeoMetaResolver::class),
            $this->projector(),
            new ThalloCanonicalPublicOriginResolver(new SystemFlags($context), null, null, null),
            $this->container()->get(HomepageEntryProvider::class),
            $this->container()->get(RouteRepository::class),
            $this->container()->get(ContentTypeRepository::class),
        );
    }

    /**
     * A projector over the container repos with a RELATIVE PathRenderer (the
     * CanonicalProjectorTest idiom): the provider's contract premise is relative
     * projector hrefs (production leaves `thallo.seo.public_url_base` unset — the
     * origin resolver is the ONE absolute-URL source), while this suite's phpunit
     * env sets PUBLIC_URL_BASE for the sitemap tests.
     */
    private function projector(): CanonicalProjector
    {
        return new CanonicalProjector(
            new DeliveryRepository($this->connection()),
            $this->container()->get(RouteRepository::class),
            $this->container()->get(ContentTypeRepository::class),
            new CanonicalPathBuilder(
                new PathRenderer('/{locale}/{type}/{slug}', null, 'en'),
                $this->container()->get(LocaleManagerInterface::class),
            ),
            'en',
        );
    }

    /**
     * A pack resolver over the SAME container reader/repo with adjusted site defaults
     * (the SeoMetaEndpointTest mapped-title idiom) — the test env configures no
     * default_og_image.
     *
     * @param array<string,string> $overrides
     */
    private function metaResolverWithDefaults(array $overrides): SeoMetaResolver
    {
        $repo = $this->container()->get(SeoMetaRepository::class);
        return new SeoMetaResolver(
            $this->container()->get(ContentDeliveryReader::class),
            static fn (string $entryUuid, string $locale): ?array => $repo->find($entryUuid, $locale),
            fallbacks: [],
            defaults: array_merge($this->seoDefaults(), $overrides),
        );
    }

    private function metaRepo(): SeoMetaRepository
    {
        return $this->container()->get(SeoMetaRepository::class);
    }

    /**
     * The env's SEO defaults, normalized exactly like SeoServiceProvider::makeSeoMetaResolver().
     *
     * @return array{site_name:string,default_og_image:string,title_template:string}
     */
    private function seoDefaults(): array
    {
        $defaults = (array) config($this->appContext(), 'seo.defaults', []);
        return [
            'site_name' => (string) ($defaults['site_name'] ?? 'Thallo'),
            'default_og_image' => (string) ($defaults['default_og_image'] ?? ''),
            'title_template' => (string) ($defaults['title_template'] ?? '{title} — {site_name}'),
        ];
    }

    private function expectedTemplatedTitle(string $title): string
    {
        $defaults = $this->seoDefaults();
        return strtr($defaults['title_template'], [
            '{title}' => $title,
            '{site_name}' => $defaults['site_name'],
        ]);
    }
}
