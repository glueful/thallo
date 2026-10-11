<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Setup\UpdaterInstaller;

final class UpdaterInstallerTest extends TestCase
{
    private string $base;
    private string $res;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-inst-' . bin2hex(random_bytes(4));
        $this->res = $this->base . '/res';
        mkdir($this->res, 0777, true);
        mkdir($this->base . '/bootstrap', 0777, true);
        mkdir($this->base . '/storage', 0775, true);
        file_put_contents($this->res . '/thallo-updater', "#!/usr/bin/env php\n<?php // v1\n");
        file_put_contents($this->res . '/update-guard.php', "<?php // guard v1\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testEnabledInstallsOnceAndUpdatesWhenTheShippedCopyChanges(): void
    {
        $i = new UpdaterInstaller();
        self::assertSame('installed-first', $i->sync($this->base, $this->res, true, null));
        self::assertFileExists($this->base . '/.thallo-update/guard.installed');
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->base . '/bin/thallo-updater')), -4));
        self::assertSame('current', $i->sync($this->base, $this->res, true, null));
        file_put_contents($this->res . '/update-guard.php', "<?php // guard v2\n");
        self::assertSame('installed', $i->sync($this->base, $this->res, true, null));
        self::assertStringContainsString('v2', (string) file_get_contents($this->base . '/bootstrap/update-guard.php'));
    }

    public function testDisabledRemovesThemUnlessRecoveryNeedsThem(): void
    {
        $i = new UpdaterInstaller();
        $i->sync($this->base, $this->res, true, null);
        self::assertSame('kept', $i->sync($this->base, $this->res, false, ['status' => 'recovery_required']));
        self::assertFileExists($this->base . '/bootstrap/update-guard.php');
        self::assertSame('removed', $i->sync($this->base, $this->res, false, ['status' => 'succeeded']));
        self::assertFileDoesNotExist($this->base . '/bin/thallo-updater');
        self::assertSame('absent', $i->sync($this->base, $this->res, false, null));
    }

    public function testEnablingSetsUpTheThreeFoldersBeforeTheGuardIsFirstUsed(): void
    {
        (new UpdaterInstaller())->sync($this->base, $this->res, true, null);
        clearstatcache();
        $mode = fn (string $p): string => substr(sprintf('%o', fileperms($this->base . '/' . $p)), -4);
        self::assertSame('0700', $mode('.thallo-update'));
        self::assertSame('0750', $mode('.thallo-update-control'));
        self::assertSame('2770', $mode('storage/framework/updates'));
        // The guard opens it read-only from its first request (spec §16.4): it already exists.
        self::assertSame('0640', $mode('.thallo-update-control/admission.lock'));
        $group = filegroup($this->base . '/storage');
        self::assertSame($group, filegroup($this->base . '/.thallo-update-control'), 'the web group, from storage/');
        self::assertSame($group, filegroup($this->base . '/storage/framework/updates'));
    }

    public function testRevisionThreeFilesLeaveTheInbox(): void
    {
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        file_put_contents($this->base . '/storage/framework/updates/operation.json', '{}');
        file_put_contents($this->base . '/storage/framework/updates/admission.lock', '');
        (new UpdaterInstaller())->sync($this->base, $this->res, true, null);
        self::assertFileDoesNotExist($this->base . '/storage/framework/updates/operation.json');
        self::assertFileDoesNotExist($this->base . '/storage/framework/updates/admission.lock');
    }
}
