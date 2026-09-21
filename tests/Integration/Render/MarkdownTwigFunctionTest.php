<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Templates\TemplatePolicy;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A theme renders a docs page's Markdown with `markdown()` and its contents with `markdown_toc()`.
 * A code fence comes out as the THEME'S OWN code block — its frame, its language label, its copy
 * button — so documentation code and a code block on a landing page are one thing.
 */
final class MarkdownTwigFunctionTest extends AppTestCase
{
    /** @param array<string,mixed> $context */
    private function render(string $template, array $context): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment()->createTemplate($template)->render($context);
    }

    private const SOURCE = "## Install\n\n```bash\n$ composer install\n```\n\n"
        . "| A | B |\n|---|---|\n| 1 | 2 |\n\n<script>x</script>";

    public function testMarkdownRendersThroughTheThemesCodeBlock(): void
    {
        $html = $this->render('{{ markdown(src) }}', ['src' => self::SOURCE]);
        self::assertStringContainsString('<h2 id="install">', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringNotContainsString('<script>x', $html);
        // The theme's code block, with the shell prompt drawn rather than written.
        self::assertStringContainsString('class="thallo-block thallo-block-code"', $html);
        self::assertStringContainsString('data-language="bash"', $html);
        self::assertStringContainsString('thallo-block-code__line--prompt', $html);
        self::assertStringContainsString('>composer install<', $html);
        // The output is markup, not escaped text.
        self::assertStringNotContainsString('&lt;h2', $html);
    }

    public function testTheCodeBlocksScriptIsAskedForOnceHoweverManyListings(): void
    {
        $html = $this->render('{{ markdown(src) }}', ['src' => "```php\na\n```\n\n```php\nb\n```"]);
        self::assertSame(2, substr_count($html, 'thallo-block-code__pre'));
        self::assertSame(1, substr_count($html, 'block-code.js'), $html);
    }

    public function testTheTableOfContentsIsTheSameRenderAsTheBody(): void
    {
        $html = $this->render(
            '{% for h in markdown_toc(src) %}[{{ h.level }}:{{ h.id }}:{{ h.text }}]{% endfor %}',
            ['src' => self::SOURCE . "\n\n### Next `step`"],
        );
        self::assertSame('[2:install:Install][3:next-step:Next step]', $html);
    }

    public function testBothAreAllowedInATemplateEditedInTheAdmin(): void
    {
        self::assertContains('markdown', TemplatePolicy::FUNCTIONS);
        self::assertContains('markdown_toc', TemplatePolicy::FUNCTIONS);
    }

    public function testSomethingThatIsNotTextRendersNothing(): void
    {
        self::assertSame('', $this->render('{{ markdown(src) }}', ['src' => ['not', 'text']]));
        self::assertSame('', $this->render('{{ markdown(src) }}', ['src' => null]));
    }
}
