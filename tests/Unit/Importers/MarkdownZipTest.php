<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Importers;

use PHPUnit\Framework\TestCase;
use Thallo\Importers\Markdown\MarkdownZip;

/**
 * A docs folder uploaded as a .zip (Settings › Import / Export). The archive is a stranger's
 * file: only Markdown comes out of it, only into the folder it is given, and only so much of it.
 */
final class MarkdownZipTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/thallo-mdzip-' . bin2hex(random_bytes(4));
        mkdir($this->work . '/out', 0777, true);
    }

    protected function tearDown(): void
    {
        MarkdownZip::remove($this->work);
    }

    /** @param array<string,string> $files name => contents */
    private function zip(array $files): string
    {
        $path = $this->work . '/' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    /** @return list<string> every file under out/, relative, sorted */
    private function extracted(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work . '/out', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            $found[] = substr($file->getPathname(), strlen($this->work . '/out/'));
        }
        sort($found);
        return $found;
    }

    public function testOnlyMarkdownComesOutAndItKeepsItsFolders(): void
    {
        $zip = $this->zip([
            'README.md' => '# Welcome',
            'guides/01-install.md' => '# Installing',
            'guides/notes.MDX' => '# Notes',
            'guides/diagram.png' => 'not an image',
            'run.php' => '<?php echo 1;',
        ]);

        self::assertSame(3, MarkdownZip::count($zip));
        self::assertSame(
            ['README.md', 'guides/01-install.md', 'guides/notes.MDX'],
            MarkdownZip::extract($zip, $this->work . '/out'),
        );
        self::assertSame(['README.md', 'guides/01-install.md', 'guides/notes.MDX'], $this->extracted());
        self::assertSame('# Installing', file_get_contents($this->work . '/out/guides/01-install.md'));
    }

    public function testTheFolderThatWasZippedIsNotPartOfAPagesPath(): void
    {
        // Zipping a folder called docs puts docs/ in front of every name, and macOS adds its own
        // bookkeeping. The page is guides/install.md whichever way it was packed: a page's path is
        // how a later import finds it again.
        $zip = $this->zip([
            'docs/README.md' => '# Welcome',
            'docs/guides/install.md' => '# Installing',
            '__MACOSX/docs/._README.md' => 'resource fork',
            'docs/.DS_Store' => 'finder',
            'docs/.hidden/secret.md' => '# Hidden',
        ]);

        self::assertSame(['README.md', 'guides/install.md'], MarkdownZip::extract($zip, $this->work . '/out'));
    }

    public function testAFolderIsOnlyDroppedWhenEveryPageIsInsideItAndItIsNotASection(): void
    {
        $zip = $this->zip(['guides/a.md' => '# A', 'reference/b.md' => '# B']);
        self::assertSame(['guides/a.md', 'reference/b.md'], MarkdownZip::extract($zip, $this->work . '/out'));

        // An upload of nothing but the guides section: `guides` is where the page is, not a wrapper.
        MarkdownZip::remove($this->work . '/out', keepRoot: true);
        $section = $this->zip(['guides/a.md' => '# A']);
        self::assertSame(['guides/a.md'], MarkdownZip::extract($section, $this->work . '/out', ['guides']));
    }

    public function testAFolderLeftOutIsNeitherCountedNorWritten(): void
    {
        // The same rule the folder import has: a folder is left out by its name or by its path,
        // read AFTER the zipped folder itself is set aside.
        $zip = $this->zip([
            'docs/README.md' => '# Welcome',
            'docs/internal/plan.md' => '# Private',
            'docs/guides/drafts/wip.md' => '# Not yet',
            'docs/guides/install.md' => '# Installing',
        ]);
        self::assertSame(2, MarkdownZip::count($zip, exclude: ['internal', 'guides/drafts']));
        self::assertSame(
            ['README.md', 'guides/install.md'],
            MarkdownZip::extract($zip, $this->work . '/out', exclude: ['/internal/', 'guides/drafts']),
        );
        self::assertSame(['README.md', 'guides/install.md'], $this->extracted());
    }

    public function testANameThatClimbsOutOfTheFolderIsRefused(): void
    {
        foreach (['../escaped.md', 'guides/../../escaped.md', '/etc/escaped.md', 'a\\..\\escaped.md'] as $name) {
            $zip = $this->zip(['ok.md' => '# Fine', $name => '# Escaped']);
            try {
                MarkdownZip::extract($zip, $this->work . '/out');
                self::fail("{$name} was extracted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('outside', $e->getMessage());
            }
            self::assertFileDoesNotExist($this->work . '/escaped.md');
            self::assertFileDoesNotExist(dirname($this->work) . '/escaped.md');
        }
    }

    public function testAnArchiveThatUnpacksTooLargeOrTooManyIsRefused(): void
    {
        $big = $this->zip(['big.md' => str_repeat('a', 2048)]);
        try {
            MarkdownZip::extract($big, $this->work . '/out', maxBytes: 1024);
            self::fail('an oversized archive was extracted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('too large', $e->getMessage());
        }
        self::assertSame([], $this->extracted());

        $many = $this->zip(['a.md' => '# A', 'b.md' => '# B', 'c.md' => '# C']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 2');
        MarkdownZip::extract($many, $this->work . '/out', maxFiles: 2);
    }

    public function testAFileThatIsNotAZipIsSaidPlainly(): void
    {
        $path = $this->work . '/not.zip';
        file_put_contents($path, 'plain text');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a zip');
        MarkdownZip::count($path);
    }
}
