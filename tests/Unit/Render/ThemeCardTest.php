<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Themes\ThemeCard;

/**
 * A theme was only a name in a select. Its manifest may now say what it IS — a title, a
 * description, an author, tags, a screenshot — and the admin shows that as a card. None of it is
 * required and none of it can break a theme: a wrong value is left out of the card, never an error,
 * because a theme that renders the site must stay selectable whatever its card says.
 */
final class ThemeCardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/thallo-theme-card-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/assets', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['assets/shot.png', 'screenshot.jpg', 'big.png', 'theme.json'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/assets');
        @rmdir($this->dir);
    }

    /** @param array<string,mixed> $manifest */
    private function card(array $manifest): ThemeCard
    {
        file_put_contents($this->dir . '/theme.json', json_encode($manifest));
        return ThemeCard::fromDir('aurora', $this->dir);
    }

    public function testItReadsWhatTheManifestSaysAboutTheTheme(): void
    {
        file_put_contents($this->dir . '/assets/shot.png', 'png');
        $card = $this->card([
            'name' => 'aurora',
            'title' => 'Aurora',
            'version' => '2.1.0',
            'description' => '  A bright theme for studios.  ',
            'author' => 'Studio North',
            'tags' => ['Portfolio', 'dark mode', 'portfolio'],
            'screenshot' => 'assets/shot.png',
            'colors' => ['background' => '#0B1020', 'text' => '#fff', 'accent' => '#7c3aed'],
        ])->toArray();

        self::assertSame('aurora', $card['name']);
        self::assertSame('Aurora', $card['title']);
        self::assertSame('2.1.0', $card['version']);
        self::assertSame('A bright theme for studios.', $card['description']);
        self::assertSame('Studio North', $card['author']);
        self::assertSame(['portfolio', 'dark mode'], $card['tags'], 'lowercased, and once each');
        self::assertSame(['background' => '#0b1020', 'text' => '#fff', 'accent' => '#7c3aed'], $card['colors']);
        // Versioned by the file's own mtime: a new screenshot is a new URL, so it can cache hard.
        self::assertMatchesRegularExpression('~\A/_thallo/theme-screenshot/aurora\?v=\d+\z~', $card['screenshot_url']);
    }

    public function testAThemeThatSaysNothingStillHasACard(): void
    {
        $card = $this->card(['name' => 'aurora'])->toArray();
        self::assertSame('aurora', $card['title'], 'its name stands in for a title');
        self::assertNull($card['version']);
        self::assertNull($card['description']);
        self::assertNull($card['author']);
        self::assertSame([], $card['tags']);
        self::assertNull($card['colors']);
        self::assertNull($card['screenshot_url']);
    }

    public function testAScreenshotAtTheThemesRootIsFoundWithoutBeingNamed(): void
    {
        file_put_contents($this->dir . '/screenshot.jpg', 'jpg');
        $card = $this->card(['name' => 'aurora']);
        self::assertSame($this->dir . '/screenshot.jpg', $card->screenshotFile());
        self::assertNotNull($card->toArray()['screenshot_url']);
    }

    public function testAScreenshotIsOnlyEverAnImageInsideTheTheme(): void
    {
        file_put_contents($this->dir . '/theme.json', '{}');
        $refused = ['../theme.json', '/etc/passwd', 'theme.json', 'assets/../../x.png', 'missing.png', 'a.svg'];
        foreach ($refused as $path) {
            $card = $this->card(['name' => 'aurora', 'screenshot' => $path]);
            self::assertNull($card->screenshotFile(), $path);
        }
        // And not one so large the gallery would crawl.
        file_put_contents($this->dir . '/big.png', str_repeat('x', ThemeCard::MAX_SCREENSHOT_BYTES + 1));
        self::assertNull($this->card(['name' => 'aurora', 'screenshot' => 'big.png'])->screenshotFile());
    }

    public function testWrongValuesAreLeftOutRatherThanRefused(): void
    {
        $card = $this->card([
            'name' => 'aurora',
            'title' => ['not', 'a', 'string'],
            'version' => 3,
            'description' => str_repeat('d', 400),
            'author' => '',
            'tags' => ['ok', 7, '<script>', str_repeat('t', 40), 'a', 'b', 'c', 'd', 'e', 'f'],
            'colors' => ['background' => 'red', 'text' => '#12345', 'accent' => '#abcdef'],
        ])->toArray();
        self::assertSame('aurora', $card['title']);
        self::assertNull($card['version']);
        self::assertSame(ThemeCard::MAX_DESCRIPTION, mb_strlen((string) $card['description']));
        self::assertNull($card['author']);
        self::assertSame(['ok', 'a', 'b', 'c', 'd', 'e'], $card['tags'], 'six at most');
        self::assertSame(['accent' => '#abcdef'], $card['colors']);
    }

    public function testAManifestThatIsNotJsonStillGivesTheBareCard(): void
    {
        file_put_contents($this->dir . '/theme.json', '{not json');
        $card = ThemeCard::fromDir('aurora', $this->dir)->toArray();
        self::assertSame('aurora', $card['title']);
    }
}
