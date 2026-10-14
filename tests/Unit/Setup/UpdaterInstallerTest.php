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

    /**
     * An install made before the guard existed has entry points without its include. Provision adds it,
     * right after the declare line — before anything loads vendor/ — and only once.
     */
    public function testTheGuardIsIncludedByEntryPointsThatPredateIt(): void
    {
        mkdir($this->base . '/public');
        $index = "<?php\n\ndeclare(strict_types=1);\n\nuse Symfony\\Component\\HttpFoundation\\Request;\n\n"
            . "\$app = require_once __DIR__ . '/../bootstrap/app.php';\n";
        file_put_contents($this->base . '/public/index.php', $index);
        $console = "#!/usr/bin/env php\n<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n";
        file_put_contents($this->base . '/glueful', $console);
        chmod($this->base . '/glueful', 0755);

        $i = new UpdaterInstaller();
        self::assertSame(['public/index.php', 'glueful'], $i->includeGuard($this->base));
        self::assertSame([], $i->includeGuard($this->base), 'once');

        $patched = (string) file_get_contents($this->base . '/public/index.php');
        $guard = strpos($patched, "require __DIR__ . '/../bootstrap/update-guard.php';");
        self::assertNotFalse($guard);
        self::assertGreaterThan(strpos($patched, 'declare(strict_types=1);'), $guard);
        self::assertLessThan(strpos($patched, 'use Symfony'), $guard);
        $cli = (string) file_get_contents($this->base . '/glueful');
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php\n", $cli);
        self::assertLessThan(strpos($cli, 'vendor/autoload.php'), strpos($cli, "'/bootstrap/update-guard.php'"));
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->base . '/glueful')), -4));
        foreach (['public/index.php', 'glueful'] as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->base . '/' . $file), $out, $code);
            self::assertSame(0, $code, $file . ' still parses');
        }
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
        // setgid: what the runner publishes there takes the web group, whatever the runner's own group.
        self::assertSame('2750', $mode('.thallo-update-control'));
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
