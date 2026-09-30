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
 * The layout stage for a document, raw: a session, the document applied, the canvas render.
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
): string {
    $hydrator = new RequestDataHydrator();
    $preview = $container->get(LayoutPreviewController::class);
    $opened = $preview->session($hydrator->hydrate(LayoutSessionData::class, [
        'surface' => $surface, 'target' => $target,
    ]));
    if ($opened->getStatusCode() !== 200) {
        throw new RuntimeException("layout session {$surface}/{$target}: {$opened->getContent()}");
    }
    $token = (string) json_decode((string) $opened->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['token'];
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
 * The layout stage for a document on the placeholder sample, self-contained for a `file://` capture.
 * Refuses a target whose surface would pick a published sample instead of the placeholder.
 *
 * @param callable(Request): Response $handle the application, for the stylesheets the page links
 * @param list<array<string,mixed>> $blocks with ids
 * @param array<string,mixed> $settings
 */
function layout_stage_html(
    ContainerInterface $container,
    callable $handle,
    string $surface,
    string $target,
    array $blocks,
    array $settings,
): string {
    $samples = $container->get(LayoutSurfaceRegistry::class)->get($surface)?->samples($target, null) ?? [];
    if ($samples !== []) {
        throw new RuntimeException("sample '{$samples[0]['id']}' would replace the placeholder");
    }
    return layout_self_contained(layout_stage_raw($container, $surface, $target, $blocks, $settings), $handle);
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
        $html = (string) preg_replace(
            '~(src|data-src)="[^"]*/blobs/' . $blob . '[^"]*"~',
            '$1="' . $dataUri('image/png', (string) file_get_contents($root . '/' . $file)) . '"',
            $html,
        );
    }
    $html = (string) preg_replace('~<script\b[^>]*>.*?</script>~s', '', $html);
    return (string) preg_replace('~<link rel="(preload|modulepreload|icon)"[^>]*>~', '', $html);
}

/**
 * The targets layout patterns are pictured on, isolated so every surface shows its placeholder:
 * fresh types with no entries, and no products. Writes only inside the caller's transaction.
 *
 * @return array<string,string> surface => target, for the surfaces this site has
 */
function layout_thumbnail_targets(ContainerInterface $container): array
{
    $types = $container->get(ContentTypeRepository::class);
    $title = ['name' => 'title', 'type' => 'string', 'required' => true];
    if ($types->findBySlug('thumb_category') === null) {
        $types->create(['slug' => 'thumb_category', 'name' => 'Categories', 'public_delivery' => true, 'schema' => [
            $title,
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]]);
    }
    if ($types->findBySlug('thumb_post') === null) {
        $types->create(['slug' => 'thumb_post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
            $title,
            ['name' => 'excerpt', 'type' => 'string'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'thumb_category',
                'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]]);
    }
    $settings = $container->get(GeneralSettings::class);
    $listing = $settings->listingTypes();
    if (!in_array('thumb_post', $listing, true)) {
        $settings->save(['listing_types' => [...$listing, 'thumb_post']]);
    }

    $surfaces = $container->get(LayoutSurfaceRegistry::class);
    $out = [];
    $contentTargets = ['entry' => 'thumb_post', 'listing' => 'thumb_post', 'archive' => 'thumb_post:categories'];
    foreach ($contentTargets as $surface => $target) {
        if ($surfaces->get($surface) !== null) {
            $out[$surface] = $target;
        }
    }
    if ($surfaces->get('product') !== null) {
        (new ShopPageSeed($container, $container->get(\Glueful\Bootstrap\ApplicationContext::class)))->clear();
        foreach (['product', 'shop_index', 'shop_category'] as $surface) {
            if ($surfaces->get($surface) !== null) {
                $out[$surface] = '@site';
            }
        }
    }
    return $out;
}
