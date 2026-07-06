<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo\Concerns;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;

/**
 * Seeds a `blog` content type with one entry published in `en` (hello) and `fr` (bonjour).
 * Requires the host TestCase to provide connection()/appContext() (AppTestCase does).
 */
trait SeedsPublishedContent
{
    private ContentTypeRepository $seedTypes;
    private EntryRepository $seedEntries;
    private RouteRepository $seedRoutes;
    private string $seedType;

    /** @return string the entry uuid (published in en + fr). */
    protected function seedBilingualPublishedEntry(): string
    {
        $this->seedTypes = new ContentTypeRepository($this->connection());
        $this->seedEntries = new EntryRepository($this->connection(), $this->appContext(), $this->seedTypes);
        $this->seedRoutes = new RouteRepository($this->connection());
        $this->seedType = $this->seedTypes->create([
            'slug' => 'blog',
            'name' => 'Blog',
            // Blog is publicly delivered — the realistic case for a type that appears in the
            // sitemap and exposes public SEO meta (both are anonymous surfaces).
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);

        $entry = $this->publishLocale('en', 'hello', 'Hello');
        $this->publishExistingLocale($entry, 'fr', 'bonjour', 'Bonjour');
        return $entry;
    }

    private function publishLocale(string $locale, string $slug, string $title): string
    {
        $entry = $this->seedEntries->createEntry($this->seedType, $locale, 1, 'user00000001');
        $this->seedEntries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->seedRoutes->assign($entry, $this->seedType, $locale, $slug);
        $this->publishSvc()->publish($entry, $locale, 'user00000001');
        return $entry;
    }

    private function publishExistingLocale(string $entry, string $locale, string $slug, string $title): void
    {
        $this->seedEntries->createLocaleDraft($entry, $locale, 1, 'user00000001');
        $this->seedEntries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->seedRoutes->assign($entry, $this->seedType, $locale, $slug);
        $this->publishSvc()->publish($entry, $locale, 'user00000001');
    }

    /**
     * Seed a content type with the given public_delivery flag and one published entry, returning
     * the entry uuid. Used to exercise visibility gating (public vs non-public) on the anonymous
     * sitemap and SEO-meta surfaces.
     */
    protected function seedPublishedEntryInType(
        string $typeSlug,
        bool $publicDelivery,
        string $locale,
        string $routeSlug,
        string $title,
    ): string {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $type = $types->create([
            'slug' => $typeSlug,
            'name' => ucfirst($typeSlug),
            'public_delivery' => $publicDelivery,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entry = $entries->createEntry($type, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $type, $locale, $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');
        return $entry;
    }

    private function publishSvc(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->seedEntries,
            new VersionRepository($this->connection()),
            $this->seedTypes,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }
}
