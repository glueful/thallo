<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Importers\Markdown\FrontMatter;
use Thallo\Importers\Markdown\MarkdownPage;

/**
 * Thallo's own docs (`docs/`), held to the rules of `docs/internal/docs-writing/GUIDE.md`: the
 * ones a machine can check. A page is read the way the import reads it ({@see MarkdownPage}),
 * so what passes here imports; what the guide asks of the WRITING is a reviewer's to judge.
 *
 * Run it after writing a page: `vendor/bin/phpunit tests/Unit/Docs`.
 */
final class DocsCorpusTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../docs';

    /** Pages other files link to by path: they stay at the top of `docs/` (GUIDE.md §2). */
    private const AT_THE_TOP = ['production.md', 'upgrading.md', 'limitations.md', 'documentation-sites.md'];

    /** @return array<string,string> relative path => contents, for every page an import reads */
    private static function pages(): array
    {
        $pages = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen(self::ROOT) + 1);
            if (!str_ends_with($relative, '.md') || str_starts_with($relative, 'internal/')) {
                continue;
            }
            $pages[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($pages);
        return $pages;
    }

    /** @return array<string,array{file: string, title: string, order: string}> slug => its row in PAGES.md */
    private static function plan(): array
    {
        $plan = [];
        $text = (string) file_get_contents(self::ROOT . '/internal/docs-writing/PAGES.md');
        preg_match_all(
            '/^### (\S+) — (.+)\n- \*\*File\*\* `([^`]+)`.*?\*\*Order\*\* (\d+)/m',
            $text,
            $rows,
            PREG_SET_ORDER,
        );
        foreach ($rows as [, $slug, $title, $file, $order]) {
            $plan[$slug] = ['file' => $file, 'title' => trim($title), 'order' => $order];
        }
        return $plan;
    }

    /** The body as prose: fenced and inline code taken out, since code is quoted, not written. */
    private static function prose(string $body): string
    {
        $body = (string) preg_replace('/^ {0,3}(`{3,}|~{3,}).*?^ {0,3}\1[ \t]*$/ms', '', $body);
        return (string) preg_replace('/(`+).+?\1/s', '', $body);
    }

    public function testThePlanIsReadableAndItsSlugsAreUnique(): void
    {
        $plan = self::plan();
        self::assertGreaterThan(40, count($plan), 'PAGES.md could not be read: its row format changed');
        foreach ($plan as $slug => $row) {
            $name = preg_replace('/^\d+-/', '', basename($row['file'], '.md'));
            self::assertSame($slug, $name, "{$row['file']} is not named after its slug");
        }
    }

    public function testEveryPageIsWhereThePlanPutsItWithTheFrontMatterItGives(): void
    {
        $plan = self::plan();
        $byFile = [];
        foreach ($plan as $slug => $row) {
            $byFile[$row['file']] = $row + ['slug' => $slug];
        }
        foreach (self::pages() as $path => $contents) {
            $front = FrontMatter::split($contents)['front'];
            if (in_array(strtolower($front['publish'] ?? ''), ['false', 'no', '0'], true)) {
                continue; // README.md: about the folder, not a page
            }
            self::assertArrayHasKey($path, $byFile, "{$path} is not in PAGES.md: add its row, or move the file");
            $row = $byFile[$path];
            $folder = str_contains($path, '/') ? explode('/', $path)[0] : null;

            self::assertContains(
                $front['section'] ?? '',
                DocsSetup::DEFAULT_SECTIONS,
                "{$path}: section must be one of the docs type's sections",
            );
            if ($folder !== null) {
                self::assertSame($folder, $front['section'], "{$path}: the section is the folder");
            } else {
                self::assertContains($path, self::AT_THE_TOP, "{$path}: a new page goes in its section's folder");
            }
            self::assertSame($row['order'], $front['order'] ?? '', "{$path}: order");
            self::assertNotSame('', trim($front['summary'] ?? ''), "{$path}: summary");
            self::assertDoesNotMatchRegularExpression(
                '/[`*_\[]/',
                $front['summary'] ?? '',
                "{$path}: the summary is plain text; Markdown in it prints literally",
            );

            if ($folder !== null) {
                self::assertSame($row['slug'], $front['slug'] ?? '', "{$path}: slug");
                self::assertSame($row['title'], $front['title'] ?? '', "{$path}: title");
                $body = ltrim(FrontMatter::split($contents)['body']);
                self::assertDoesNotMatchRegularExpression(
                    '/\A#[ \t]/',
                    $body,
                    "{$path}: the title is in the front matter; the body has no # H1",
                );
            }
        }
    }

    public function testNoTwoPagesClaimOneUrl(): void
    {
        $claimed = [];
        foreach (self::pages() as $path => $contents) {
            $page = MarkdownPage::read($path, $contents, DocsSetup::DEFAULT_SECTIONS);
            if ($page->skip) {
                continue;
            }
            self::assertArrayNotHasKey(
                $page->slug,
                $claimed,
                "{$path} and " . ($claimed[$page->slug] ?? '') . " both claim /docs/{$page->slug}",
            );
            $claimed[$page->slug] = $path;
            self::assertNotNull($page->section, "{$path} has no section: it would fall out of the sidebar");
        }
    }

    public function testAPageUsesOnlyWhatTheRendererRenders(): void
    {
        foreach (self::pages() as $path => $contents) {
            $body = FrontMatter::split($contents)['body'];
            $prose = self::prose($body);

            self::assertDoesNotMatchRegularExpression(
                '/<\/?[a-zA-Z][a-zA-Z0-9-]*(\s[^<>]*)?>|<!--/',
                (string) preg_replace('/<https?:[^>\s]+>/', '', $prose),
                "{$path}: raw HTML is stripped by the renderer",
            );
            self::assertDoesNotMatchRegularExpression('/!\[[^\]]*\]\(/', $prose, "{$path}: images do not travel yet");
            self::assertDoesNotMatchRegularExpression(
                '/^\s*>\s*\[!\w+\]|^:::/m',
                $prose,
                "{$path}: admonition syntax is not rendered",
            );
            // An opening fence names its language. Fences alternate open/close, in order.
            preg_match_all('/^ {0,3}(`{3,}|~{3,})(.*)$/m', $body, $fences, PREG_SET_ORDER);
            foreach ($fences as $i => $fence) {
                if ($i % 2 === 0) {
                    self::assertNotSame('', trim($fence[2]), "{$path}: a code fence has no language");
                }
            }
        }
    }

    public function testEveryLinkToAnotherPageLeadsToOneThatExistsOrIsPlanned(): void
    {
        $pages = self::pages();
        $planned = array_column(self::plan(), 'file');
        $slugByPath = [];
        foreach ($pages as $path => $contents) {
            $slugByPath[$path] = MarkdownPage::read($path, $contents, DocsSetup::DEFAULT_SECTIONS)->slug;
        }
        // A page that is planned but not written yet is a link that is allowed to wait.
        foreach ($planned as $file) {
            $slugByPath[$file] ??= 'planned';
        }
        foreach ($pages as $path => $contents) {
            $page = MarkdownPage::read($path, $contents, DocsSetup::DEFAULT_SECTIONS)
                ->withLinks($slugByPath, '/docs');
            self::assertSame([], $page->brokenLinks, "{$path} links to a file that is neither a page nor planned");
            self::assertDoesNotMatchRegularExpression(
                '~\]\(/docs/~',
                self::prose(FrontMatter::split($contents)['body']),
                "{$path}: link to the .md file, never to /docs/… by hand",
            );
        }
    }
}
