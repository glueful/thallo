<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Helpers\Utils;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Field blocks (type layouts spec §4): each shows the current entry's data — title, date, cover,
 * excerpt, terms, any field, its own content, its neighbours, related entries — and holds none of
 * it. An empty field is nothing on the site and a named placeholder on the layout's stage.
 */
final class FieldBlocksRenderTest extends AppTestCase
{
    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    protected function tearDown(): void
    {
        $this->extension()->setAnnotationScope('none');
        parent::tearDown();
    }

    /** @param array<string,mixed> $data */
    private static function block(string $type, array $data, string $id = 'fieldblock01'): array
    {
        return ['id' => $id, 'type' => $type, 'data' => $data, 'settings' => []];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $extra
     */
    private function render(array $blocks, array $entry, array $extra = [], string $scope = 'none'): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->env()->createTemplate('{{ layout_blocks(list) }}')->render([
            'list' => $blocks,
            'entry' => $entry,
            'type' => 'post',
        ] + $extra);
    }

    private function blob(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => 'cover.png', 'mime_type' => 'image/png', 'size' => 1,
            'url' => 'uploads/cover.png', 'visibility' => 'public', 'status' => 'active',
            'created_by' => 'user00000001', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /** @return array<string,mixed> */
    private static function entry(array $fields = []): array
    {
        return [
            'uuid' => 'entry0000001',
            'published_at' => '2026-06-02T09:30:00+00:00',
            'fields' => $fields + [
                'title' => 'Hello world',
                'excerpt' => 'A short line.',
                'reading_time' => 1200,
                'categories' => [['entry_uuid' => 'catnews00001', 'fields' => ['title' => 'News', 'slug' => 'news']]],
                'body' => [['id' => 'bodytext0001', 'type' => 'rich_text',
                    'data' => ['body' => '<p>Body words.</p>'], 'settings' => []]],
            ],
        ];
    }

    public function testTheTitleDateExcerptFieldAndCoverShowTheEntrysData(): void
    {
        $cover = $this->blob();
        $html = $this->render([
            self::block('entry_title', ['level' => 'h1'], 'b1'),
            self::block('entry_date', ['format' => 'long'], 'b2'),
            self::block('entry_excerpt', ['field' => 'excerpt'], 'b3'),
            self::block('entry_field', ['field' => 'reading_time', 'format' => 'number'], 'b4'),
            self::block('entry_cover', ['field' => 'cover', 'aspect' => '16:9'], 'b5'),
        ], self::entry(['cover' => $cover]));

        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-entry_title[^"]*"[^>]*>Hello world</h1>~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<time class="thallo-block thallo-block-entry_date[^"]*"[^>]*datetime="2026-06-02"[^>]*>'
                . 'June 2, 2026</time>~',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<p class="thallo-block thallo-block-entry_excerpt[^"]*"[^>]*>A short line.</p>~',
            $html,
        );
        self::assertStringContainsString('1,200', $html);
        self::assertStringContainsString('thallo-block-entry_cover--16-9', $html);
        // The cover is the entry's own media, served through the blob route (under whatever API prefix).
        self::assertMatchesRegularExpression('~<img [^>]*src="[^"]*/v1/blobs/' . preg_quote($cover, '~') . '"~', $html);
    }

    public function testAnEmptyFieldIsNothingOnTheSiteAndNamedOnTheLayoutStage(): void
    {
        $cover = [self::block('entry_cover', ['field' => 'cover'])];
        $site = $this->render($cover, self::entry());
        self::assertStringNotContainsString('<img', $site);
        self::assertStringNotContainsString('thallo-field-empty', $site);

        $stage = $this->render($cover, self::entry(), [], 'layout');
        self::assertStringContainsString('thallo-field-empty', $stage);
        self::assertStringContainsString('Cover', $stage);
    }

    public function testTermsLinkToTheirArchiveOnlyWhenTheTypeIsListed(): void
    {
        $terms = [self::block('entry_terms', ['field' => 'categories', 'style' => 'badges', 'link' => true])];
        $listed = $this->render($terms, self::entry(), ['type_listing' => [
            'path' => '/post',
            'archives' => ['categories' => ['path' => '/post/categories', 'slug_field' => 'slug']],
        ]]);
        self::assertMatchesRegularExpression(
            '~<a class="thallo-block-entry_terms__term[^"]*" href="/post/categories/news">News</a>~',
            $listed,
        );

        $unlisted = $this->render($terms, self::entry(), ['type_listing' => null]);
        self::assertMatchesRegularExpression(
            '~<span class="thallo-block-entry_terms__term[^"]*">News</span>~',
            $unlisted,
        );
        self::assertStringNotContainsString('href="/post/categories', $unlisted);
    }

    public function testTheContentSlotShowsTheEntrysBody(): void
    {
        $html = $this->render([self::block('entry_content', ['field' => 'body'])], self::entry());
        self::assertStringContainsString('Body words.', $html);
    }

    public function testRelatedEntriesAndNeighboursComeFromThePublishedPosts(): void
    {
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $publish = function (string $uuid, string $title, string $at) use ($type): void {
            $db = $this->connection();
            $version = 'v' . substr($uuid, 1);
            $db->table('entries')->insert(['uuid' => $uuid, 'content_type_uuid' => $type, 'status' => 'active',
                'created_at' => $at, 'updated_at' => $at]);
            $db->table('entry_versions')->insert(['uuid' => $version, 'entry_uuid' => $uuid, 'locale' => 'en',
                'version' => 1, 'fields' => json_encode(['title' => $title]), 'schema_version' => 1,
                'created_at' => $at]);
            $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en',
                'version_uuid' => $version, 'published_at' => $at]);
            (new RouteRepository($db))->assign($uuid, $type, 'en', strtolower($title));
        };
        $publish('postold00001', 'Older', '2026-05-01 09:00:00');
        $publish('postmid00001', 'Middle', '2026-05-10 09:00:00');
        $publish('postnew00001', 'Newer', '2026-05-20 09:00:00');
        $middle = [
            'uuid' => 'postmid00001',
            'published_at' => '2026-05-10T09:00:00+00:00',
            'fields' => ['title' => 'Middle'],
        ];

        $related = $this->render([self::block('entry_related', ['count' => 1, 'style' => 'list'])], $middle);
        self::assertStringContainsString('Newer', $related);
        self::assertStringNotContainsString('Older', $related, 'at most count');
        self::assertStringNotContainsString('>Middle<', $related, 'never itself');

        $neighbours = $this->render([self::block('entry_neighbours', [])], $middle);
        self::assertMatchesRegularExpression('~rel="prev"[^>]*>.*Older~s', $neighbours);
        self::assertMatchesRegularExpression('~rel="next"[^>]*>.*Newer~s', $neighbours);
    }

    /**
     * Entries published in the same second are ordered by their uuid as well: walking previous and
     * next visits each once — a chain, never a cycle.
     */
    public function testNeighboursPublishedInTheSameSecondFormAChain(): void
    {
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $at = '2026-05-10 09:00:00';
        foreach (['posttie00001' => 'A', 'posttie00002' => 'B', 'posttie00003' => 'C'] as $uuid => $title) {
            $db = $this->connection();
            $version = 'v' . substr($uuid, 1);
            $db->table('entries')->insert(['uuid' => $uuid, 'content_type_uuid' => $type, 'status' => 'active',
                'created_at' => $at, 'updated_at' => $at]);
            $db->table('entry_versions')->insert(['uuid' => $version, 'entry_uuid' => $uuid, 'locale' => 'en',
                'version' => 1, 'fields' => json_encode(['title' => $title]), 'schema_version' => 1,
                'created_at' => $at]);
            $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en',
                'version_uuid' => $version, 'published_at' => $at]);
            (new RouteRepository($db))->assign($uuid, $type, 'en', strtolower($title));
        }
        $reader = $this->container()->get(\Thallo\Contracts\Delivery\EntryListReader::class);
        $around = static fn (string $uuid): array => array_map(
            static fn (?array $e): ?string => $e['uuid'] ?? null,
            array_intersect_key($reader->neighbours('post', $uuid, 'en'), ['previous' => 1, 'next' => 1]),
        );
        self::assertSame(['previous' => null, 'next' => 'posttie00002'], $around('posttie00001'));
        self::assertSame(['previous' => 'posttie00001', 'next' => 'posttie00003'], $around('posttie00002'));
        self::assertSame(['previous' => 'posttie00002', 'next' => null], $around('posttie00003'));
    }
}
