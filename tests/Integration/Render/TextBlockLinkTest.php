<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A text block can be a link (`url`, `new_tab`): a Heading's text is the link; a Rich text block
 * is covered by one, its own links staying clickable above it. The URL passes safe_url, so a
 * `javascript:` one leaves the text as it was. Animated text has no link: it is a reveal, not a
 * label.
 */
final class TextBlockLinkTest extends AppTestCase
{
    /** @param array<string,mixed> $data */
    private function render(string $type, array $data): string
    {
        $base = $this->appContext()->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $locator = new ThemeLocator('default', $base . '/themes');
        $env = (new TwigFactory($locator, $extension, $base . '/storage/cache/twig'))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'textlink0001', 'type' => $type, 'data' => $data,
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
}
