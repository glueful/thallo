<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves thallo-render is cleanly disable-able: with lemma.render disabled the boot gate
 * skips the routes entirely, so unmatched public paths behave EXACTLY as pre-render
 * (the router's standard JSON 404 — this is also the byte-compat source the pipeline
 * test's reserved-guard responses are shaped after). Boundary: no app-engine references
 * in packages/thallo-render/src.
 */
final class RenderRemovabilityTest extends AppTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$disabledApp ??= self::bootAppWithConfigOverride('lemma', [
            'capabilities' => ['lemma.render' => false],
        ]);
    }

    private function hit(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return (new Application(self::$disabledApp))->handle(
            Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        );
    }

    public function testPublicPathsAreStandardJson404sWhenDisabled(): void
    {
        foreach (['/blog/hello', '/anything', '/a/b/c'] as $path) {
            $res = $this->hit($path);
            self::assertSame(404, $res->getStatusCode(), $path);
            self::assertStringContainsString('json', (string) $res->headers->get('Content-Type'), $path);
            $body = json_decode((string) $res->getContent(), true);
            self::assertFalse($body['success'], $path);
            self::assertSame('Not Found', $body['message'], $path);
        }
    }

    public function testThemeAssetsAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('/theme-assets/site.css')->getStatusCode());
    }

    public function testPackSourceHasNoAppReferences(): void
    {
        // Mirror scripts/check-pack-boundaries.php: a leading [^\w] catches bare FQCNs too.
        $root = dirname(__DIR__, 3) . '/packages/thallo-render/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/(^|[^\\w])App\\\\/m',
                $src,
                "{$file->getPathname()} must not reference the app engine namespace (pack boundary)",
            );
            $checked++;
        }
        self::assertGreaterThan(5, $checked, 'boundary sweep must actually see the pack sources');
    }
}
