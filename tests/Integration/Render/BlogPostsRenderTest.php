<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

final class BlogPostsRenderTest extends AppTestCase
{
    private string $userUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        $this->userUuid = $this->seedUser();
    }

    protected function tearDown(): void
    {
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        // Annotation mode is a per-render flag on a shared singleton — reset it so a
        // preview test cannot leak annotation into a later public render.
        $this->container()->get(RenderContextExtension::class)->setBlockAnnotations(false);
        parent::tearDown();
    }

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list, ?string $regionSlug = null): string
    {
        // The shared per-render reset every real render boundary performs — the
        // priority-image claim must never leak between tests on the singleton.
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(list) }}')
            ->render(['list' => $list, 'region_slug' => $regionSlug]);
    }

    public function testBlogPostsEmptyRendersPlaceholderOnlyInPreview(): void
    {
        // No posts seeded → entries('post') is empty. Public render (annotateBlocks
        // false by default) shows nothing; the block still emits its root so CSS
        // binds, but no __empty placeholder and no __card.
        $out = $this->render([[
            'id' => 'bp1', 'type' => 'blog_posts',
            'data' => ['type' => 'post', 'limit' => 3, 'columns' => '3'],
        ]]);
        self::assertStringContainsString('thallo-block-blog_posts', $out);
        self::assertStringContainsString('thallo-block-blog_posts--columns-3', $out);
        self::assertStringNotContainsString('thallo-block-blog_posts__card', $out);
        self::assertStringNotContainsString('thallo-block-blog_posts__empty', $out);
    }

    public function testEmptyShowsPlaceholderUnderPreviewAnnotation(): void
    {
        $this->container()->get(RenderContextExtension::class)->setBlockAnnotations(true);
        // Unknown type → entries() gate-fails to []; preview mode shows the placeholder.
        $out = $this->render([[
            'id' => 'bp2', 'type' => 'blog_posts',
            'data' => ['type' => 'does-not-exist', 'limit' => 3],
        ]]);
        self::assertStringContainsString('thallo-block-blog_posts__empty', $out);
        self::assertStringContainsString('No posts found.', $out);
        self::assertStringNotContainsString('thallo-block-blog_posts__card', $out);
    }

    public function testPublishedPostsRenderAsCards(): void
    {
        $this->createPostType();
        // One post WITH a cover, one WITHOUT — the coverless card omits __image.
        // The cover is a REAL public image blob: the card resolves media_image()
        // first and only renders __image when it resolves (storefront-performance
        // spec §3 — an unresolvable cover omits the element entirely).
        $cover = Utils::generateNanoID();
        $this->seedPublicImageBlob($cover);
        $this->publishPost(['title' => 'With cover', 'excerpt' => 'Has an image.',
            'cover' => $cover], 'with-cover');
        $this->publishPost(['title' => 'No cover', 'excerpt' => 'Text only.'], 'no-cover');

        $out = $this->render([[
            'id' => 'bp3', 'type' => 'blog_posts',
            'data' => ['type' => 'post', 'limit' => 12, 'columns' => '3'],
        ]]);

        self::assertSame(2, substr_count($out, 'thallo-block-blog_posts__card'));
        // Exactly one card carries an image (the covered post).
        self::assertSame(1, substr_count($out, 'thallo-block-blog_posts__image'));
        // Titles, canonical hrefs, and dates are present.
        self::assertStringContainsString('With cover', $out);
        self::assertStringContainsString('No cover', $out);
        self::assertStringContainsString('/post/with-cover"', $out);
        self::assertStringContainsString('thallo-block-blog_posts__date', $out);
    }

    public function testNestedBlogPostsInHeaderRegionNeverClaimsPriority(): void
    {
        // Region validation constrains only top-level palette entries, so a nested
        // blog_posts block can still reach blog_posts.twig inside a region render.
        // The card macro's ISOLATED context must not erase the region ancestry —
        // region_slug is threaded as a macro argument so the needs_context claim
        // helper still sees the header region (storefront-performance spec §4).
        $this->createPostType();
        $cover = Utils::generateNanoID();
        $this->seedPublicImageBlob($cover);
        $this->publishPost(['title' => 'Nested cover', 'cover' => $cover], 'nested-cover');

        $out = $this->render([[
            'id' => 'container-region',
            'type' => 'container',
            'data' => ['content' => [[
                'id' => 'nested-posts',
                'type' => 'blog_posts',
                'data' => ['type' => 'post', 'limit' => 1],
            ]]],
        ]], regionSlug: 'header');

        self::assertStringContainsString('thallo-block-blog_posts__image', $out);
        self::assertStringNotContainsString('fetchpriority', $out);
        self::assertStringContainsString('loading="lazy"', $out);
    }

    // ── Seeding helpers (modeled on EntryListReaderTest) ──────────────────────

    /** A public, active image blob — the shape MediaVariantUrlResolverTest seeds. */
    private function seedPublicImageBlob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'blog-cover-' . $uuid,
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function createPostType(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text'],
                ['name' => 'cover', 'type' => 'asset'],
            ],
        ]);
    }

    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'blog_' . substr($uuid, 0, 6),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /** @param array<string,mixed> $fields */
    private function publishPost(array $fields, string $slug): string
    {
        $typeUuid = (string) (new ContentTypeRepository($this->connection()))->findBySlug('post')['uuid'];
        $entries = $this->entries();
        $uuid = $entries->createEntry($typeUuid, 'en', 1, $this->userUuid);
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, $this->userUuid);
        (new RouteRepository($this->connection()))->assign($uuid, $typeUuid, 'en', $slug);
        $this->publishService()->publish($uuid, 'en', $this->userUuid);
        return $uuid;
    }
}
