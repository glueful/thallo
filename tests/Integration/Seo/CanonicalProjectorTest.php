<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Seo;

use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Seo\CanonicalProjector;
use Thallo\Core\Content\Seo\PathRenderer;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;

final class CanonicalProjectorTest extends AppTestCase
{
    private ContentTypeRepository $types;
    private EntryRepository $entries;
    private RouteRepository $routes;
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->types = new ContentTypeRepository($this->connection());
        $this->entries = new EntryRepository($this->connection(), $this->appContext(), $this->types);
        $this->routes = new RouteRepository($this->connection());
        $this->type = $this->types->create([
            'slug' => 'blog',
            'name' => 'Blog',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    public function testProjectsCanonicalAlternatesAndXDefault(): void
    {
        $entry = $this->publish('en', 'hello', 'Hello');
        $this->publishExisting($entry, 'fr', 'bonjour', 'Bonjour');

        $seo = $this->projector()->project($entry, $this->type, 'blog', 'fr');

        self::assertSame('/fr/blog/bonjour', $seo['canonical']['href']);
        self::assertSame([
            // The en alternate is canonical too: default locale collapsed.
            ['locale' => 'en', 'href' => '/blog/hello', 'content_type' => 'blog', 'slug' => 'hello'],
            ['locale' => 'fr', 'href' => '/fr/blog/bonjour', 'content_type' => 'blog', 'slug' => 'bonjour'],
        ], $seo['alternates']);
        self::assertSame('/blog/hello', $seo['x_default']['href']);
    }

    public function testUnpublishedLocaleIsExcludedFromAlternates(): void
    {
        $entry = $this->publish('en', 'hello', 'Hello');
        $this->entries->createLocaleDraft($entry, 'de', 1, 'user00000001');
        $this->routes->assign($entry, $this->type, 'de', 'hallo');

        $seo = $this->projector()->project($entry, $this->type, 'blog', 'en');

        self::assertSame(['en'], array_column($seo['alternates'], 'locale'));
    }

    private function projector(): CanonicalProjector
    {
        return new CanonicalProjector(
            new DeliveryRepository($this->connection()),
            $this->routes,
            $this->types,
            new \Thallo\Core\Content\Seo\CanonicalPathBuilder(
                new PathRenderer('/{locale}/{type}/{slug}', null, 'en'),
                $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
            ),
            'en'
        );
    }

    private function publish(string $locale, string $slug, string $title): string
    {
        $entry = $this->entries->createEntry($this->type, $locale, 1, 'user00000001');
        $this->entries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->routes->assign($entry, $this->type, $locale, $slug);
        $this->publishService()->publish($entry, $locale, 'user00000001');

        return $entry;
    }

    private function publishExisting(string $entry, string $locale, string $slug, string $title): void
    {
        $this->entries->createLocaleDraft($entry, $locale, 1, 'user00000001');
        $this->entries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->routes->assign($entry, $this->type, $locale, $slug);
        $this->publishService()->publish($entry, $locale, 'user00000001');
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries,
            new VersionRepository($this->connection()),
            $this->types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }
}
