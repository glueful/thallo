<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The theme's layout defaults (container-layout spec §3.4–§3.8), pinned as CSS: they are the
 * fallback every managed layout value overrides by layer order, and several compiled rules depend
 * on what the theme does NOT set.
 */
final class ContainerThemeDefaultsTest extends AppTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath()
                . '/packages/thallo-render/themes/default/assets/blocks.css',
        );
    }

    /** The rule body for a selector, whitespace-collapsed. */
    private function rule(string $selector): string
    {
        $quoted = preg_quote($selector, '~');
        self::assertMatchesRegularExpression("~{$quoted}\s*\{~", $this->css(), $selector);
        preg_match("~{$quoted}\s*\{([^}]*)\}~", $this->css(), $m);
        return trim(preg_replace('~\s+~', ' ', $m[1] ?? ''));
    }

    public function testTheContainerRootReadsTheRootLayoutVariableAndInitialisesItLocally(): void
    {
        // Min height sets --thallo-root-layout; the theme consumes it, so managed visibility keeps
        // sole authority over `display` (spec §3.5). The local initialisation stops a nested
        // container inheriting an ancestor's flex sizing.
        $rule = $this->rule('.thallo-block-container');
        self::assertStringContainsString('--thallo-root-layout: block;', $rule);
        self::assertStringContainsString('display: var(--thallo-root-layout);', $rule);
        self::assertStringContainsString('flex-direction: column;', $rule);
    }

    public function testTheInnerAreaInitialisesTheGutterLocallyAndFillsATallBand(): void
    {
        $rule = $this->rule('.thallo-block-container__inner');
        self::assertStringContainsString('--thallo-default-gutter: 0px;', $rule);
        self::assertStringContainsString('padding-inline: var(--thallo-default-gutter);', $rule);
        self::assertStringContainsString('flex: 1 1 auto;', $rule);
        // As a flex item the gutter's auto inline margins cancel the cross-axis stretch, so the
        // width is stated: a boxed content area fills its measure inside a tall band.
        self::assertStringContainsString('width: 100%;', $rule);
    }

    public function testTheInnerAreaIsAFlexColumnByDefaultAndSetsNoTrackCount(): void
    {
        // The default mode is a flex column whose gaps carry the block rhythm (spec §3.8, §11.1):
        // an untouched container stacks its children at the distances block flow gave them. The
        // default track state stays one track — span clamping (§3.7) depends on it.
        $rule = $this->rule('.thallo-block-container__inner');
        self::assertStringContainsString('display: flex;', $rule);
        self::assertStringContainsString('flex-direction: column;', $rule);
        self::assertStringContainsString('gap: var(--space-5);', $rule);
        self::assertStringNotContainsString('grid-template-columns', $rule);
    }

    public function testSpacingComesFromTheGapsInEveryModeAtEveryBreakpoint(): void
    {
        // One rule, no mode and no breakpoint in it: a container's direct children carry no
        // default vertical margin, on the page and on the stage (spec §3.8).
        $css = $this->css();
        self::assertMatchesRegularExpression(
            '~\.thallo-block-container__inner > \.thallo-block,\s*'
            . '\.thallo-block-container__inner > \.thallo-preview-block > \.thallo-block \{ margin-block: 0; \}~',
            $css,
        );
        // Nothing restores a margin for a mode: there is no block display to restore it for.
        self::assertStringNotContainsString('t-display-block', $css);
        self::assertDoesNotMatchRegularExpression('~t-display-(flex|grid|reset) > [^{]*\{ margin~', $css);
        // A rich text contributes no outer paragraph margin of its own — on the page and on the
        // stage, where its body sits one level deeper inside an edit region.
        self::assertStringContainsString('.thallo-block-rich_text > :first-child,', $css);
        self::assertStringContainsString(
            '.thallo-block-rich_text > .thallo-edit-region > :first-child { margin-top: 0; }',
            $css,
        );
        self::assertStringContainsString('.thallo-block-rich_text > :last-child,', $css);
        self::assertStringContainsString(
            '.thallo-block-rich_text > .thallo-edit-region > :last-child { margin-bottom: 0; }',
            $css,
        );
    }
}
