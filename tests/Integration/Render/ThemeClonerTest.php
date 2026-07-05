<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderThemeValidator;
use Glueful\Lemma\Render\Templates\ThemeCloner;

/**
 * Clone-theme pins: strict lowercase name grammar, 'default' reserved,
 * refuse-overwrite, full copy with theme.json name rewrite, and the clone is
 * immediately a VALID selectable theme (the validator accepts it).
 */
final class ThemeClonerTest extends LemmaTestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themesDir = sys_get_temp_dir() . '/lemma-theme-clone-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->themesDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->themesDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->themesDir);
        }
        parent::tearDown();
    }

    private function cloner(): ThemeCloner
    {
        $packThemes = dirname(__DIR__, 3) . '/packages/lemma-render/themes';
        return new ThemeCloner($this->themesDir, $packThemes, new RenderThemeValidator($this->themesDir));
    }

    public function testClonesTheDefaultThemeAndRewritesThemeJson(): void
    {
        $created = $this->cloner()->clone('corporate');

        self::assertSame('corporate', $created['name']);
        self::assertFileExists($this->themesDir . '/corporate/theme.json');
        self::assertFileExists($this->themesDir . '/corporate/templates/layout.twig');
        self::assertFileExists($this->themesDir . '/corporate/assets/site.css');

        $config = json_decode((string) file_get_contents($this->themesDir . '/corporate/theme.json'), true);
        self::assertSame('corporate', $config['name']);

        // The clone is immediately a valid selectable theme.
        self::assertTrue((new RenderThemeValidator($this->themesDir))->isValidTheme('corporate'));
    }

    public function testCloneFromAClonedThemeWorks(): void
    {
        $this->cloner()->clone('corporate');
        file_put_contents($this->themesDir . '/corporate/assets/site.css', '.corp { color: teal; }');

        $this->cloner()->clone('corporate-dark', 'corporate');
        self::assertStringContainsString(
            '.corp',
            (string) file_get_contents($this->themesDir . '/corporate-dark/assets/site.css'),
        );
    }

    public function testNameGrammarReservedNameAndOverwriteAreRejected(): void
    {
        $cloner = $this->cloner();

        foreach (['Corporate', 'my theme', '../evil', '-lead', ''] as $bad) {
            try {
                $cloner->clone($bad);
                self::fail("name '{$bad}' must be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $cloner->clone('default');
            self::fail("'default' must be reserved");
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $cloner->clone('corporate');
        $this->expectException(\RuntimeException::class); // refuse-overwrite
        $cloner->clone('corporate');
    }

    public function testUnknownSourceThemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cloner()->clone('corporate', 'nope');
    }
}
