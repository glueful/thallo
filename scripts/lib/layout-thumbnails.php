<?php

/**
 * Pictures of layout patterns are the layout stage itself (sections and templates design §6): the
 * document opened in a real layout session, applied, and rendered as the editor's iframe receives it
 * — through the surface's own frame and the real presentation path — on the placeholder sample.
 * These helpers are shared by scripts/build-pattern-thumbnails and its proof
 * (tests/Integration/Content/Layouts/LayoutThumbnailSourceTest).
 */

declare(strict_types=1);

use Glueful\Validation\RequestDataHydrator;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The layout stage for a document, raw: a session on `$sample`, the document applied, the canvas
 * render. Refuses a session that does not show `$sample` (the stage picks the newest published item
 * when the one asked for is not there).
 *
 * @param list<array<string,mixed>> $blocks with ids (the stage annotates blocks by them)
 * @param array<string,mixed> $settings the Frame settings
 */
function layout_stage_raw(
    ContainerInterface $container,
    string $surface,
    string $target,
    array $blocks,
    array $settings,
    string $sample,
): string {
    $hydrator = new RequestDataHydrator();
    $preview = $container->get(LayoutPreviewController::class);
    $opened = $preview->session($hydrator->hydrate(LayoutSessionData::class, [
        'surface' => $surface, 'target' => $target, 'sample' => $sample,
    ]));
    if ($opened->getStatusCode() !== 200) {
        throw new RuntimeException("layout session {$surface}/{$target}: {$opened->getContent()}");
    }
    $session = json_decode((string) $opened->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
    $shown = (string) ($session['sample']['id'] ?? '');
    if ($shown !== $sample) {
        throw new RuntimeException("the {$surface} stage shows sample '{$shown}', not the fixture '{$sample}'");
    }
    $token = (string) $session['token'];
    $applied = $preview->apply($hydrator->hydrate(ApplyLayoutData::class, [
        'token' => $token,
        'layout' => ['blocks' => $blocks, 'settings' => $settings],
        'epoch' => null,
        'base_revision' => null,
    ]));
    if ($applied->getStatusCode() !== 200) {
        throw new RuntimeException("applying to {$surface}/{$target}: {$applied->getContent()}");
    }
    $rendered = $container->get(RenderController::class)->preview(
        Request::create('/_preview/' . $token . '?canvas=1', 'GET'),
        $token,
    );
    if ($rendered->getStatusCode() !== 200) {
        throw new RuntimeException("the {$surface}/{$target} stage returned {$rendered->getStatusCode()}");
    }
    return (string) $rendered->getContent();
}

/**
 * The layout stage for a document on its fixture sample, self-contained for a `file://` capture.
 *
 * @param callable(Request): Response $handle the application, for the stylesheets the page links
 * @param list<array<string,mixed>> $blocks with ids
 * @param array<string,mixed> $settings
 * @param array<string,string> $images blob uuid => committed file, inlined for the fixtures' pictures
 */
function layout_stage_html(
    ContainerInterface $container,
    callable $handle,
    string $surface,
    string $target,
    array $blocks,
    array $settings,
    string $sample,
    array $images = [],
): string {
    return layout_self_contained(
        layout_stage_raw($container, $surface, $target, $blocks, $settings, $sample),
        $handle,
        $images,
        dirname(__DIR__, 2),
    );
}

/**
 * A page as a self-contained document: stylesheets, fonts and images inlined through the
 * application, scripts and preloads dropped.
 *
 * @param callable(Request): Response $handle
 * @param array<string,string> $images blob uuid => a committed file (relative to `$root`) to inline for it
 */
function layout_self_contained(string $html, callable $handle, array $images = [], string $root = ''): string
{
    $get = static function (string $path) use ($handle): Response {
        for ($hop = 0; $hop < 4; $hop++) {
            $response = $handle(Request::create($path, 'GET'));
            if (!$response->isRedirection()) {
                return $response;
            }
            $path = (string) $response->headers->get('Location');
        }
        throw new RuntimeException("too many redirects for {$path}");
    };
    $dataUri = static fn (string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);
    $inlineUrls = static function (string $css, string $base) use ($get, $dataUri): string {
        return (string) preg_replace_callback(
            '~url\(\s*(["\']?)([^"\')]+)\1\s*\)~',
            static function (array $m) use ($get, $dataUri, $base): string {
                $url = $m[2];
                if (str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                    return $m[0];
                }
                $path = str_starts_with($url, '/') ? $url : rtrim(dirname($base), '/') . '/' . $url;
                $asset = $get($path);
                if ($asset->getStatusCode() !== 200) {
                    throw new RuntimeException("cannot inline {$path}: {$asset->getStatusCode()}");
                }
                $mime = strtok((string) $asset->headers->get('Content-Type', 'application/octet-stream'), ';');
                return 'url("' . $dataUri((string) $mime, (string) $asset->getContent()) . '")';
            },
            $css,
        );
    };
    $html = (string) preg_replace_callback(
        '~<link rel="stylesheet" href="([^"]+)">~',
        static function (array $m) use ($get, $inlineUrls): string {
            $href = html_entity_decode($m[1]);
            $sheet = $get($href);
            if ($sheet->getStatusCode() !== 200) {
                throw new RuntimeException("cannot inline stylesheet {$href}: {$sheet->getStatusCode()}");
            }
            return '<style data-inlined="' . htmlspecialchars($href) . '">'
                . $inlineUrls((string) $sheet->getContent(), (string) parse_url($href, PHP_URL_PATH)) . '</style>';
        },
        $html,
    );
    $html = (string) preg_replace_callback(
        '~<style([^>]*)>(.*?)</style>~s',
        static fn (array $m): string => '<style' . $m[1] . '>' . $inlineUrls($m[2], '/') . '</style>',
        $html,
    );
    foreach ($images as $blob => $file) {
        // The picture itself, and no `srcset` of the same image's server sizes, which a file:// page
        // cannot load and a browser would prefer over `src`.
        $html = (string) preg_replace('~\ssrcset="[^"]*/blobs/' . $blob . '[^"]*"~', '', $html);
        $html = (string) preg_replace(
            '~(src|data-src)="[^"]*/blobs/' . $blob . '[^"]*"~',
            '$1="' . $dataUri('image/png', (string) file_get_contents($root . '/' . $file)) . '"',
            $html,
        );
    }
    $html = (string) preg_replace('~<script\b[^>]*>.*?</script>~s', '', $html);
    return (string) preg_replace('~<link rel="(preload|modulepreload|icon)"[^>]*>~', '', $html);
}

/** Blob uuid => the committed image the thumbnails' posts show as their covers. */
const LAYOUT_THUMBNAIL_IMAGES = [
    'thumbblob001' => 'tests/fixtures/commerce/product-cover.png',
    'thumbblob002' => 'tests/fixtures/commerce/product-alt.png',
];

/**
 * The content layout patterns are pictured on (sections and templates design §6): fresh types of
 * their own — a post type and the categories that file it — with a category and six published posts
 * (titles, excerpts, covers, a written body), and the fixture shop's products for the product and
 * shop pages. Nothing else in the database is shown: the types are new, the shop is cleared first.
 * Writes only inside the caller's transaction.
 *
 * @return array{targets: array<string,string>, samples: array<string,string>, images: array<string,string>,
 *     shop: ?ShopPageSeed} per surface this site has: the target, and the sample each picture shows
 */
function layout_thumbnail_fixtures(ContainerInterface $container): array
{
    $context = $container->get(\Glueful\Bootstrap\ApplicationContext::class);
    $db = $container->get(\Glueful\Database\Connection::class);
    $types = $container->get(ContentTypeRepository::class);
    $seed = new \Thallo\Core\Tests\Support\ListingPageSeed($container, $context);
    $disk = (string) config($context, 'thallo.media_disk', 'local');
    foreach (LAYOUT_THUMBNAIL_IMAGES as $blob => $file) {
        if ($db->table('blobs')->where('uuid', '=', $blob)->first() === null) {
            $db->table('blobs')->insert([
                'uuid' => $blob, 'name' => basename($file), 'mime_type' => 'image/png', 'size' => 1,
                'url' => 'uploads/' . basename($file), 'visibility' => 'public', 'status' => 'active',
                'storage_type' => $disk, 'created_by' => 'user00000001', 'created_at' => '2026-07-01 00:00:00',
            ]);
        }
    }
    $title = ['name' => 'title', 'type' => 'string', 'required' => true];
    $categoryType = (string) $types->create([
        'slug' => 'thumb_category', 'name' => 'Categories', 'public_delivery' => true, 'schema' => [
            $title,
            ['name' => 'slug', 'type' => 'string', 'required' => true],
            ['name' => 'description', 'type' => 'text', 'format' => 'rich'],
        ],
    ]);
    $postType = (string) $types->create([
        'slug' => 'thumb_post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
            $title,
            ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'thumb_category',
                'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ],
    ]);
    $settings = $container->get(GeneralSettings::class);
    $settings->save(['listing_types' => [...$settings->listingTypes(), 'thumb_post']]);

    $studio = $seed->publish($categoryType, 'studio-notes', [
        'title' => 'Studio notes',
        'slug' => 'studio-notes',
        'description' => '<p>What we are making, what went wrong, and what we learned from it.</p>',
    ], '2026-07-01 09:00:00');
    // Twelve: more than a listing page holds (render.listing_per_page, 10), so page navigation shows.
    $posts = [
        ['kiln-log', 'Keeping a kiln log', 'Every firing written down: the cones, the weather and what cracked.'],
        ['wedging', 'Wedging, twice', 'Ten minutes on the bench saves an hour of air bubbles later.'],
        ['handles', 'Pulling handles', 'Wet hands, a loose grip and a handle that will not crack as it dries.'],
        ['celadon', 'A week of celadon', 'Seven tiles, three thicknesses and the green we were after.'],
        ['reclaim', 'Reclaiming clay', 'Nothing is wasted: the slop bucket becomes next month\'s mugs.'],
        ['shelf-life', 'Kiln shelves', 'Why we wash our shelves and what happens when we forget.'],
        ['open-studio', 'Open studio this autumn', 'Three weekends, the kiln room open, and tea on the wheel bench.'],
        ['trimming-feet', 'Trimming feet on a wet day', 'Why leather-hard is a moving target when the air is damp.'],
        ['fire-slowly', 'Why we fire slowly', 'A slow climb through the first six hundred degrees saves more pots.'],
        ['glaze-shelf', 'Notes from the glaze shelf', 'Four tiles, one bucket and the celadon we keep coming back to.'],
        ['clay-body', 'Choosing a clay body', 'Stoneware for the mugs, porcelain for the bowls, and why.'],
        ['lidded-jar', 'A lidded jar, start to finish', 'From a pound of clay to a lid that seats with a quiet click.'],
    ];
    $newest = '';
    foreach ($posts as $i => [$slug, $postTitle, $excerpt]) {
        $newest = $seed->publish($postType, $slug, [
            'title' => $postTitle,
            'excerpt' => $excerpt,
            'cover' => array_keys(LAYOUT_THUMBNAIL_IMAGES)[$i % 2],
            'categories' => [$studio],
            'body' => [
                ['id' => 'thumbbody' . $i . 'a', 'type' => 'rich_text', 'data' => ['body' => '<p>' . $excerpt
                    . ' We keep a notebook by the wheel and write down what each pot asked of us, because the '
                    . 'next one usually asks the same.</p><p>The glaze goes on thin, the kiln climbs slowly, and '
                    . 'the door stays shut until the pots are cool enough to hold.</p>'], 'settings' => []],
            ],
        ], sprintf('2026-07-%02d 09:00:00', 10 + $i));
        $container->get(\Thallo\Core\Content\Repositories\PublishedReferenceRepository::class)
            ->projectFromPublished($newest, $postType, 'en');
    }

    $surfaces = $container->get(LayoutSurfaceRegistry::class);
    $out = ['targets' => [], 'samples' => [], 'images' => LAYOUT_THUMBNAIL_IMAGES, 'shop' => null];
    $contentTargets = ['entry' => 'thumb_post', 'listing' => 'thumb_post', 'archive' => 'thumb_post:categories'];
    foreach ($contentTargets as $surface => $target) {
        $kind = $surfaces->get($surface);
        if ($kind === null) {
            continue;
        }
        $out['targets'][$surface] = $target;
        $out['samples'][$surface] = $surface === 'entry'
            ? $newest
            : (string) ($kind->samples($target, null)[0]['id'] ?? '');
    }
    if ($surfaces->get('product') !== null) {
        $shop = new ShopPageSeed($container, $context);
        $outside = $shop->useTenant();
        try {
            $shop->clear();
            $shop->seed();
            // Described, pictured and named as a real shop's: the fixture shop's products are mostly
            // "Item 06"… with no picture and no description, which is all a picture of a shop would show.
            $db->table('commerce_products')->where('tenant_uuid', '=', ShopPageSeed::TENANT)->update([
                'description' => '<p>Thrown on the wheel and glazed by hand in small batches, so no two are '
                    . 'quite alike. Dishwasher safe, and heavy enough to keep your coffee warm.</p>',
            ]);
            layout_thumbnail_shop_pictures($container, $context, $db);
            foreach (['product', 'shop_index', 'shop_category'] as $surface) {
                $kind = $surfaces->get($surface);
                if ($kind !== null) {
                    $out['targets'][$surface] = '@site';
                    $out['samples'][$surface] = (string) ($kind->samples('@site', null)[0]['id'] ?? '');
                }
            }
            // The product page's sample tells its story, as a product with a linked entry does.
            if (($out['samples']['product'] ?? '') !== '') {
                $container->get(\Thallo\Commerce\Links\ProductLinkService::class)->link(
                    $context,
                    $out['samples']['product'],
                    layout_thumbnail_story($container, $seed),
                );
            }
        } finally {
            $shop->restoreTenant($outside);
        }
        $out['shop'] = $shop;
        $out['images'] += ShopPageSeed::IMAGES + LAYOUT_THUMBNAIL_SHOP_IMAGES;
    }
    foreach ($out['samples'] as $surface => $sample) {
        if ($sample === '') {
            throw new RuntimeException("the {$surface} fixtures give its stage no sample");
        }
    }
    return $out;
}

/** A published story for the sample product: a heading and two paragraphs, in a type of its own. */
function layout_thumbnail_story(ContainerInterface $container, \Thallo\Core\Tests\Support\ListingPageSeed $seed): string
{
    $types = $container->get(ContentTypeRepository::class);
    $type = (string) $types->create([
        'slug' => 'thumb_story', 'name' => 'Stories', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ],
    ]);
    return $seed->publish($type, 'made-by-hand', [
        'title' => 'Made by hand',
        'body' => [
            ['id' => 'thumbstory01', 'type' => 'heading', 'data' => ['text' => 'Made by hand', 'level' => 'h2'],
                'settings' => []],
            ['id' => 'thumbstory02', 'type' => 'rich_text', 'data' => ['body' => '<p>Each piece starts as a pound '
                . 'of stoneware on the wheel. We trim it leather-hard, fire it slowly, and dip it in a glaze we mix '
                . 'ourselves.</p><p>No two come out of the kiln quite alike: the glaze pools where it wants to, and '
                . 'we like it that way.</p>'], 'settings' => []],
        ],
    ], '2026-07-01 09:00:00');
}

/** The fixture shop's second picture, for the products the seed leaves without one. */
const LAYOUT_THUMBNAIL_SHOP_IMAGES = ['thumbshop002' => 'tests/fixtures/commerce/product-alt.png'];

/**
 * Every fixture product named as a potter's shop would name it, and pictured: the two committed
 * images, alternating, on each product without a cover.
 */
function layout_thumbnail_shop_pictures(
    ContainerInterface $container,
    \Glueful\Bootstrap\ApplicationContext $context,
    \Glueful\Database\Connection $db,
): void {
    $names = [
        'Stoneware bowl', 'Espresso cup', 'Bud vase', 'Serving platter', 'Tea bowl', 'Salt cellar',
        'Pasta bowl', 'Jug', 'Butter dish', 'Planter', 'Side plate', 'Dinner plate', 'Oil bottle',
        'Lidded jar', 'Pinch pot', 'Candle holder', 'Spoon rest', 'Ramekin', 'Sake set', 'Fruit bowl',
        'Soap dish',
    ];
    foreach (LAYOUT_THUMBNAIL_SHOP_IMAGES as $blob => $file) {
        if ($db->table('blobs')->where('uuid', '=', $blob)->first() === null) {
            $db->table('blobs')->insert([
                'uuid' => $blob, 'name' => basename($file), 'mime_type' => 'image/png', 'size' => 1,
                'url' => 'uploads/' . basename($file), 'visibility' => 'public', 'status' => 'active',
                'created_by' => 'user00000001', 'created_at' => '2026-09-28 00:00:00',
            ]);
        }
    }
    $images = [...array_keys(ShopPageSeed::IMAGES), ...array_keys(LAYOUT_THUMBNAIL_SHOP_IMAGES)];
    $media = $container->get(\Glueful\Extensions\Commerce\Catalog\ProductMediaRepository::class);
    $products = $db->table('commerce_products')->where('tenant_uuid', '=', ShopPageSeed::TENANT)
        ->orderBy('slug', 'ASC')->get();
    $n = 0;
    foreach ($products as $i => $product) {
        if (str_starts_with((string) $product['slug'], 'item-')) {
            $db->table('commerce_products')->where('uuid', '=', $product['uuid'])
                ->update(['name' => $names[$n++ % count($names)]]);
        }
        $covered = $db->table('commerce_product_media')->where('product_uuid', '=', $product['uuid'])
            ->where('role', '=', 'cover')->first();
        if ($covered === null) {
            $media->insert($context, [
                'uuid' => 'thumbmedia' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'tenant_uuid' => ShopPageSeed::TENANT, 'product_uuid' => $product['uuid'],
                'blob_uuid' => $images[$i % count($images)], 'role' => 'cover', 'position' => 0,
                'alt' => (string) $product['name'],
            ]);
        }
    }
}

/**
 * Run `$fn` where a surface's fixtures live: the shop's in the fixture shop's workspace, restored after.
 *
 * @template T
 * @param array{shop: ?ShopPageSeed} $fixtures
 * @param callable(): T $fn
 * @return T
 */
function layout_thumbnail_in_place(array $fixtures, string $surface, callable $fn): mixed
{
    $shop = $fixtures['shop'] ?? null;
    if ($shop === null || !in_array($surface, ['product', 'shop_index', 'shop_category'], true)) {
        return $fn();
    }
    $outside = $shop->useTenant();
    try {
        return $fn();
    } finally {
        $shop->restoreTenant($outside);
    }
}
