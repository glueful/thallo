<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The canvas preview injected `/_preview.css` and `/_preview-bridge.js` at the site root. A
 * web-server rule that serves every `.css`/`.js` URL from disk (CloudPanel's template does)
 * answered those with 404 even after the operator proxied the documented `/theme-assets/*` and
 * `/_thallo/*` prefixes, so the designer loaded unstyled and without its bridge. PHP-served
 * assets all live under `/_thallo/` now: one documented prefix pair covers everything.
 */
final class PreviewAssetsUnderProxiedPrefixTest extends AppTestCase
{
    public function testPreviewSupportAssetsAreRoutedUnderTheThalloPrefix(): void
    {
        self::assertNotNull($this->findRoute('GET', '/_thallo/preview.css'));
        self::assertNotNull($this->findRoute('GET', '/_thallo/preview-bridge.js'));
        self::assertNull($this->findRoute('GET', '/_preview.css'), 'the root-level alias is gone');
        self::assertNull($this->findRoute('GET', '/_preview-bridge.js'));
    }

    public function testTheInjectedTagsPointUnderTheThalloPrefix(): void
    {
        self::assertSame('/_thallo/preview.css', RenderController::PREVIEW_CSS_PATH);
        self::assertSame('/_thallo/preview-bridge.js', RenderController::PREVIEW_BRIDGE_PATH);
    }

    public function testAThemedPreviewsAssetsAreRoutedUnderTheThalloPrefix(): void
    {
        // The same fault, found again on a live host: a preview session carrying a theme served
        // that theme's stylesheets and fonts from `/_preview-assets/{token}/…` — `.css` and
        // `.woff2` URLs outside the proxied prefixes, so nginx answered them 404 itself and the
        // previewed page loaded unstyled. (The admin's Appearance preview sent the theme with
        // every request, which is what made it visible.)
        self::assertNotNull($this->findRoute('GET', '/_thallo/preview-assets/{token}/{path}'));
        self::assertNull(
            $this->findRoute('GET', '/_preview-assets/{token}/{path}'),
            'the root-level prefix is gone',
        );
        self::assertSame('/_thallo/preview-assets', RenderController::PREVIEW_ASSETS_PREFIX);
    }

    public function testNoRouteThatCanServeAFileShapedUrlSitsOutsideTheProxiedPrefixes(): void
    {
        // The rule, held for every route there is: a path that can end in a file extension — a
        // literal one, or a catch-all parameter — is eaten by a static-file rule unless the web
        // server was told to hand its prefix to PHP, and docs/production.md names exactly these.
        $proxied = '~^/(theme-assets|_thallo|v1|api-docs)(/|$)~';
        $static = 'css|js|mjs|json|map|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|txt|xml';
        $offenders = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            $path = (string) $route['path'];
            if (strtoupper((string) $route['method']) !== 'GET' || preg_match($proxied, $path) === 1) {
                continue;
            }
            $literalFile = preg_match('~\.(' . $static . ')$~i', $path) === 1;
            // A trailing parameter that names a path or a file takes a file-shaped value:
            // `{path}` may be `fonts/a.woff2`. (A route record does not expose its constraints.)
            $fileParameter = preg_match('~/\{(path|file|filename|asset)\}$~', $path) === 1;
            if ($literalFile || $fileParameter) {
                $offenders[] = $path;
            }
        }
        // Known and deliberate: files a crawler or browser asks for AT THE ROOT by convention.
        // A host serves them from PHP because the static rule's own try_files falls through to
        // the front controller for a file that is not on disk (docs/production.md).
        $rootByConvention = [
            '/robots.txt', '/sitemap.xml', '/sitemap/{n}.xml', '/favicon.ico', '/feed.xml', '/llms.txt',
        ];
        // The page catch-all: content, not an asset.
        $content = ['/{path}'];
        // KNOWN DEFECTS of exactly this kind, found by this sweep and not yet moved: the
        // storefront's and the account pages' fingerprinted scripts and stylesheets. On a host
        // configured per docs/production.md they are answered 404 by the static-file rule. They
        // move under /_thallo/ the way the preview assets did; until then they are listed here
        // so the sweep still fails for any NEW route of this shape.
        $knownDefects = ['/_shop/assets/{file}', '/_account/assets/{file}'];
        self::assertSame(
            [],
            array_values(array_diff($offenders, $rootByConvention, $content, $knownDefects)),
        );
    }
}
