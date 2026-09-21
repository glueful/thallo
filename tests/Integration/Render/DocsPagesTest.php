<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Core\Content\Authoring\EngineContentWriter;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Importers\Markdown\MarkdownFolderImport;

/**
 * The whole path a site's documentation takes: `thallo:docs:setup`, a folder of Markdown imported,
 * and the default theme serving it — a page with its section sidebar, its rendered body, an
 * on-page contents, previous and next, and an edit link; and `/docs` as the index of it all.
 */
final class DocsPagesTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/thallo-docs-pages-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/getting-started', 0777, true);
        mkdir($this->dir . '/guides', 0777, true);
        file_put_contents($this->dir . '/getting-started/01-install.md', <<<'MD'
            ---
            summary: Get Thallo running.
            ---
            # Installing

            ## Requirements

            PHP 8.3 and PostgreSQL.

            ## Create a project

            ```bash
            $ composer create-project glueful/thallo site
            ```

            | Setting | Value |
            |---|---|
            | `APP_ENV` | production |

            Next: [your first page](02-first-page.md).
            MD);
        file_put_contents($this->dir . '/getting-started/02-first-page.md', "# Your first page\n\nWrite it.\n");
        file_put_contents($this->dir . '/guides/theming.md', "# Theming <em>sites</em>\n\n<script>alert(1)</script>\n");

        $c = $this->container();
        $c->get(DocsSetup::class)->run('docs', ['getting-started', 'guides']);
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        (new MarkdownFolderImport(
            new EngineContentWriter($entries, new PublishService(
                $this->appContext(),
                $entries,
                new VersionRepository($this->connection()),
                $types,
                new FieldValidator($this->connection()),
                new ReferenceProjectionRepository($this->connection()),
            ), $types, new FieldValidator($this->connection())),
            $c->get(ContentUpserter::class),
            $c->get(ContentTypeReader::class),
            $c->get(CapabilityRegistry::class),
        ))->run($this->dir, [
            'type' => 'docs', 'publish' => true,
            'edit_base' => 'https://github.com/acme/site/edit/main/docs',
        ]);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    private function get(string $path): string
    {
        $res = $this->handle(Request::create($path, 'GET'));
        self::assertSame(200, $res->getStatusCode(), $path);
        return (string) $res->getContent();
    }

    public function testADocsPageHasItsSidebarBodyContentsAndNeighbours(): void
    {
        $html = $this->get('/docs/first-page');

        // The sidebar: every page under its section, in order, this one marked.
        self::assertSame(1, preg_match('~<nav[^>]*class="docs__nav"[^>]*>(.*?)</nav>~s', $html, $nav), $html);
        self::assertMatchesRegularExpression(
            '~Getting started.*Installing.*Your first page.*Guides.*Theming~s',
            strip_tags($nav[1]),
        );
        self::assertMatchesRegularExpression('~<a[^>]+href="[^"]*/docs/first-page"[^>]+aria-current="page"~', $nav[1]);
        self::assertSame(1, substr_count($nav[1], 'aria-current="page"'));

        // The title once, as the page's h1 — the Markdown's own heading was taken for it.
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('Your first page</h1>', $html);

        // Previous and next, in reading order across sections.
        self::assertMatchesRegularExpression('~rel="prev"[^>]*href="[^"]*/docs/install"~', $html);
        self::assertMatchesRegularExpression('~rel="next"[^>]*href="[^"]*/docs/theming"~', $html);
        self::assertStringContainsString(
            'href="https://github.com/acme/site/edit/main/docs/getting-started/02-first-page.md"',
            $html,
        );
    }

    public function testTheBodyIsTheRenderedMarkdownWithItsContents(): void
    {
        $html = $this->get('/docs/install');
        self::assertStringContainsString('<h2 id="requirements">', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('class="thallo-block thallo-block-code"', $html);
        self::assertStringContainsString('data-language="bash"', $html);
        // A link between two files is a link between their pages.
        self::assertStringContainsString('href="/docs/first-page"', $html);
        // On this page: the h2s, linked.
        self::assertSame(1, preg_match('~<nav[^>]*class="docs__toc-nav"[^>]*>(.*?)</nav>~s', $html, $toc));
        self::assertStringContainsString('href="#requirements"', $toc[1]);
        self::assertStringContainsString('href="#create-a-project"', $toc[1]);
        self::assertStringContainsString('Get Thallo running.', $html);
        // Search is off in this boot: the page offers no search box that could not answer.
        self::assertStringNotContainsString('data-docs-search', $html);
        // The first page has no previous.
        self::assertStringNotContainsString('rel="prev"', $html);
    }

    public function testMarkupInASourceFileNeverReachesThePage(): void
    {
        $html = $this->get('/docs/theming');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<em>sites</em></h1>', $html);
        self::assertStringContainsString('Theming &lt;em&gt;sites&lt;/em&gt;</h1>', $html);
    }

    public function testTheIndexListsEverySectionAndItsPages(): void
    {
        $html = $this->get('/docs');
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertMatchesRegularExpression(
            '~Getting started.*Installing.*Get Thallo running\..*Guides.*Theming~s',
            strip_tags($html),
        );
        self::assertMatchesRegularExpression('~href="[^"]*/docs/install"~', $html);
    }
}
