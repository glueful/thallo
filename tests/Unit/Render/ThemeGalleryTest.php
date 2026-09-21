<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\RenderThemeValidator;
use Thallo\Render\Templates\ThemeCloner;
use Thallo\Render\Themes\ThemeGallery;

/**
 * The gallery is the set of themes an operator may switch to — exactly the validator's set, in
 * the switcher's order — each with its card. A directory that is not a loadable theme has no card
 * and no screenshot, however nicely its manifest describes it.
 */
final class ThemeGalleryTest extends TestCase
{
    private string $app;
    private string $pack;

    protected function setUp(): void
    {
        $this->pack = \dirname(__DIR__, 3) . '/packages/thallo-render/themes';
        $this->app = sys_get_temp_dir() . '/thallo-gallery-' . bin2hex(random_bytes(4));
        mkdir($this->app, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->app, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->app);
    }

    private function gallery(): ThemeGallery
    {
        return new ThemeGallery($this->app, $this->pack, new RenderThemeValidator($this->app));
    }

    public function testTheShippedThemeIsFirstAndDescribesItself(): void
    {
        $cards = $this->gallery()->cards();
        self::assertSame(['default'], array_map(static fn ($c) => $c->toArray()['name'], $cards));
        $card = $cards[0]->toArray();
        // The theme Thallo ships is the example of a described theme: every key, and a screenshot.
        self::assertSame('Default', $card['title']);
        self::assertNotNull($card['description']);
        self::assertSame('Thallo', $card['author']);
        self::assertNotSame([], $card['tags']);
        self::assertNotNull($card['screenshot_url']);
        $shot = $cards[0]->screenshotFile();
        self::assertNotNull($shot);
        self::assertLessThan(400_000, filesize($shot), 'a thumbnail, not a poster');
    }

    public function testAnAppThemeJoinsItOnlyWhileItIsALoadableTheme(): void
    {
        (new ThemeCloner($this->app, $this->pack, new RenderThemeValidator($this->app)))->clone('aurora', 'default');
        mkdir($this->app . '/broken');
        file_put_contents($this->app . '/broken/theme.json', '{"name":"broken","title":"Looks fine"}');

        $names = array_map(static fn ($c) => $c->toArray()['name'], $this->gallery()->cards());
        self::assertSame(['default', 'aurora'], $names);
        self::assertNotNull($this->gallery()->card('aurora'));
        self::assertNull($this->gallery()->card('broken'));
        self::assertNull($this->gallery()->card('../default'));
        self::assertNull($this->gallery()->card('nope'));
    }
}
