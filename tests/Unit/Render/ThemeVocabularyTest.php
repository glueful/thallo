<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Render\RenderThemeValidator;
use Thallo\Render\Style\ThemeVocabulary;
use Thallo\Render\ThemeConfigError;
use Thallo\Render\ThemeLocator;

/**
 * Visual builder spec §2.2: Thallo owns the token names, themes own the values. A theme maps
 * every baseline name in `theme.json` and lists its stylesheets; missing either fails loudly at
 * load and on switch.
 */
final class ThemeVocabularyTest extends TestCase
{
    private const DEFAULT_THEME = __DIR__ . '/../../../packages/thallo-render/themes/default';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** @return array<string,mixed> */
    private function defaultThemeJson(): array
    {
        return json_decode((string) file_get_contents(self::DEFAULT_THEME . '/theme.json'), true);
    }

    public function testTheShippedThemeMapsEveryBaselineName(): void
    {
        $vocabulary = ThemeVocabulary::fromThemeJson($this->defaultThemeJson(), self::DEFAULT_THEME);

        foreach (Vocabulary::all() as $token) {
            self::assertNotSame('', $vocabulary->value($token), $token);
        }
        self::assertSame('var(--space-4)', $vocabulary->value('spacing.lg'));
        self::assertSame('var(--accent)', $vocabulary->value('color.accent'));
        self::assertSame('999px', $vocabulary->value('radius.full'));
        self::assertSame('transparent', $vocabulary->value('color.transparent'));
        self::assertSame(
            [
                'assets/site.css',
                'assets/blocks.css',
                'assets/navigation.css',
                'assets/stepper.css',
                'assets/docs.css',
            ],
            $vocabulary->stylesheets(),
        );
    }

    public function testATokenWithALiteralDefaultIsFilledWhenAThemeOmitsIt(): void
    {
        // color.white joined the baseline after themes were copied: a theme.json that predates it
        // still loads, with the literal default, and a theme may still map it itself.
        $json = $this->defaultThemeJson();
        unset($json['vocabulary']['color.white']);
        $vocabulary = ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
        self::assertSame('#ffffff', $vocabulary->value('color.white'));

        $json['vocabulary']['color.white'] = 'var(--paper)';
        $mapped = ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
        self::assertSame('var(--paper)', $mapped->value('color.white'));
    }

    public function testAMissingBaselineNameFailsNamingIt(): void
    {
        $json = $this->defaultThemeJson();
        unset($json['vocabulary']['radius.full'], $json['vocabulary']['spacing.xs']);

        $this->expectException(ThemeConfigError::class);
        $this->expectExceptionMessage('theme "default" vocabulary is missing spacing.xs, radius.full');
        ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
    }

    public function testUnknownTokensAndNonStringValuesAreRejected(): void
    {
        $json = $this->defaultThemeJson();
        $json['vocabulary']['spacing.hero'] = '9rem';
        try {
            ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
            self::fail('extension accepted');
        } catch (ThemeConfigError $e) {
            self::assertSame('theme "default" vocabulary declares unknown token spacing.hero', $e->getMessage());
        }

        $json = $this->defaultThemeJson();
        $json['vocabulary']['radius.md'] = 12;
        $this->expectException(ThemeConfigError::class);
        $this->expectExceptionMessage('theme "default" vocabulary value for radius.md must be a CSS value string');
        ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
    }

    public function testStylesheetsMustBeListedAndExist(): void
    {
        $json = $this->defaultThemeJson();
        $json['stylesheets'][] = 'assets/missing.css';
        try {
            ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
            self::fail('missing stylesheet accepted');
        } catch (ThemeConfigError $e) {
            self::assertSame('theme "default" stylesheet assets/missing.css does not exist', $e->getMessage());
        }

        unset($json['stylesheets']);
        $this->expectException(ThemeConfigError::class);
        $this->expectExceptionMessage('theme "default" declares no stylesheets');
        ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
    }

    private function appThemesDirWith(array $themeJson, bool $withCss = true): string
    {
        $dir = sys_get_temp_dir() . '/thallo-vocab-' . uniqid('', true);
        mkdir($dir . '/custom/templates', 0755, true);
        mkdir($dir . '/custom/assets', 0755, true);
        if ($withCss) {
            file_put_contents($dir . '/custom/assets/site.css', ':root{}');
        }
        file_put_contents($dir . '/custom/theme.json', json_encode($themeJson));
        $this->tempDirs[] = $dir;
        return $dir;
    }

    public function testTheLocatorAndTheSwitchValidatorFailAnAppThemeWithoutAVocabulary(): void
    {
        $themes = $this->appThemesDirWith(['name' => 'custom', 'stylesheets' => ['assets/site.css']]);

        self::assertFalse((new RenderThemeValidator($themes))->isValidTheme('custom'));
        $this->expectException(ThemeConfigError::class);
        $this->expectExceptionMessage('theme "custom" vocabulary is missing');
        new ThemeLocator('custom', $themes);
    }

    public function testACompleteAppThemeLoadsAndSwitches(): void
    {
        $json = $this->defaultThemeJson();
        $json['name'] = 'custom';
        $json['stylesheets'] = ['assets/site.css'];
        $themes = $this->appThemesDirWith($json);

        self::assertTrue((new RenderThemeValidator($themes))->isValidTheme('custom'));
        self::assertSame('custom', (new ThemeLocator('custom', $themes))->activePaths()['name']);
    }
}
