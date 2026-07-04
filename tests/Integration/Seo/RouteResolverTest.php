<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

final class RouteResolverTest extends LemmaTestCase
{
    private ContentTypeRepository $types;
    private EntryRepository $entries;
    private RouteRepository $routes;
    private RedirectRepository $redirects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->types = new ContentTypeRepository($this->connection());
        $this->entries = new EntryRepository($this->connection(), $this->appContext(), $this->types);
        $this->routes = new RouteRepository($this->connection());
        $this->redirects = new RedirectRepository($this->connection());
    }

    public function testLiveRouteWinsOverRedirect(): void
    {
        $type = $this->type('blog');
        $entry = $this->publish($type, 'en', 'old', 'Current');
        $this->redirects->create([
            'content_type_uuid' => $type,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_url' => '/other',
            'status' => 301,
            'origin' => 'manual',
        ]);

        $result = $this->resolver()->resolve($type, 'blog', ['en'], 'old');

        self::assertTrue($result->isContent());
        self::assertSame($entry, $result->content()['entry_uuid']);
    }

    public function testRedirectMatchesRequestedLocaleOnly(): void
    {
        $type = $this->type('blog');
        $this->redirects->create([
            'content_type_uuid' => $type,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_url' => '/en/blog/new',
            'status' => 301,
            'origin' => 'manual',
        ]);

        self::assertNull($this->resolver()->resolve($type, 'blog', ['fr', 'en'], 'old'));
    }

    public function testInternalRedirectCanTargetAnotherTypeAndLocale(): void
    {
        $source = $this->type('blog');
        $target = $this->type('docs');
        $entry = $this->publish($target, 'fr', 'nouveau', 'Target');
        $this->redirects->create([
            'content_type_uuid' => $source,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => $target,
            'target_locale' => 'fr',
            'target_entry_uuid' => $entry,
            'status' => 308,
            'origin' => 'manual',
        ]);

        $result = $this->resolver()->resolve($source, 'blog', ['en'], 'old');

        self::assertTrue($result->isRedirect());
        self::assertSame('/fr/docs/nouveau', $result->redirect()['to']);
        self::assertSame(308, $result->redirect()['status']);
        self::assertSame('live', $result->redirect()['target_state']);
        self::assertSame([
            'content_type' => 'docs',
            'locale' => 'fr',
            'slug' => 'nouveau',
        ], $result->redirect()['target']);
    }

    public function testUnpublishedInternalTargetIsGone(): void
    {
        $source = $this->type('blog');
        $target = $this->type('docs');
        $entry = $this->entries->createEntry($target, 'fr', 1, 'user00000001');
        $this->routes->assign($entry, $target, 'fr', 'draft-only');
        $this->redirects->create([
            'content_type_uuid' => $source,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => $target,
            'target_locale' => 'fr',
            'target_entry_uuid' => $entry,
            'status' => 301,
            'origin' => 'manual',
        ]);

        $result = $this->resolver()->resolve($source, 'blog', ['en'], 'old');

        self::assertTrue($result->isGone());
        self::assertSame('broken', $result->redirect()['target_state']);
    }

    public function testExternalRedirectIsTerminal(): void
    {
        $type = $this->type('blog');
        $this->redirects->create([
            'content_type_uuid' => $type,
            'locale' => 'en',
            'source_slug' => 'old',
            'target_url' => 'https://example.com/new',
            'status' => 302,
            'origin' => 'manual',
        ]);

        $result = $this->resolver()->resolve($type, 'blog', ['en'], 'old');

        self::assertTrue($result->isRedirect());
        self::assertSame('https://example.com/new', $result->redirect()['to']);
        self::assertTrue($result->redirect()['external']);
    }

    public function testNanoidFallbackResolvesPublishedEntry(): void
    {
        $type = $this->type('blog');
        $entry = $this->publish($type, 'en', 'hello', 'Hello');

        $result = $this->resolver()->resolve($type, 'blog', ['en'], $entry);

        self::assertTrue($result->isContent());
        self::assertSame('Hello', $result->content()['fields']['title']);
    }

    private function resolver(): RouteResolver
    {
        return new RouteResolver(
            new DeliveryRepository($this->connection()),
            $this->redirects,
            $this->routes,
            $this->types,
            new \App\Content\Seo\CanonicalPathBuilder(
                new PathRenderer('/{locale}/{type}/{slug}'),
                $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
            )
        );
    }

    private function type(string $slug): string
    {
        return $this->types->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function publish(string $type, string $locale, string $slug, string $title): string
    {
        $entry = $this->entries->createEntry($type, $locale, 1, 'user00000001');
        $this->entries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->routes->assign($entry, $type, $locale, $slug);
        (new PublishService(
            $this->appContext(),
            $this->entries,
            new VersionRepository($this->connection()),
            $this->types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');

        return $entry;
    }
}
