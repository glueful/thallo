<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Render\RenderContextExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * facets() in Twig (preview spec §5): the {items, cache_tags} contract (incl. the
 * valid-empty case), the render-scoped tag collector, and gate fail-safety.
 */
final class FacetsTwigTest extends AppTestCase
{
    private const CAT_TYPE_UUID = 'cattypefctw0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID, 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ],
        ]);
    }

    private function reader(): FacetCountsReader
    {
        return $this->container()->get(FacetCountsReader::class);
    }

    private function seedTermAndMember(): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'ftwterm00001', 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vftwterm0001', 'entry_uuid' => 'ftwterm00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => 'php'], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ftwterm00001', 'locale' => 'en', 'version_uuid' => 'vftwterm0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $db->table('entries')->insert([
            'uuid' => 'ftwpost00001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vftwpost0001', 'entry_uuid' => 'ftwpost00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(
                ['title' => 'P', 'category' => ['ftwterm00001']],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ftwpost00001', 'locale' => 'en', 'version_uuid' => 'vftwpost0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished('ftwpost00001', $this->postType, 'en');
    }

    public function testReaderReturnsItemsAndTags(): void
    {
        $this->seedTermAndMember();
        $r = $this->reader()->counts('post', 'category', 'en');
        self::assertSame(
            [['uuid' => 'ftwterm00001', 'slug' => 'php', 'count' => 1]],
            $r['items'],
        );
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $r['cache_tags']);
    }

    public function testValidEmptyFacetStillCarriesTags(): void
    {
        // No members at all — items empty, but the tags MUST be present so a page
        // showing this facet purges when the first matching entry publishes (review P1).
        $r = $this->reader()->counts('post', 'category', 'en');
        self::assertSame([], $r['items']);
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $r['cache_tags']);
    }

    public function testGateFailuresReturnEmptyItemsAndEmptyTags(): void
    {
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('post', 'title', 'en'),   // not a reference field
        );
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('nope', 'category', 'en'), // unknown type
        );
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('post', 'category', 'en'), // non-visible target
        );
    }

    public function testTwigFacetsCollectsTagsAndCollectorScopesPerRender(): void
    {
        $this->seedTermAndMember();
        $extension = $this->container()->get(RenderContextExtension::class);
        $twig = new Environment(new ArrayLoader([
            'ok.twig' => '{% for f in facets("post", "category") %}{{ f.slug }}:{{ f.count }}{% endfor %}',
            'boom.twig' => '{{ facets("post", "category")|length }}{{ undefined_fn() }}',
        ]));
        $twig->addExtension($extension);
        $extension->setLocale('en');

        // Successful render: items in output, tags in the collector.
        $extension->resetTags();
        self::assertSame('php:1', $twig->render('ok.twig'));
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $extension->drainTags());
        self::assertSame([], $extension->drainTags()); // drain clears

        // A failing render must not leak tags into the NEXT render (review pin):
        // the controller resets BEFORE every render, so the next reset wipes whatever
        // the exploded render collected.
        $extension->resetTags();
        try {
            $twig->render('boom.twig');
            self::fail('boom.twig should have thrown');
        } catch (\Throwable) {
        }
        $extension->resetTags(); // the next render's reset
        self::assertSame([], $extension->drainTags());
    }
}
