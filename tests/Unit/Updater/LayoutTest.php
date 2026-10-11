<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Layout;
use Thallo\Core\Updater\Paths;

/**
 * The trust boundary's folders (spec §16.1): the private folder and the control folder are real
 * directories the runner owns, with their exact modes, and no ancestor lets anyone else replace them.
 */
final class LayoutTest extends TestCase
{
    private string $root;
    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/thallo-layout-' . bin2hex(random_bytes(4));
        $this->base = $this->root . '/site';
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        chmod($this->root, 0755);
        chmod($this->base, 0755);
        mkdir($this->base . '/.thallo-update', 0700);
        mkdir($this->base . '/.thallo-update-control', 0750);
        chmod($this->base . '/.thallo-update', 0700);
        chmod($this->base . '/.thallo-update-control', 0750);
    }

    protected function tearDown(): void
    {
        @chmod($this->root, 0755);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testACorrectLayoutHasNoProblems(): void
    {
        self::assertSame([], $this->problems());
    }

    public function testASymbolicLinkPrivateFolderIsRefused(): void
    {
        rename($this->base . '/.thallo-update', $this->root . '/elsewhere');
        symlink($this->root . '/elsewhere', $this->base . '/.thallo-update');
        self::assertStringContainsString('.thallo-update', implode(' ', $this->problems()));
    }

    public function testASymbolicLinkInboxIsRefused(): void
    {
        rename($this->base . '/storage/framework/updates', $this->root . '/inbox-elsewhere');
        symlink($this->root . '/inbox-elsewhere', $this->base . '/storage/framework/updates');
        self::assertStringContainsString('storage/framework/updates', implode(' ', $this->problems()));
    }

    public function testAGroupWritableSiteRootIsRefused(): void
    {
        chmod($this->base, 0775);
        self::assertNotSame([], $this->problems());
    }

    public function testAPrivateFolderOpenToTheGroupIsRefused(): void
    {
        chmod($this->base . '/.thallo-update', 0750);
        self::assertStringContainsString('0700', implode(' ', $this->problems()));
    }

    public function testAControlFolderWritableByTheGroupIsRefused(): void
    {
        chmod($this->base . '/.thallo-update-control', 0770);
        self::assertStringContainsString('0750', implode(' ', $this->problems()));
    }

    public function testAStickyWorldWritableAncestorIsAcceptedWhenTheNextFolderIsOurs(): void
    {
        chmod($this->root, 01777);
        self::assertSame([], $this->problems());
    }

    public function testANonStickyWorldWritableAncestorIsRefused(): void
    {
        chmod($this->root, 0777);
        self::assertNotSame([], $this->problems());
    }

    /** @return list<string> */
    private function problems(): array
    {
        clearstatcache();
        return Layout::problems(new Paths($this->base), posix_geteuid());
    }
}
