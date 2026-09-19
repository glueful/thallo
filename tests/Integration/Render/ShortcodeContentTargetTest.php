<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * A shortcode block is two elements: a layout-neutral wrapper held to the page measure, and
 * whatever the included `shortcodes/<name>.twig` renders inside it. A background on the wrapper
 * would paint a bar across the page, so the settings that give something its look — colours,
 * border, radius, shadow — land on a second target, `content`: the shortcode's own element.
 *
 * The block template owns the helpers and hands the result down to the include, because only the
 * shortcode's template knows which element is "it". A shortcode that ignores what it is handed
 * is simply not styleable, which is why the target is optional.
 */
final class ShortcodeContentTargetTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $settings
     */
    private function shortcode(string $name, array $params = [], array $settings = []): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(l) }}')->render([
            'l' => [[
                'id' => 'sc0000000001', 'type' => 'shortcode',
                'data' => ['name' => $name, 'params' => $params],
                'settings' => $settings,
            ]],
            'site' => ['name' => 'Thallo', 'version' => '1.0.0-beta.43'],
        ]);
    }

    /** The opening tag that carries the class. */
    private function tagWithClass(string $html, string $class): string
    {
        $pattern = '~<[a-z0-9]+[^>]*\bclass="[^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*"[^>]*>~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class . ' in ' . $html);
        return $m[0];
    }

    /** @return array<string,mixed> the look of a pill, as the Style tab stores it */
    private function look(): array
    {
        return ['style' => [
            'colors' => [
                'surface' => ['type' => 'token', 'value' => 'color.background'],
                'text' => ['type' => 'token', 'value' => 'color.muted'],
            ],
            'border' => ['width' => ['type' => 'choice', 'value' => 'thin']],
            'radius' => ['type' => 'token', 'value' => 'radius.md'],
            'shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.sm']],
            'spacing' => ['margin' => ['bottom' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]],
        ]];
    }

    /** @return list<string> the utility classes `look()` compiles to, apart from the margin */
    private function lookClasses(): array
    {
        return [
            ClassNames::for('colors.surface', 'color.background'),
            ClassNames::for('colors.text', 'color.muted'),
            ClassNames::for('border.width', 'thin'),
            ClassNames::for('radius', 'radius.md'),
            ClassNames::for('shadow', 'shadow.sm'),
        ];
    }

    public function testBackgroundBorderRadiusAndShadowLandOnThePillAndNotOnTheWrapper(): void
    {
        $html = $this->shortcode('thallo-version', ['prefix' => 'Developer Preview '], $this->look());

        $pill = $this->tagWithClass($html, 'thallo-shortcode-version');
        $wrapper = $this->tagWithClass($html, 'thallo-block-shortcode');
        self::assertStringStartsWith('<span ', $pill);
        self::assertStringStartsWith('<div ', $wrapper);

        foreach ($this->lookClasses() as $class) {
            self::assertStringContainsString(' ' . $class, $pill, "{$class} belongs on the pill");
            self::assertStringNotContainsString($class, $wrapper, "{$class} must not paint the wrapper");
        }
        // Spacing stays where it was: the wrapper is what sits in the page's flow.
        $margin = ClassNames::for('spacing.margin.bottom', 'spacing.lg');
        self::assertStringContainsString(' ' . $margin, $wrapper);
        self::assertStringNotContainsString($margin, $pill);
        self::assertStringContainsString('>Developer Preview 1.0.0-beta.43</span>', $html);
    }

    public function testAnUnstyledShortcodeRendersExactlyAsItDid(): void
    {
        $html = $this->shortcode('thallo-version', ['prefix' => 'Developer Preview ']);
        self::assertStringContainsString(
            '<span class="thallo-shortcode-version">Developer Preview 1.0.0-beta.43</span>',
            $html,
        );
    }

    public function testTheCopyrightShortcodeTakesTheSameSettingsOnItsOwnElement(): void
    {
        $html = $this->shortcode('copyright', ['name' => 'Thallo'], $this->look());
        $line = $this->tagWithClass($html, 'thallo-shortcode-copyright');
        foreach ($this->lookClasses() as $class) {
            self::assertStringContainsString(' ' . $class, $line);
        }
    }

    public function testTheDotCanBeHiddenOrGivenAThemeColour(): void
    {
        $plain = $this->tagWithClass($this->shortcode('thallo-version'), 'thallo-shortcode-version');
        self::assertStringNotContainsString('--dot-', $plain);
        self::assertStringNotContainsString('--no-dot', $plain);

        $hidden = $this->tagWithClass(
            $this->shortcode('thallo-version', ['dot' => false]),
            'thallo-shortcode-version',
        );
        self::assertStringContainsString('thallo-shortcode-version--no-dot', $hidden);

        $muted = $this->tagWithClass(
            $this->shortcode('thallo-version', ['dot_color' => 'muted']),
            'thallo-shortcode-version',
        );
        self::assertStringContainsString('thallo-shortcode-version--dot-muted', $muted);
        self::assertStringNotContainsString('--no-dot', $muted);
    }

    public function testADotColourTheThemeDoesNotNameNeverReachesTheClassAttribute(): void
    {
        // `params` is free JSON typed by an author: only the theme's own colour names become a class.
        foreach (['hotpink', 'muted" onmouseover="x', '', 7, ['muted']] as $value) {
            $tag = $this->tagWithClass(
                $this->shortcode('thallo-version', ['dot_color' => $value]),
                'thallo-shortcode-version',
            );
            self::assertStringNotContainsString('--dot-', $tag, json_encode($value) ?: '');
            self::assertStringNotContainsString('onmouseover', $tag);
        }
    }

    public function testTheThemeDrawsTheDotInTheTextColourAndKnowsEveryDotColourItAccepts(): void
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $css = (string) file_get_contents($base . '/packages/thallo-render/themes/default/assets/blocks.css');

        // The dot follows the text: recolour the pill's text in the Style tab and the dot comes
        // along. `--version-dot` still overrides it for a site's custom CSS.
        self::assertStringContainsString('background: var(--version-dot, currentColor)', $css);
        self::assertStringContainsString('.thallo-shortcode-version--no-dot::before { display: none; }', $css);
        foreach (['accent', 'text', 'muted', 'accent-contrast', 'background'] as $name) {
            self::assertStringContainsString(".thallo-shortcode-version--dot-{$name} ", $css, $name);
        }
        // A border width set in the Style tab has a style and a colour to show itself with.
        self::assertStringContainsString('border: 0 solid var(--line)', $css);
    }
}
