<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A text block can be a link (`url`, `new_tab`): a Heading's text is the link; a Rich text block
 * is covered by one, its own links staying clickable above it. The URL passes safe_url, so a
 * `javascript:` one leaves the text as it was. Animated text has no link: it is a reveal, not a
 * label.
 *
 * A link has a hover look an author can set (hover state spec §2.2): a Heading's on its Link part,
 * the link itself; a Rich text block's on the block, and only while it is a link, so plain text
 * never changes under the pointer.
 */
final class TextBlockLinkTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     */
    private function render(string $type, array $data, array $settings = []): string
    {
        $base = $this->appContext()->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $locator = new ThemeLocator('default', $base . '/themes');
        $env = (new TwigFactory($locator, $extension, $base . '/storage/cache/twig'))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'textlink0001', 'type' => $type, 'data' => $data, 'settings' => $settings,
        ]]]);
    }

    public function testAHeadingWithAUrlIsALink(): void
    {
        $html = $this->render('heading', ['text' => 'Our story', 'level' => 'h2', 'url' => '/about']);
        self::assertMatchesRegularExpression(
            '~<h2 [^>]*><a class="thallo-block-heading__link" href="/about">Our story</a></h2>~',
            $html,
        );
        self::assertStringNotContainsString('target=', $html);

        $tab = $this->render('heading', ['text' => 'Shop', 'url' => 'https://example.com', 'new_tab' => true]);
        self::assertStringContainsString(
            '<a class="thallo-block-heading__link" href="https://example.com"'
                . ' target="_blank" rel="noopener noreferrer">',
            $tab,
        );
    }

    public function testAHeadingWithoutAUsableUrlIsPlainText(): void
    {
        foreach ([[], ['url' => ''], ['url' => 'javascript:alert(1)']] as $link) {
            $html = $this->render('heading', ['text' => 'Our story'] + $link);
            self::assertStringNotContainsString('<a', $html, json_encode($link) ?: '');
            self::assertStringContainsString('>Our story</h2>', $html);
        }
    }

    public function testARichTextBlockWithAUrlIsCoveredByALinkAndKeepsItsOwn(): void
    {
        $html = $this->render('rich_text', [
            'body' => '<p>Call us now, or <a href="mailto:a@b.co">email</a>.</p>',
            'url' => 'tel:+233597478403',
            'new_tab' => true,
        ]);
        self::assertStringContainsString(
            '<a class="thallo-block-link" href="tel:+233597478403" target="_blank" rel="noopener noreferrer"'
                . ' aria-label="Call us now, or email."></a>',
            $html,
        );
        self::assertStringContainsString('href="mailto:a&#64;b.co"', $html, 'its own link stays');
        self::assertStringContainsString('thallo-block-rich_text--linked', $html);
    }

    public function testARichTextBlockWithoutAUsableUrlHasNoCover(): void
    {
        foreach ([[], ['url' => ''], ['url' => 'javascript:alert(1)']] as $link) {
            $html = $this->render('rich_text', ['body' => '<p>Text</p>'] + $link);
            self::assertStringNotContainsString('thallo-block-link', $html, json_encode($link) ?: '');
            self::assertStringNotContainsString('--linked', $html);
        }
    }

    public function testHeadingAndRichTextOfferALinkAndAnimatedTextDoesNot(): void
    {
        $fields = [];
        foreach (StarterBlockTypes::definitions() as $definition) {
            $fields[$definition['slug']] = array_column($definition['schema'], 'type', 'name');
        }
        foreach (['heading', 'rich_text'] as $slug) {
            self::assertSame('string', $fields[$slug]['url'] ?? null, $slug);
            self::assertSame('boolean', $fields[$slug]['new_tab'] ?? null, $slug);
        }
        self::assertArrayNotHasKey('url', $fields['animated_text']);
    }

    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    private static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
    }

    /** @return StyleTargets the shipped declaration of `$slug` */
    private static function targets(string $slug): StyleTargets
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === $slug) {
                return StyleTargets::fromDeclaration($definition['style_targets']);
            }
        }
        self::fail("no starter block {$slug}");
    }

    public function testAHeadingsLinkPartOffersATextColourAtRestAndOnHover(): void
    {
        $heading = self::targets('heading');
        self::assertTrue($heading->isPart('link'));
        foreach (['colors.text', 'hover.colors.text'] as $path) {
            self::assertTrue($heading->partCapabilities('link')->allows($path), $path);
        }
        self::assertNotContains('hover.colors.text', $heading->stylePathsFor('root'), 'the heading is not the link');
    }

    public function testALinkedHeadingsLinkCarriesItsLinkPart(): void
    {
        $html = $this->render('heading', ['text' => 'Our story', 'url' => '/about'], ['parts' => ['link' => [
            'colors' => ['text' => self::token('color.muted')],
            'hover' => ['colors' => ['text' => self::token('color.accent')]],
        ]]]);
        self::assertMatchesRegularExpression(
            '~<h2 class="thallo-block thallo-block-heading"><a class="thallo-block-heading__link'
                . ' t-fg-muted t-hover-fg-accent" href="/about">~',
            $html,
        );
    }

    public function testALinkTakesAWeightAndALineOnHover(): void
    {
        $paths = [
            'typography.weight', 'typography.decoration', 'hover.typography.weight', 'hover.typography.decoration',
        ];
        $heading = self::targets('heading');
        foreach ($paths as $path) {
            self::assertTrue($heading->partCapabilities('link')->allows($path), "heading link: {$path}");
            self::assertTrue(self::targets('links')->partCapabilities('link')->allows($path), "links: {$path}");
            self::assertContains($path, self::targets('rich_text')->stylePathsFor('root'), "rich_text: {$path}");
        }
        self::assertNotContains('typography.size', $heading->partCapabilities('link')->paths(), 'the heading sizes it');

        $html = $this->render('heading', ['text' => 'Our story', 'url' => '/about'], ['parts' => ['link' => [
            'typography' => ['decoration' => self::choice('none')],
            'hover' => ['typography' => ['weight' => self::choice('bold'), 'decoration' => self::choice('underline')]],
        ]]]);
        self::assertMatchesRegularExpression(
            '~<a class="thallo-block-heading__link t-decor-none t-hover-weight-bold t-hover-decor-underline"~',
            $html,
        );
    }

    public function testARichTextBlockOffersAHoverTextColour(): void
    {
        self::assertContains('hover.colors.text', self::targets('rich_text')->stylePathsFor('root'));
    }

    public function testARichTextBlockCarriesItsHoverColourOnlyWhileItIsALink(): void
    {
        $settings = ['style' => [
            'colors' => ['text' => self::token('color.muted')],
            'hover' => ['colors' => ['text' => self::token('color.accent')]],
        ]];
        $linked = $this->render('rich_text', ['body' => '<p>Call us</p>', 'url' => 'tel:+233597478403'], $settings);
        self::assertMatchesRegularExpression('~class="[^"]*--linked[^"]*\bt-hover-fg-accent\b~', $linked);

        $plain = $this->render('rich_text', ['body' => '<p>Call us</p>'], $settings);
        self::assertStringContainsString('t-fg-muted', $plain, 'its colour at rest stays');
        self::assertStringNotContainsString('t-hover-', $plain);
    }
}
