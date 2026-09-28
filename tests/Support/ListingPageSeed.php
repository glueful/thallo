<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Settings\GeneralSettings;

/**
 * The listing and archive pages the Release B proofs render (type layouts plan B, B0): every value
 * fixed, so `/post`, `/post/page/2` and `/post/categories/pottery` render the same markup on every
 * run and in every environment — only the ids the store generates vary, and {@see self::seed()}
 * names them. Shared by the PHP tests and scripts/build-listing-layout-proof-fixtures.
 *
 * Three posts, newest first two to a page (per page 2 — the suite's, and the fixture script's),
 * two with covers; one category, Pottery, with a description, holding all three. Published
 * directly, without the review workflow (a fresh database grants no one its bypass), with the
 * archive projection written as the publish listener writes it.
 */
final class ListingPageSeed
{
    /** blob uuid => the committed image a self-contained page inlines for it */
    public const IMAGES = [
        'listblob0001' => 'tests/fixtures/commerce/product-cover.png',
        'listblob0002' => 'tests/fixtures/commerce/product-alt.png',
    ];

    /**
     * slug => [title, excerpt, published at, cover blob], oldest first. Each excerpt runs past the
     * listing row's three-line clamp at every width: the row's text column is as wide as its text up to
     * the space it has, and text measures a little differently on each platform (macOS and the Linux CI
     * run), so an excerpt that fills the space makes the column's width the layout's, not the font's.
     */
    private const POSTS = [
        'first-firing' => [
            'First firing',
            'What the kiln taught us the first time we loaded it. We stacked the shelves too tight, '
                . 'trusted a cone we had never tested and opened the door a day early; every pot that came out '
                . 'whole was luck, and every cracked one taught us something we still do today. Since then we '
                . 'fire slowly, keep a notebook by the door and test every new glaze on a tile first, because '
                . 'the kiln is patient with people who are patient with it.',
            '2026-08-01 09:00:00',
            null,
        ],
        'glazing-by-hand' => [
            'Glazing by hand',
            'Dipping, pouring and brushing: three ways to glaze a pot. Each lays the glaze down '
                . 'differently, and each changes how it breaks over an edge, pools in a hollow and runs in the '
                . 'firing, so we choose the method before we choose the colour. Dipping gives the most even '
                . 'coat, pouring lets two glazes overlap on purpose, and brushing is how we lay a band or a '
                . 'line where it has to sit exactly, however the pot turns on the wheel.',
            '2026-08-15 09:00:00',
            'listblob0002',
        ],
        'the-kiln-at-dawn' => [
            'The kiln at dawn',
            'Opening the kiln after a long firing is the best part of the week. The door is still '
                . 'warm, the shelves tick as they cool, and for a few minutes every piece is new again, before '
                . 'we sort the keepers from the seconds and start the next load. We write down what worked, '
                . 'photograph what surprised us, and put the best pieces on the window shelf, where the morning '
                . 'light shows every run of glaze and every mark the flame left.',
            '2026-09-01 09:00:00',
            'listblob0001',
        ],
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    /**
     * @return array{post_type: string, category_type: string, pottery: string, posts: array<string,string>}
     *         the uuids the store generated (posts by slug)
     */
    public function seed(): array
    {
        $this->container->get(StarterBlockTypeSeeder::class)->seedMissing();
        $db = $this->container->get(Connection::class);
        $disk = (string) config($this->context, 'thallo.media_disk', 'local');
        foreach (self::IMAGES as $blob => $file) {
            $db->table('blobs')->insert([
                'uuid' => $blob, 'name' => basename($file), 'mime_type' => 'image/png', 'size' => 1,
                'url' => 'uploads/' . basename($file), 'visibility' => 'public', 'status' => 'active',
                'storage_type' => $disk, 'created_by' => 'user00000001', 'created_at' => '2026-07-01 00:00:00',
            ]);
        }

        $types = $this->container->get(ContentTypeRepository::class);
        $categoryType = (string) $types->create([
            'slug' => 'category', 'name' => 'Categories', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
                ['name' => 'description', 'type' => 'text', 'format' => 'rich'],
            ],
        ]);
        $postType = (string) $types->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
                ['name' => 'cover', 'type' => 'asset'],
                ['name' => 'body', 'type' => 'blocks'],
                ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                    'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ],
        ]);
        $this->container->get(GeneralSettings::class)->save(['listing_types' => ['post']]);

        $pottery = $this->publish($categoryType, 'pottery', [
            'title' => 'Pottery',
            'slug' => 'pottery',
            'description' => '<p>Wheel-thrown and hand-built work from the studio.</p>',
        ], '2026-07-15 09:00:00');
        $posts = [];
        foreach (self::POSTS as $slug => [$title, $excerpt, $at, $cover]) {
            $fields = ['title' => $title, 'excerpt' => $excerpt, 'categories' => [$pottery], 'body' => []];
            if ($cover !== null) {
                $fields['cover'] = $cover;
            }
            $posts[$slug] = $this->publish($postType, $slug, $fields, $at);
            $this->container->get(PublishedReferenceRepository::class)
                ->projectFromPublished($posts[$slug], $postType, 'en');
        }

        return ['post_type' => $postType, 'category_type' => $categoryType, 'pottery' => $pottery, 'posts' => $posts];
    }

    /**
     * One entry of `$type`, published at `$publishedAt` under `$slug` — as the seed publishes its own.
     *
     * @param array<string,mixed> $fields
     */
    public function publish(string $type, string $slug, array $fields, string $publishedAt): string
    {
        $db = $this->container->get(Connection::class);
        $entries = $this->container->get(EntryRepository::class);
        $types = $this->container->get(ContentTypeRepository::class);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');
        $this->container->get(RouteRepository::class)->assign($entry, $type, 'en', $slug);
        (new PublishService(
            $this->context,
            $entries,
            new VersionRepository($db),
            $types,
            new FieldValidator($db, $this->context, new BlockTypeRepository($db)),
            new ReferenceProjectionRepository($db),
        ))->publish($entry, 'en', 'user00000001');
        $db->table('entry_publications')->where('entry_uuid', '=', $entry)->update(['published_at' => $publishedAt]);
        return $entry;
    }
}
