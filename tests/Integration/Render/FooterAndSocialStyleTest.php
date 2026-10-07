<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * The Footer block's divider — the line under its top section — takes its own colour, width and
 * style on that section's bottom edge; the Social links block's Icon section styles every one of its
 * links, which each Social link reads from its parent; and Font style is a Typography setting.
 */
final class FooterAndSocialStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => $blocks]);
    }

    /** @return list<string> the class attribute of every element carrying `$class` */
    private static function classesOf(string $html, string $class): array
    {
        preg_match_all('~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~', $html, $m);
        return $m[1];
    }

    public function testTheFooterDividerStylesTheTopSectionsBottomEdge(): void
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $html = $this->render([[
            'id' => 'footer000001', 'type' => 'footer',
            'data' => ['top' => [['id' => 'topnote00001', 'type' => 'rich_text', 'data' => ['body' => '<p>Top</p>']]]],
            'settings' => ['style' => ['footer' => [
                'divider_color' => ['type' => 'token', 'value' => 'color.accent'],
                'divider_width' => $choice('thick'),
                'divider_style' => $choice('dashed'),
            ]]],
        ]]);
        [$top] = self::classesOf($html, 'thallo-block-footer__top');
        [$root] = self::classesOf($html, 'thallo-block-footer');
        foreach (
            [
            ClassNames::for('footer.divider_color', 'color.accent'),
            ClassNames::for('footer.divider_width', 'thick'),
            ClassNames::for('footer.divider_style', 'dashed'),
            ] as $class
        ) {
            self::assertStringContainsString($class, $top);
            self::assertStringNotContainsString($class, $root);
        }
    }

    public function testTheSocialLinksIconSectionStylesEveryLink(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $link = static fn (string $id, string $icon): array => [
            'id' => $id, 'type' => 'social_link', 'data' => ['icon' => $icon, 'url' => 'https://example.com/' . $id],
        ];
        $html = $this->render([[
            'id' => 'socials00001', 'type' => 'social_links',
            'data' => ['items' => [$link('sociallink01', 'brand:instagram'), $link('sociallink02', 'brand:x')]],
            'settings' => ['parts' => ['icon' => [
                'colors' => ['surface' => $token('color.black'), 'border' => $token('color.accent')],
                'border' => ['width' => ['type' => 'choice', 'value' => 'thin']],
                'radius' => $token('radius.sm'),
                'typography' => ['size' => ['base' => $token('typography.size.lg')]],
                'spacing' => ['padding' => ['top' => ['base' => $token('spacing.sm')]]],
            ]]],
        ]]);
        $links = self::classesOf($html, 'thallo-block-social_link__link');
        self::assertCount(2, $links, $html);
        $look = [
            ClassNames::for('colors.surface', 'color.black'),
            ClassNames::for('colors.border', 'color.accent'),
            ClassNames::for('border.width', 'thin'),
            ClassNames::for('radius', 'radius.sm'),
            ClassNames::for('typography.size', 'typography.size.lg'),
            ClassNames::for('spacing.padding.top', 'spacing.sm'),
        ];
        foreach ($links as $classes) {
            foreach ($look as $class) {
                self::assertStringContainsString($class, $classes);
            }
        }
        // Not on the row, nor on each link's wrapper.
        $wrappers = [
            ...self::classesOf($html, 'thallo-block-social_links'),
            ...self::classesOf($html, 'thallo-block-social_link'),
        ];
        foreach ($wrappers as $classes) {
            self::assertStringNotContainsString(ClassNames::for('colors.surface', 'color.black'), $classes);
        }
    }

    public function testASocialLinkWithoutThatParentRendersUnstyled(): void
    {
        $html = $this->render([[
            'id' => 'sociallink09', 'type' => 'social_link',
            'data' => ['icon' => 'brand:x', 'url' => 'https://example.com/x'],
        ]]);
        self::assertSame(['thallo-block-social_link__link'], self::classesOf($html, 'thallo-block-social_link__link'));
    }

    public function testFontStyleIsATypographySetting(): void
    {
        $registry = $this->container()->get(BlockStyleRegistry::class);
        self::assertTrue($registry->capabilitiesFor('heading')->allows('typography.style'));
        $html = $this->render([[
            'id' => 'heading00001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['typography' => ['style' => ['type' => 'choice', 'value' => 'italic']]]],
        ]]);
        self::assertStringContainsString(ClassNames::for('typography.style', 'italic'), $html);
        self::assertStringNotContainsString('md:t-fstyle-', $html, 'set once for every width');
    }
}
