<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Journal;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\ProvisionOutputs;

final class ProvisionOutputsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-outputs-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'public/admin/assets', 'storage/cache/style', 'config', 'docs'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/.env", "APP_KEY=old\n");
        file_put_contents("{$this->base}/config/extensions.php", "<?php return ['enabled' => ['a']];");
        file_put_contents("{$this->base}/public/admin/index.html", 'old admin');
        file_put_contents("{$this->base}/public/admin/assets/app-old.js", 'old');
        file_put_contents("{$this->base}/storage/cache/style/settings-old.css", 'old css');
        file_put_contents("{$this->base}/docs/openapi.json", '{"v":"old"}');
        // docs/index.html and bootstrap/cache/extensions.php are absent before provision.
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testRestorePutsBackExactlyWhatProvisionChangedIncludingAbsence(): void
    {
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        self::assertFalse($manifest['paths']['docs/index.html']);
        self::assertTrue($manifest['paths']['public/admin']);
        self::assertSame(hash('sha256', 'old admin'), $manifest['files']['public/admin/index.html']['sha256']);

        // What a newer provision does:
        file_put_contents("{$this->base}/.env", "APP_KEY=old\nNEW_KEY=x\n");
        file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        unlink("{$this->base}/public/admin/assets/app-old.js");
        file_put_contents("{$this->base}/public/admin/assets/app-new.js", 'new');
        unlink("{$this->base}/storage/cache/style/settings-old.css");
        file_put_contents("{$this->base}/storage/cache/style/settings-new.css", 'new css');
        file_put_contents("{$this->base}/docs/index.html", 'new ui');
        mkdir("{$this->base}/bootstrap/cache", 0777, true);
        file_put_contents("{$this->base}/bootstrap/cache/extensions.php", '<?php return [];');

        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        $journal = new Journal($ops, $run['id'], $run['owner']);
        ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", $journal);

        self::assertSame("APP_KEY=old\n", file_get_contents("{$this->base}/.env"));
        self::assertSame('old admin', file_get_contents("{$this->base}/public/admin/index.html"));
        self::assertFileExists("{$this->base}/public/admin/assets/app-old.js");
        self::assertFileDoesNotExist("{$this->base}/public/admin/assets/app-new.js");
        $oldCss = "{$this->base}/storage/cache/style/settings-old.css";
        self::assertFileExists($oldCss, 'the old code needs its own compiled style');
        self::assertFileDoesNotExist("{$this->base}/docs/index.html", 'absent before, absent after');
        self::assertFileDoesNotExist("{$this->base}/bootstrap/cache/extensions.php");
        self::assertTrue(is_dir("{$this->base}/snap"), 'the backup snapshot is left intact');
    }

    public function testEmptySnapshottedDirectoriesComeBackWithTheirModes(): void
    {
        unlink("{$this->base}/storage/cache/style/settings-old.css"); // a top-level snapshot path, now empty
        mkdir("{$this->base}/public/admin/empty", 0750);              // an empty directory inside one
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        self::assertArrayHasKey('storage/cache/style', $manifest['dirs']);
        self::assertSame(0750, $manifest['dirs']['public/admin/empty']);

        file_put_contents("{$this->base}/storage/cache/style/settings-new.css", 'new css');
        exec('rm -rf ' . escapeshellarg("{$this->base}/public/admin/empty"));

        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        $journal = new Journal($ops, $run['id'], $run['owner']);
        ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", $journal);

        self::assertDirectoryExists("{$this->base}/storage/cache/style");
        $left = array_values(array_diff(scandir("{$this->base}/storage/cache/style"), ['.', '..']));
        self::assertSame([], $left, 'empty, as before');
        self::assertDirectoryExists("{$this->base}/public/admin/empty");
        self::assertSame('0750', substr(sprintf('%o', fileperms("{$this->base}/public/admin/empty")), -4));
    }

    public function testAFailedCopyRefusesTheSnapshot(): void
    {
        chmod("{$this->base}/public/admin/assets/app-old.js", 0000);
        try {
            ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
            self::fail('an unreadable file must refuse the snapshot');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('public/admin/assets/app-old.js', $e->getMessage());
        } finally {
            chmod("{$this->base}/public/admin/assets/app-old.js", 0644);
        }
    }

    public function testRestoreRefusesATamperedSnapshotBeforeTouchingAnything(): void
    {
        $manifest = ProvisionOutputs::snapshot($this->base, "{$this->base}/snap");
        file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        file_put_contents("{$this->base}/snap/public/admin/index.html", 'tampered');
        $ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $ops->start('2', '1', 'u');
        $run = $ops->claim($op['id'], 1, 1);
        try {
            $journal = new Journal($ops, $run['id'], $run['owner']);
            ProvisionOutputs::restore($this->base, "{$this->base}/snap", $manifest, "{$this->base}/work", $journal);
            self::fail('a snapshot that does not match its manifest is never restored');
        } catch (\RuntimeException) {
        }
        self::assertSame('new admin', file_get_contents("{$this->base}/public/admin/index.html"), 'nothing was moved');
        self::assertSame([], $ops->current()['journal']);
    }

    public function testEveryInventoriedPathHasExactlyOneRule(): void
    {
        $all = array_merge(
            ProvisionOutputs::SNAPSHOT,
            ProvisionOutputs::CLEAR,
            ProvisionOutputs::RETAIN,
            ProvisionOutputs::RUNNER_OWNED,
        );
        self::assertSame(count($all), count(array_unique($all)));
        self::assertSame('retain', ProvisionOutputs::ruleFor('bin/thallo-updater'));
        self::assertSame('snapshot', ProvisionOutputs::ruleFor('public/admin/assets/x.js'));
        self::assertSame('runner', ProvisionOutputs::ruleFor('vendor/autoload.php'));
        self::assertSame('clear', ProvisionOutputs::ruleFor('storage/cache/routes_prod.php'));
        self::assertNull(ProvisionOutputs::ruleFor('themes/default/theme.json'), 'an uninventoried path has no rule');
    }
}
