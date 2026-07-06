<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Navigation\MenuReader;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves thallo-navigation is cleanly disable-able: with lemma.navigation disabled, the
 * boot gate skips routes (404s) and MenuReader resolves null — indistinguishable from
 * "pack absent" for consumers. Also guards the pack boundary: no app-engine references
 * in packages/thallo-navigation/src.
 */
final class NavigationRemovabilityTest extends AppTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$disabledApp ??= self::bootAppWithConfigOverride('lemma', [
            'capabilities' => ['lemma.navigation' => false],
        ]);
    }

    private function hit(string $method, string $path): int
    {
        return (new Application(self::$disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();
    }

    public function testRoutesAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/v1/admin/navigation/menus'));
        self::assertSame(404, $this->hit('PUT', '/v1/admin/navigation/menus/main/items'));
        self::assertSame(404, $this->hit('GET', '/v1/menus/main'));
    }

    public function testMenuReaderResolvesNullWhenDisabled(): void
    {
        $reader = self::$disabledApp->getContainer()->get(MenuReader::class);
        self::assertNull($reader->menu('main', 'en'), 'disabled capability must look like pack absent');
    }

    public function testPackSourceHasNoAppReferences(): void
    {
        // Mirror scripts/check-pack-boundaries.php: a leading [^\w] catches bare FQCNs too.
        $root = dirname(__DIR__, 3) . '/packages/thallo-navigation/src';
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
