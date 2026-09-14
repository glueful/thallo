<?php

/**
 * Seeds the populated beta.28 upgrade fixture (visual builder plan A1.6). Runs INSIDE a
 * beta.28 install (copied there by scripts/build-upgrade-fixture): boots its bootstrap/app.php
 * and writes, through that release's own repositories, the content the conversion must handle:
 * two drafts, two published entries with two retained versions each, header and footer regions,
 * and every legacy presentation value the §7.2 conversion table names, mappable and unmappable.
 */

declare(strict_types=1);

use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

$app = require __DIR__ . '/bootstrap/app.php';
$context = $app->getContext();
$db = Connection::fromContext($context);
$actor = 'user00000001';

$types = new ContentTypeRepository($db);
$entries = new EntryRepository($db, $context, $types);
$routes = new RouteRepository($db);
$versions = new VersionRepository($db);
$publish = new PublishService(
    $context,
    $entries,
    $versions,
    $types,
    new FieldValidator($db, $context, new BlockTypeRepository($db)),
    new ReferenceProjectionRepository($db),
);

$pageType = $types->create([
    'slug' => 'page',
    'name' => 'Page',
    'public_delivery' => true,
    'schema' => [
        ['name' => 'title', 'type' => 'string', 'required' => true],
        ['name' => 'body', 'type' => 'blocks'],
    ],
]);

// A real blob on the media disk so the image block validates.
$blobUuid = Utils::generateNanoID();
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
);
$mediaRoot = (string) config($context, 'filesystem.paths.uploads', __DIR__ . '/storage/uploads');
@mkdir($mediaRoot . '/fixture', 0755, true);
file_put_contents($mediaRoot . '/fixture/pixel.png', $png);
$db->table('blobs')->insert([
    'uuid' => $blobUuid,
    'name' => 'pixel.png',
    'mime_type' => 'image/png',
    'size' => strlen($png),
    'url' => 'fixture/pixel.png',
    'storage_type' => 'uploads',
    'visibility' => 'public',
    'status' => 'active',
    'created_by' => $actor,
    'created_at' => gmdate('Y-m-d H:i:s'),
]);

$id = static fn (): string => Utils::generateNanoID();

/** Every legacy presentation value the conversion table must handle. */
$legacyBody = static fn (string $suffix): array => [
    ['id' => $id(), 'type' => 'heading', 'data' => [
        'text' => "Heading {$suffix}", 'level' => 'h2', 'align' => 'start', 'color' => '#ff0000',
    ]],
    ['id' => $id(), 'type' => 'button', 'data' => [
        'label' => 'Go', 'url' => '/go', 'variant' => 'solid', 'align' => 'center', 'shape' => 'square',
        'size' => 'lg',
    ]],
    ['id' => $id(), 'type' => 'animated_text', 'data' => [
        'prefix' => 'Build', 'rotate_words' => "fast\nwell", 'suffix' => 'sites',
        'prefix_color' => '#112233', 'rotate_color' => '#445566', 'suffix_color' => '#778899',
    ]],
    ['id' => $id(), 'type' => 'image', 'data' => [
        'image' => $blobUuid, 'alt' => 'pixel', 'size' => 'wide', 'width' => 320, 'height' => 240,
    ]],
    ['id' => $id(), 'type' => 'carousel', 'data' => [
        'slides' => [['id' => $id(), 'type' => 'rich_text', 'data' => ['content' => '<p>slide</p>']]],
        'transition_duration' => 1.5,
    ]],
    ['id' => $id(), 'type' => 'container', 'data' => [
        'padding_preset' => 'large',
        'padding' => ['top' => 17, 'right' => 17, 'bottom' => 17, 'left' => 17],
        'margin' => ['top' => 8, 'bottom' => 8],
        'radius' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
        'border_style' => 'solid', 'border_width' => 2, 'border_color' => '#123456',
        'shadow' => 'md', 'background_color' => '#f0f0f0',
        'overlay_color' => '#000000', 'overlay_opacity' => 40,
        'max_width' => 900, 'min_height' => 'half', 'min_height_px' => 420, 'gap' => 12,
        'content' => [
            ['id' => $id(), 'type' => 'style', 'data' => [
                'shadow' => 'lg', 'shadow_color' => '#123456', 'shadow_opacity' => 40,
                'padding' => 'medium', 'margin' => 'small', 'class_hook' => 'promo',
                'content' => [
                    ['id' => $id(), 'type' => 'heading', 'data' => [
                        'text' => 'Nested', 'level' => 'h3', 'align' => 'center',
                    ]],
                ],
            ]],
        ],
    ]],
];

$slugs = [];
foreach (['alpha', 'beta'] as $name) {
    $entry = $entries->createEntry($pageType, 'en', 1, $actor);
    $entries->saveDraft(
        $entry,
        'en',
        ['title' => "Published {$name}", 'body' => $legacyBody($name . ' v1')],
        1,
        0,
        $actor,
    );
    $routes->assign($entry, $pageType, 'en', "published-{$name}");
    $publish->publish($entry, 'en', $actor);
    // A second retained version: edit and publish again.
    $draft = $entries->findDraft($entry, 'en');
    $entries->saveDraft(
        $entry,
        'en',
        ['title' => "Published {$name} again", 'body' => $legacyBody($name . ' v2')],
        1,
        (int) $draft['lock_version'],
        $actor,
    );
    $publish->publish($entry, 'en', $actor);
    $slugs[] = "published-{$name}";
}
foreach (['gamma', 'delta'] as $name) {
    $entry = $entries->createEntry($pageType, 'en', 1, $actor);
    $entries->saveDraft($entry, 'en', ['title' => "Draft {$name}", 'body' => $legacyBody($name)], 1, 0, $actor);
}

$regions = new RegionRepository($db);
$regions->save('header', [
    ['id' => $id(), 'type' => 'container', 'data' => [
        'padding_preset' => 'small', 'background_color' => '#ffffff',
        'content' => [
            ['id' => $id(), 'type' => 'button', 'data' => ['label' => 'Docs', 'url' => '/docs', 'shape' => 'pill']],
        ],
    ]],
], ['sticky' => true, 'width' => 'contained'], $actor);
$regions->save('footer', [
    ['id' => $id(), 'type' => 'rich_text', 'data' => ['content' => '<p>Footer</p>']],
], ['width' => 'contained'], $actor);

file_put_contents(__DIR__ . '/fixture-manifest.json', json_encode([
    'type' => 'page',
    'routes' => $slugs,
    'blob' => $blobUuid,
    'seeded_at' => gmdate('c'),
], JSON_PRETTY_PRINT));
fwrite(STDOUT, "fixture seeded: " . implode(', ', $slugs) . "\n");
