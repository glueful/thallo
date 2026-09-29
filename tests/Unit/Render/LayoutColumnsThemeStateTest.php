<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Render\Style\BlockStyleEmitter;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeVocabulary;

/**
 * The theme's own tracks (type layouts plan C2, S1). A container that declares no track count emits
 * `columns-auto` — one track, `grid-template-columns: none` — so a span pairs with a track state.
 * On a target whose declared defaults carry columns (the Product list's cards, an adaptive grid in
 * the shop stylesheet), that `none` in `@layer settings` would beat the theme's tracks: there the
 * emitter writes `columns-theme`, whose rule declares nothing, so the theme's tracks stand. Spans
 * pair with it as with `auto` — clamped to one track — and every other target is untouched.
 */
final class LayoutColumnsThemeStateTest extends TestCase
{
    private const DEFAULT_THEME = __DIR__ . '/../../../packages/thallo-render/themes/default';
    private const BREAKPOINTS = ['base', 'md', 'lg'];

    private static function css(): string
    {
        $json = json_decode((string) file_get_contents(self::DEFAULT_THEME . '/theme.json'), true);
        return StyleCompiler::compile(ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME));
    }

    private static function cards(?array $defaults): StyleTargets
    {
        $spec = ['kind' => 'stack'] + ($defaults === null ? [] : ['defaults' => $defaults]);
        return StyleTargets::fromDeclaration(StyleTargets::root('box', ['spacing'], [
            'targets' => ['cards' => $spec],
            'map' => ['layout.display' => 'cards', 'layout.columns' => 'cards'],
        ]));
    }

    /** @return list<string> the track classes a cards target emits */
    private static function tracks(array $settings, StyleTargets $targets, array $classes = []): array
    {
        return array_values(array_filter(
            (new BlockStyleEmitter())->classesFor($settings, $targets, 'cards', $classes),
            static fn (string $class): bool => str_contains($class, 't-cols-'),
        ));
    }

    public function testATargetWithDefaultColumnsEmitsTheThemeState(): void
    {
        $adaptive = self::cards(['display' => 'grid', 'columns' => ['label' => 'Adaptive']]);
        self::assertSame(['t-cols-theme', 'md:t-cols-theme', 'lg:t-cols-theme'], self::tracks([], $adaptive));
    }

    public function testEveryOtherTargetKeepsTheAutoState(): void
    {
        self::assertSame(['t-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto'], self::tracks([], self::cards(null)));
        // Defaults without columns say nothing about the tracks.
        self::assertSame(
            ['t-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto'],
            self::tracks([], self::cards(['display' => 'flex'])),
        );
    }

    public function testADeclaredTrackCountStillWins(): void
    {
        $adaptive = self::cards(['display' => 'grid', 'columns' => ['label' => 'Adaptive']]);
        $settings = ['style' => ['layout' => ['columns' => ['md' => ['type' => 'choice', 'value' => '3']]]]];
        // The default-state classes follow the declared ones, as `auto`'s always have.
        self::assertSame(['md:t-cols-3', 'lg:t-cols-3', 't-cols-theme'], self::tracks($settings, $adaptive));
    }

    public function testAnExplicitResetOverAClassIsEmittedAfterIt(): void
    {
        $adaptive = self::cards(['display' => 'grid', 'columns' => ['label' => 'Adaptive']]);
        $class = ['id' => 'threecols', 'style' => ['layout' => ['columns' => [
            'base' => ['type' => 'choice', 'value' => '3'],
        ]]]];
        $reset = ['style' => ['layout' => ['columns' => [
            'base' => ['type' => 'reset'], 'md' => ['type' => 'reset'], 'lg' => ['type' => 'reset'],
        ]]]];
        self::assertSame(['t-cols-3', 'md:t-cols-3', 'lg:t-cols-3'], self::tracks([], $adaptive, [$class]));
        self::assertSame(
            ['t-cols-reset', 'md:t-cols-reset', 'lg:t-cols-reset'],
            self::tracks($reset, $adaptive, [$class]),
        );
        $rule = self::rule(self::css(), 't-cols-reset');
        self::assertSame('grid-template-columns: revert-layer;', $rule, 'a reset reverts to the theme layer');
    }

    public function testTheThemeStateDeclaresNothing(): void
    {
        $css = self::css();
        foreach (self::BREAKPOINTS as $bp) {
            self::assertSame('', self::rule($css, ClassNames::for('layout.columns', 'theme', $bp)), $bp);
        }
        // The auto state is unchanged.
        self::assertSame('grid-template-columns: none;', self::rule($css, 't-cols-auto'));
    }

    public function testSpansPairWithTheThemeStateAsWithAuto(): void
    {
        $css = self::css();
        foreach (self::BREAKPOINTS as $bp) {
            foreach (['1', '2', '3', '4', '6', '12', 'full', 'reset'] as $span) {
                $child = ClassNames::selector(ClassNames::for('layout.span', $span, $bp));
                $auto = ClassNames::selector(ClassNames::for('layout.columns', 'auto', $bp));
                $theme = ClassNames::selector(ClassNames::for('layout.columns', 'theme', $bp));
                self::assertSame(
                    self::pairRule($css, $auto, $child),
                    self::pairRule($css, $theme, $child),
                    "span {$span} at {$bp}",
                );
            }
        }
        // And a span wider than one track clamps under both.
        self::assertSame('grid-column: 1 / -1;', self::pairRule($css, '.t-cols-theme', '.t-span-2'));
    }

    private static function rule(string $css, string $class): string
    {
        $selector = preg_quote(ClassNames::selector($class), '~');
        self::assertMatchesRegularExpression("~(^|\n){$selector} \{~", $css, $class);
        preg_match("~(?:^|\n){$selector} \{([^}]*)\}~", $css, $m);
        return trim($m[1] ?? '');
    }

    private static function pairRule(string $css, string $parent, string $child): string
    {
        $selector = preg_quote($parent . ' > ' . $child . ",\n", '~');
        self::assertMatchesRegularExpression("~{$selector}~", $css, "{$parent} > {$child}");
        preg_match("~{$selector}[^{]*\{([^}]*)\}~", $css, $m);
        return trim($m[1] ?? '');
    }
}
