<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A feature's marker — its icon chip or number badge — has corners and a shadow of its own, set
 * in the Style tab under Marker. They are the `marker.*` paths and land on the `marker` target;
 * the block's `radius` and `shadow` stay the card's, on the root. Two elements, two slots.
 *
 * A number badge's lettering — typeface, style, weight, transform — is the `number` part, shown in
 * the Style tab only while the marker is a number (its `when`); its size stays the marker's Size.
 *
 * The marker takes a padding of its own (`marker.padding`, settings version 20), one value for all
 * four sides of the chip. A feature can carry a headline above its title, styled as the `headline`
 * part: its lettering, colour and the space below it.
 */
final class FeatureMarkerStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $style
     * @param array<string,mixed> $parts
     */
    private function feature(array $data, array $style, array $parts = []): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'feat00000001', 'type' => 'feature',
            'data' => ['title' => 'Step', 'description' => 'Text'] + $data,
            'settings' => ['style' => $style, 'parts' => $parts],
        ]]]);
    }

    private function tag(string $html, string $class): string
    {
        $pattern = '~<[a-z0-9]+[^>]*\bclass="[^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*"[^>]*>~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class . ' in ' . $html);
        return $m[0];
    }

    /** @return array<string,mixed> */
    private function style(): array
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        return [
            'marker' => ['radius' => $token('radius.full'), 'shadow' => ['base' => $token('shadow.md')]],
            'radius' => $token('radius.lg'),
            'shadow' => ['base' => $token('shadow.sm')],
        ];
    }

    public function testTheMarkersCornersAndShadowLandOnTheMarkerAndTheCardsStayOnTheCard(): void
    {
        $html = $this->feature(['marker' => 'number', 'number' => '1'], $this->style());
        $marker = $this->tag($html, 'thallo-block-feature__marker');
        $root = $this->tag($html, 'thallo-block-feature');

        $own = [ClassNames::for('marker.radius', 'radius.full'), ClassNames::for('marker.shadow', 'shadow.md')];
        $card = [ClassNames::for('radius', 'radius.lg'), ClassNames::for('shadow', 'shadow.sm')];
        foreach ($own as $class) {
            self::assertStringContainsString(' ' . $class, $marker);
            self::assertStringNotContainsString($class, $root);
        }
        foreach ($card as $class) {
            self::assertStringContainsString(' ' . $class, $root);
            self::assertStringNotContainsString($class, $marker);
        }
    }

    public function testAnIconMarkerTakesThemToo(): void
    {
        $html = $this->feature(['marker' => 'icon', 'icon' => 'star'], $this->style());
        $marker = $this->tag($html, 'thallo-block-feature__marker');
        self::assertStringContainsString(' ' . ClassNames::for('marker.radius', 'radius.full'), $marker);
    }

    public function testAFeatureWithNoMarkerRendersNoneAndIsNotAnError(): void
    {
        // The target is optional: `marker = none`, or a number marker with no number, has no element.
        $html = $this->feature(['marker' => 'none'], $this->style());
        self::assertStringNotContainsString('thallo-block-feature__marker', $html);
        self::assertStringNotContainsString(ClassNames::for('marker.radius', 'radius.full'), $html);
    }

    /** @return array<string,mixed> the `number` part: a serif, italic, bold, uppercase number */
    private static function lettering(): array
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        return ['number' => ['typography' => [
            'family' => ['type' => 'font', 'value' => 'serif'],
            'style' => $choice('italic'),
            'weight' => ['base' => $choice('bold')],
            'transform' => $choice('uppercase'),
        ]]];
    }

    public function testTheNumberPartOffersTheBadgesLetteringButNotItsSize(): void
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === 'feature') {
                $declaration = $definition['style_targets'];
            }
        }
        $targets = StyleTargets::fromDeclaration($declaration ?? []);
        self::assertTrue($targets->isPart('number'));
        $offers = $targets->partCapabilities('number');
        foreach (['typography.family', 'typography.style', 'typography.weight', 'typography.transform'] as $path) {
            self::assertTrue($offers->allows($path), $path);
        }
        self::assertFalse($offers->allows('typography.size'), 'the badge\'s size is the marker\'s Size');
        self::assertSame(['field' => 'marker', 'equals' => 'number'], $declaration['parts']['number']['when'] ?? null);
    }

    public function testANumberBadgeTakesItsLettering(): void
    {
        $html = $this->feature(['marker' => 'number', 'number' => 'Step 1'], [], self::lettering());
        $marker = $this->tag($html, 'thallo-block-feature__marker');
        $lettering = [
            't-font-serif',
            ClassNames::for('typography.style', 'italic'),
            ClassNames::for('typography.weight', 'bold'),
            ClassNames::for('typography.transform', 'uppercase'),
        ];
        foreach ($lettering as $class) {
            self::assertStringContainsString(' ' . $class, $marker);
        }
        self::assertStringNotContainsString('t-font-serif', $this->tag($html, 'thallo-block-feature__title'));
    }

    public function testAnIconMarkerTakesNoLettering(): void
    {
        $html = $this->feature(['marker' => 'icon', 'icon' => 'star'], [], self::lettering());
        self::assertStringNotContainsString('t-font-serif', $html);
        self::assertStringNotContainsString(ClassNames::for('typography.transform', 'uppercase'), $html);
    }

    public function testTheMarkerTakesAPaddingOfItsOwn(): void
    {
        $padding = ['marker' => ['padding' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]];
        foreach ([['marker' => 'icon', 'icon' => 'star'], ['marker' => 'number', 'number' => '1']] as $data) {
            $html = $this->feature($data, $padding);
            self::assertStringContainsString(
                ' ' . ClassNames::for('marker.padding', 'spacing.lg'),
                $this->tag($html, 'thallo-block-feature__marker'),
                $data['marker'],
            );
            self::assertStringNotContainsString(
                ClassNames::for('marker.padding', 'spacing.lg'),
                $this->tag($html, 'thallo-block-feature'),
            );
        }
    }

    public function testAHeadlineSitsAboveTheTitleAndTakesItsOwnStyle(): void
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $html = $this->feature(['headline' => 'Online store'], [], ['headline' => [
            'typography' => ['transform' => $choice('none'), 'letter_spacing' => $choice('normal')],
            'colors' => ['text' => ['type' => 'token', 'value' => 'color.muted']],
        ]]);
        $headline = $this->tag($html, 'thallo-block-feature__headline');
        self::assertStringStartsWith('<p ', $headline);
        foreach (
            [
            ClassNames::for('typography.transform', 'none'),
            ClassNames::for('typography.letter_spacing', 'normal'),
            't-fg-muted',
            ] as $class
        ) {
            self::assertStringContainsString(' ' . $class, $headline);
        }
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block-feature__body">\\s*<p class="thallo-block-feature__headline[^"]*">'
                . '[^<]*Online store[^<]*</p>\s*<h3 ~',
            preg_replace('~<!--.*?-->|<span[^>]*data-thallo-edit[^>]*>|</span>~s', '', $html) ?? '',
        );
    }

    public function testAFeatureWithoutAHeadlineRendersNone(): void
    {
        foreach ([[], ['headline' => '']] as $data) {
            self::assertStringNotContainsString('__headline', $this->feature($data, []));
        }
    }

    public function testTheHeadlinePartOffersLetteringColourAndTheSpaceBelow(): void
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === 'feature') {
                $declaration = $definition['style_targets'];
                $fields = array_column($definition['schema'], 'type', 'name');
            }
        }
        self::assertSame('string', $fields['headline'] ?? null);
        $offers = StyleTargets::fromDeclaration($declaration ?? [])->partCapabilities('headline');
        foreach (
            [
            'typography.family', 'typography.size', 'typography.weight', 'typography.letter_spacing',
            'typography.transform', 'colors.text', 'spacing.margin.bottom',
            ] as $path
        ) {
            self::assertTrue($offers->allows($path), $path);
        }
    }
}
