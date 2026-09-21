<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Importers;

use PHPUnit\Framework\TestCase;
use Thallo\Importers\Markdown\MarkdownPage;

/**
 * One Markdown file of a docs folder, read as a page. The front matter says what it can; the file
 * says the rest — so a folder written for GitHub (no front matter at all, a `# Title` on top,
 * `01-` prefixes for order, links between `.md` files) imports as it stands.
 */
final class MarkdownPageTest extends TestCase
{
    private const SECTIONS = ['getting-started', 'guides'];

    public function testTheFrontMatterSaysWhatThePageIs(): void
    {
        $page = MarkdownPage::read('guides/theming.md', <<<MD
            ---
            title: "Theming a site"
            slug: Theme Guide
            section: guides
            order: 3
            summary: How a theme is put together.
            ---
            # Ignored as a title, kept as written

            Body.
            MD, self::SECTIONS);
        self::assertSame('theme-guide', $page->slug);
        self::assertSame('Theming a site', $page->title);
        self::assertSame('guides', $page->section);
        self::assertSame(3.0, $page->order);
        self::assertSame('How a theme is put together.', $page->summary);
        self::assertSame('guides/theming.md', $page->sourcePath);
        self::assertStringContainsString('# Ignored as a title', $page->body);
        self::assertFalse($page->skip);
    }

    public function testAFileWithNoFrontMatterSaysItWithItsNameItsFolderAndItsFirstHeading(): void
    {
        $page = MarkdownPage::read(
            'getting-started/02-First_Page.md',
            "# Your first page\n\nStart here.\n",
            self::SECTIONS,
        );
        self::assertSame('first-page', $page->slug);
        self::assertSame(2.0, $page->order);
        self::assertSame('getting-started', $page->section);
        self::assertSame('Your first page', $page->title);
        // The heading became the page's title, which the template prints: not twice.
        self::assertSame("Start here.", trim($page->body));

        // A folder that is not one of the type's sections is just a folder.
        self::assertNull(MarkdownPage::read('misc/notes.md', 'x', self::SECTIONS)->section);
        // With no heading either, the name is the title.
        self::assertSame('Release notes', MarkdownPage::read('release-notes.md', 'x', self::SECTIONS)->title);
    }

    public function testAnIndexFileIsItsFolder(): void
    {
        self::assertSame('guides', MarkdownPage::read('guides/README.md', 'x', self::SECTIONS)->slug);
        self::assertSame('guides', MarkdownPage::read('guides/index.md', 'x', self::SECTIONS)->slug);
        self::assertSame('index', MarkdownPage::read('README.md', 'x', self::SECTIONS)->slug);
    }

    public function testAPageCanKeepItselfOutOfTheImport(): void
    {
        self::assertTrue(MarkdownPage::read('a.md', "---\ndraft: true\n---\nx", self::SECTIONS)->skip);
        self::assertTrue(MarkdownPage::read('a.md', "---\npublish: false\n---\nx", self::SECTIONS)->skip);
        self::assertFalse(MarkdownPage::read('a.md', "---\ndraft: false\n---\nx", self::SECTIONS)->skip);
    }

    public function testLinksBetweenFilesBecomeLinksBetweenPages(): void
    {
        $slugs = ['guides/theming.md' => 'theming', 'production.md' => 'production', 'guides/README.md' => 'guides'];
        $page = MarkdownPage::read('guides/blocks.md', <<<'MD'
            See [theming](theming.md), [going live](../production.md#tls) and [the guides](./README.md).
            An [external](https://example.com/a.md) link, an [anchor](#here), [unknown](missing.md).

            Written as `[theming](theming.md)` or ``[x](missing-in-code.md)``, then [really](theming.md).

            [ref]: ../production.md "Production"

            ```md
            [not a link](theming.md)
            ```
            MD, self::SECTIONS);
        $out = $page->withLinks($slugs, '/docs');

        self::assertStringContainsString('[theming](/docs/theming)', $out->body);
        self::assertStringContainsString('[going live](/docs/production#tls)', $out->body);
        self::assertStringContainsString('[the guides](/docs/guides)', $out->body);
        self::assertStringContainsString('[ref]: /docs/production "Production"', $out->body);
        // Inline code is quoted too: left as written, and never reported as broken.
        self::assertStringContainsString('`[theming](theming.md)`', $out->body);
        self::assertStringContainsString('``[x](missing-in-code.md)``', $out->body);
        self::assertStringContainsString('[really](/docs/theming)', $out->body);
        // Left alone: another site, an anchor, and code.
        self::assertStringContainsString('(https://example.com/a.md)', $out->body);
        self::assertStringContainsString('(#here)', $out->body);
        self::assertStringContainsString("```md\n[not a link](theming.md)\n```", $out->body);
        // A link to a file that is not in the import is left as written, and reported.
        self::assertStringContainsString('(missing.md)', $out->body);
        self::assertSame(['missing.md'], $out->brokenLinks);
    }
}
