<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A single post in the default theme (entry/post.twig): its categories, title, date and lead
 * above the body, and below it the newest other posts and the way back to them all. Category
 * and listing links appear only where those pages exist — posts listed, the field archived — so
 * the page never links to a 404.
 *
 * Suite env: RENDER_LISTING_TYPES=blog,post.
 */
final class PostPageTest extends AppTestCase
{
    private string $postType;
    private string $catType;

    protected function setUp(): void
    {
        parent::setUp();
        $types = new ContentTypeRepository($this->connection());
        $this->catType = $types->create([
            'slug' => 'category', 'name' => 'Categories', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
            ],
        ]);
        $this->postType = $types->create(['slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'body', 'type' => 'blocks', 'required' => true],
            ['name' => 'categories', 'type' => 'reference', 'multiple' => true, 'filterable' => true,
                'reference_type' => 'category', 'reference_slug_field' => 'slug'],
        ]]);
        $this->publish('catnews00001', $this->catType, ['title' => 'News', 'slug' => 'news'], '2026-06-01 00:00:00');
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    /** @param array<string,mixed> $fields */
    private function publish(string $uuid, string $type, array $fields, string $at, ?string $slug = null): void
    {
        $db = $this->connection();
        $version = 'v' . substr($uuid, 1);
        $db->table('entries')->insert(['uuid' => $uuid, 'content_type_uuid' => $type, 'status' => 'active',
            'created_at' => $at, 'updated_at' => $at]);
        $db->table('entry_versions')->insert(['uuid' => $version, 'entry_uuid' => $uuid, 'locale' => 'en',
            'version' => 1, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'schema_version' => 1,
            'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en',
            'version_uuid' => $version, 'published_at' => $at]);
        if ($slug !== null) {
            (new RouteRepository($db))->assign($uuid, $type, 'en', $slug);
            $this->container()->get(PublishedReferenceRepository::class)->projectFromPublished($uuid, $type, 'en');
        }
    }

    private function post(string $uuid, string $title, string $at, array $extra = []): void
    {
        $this->publish($uuid, $this->postType, $extra + [
            'title' => $title,
            'body' => [['id' => $uuid . 'b', 'type' => 'rich_text', 'data' => ['body' => "<p>{$title} body.</p>"],
                'settings' => []]],
        ], $at, strtolower(str_replace(' ', '-', $title)));
    }

    private function get(string $path): string
    {
        $res = $this->handle(Request::create($path, 'GET'));
        self::assertSame(200, $res->getStatusCode(), $path);
        return (string) $res->getContent();
    }

    public function testAPostHasItsCategoriesTitleDateAndLeadAboveItsBody(): void
    {
        $this->post('posthello001', 'Hello world', '2026-06-02 09:30:00', [
            'excerpt' => 'A short line about it.',
            'categories' => ['catnews00001'],
        ]);
        $html = $this->get('/post/hello-world');

        self::assertStringContainsString('<article class="post"', $html);
        self::assertMatchesRegularExpression('~<h1 class="post__title">Hello world</h1>~', $html);
        self::assertStringContainsString('<time class="post__date" datetime="2026-06-02">June 2, 2026</time>', $html);
        self::assertStringContainsString('<p class="post__lead">A short line about it.</p>', $html);
        self::assertStringContainsString('<a class="post__category" href="/post/categories/news">News</a>', $html);
        self::assertStringContainsString('Hello world body.', $html);
        // No cover set: no empty figure.
        self::assertStringNotContainsString('post__cover', $html);
        // The way back to every post.
        self::assertStringContainsString('<a class="post__all" href="/post">', $html);
    }

    public function testBelowItTheNewestOtherPostsNeverItself(): void
    {
        $this->post('postold00001', 'Oldest', '2026-05-01 09:00:00');
        $this->post('postmid00001', 'Middle', '2026-05-10 09:00:00');
        $this->post('postnew00001', 'Newest', '2026-05-20 09:00:00');
        $this->post('postlast0001', 'Latest', '2026-05-30 09:00:00');
        $this->post('posthere0001', 'This one', '2026-06-02 09:00:00');

        $html = $this->get('/post/this-one');
        $more = substr($html, (int) strpos($html, 'post__more'));
        self::assertStringContainsString('More posts', $more);
        self::assertStringNotContainsString('This one', $more);
        foreach (['Latest', 'Newest', 'Middle'] as $title) {
            self::assertStringContainsString($title, $more);
        }
        self::assertStringNotContainsString('Oldest', $more, 'three at most');
        self::assertLessThan(strpos($more, 'Middle'), strpos($more, 'Latest'), 'newest first');
    }

    public function testWherePostsAreNotListedNothingLinksToAPageThatDoesNotExist(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['blog']]);
        $this->post('postalone001', 'Alone', '2026-06-02 09:30:00', ['categories' => ['catnews00001']]);
        $html = $this->get('/post/alone');

        self::assertStringContainsString('<span class="post__category">News</span>', $html);
        self::assertStringNotContainsString('/post/categories/', $html);
        self::assertStringNotContainsString('post__all', $html);
    }
}
