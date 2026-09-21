<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Markdown\MarkdownRenderer;

/**
 * Documentation is written in Markdown and kept in git, so a docs page stores its Markdown and the
 * site renders it. The rich-text sanitizer could not carry it: it has no tables, drops a code
 * fence's language and a heading's id. This renderer is the docs page's body: GitHub-flavoured
 * Markdown, a stable id on every heading with the table of contents they make, fenced code handed
 * to the theme's own code block — and nothing a source file says can become markup of its own.
 */
final class MarkdownRendererTest extends TestCase
{
    /** @return array{html:string,toc:list<array{id:string,text:string,level:int}>} */
    private function render(string $markdown): array
    {
        return (new MarkdownRenderer())->render(
            $markdown,
            static fn (string $code, string $language): string
                => '<code-block lang="' . $language . '">' . htmlspecialchars($code) . '</code-block>',
        );
    }

    public function testItRendersGithubFlavouredMarkdown(): void
    {
        $html = $this->render(
            "| Key | Value |\n|---|---|\n| a | b |\n\n~~old~~ https://example.com\n\n- [x] done",
        )['html'];
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Key</th>', $html);
        self::assertStringContainsString('<del>old</del>', $html);
        self::assertStringContainsString('<a href="https://example.com">', $html);
        self::assertStringContainsString('type="checkbox"', $html);
    }

    public function testEveryHeadingHasAStableIdAndTheTableOfContentsIsMadeOfThem(): void
    {
        $out = $this->render(
            "# Title\n\n## Install it\n\ntext\n\n### With `composer`\n\n## Install it\n\n#### Too deep",
        );
        self::assertStringContainsString('<h2 id="install-it">', $out['html']);
        self::assertStringContainsString('<h3 id="with-composer">', $out['html']);
        // Two headings with the same words are still two places to link to.
        self::assertStringContainsString('<h2 id="install-it-1">', $out['html']);
        // A link to the heading sits beside it, for a reader to copy.
        self::assertMatchesRegularExpression('~<a[^>]+href="#install-it"[^>]*class="heading-anchor"~', $out['html']);
        // h2 and h3 make the on-page contents: the title is the page's, deeper levels are noise.
        self::assertSame([
            ['id' => 'install-it', 'text' => 'Install it', 'level' => 2],
            ['id' => 'with-composer', 'text' => 'With composer', 'level' => 3],
            ['id' => 'install-it-1', 'text' => 'Install it', 'level' => 2],
        ], $out['toc']);
    }

    public function testFencedCodeIsHandedToTheThemeWithItsLanguage(): void
    {
        $html = $this->render("```php\n<?php echo '<b>';\n```\n\n```\nplain\n```\n\n    indented")['html'];
        self::assertStringContainsString(
            '<code-block lang="php">&lt;?php echo &#039;&lt;b&gt;&#039;;</code-block>',
            $html,
        );
        self::assertStringContainsString('<code-block lang="text">plain</code-block>', $html);
        self::assertStringContainsString('<code-block lang="text">indented</code-block>', $html);
        // A language is a word, never markup or a class list of the source's choosing.
        $odd = $this->render("```php\" onload=\"x\ncode\n```")['html'];
        self::assertStringContainsString('lang="php"', $odd);
        self::assertStringNotContainsString('onload', $odd);
    }

    public function testNothingASourceFileSaysBecomesMarkupOfItsOwn(): void
    {
        $html = $this->render(
            "<script>alert(1)</script>\n\n<div onclick=\"x\">raw</div>\n\n[bad](javascript:alert(1)) "
            . "[data](data:text/html,x) ![img](javascript:x)\n\n<!-- c --> a <b>bold</b>",
        )['html'];
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('data:text', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testAnEmptyBodyIsAnEmptyPage(): void
    {
        self::assertSame(['html' => '', 'toc' => []], $this->render("  \n"));
    }
}
