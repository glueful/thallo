<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Render\Templates\RuntimeAssetMap;
use Thallo\Render\Templates\TemplatePolicy;

final class RuntimeDeliveryTest extends AppTestCase
{
    private function map(): RuntimeAssetMap
    {
        return new RuntimeAssetMap(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime'
        );
    }

    private function hit(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return (new Application($this->appContext()))->handle(Request::create($path, 'GET'));
    }

    public function testMapFingerprintsTheRuntime(): void
    {
        $name = $this->map()->fingerprintedName('runtime.js');
        self::assertMatchesRegularExpression('/^runtime-[0-9a-f]{12}\.js$/', (string) $name);
        self::assertNull($this->map()->resolve('runtime.js'), 'logical name is not a file key');
        self::assertFileExists((string) $this->map()->resolve((string) $name));
    }

    public function testLogicalAliasRedirectsUncachedToCurrentFingerprint(): void
    {
        $res = $this->hit('/_thallo/runtime/runtime.js');
        self::assertSame(302, $res->getStatusCode());
        self::assertSame(
            '/_thallo/runtime/' . rawurlencode((string) $this->map()->fingerprintedName('runtime.js')),
            $res->headers->get('Location'),
        );
        self::assertStringNotContainsString(
            'immutable',
            (string) $res->headers->get('Cache-Control'),
            'the alias must never be immutable-cached',
        );
    }

    public function testExactFingerprintServesImmutableBytesAndStaleFourOhFours(): void
    {
        $current = (string) $this->map()->fingerprintedName('runtime.js');
        $ok = $this->hit('/_thallo/runtime/' . $current);
        self::assertSame(200, $ok->getStatusCode());
        // Symfony's HeaderBag re-emits Cache-Control directives alphabetically; this is
        // the controller's 'public, max-age=31536000, immutable' after normalization.
        self::assertSame('immutable, max-age=31536000, public', $ok->headers->get('Cache-Control'));
        self::assertStringContainsString('window.ThalloRuntime', (string) $ok->getContent());

        self::assertSame(404, $this->hit('/_thallo/runtime/runtime-deadbeefdead.js')->getStatusCode());
        self::assertSame(404, $this->hit('/_thallo/runtime/..%2F..%2Fetc%2Fpasswd')->getStatusCode());
    }

    public function testPolicyAllowsRuntimeScriptAndBumpedCacheVersion(): void
    {
        self::assertContains('runtime_script', TemplatePolicy::FUNCTIONS);
        self::assertGreaterThanOrEqual(
            12,
            TemplatePolicy::CACHE_VERSION,
            'CACHE_VERSION must bump with the runtime_script allowlist addition',
        );
    }

    public function testCompatibilityLoaderIsGoneFromTheDefaultTheme(): void
    {
        // theme-runtime spec §11.4, executed pre-launch: no released version ever
        // shipped the loader, so nothing depends on asset('blocks.js') any more.
        self::assertFileDoesNotExist($this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/blocks.js');
    }

    public function testFingerprintedRuntimeServesJavascriptMime(): void
    {
        // JS mime coverage moved here when the theme lost its last .js asset.
        $current = (string) $this->map()->fingerprintedName('runtime.js');
        self::assertStringContainsString(
            'javascript',
            (string) $this->hit('/_thallo/runtime/' . $current)->headers->get('Content-Type'),
        );
    }

    public function testLayoutLoadsRuntimeScriptNotThemeBlocksJs(): void
    {
        $layout = (string) file_get_contents($this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/templates/layout.twig');
        self::assertStringContainsString('runtime_script()', $layout);
        self::assertStringNotContainsString("asset('blocks.js')", $layout);
    }
}
