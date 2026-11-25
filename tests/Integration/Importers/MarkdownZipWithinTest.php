<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Importers;

use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Importers\Markdown\MarkdownFolderImport;
use Thallo\Importers\Markdown\MarkdownZip;

/**
 * A repository archive (GitHub's zip of a commit) wraps the whole repo in one folder. A docs sync
 * reads one folder of it: only that folder's pages, by the path each has inside it, so a page is
 * found again by the same path the folder import gives it from a checkout.
 */
final class MarkdownZipWithinTest extends AppTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/thallo-zip-within-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        MarkdownZip::remove($this->tmp);
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    /** @param array<string,string> $entries name => contents */
    private function zip(array $entries): string
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    /** @return array<string,string> */
    private function repo(): array
    {
        return [
            'thallo-abc123/README.md' => "# Read me\n",
            'thallo-abc123/docs/guides/a.md' => "# A\n",
            'thallo-abc123/docs/index.md' => "# Index\n",
            'thallo-abc123/packages/x/README.md' => "# X\n",
        ];
    }

    private function into(): string
    {
        $dir = $this->tmp . '/out-' . bin2hex(random_bytes(4));
        mkdir($dir);
        return $dir;
    }

    public function testWithinTakesOnlyTheFolderWithPathsRelativeToIt(): void
    {
        $written = MarkdownZip::extract($this->zip($this->repo()), $this->into(), within: 'docs');

        self::assertSame(['guides/a.md', 'index.md'], $written);
    }

    public function testWithinPathsMatchTheFolderImportFromACheckout(): void
    {
        $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
        $checkout = $this->into();
        mkdir($checkout . '/guides');
        file_put_contents($checkout . '/guides/a.md', "# A\n");
        file_put_contents($checkout . '/index.md', "# Index\n");
        $c = $this->container();
        $import = new MarkdownFolderImport(
            $c->get(ContentWriter::class),
            $c->get(ContentUpserter::class),
            $c->get(ContentTypeReader::class),
            $c->get(CapabilityRegistry::class),
        );
        $fromCheckout = array_map(
            static fn ($p) => $p->sourcePath,
            $import->read($checkout, ['type' => 'docs'])->pages,
        );

        $extracted = $this->into();
        MarkdownZip::extract($this->zip($this->repo()), $extracted, within: 'docs');
        $fromArchive = array_map(
            static fn ($p) => $p->sourcePath,
            $import->read($extracted, ['type' => 'docs'])->pages,
        );

        self::assertSame($fromCheckout, $fromArchive);
    }

    public function testWithinIsCaseSensitive(): void
    {
        // A folder saved as `Docs` for a repo whose folder is `docs` holds no pages, and says so.
        self::assertSame(0, MarkdownZip::count($this->zip($this->repo()), within: 'Docs'));
        self::assertSame(2, MarkdownZip::count($this->zip($this->repo()), within: 'docs'));
    }

    public function testZipSlipUnderWithinStillRefusesTheWholeArchive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarkdownZip::extract(
            $this->zip(['thallo-abc123/docs/../../evil.md' => "# Evil\n"] + $this->repo()),
            $this->into(),
            within: 'docs',
        );
    }

    public function testAnArchiveWithoutASingleWrapperIsRefusedWithin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no single top folder/');
        MarkdownZip::count(
            $this->zip(['one/docs/a.md' => "# A\n", 'two/docs/b.md' => "# B\n"]),
            within: 'docs',
        );
    }
}
