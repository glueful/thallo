<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\PublishedPageDirectory;

/**
 * The app's {@see PublishedPageDirectory}: published, publicly-delivered pages as SITE-RELATIVE
 * canonical paths, one per page (default locale), for the account redirect picker. (phpunit.xml
 * sets PUBLIC_URL_BASE=https://site.test, so the builder yields absolute canonical URLs and the
 * bridge must reduce them to paths.)
 */
final class PublishedPageDirectoryTest extends AppTestCase
{
    private function directory(): PublishedPageDirectory
    {
        return $this->container()->get(PublishedPageDirectory::class);
    }

    /** Seed a published, root-mounted `pages` entry: `about` (en) + `a-propos` (fr). */
    private function seedPage(bool $public = true, bool $root = true): void
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
    }

    public function testListsAPublishedPublicPageAsASiteRelativeDefaultLocalePath(): void
    {
        $this->seedPage();

        $pages = $this->directory()->publicPages();
        $paths = array_column($pages, 'path');

        // Site-relative (no https://site.test host), default-locale (en) canonical path.
        self::assertContains('/about', $paths);
        foreach ($paths as $path) {
            self::assertStringStartsWith('/', $path, 'a suggestion must be site-relative');
            self::assertStringNotContainsString('://', $path, 'never an absolute URL');
        }
        // Deduped to the default locale — the fr variant is not a separate suggestion.
        self::assertNotContains('/fr/a-propos', $paths);
    }

    public function testExcludesANonPublicType(): void
    {
        $this->seedPage(public: false);

        self::assertNotContains(
            '/about',
            array_column($this->directory()->publicPages(), 'path'),
            'a type without public_delivery is never a published page',
        );
    }
}
