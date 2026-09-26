<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Thallo\Importers\Markdown\FrontMatter;

/**
 * The changelog as a page of the docs (`docs/reference/08-changelog.md`, served at
 * /docs/changelog) is CHANGELOG.md as released: every released section word for word, without
 * the work in progress under [Unreleased] and without its `# Changelog` heading (the page's
 * title is in its front matter). A new entry under [Unreleased] leaves it alone; a cut changes
 * it, and the cut runs `php scripts/sync-docs-changelog`.
 */
final class ChangelogPageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testTheChangelogPageIsTheChangelogAsReleased(): void
    {
        $changelog = (string) file_get_contents(self::ROOT . '/CHANGELOG.md');
        $released = (string) preg_replace('~^## \[Unreleased\][^\n]*\n.*?(?=^## \[)~ms', '', $changelog);
        $released = (string) preg_replace('~\A\s*# [^\n]*\n~', '', $released);
        // Its links into the docs, written from the project root, are relative to the page.
        $released = str_replace('](docs/', '](../', $released);

        ['front' => $front, 'body' => $body] = FrontMatter::split(
            (string) file_get_contents(self::ROOT . '/docs/reference/08-changelog.md'),
        );

        self::assertSame('Changelog', $front['title'] ?? '');
        self::assertSame('changelog', $front['slug'] ?? '');
        self::assertStringNotContainsString('## [Unreleased]', $body);
        self::assertSame(
            trim($released),
            trim($body),
            'docs/reference/08-changelog.md is behind CHANGELOG.md: run php scripts/sync-docs-changelog',
        );
    }
}
