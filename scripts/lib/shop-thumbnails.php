<?php

/**
 * The thumbnail build's help for pictures of the shop's JavaScript-painted blocks (sections and
 * templates design §6). Pictures are drawn from `file://` pages, where shop.js's requests to the
 * block-data endpoints have nothing to answer them: the build asks the real controller for each
 * block's exact request, and the page answers from those responses.
 */

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\Shop\ShopBlockDataController;

/**
 * Each shop block's response, keyed by the path and query shop.js requests — built in shop.js's
 * own parameter order from the block's data-* attributes — with cover URLs rewritten to the
 * committed images they came from.
 *
 * @param array<string,string> $imageFiles blob uuid => repo-relative committed file
 * @return array<string,string> "<path>?<query>" => response JSON
 */
function shop_block_responses(string $html, ContainerInterface $container, array $imageFiles, string $root): array
{
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();

    $controller = $container->get(ShopBlockDataController::class);
    $responses = [];
    foreach ((new DOMXPath($document))->query('//*[@data-shop-block]') ?: [] as $node) {
        /** @var DOMElement $node */
        $block = $node->getAttribute('data-shop-block');
        $params = match ($block) {
            'featured-product', 'add-to-cart' => [
                'product_slug' => $node->getAttribute('data-product-slug'),
                'entry_uuid' => $node->getAttribute('data-entry-uuid'),
            ],
            default => null,
        };
        if ($params === null) {
            continue;
        }
        $path = '/_shop/blocks/' . $block;
        $request = Request::create($path, 'GET', $params);
        $response = match ($block) {
            'featured-product' => $controller->featuredProduct($request),
            'add-to-cart' => $controller->addToCart($request),
        };
        $json = (string) $response->getContent();
        foreach ($imageFiles as $blob => $file) {
            $json = (string) preg_replace(
                '~"(?:[^"\\\\]|\\\\.)*' . preg_quote($blob, '~') . '(?:[^"\\\\]|\\\\.)*"~',
                (string) json_encode('file://' . $root . '/' . $file),
                $json,
            );
        }
        // shop.js encodes each value with encodeURIComponent, joined with '&' in this order.
        $query = implode('&', array_map(
            static fn (string $key, string $value): string => $key . '=' . rawurlencode($value),
            array_keys($params),
            $params,
        ));
        $responses[$path . '?' . $query] = $json;
    }

    return $responses;
}

/**
 * The page's own product images — a Product grid's cards render on the server — pointed at the
 * committed images they came from, as the block responses' covers are: a media URL resolves nowhere
 * under `file://`. Every attribute value naming a seeded blob becomes that file.
 *
 * @param array<string,string> $imageFiles blob uuid => repo-relative committed file
 */
function shop_local_images(string $html, array $imageFiles, string $root): string
{
    foreach ($imageFiles as $blob => $file) {
        $html = (string) preg_replace(
            '~"[^"<>]*' . preg_quote($blob, '~') . '[^"<>]*"~',
            '"' . htmlspecialchars('file://' . $root . '/' . $file, ENT_QUOTES) . '"',
            $html,
        );
    }
    return $html;
}

/**
 * The page, made to hydrate under `file://`: the shop's root-relative stylesheet link and script tag
 * removed (they resolve nowhere there; the shop's styles come inlined through the theme artifact),
 * then — at the end of the document, after `</main>`, since the page has no `</body>` — a fetch that
 * answers the recorded responses by exact path and query (anything else unmatched is an error, so a
 * mismatch fails the capture) and shop.js itself.
 *
 * @param array<string,string> $responses
 */
function shop_inject(string $html, array $responses, string $root): string
{
    $html = str_replace(
        [
            '<link rel="stylesheet" href="/_thallo/shop/shop.css">',
            '<script src="/_thallo/shop/shop.js" defer></script>',
        ],
        '',
        $html,
    );
    $map = json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    $stub = <<<JS
<script>
(function () {
  var R = {$map};
  var original = window.fetch;
  window.fetch = function (input, init) {
    var url = new URL(typeof input === 'string' ? input : input.url, 'http://thumbnail.invalid');
    if (url.pathname.indexOf('/_shop/') !== 0) {
      return original.apply(this, arguments);
    }
    var key = url.pathname + url.search;
    if (Object.prototype.hasOwnProperty.call(R, key)) {
      return Promise.resolve(new Response(R[key], { headers: { 'Content-Type': 'application/json' } }));
    }
    console.error('thumbnail: no recorded response for ' + key);
    return Promise.resolve(new Response('{}', { status: 500 }));
  };
})();
</script>
JS;

    return $html . $stub . "\n<script src=\"file://{$root}/packages/thallo-commerce/assets/shop.js\"></script>\n";
}
