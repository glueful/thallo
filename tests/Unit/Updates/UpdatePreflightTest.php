<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updates\RunnerLauncher;
use Thallo\Core\Updates\UpdatePreflight;

final class UpdatePreflightTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-pre-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', '.thallo-update-control', 'bootstrap', 'public', 'vendor'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        $root = ['require' => ['glueful/thallo-core' => '^1.0.0-beta.101']];
        file_put_contents("{$this->base}/composer.json", json_encode($root));
        file_put_contents("{$this->base}/bootstrap/update-guard.php", '<?php');
        $index = "<?php require __DIR__ . '/../bootstrap/update-guard.php';";
        file_put_contents("{$this->base}/public/index.php", $index);
        file_put_contents("{$this->base}/glueful", "<?php require __DIR__ . '/bootstrap/update-guard.php';");
        chmod("{$this->base}/vendor", 0555); // FPM cannot write code
        $this->report([]);
    }

    protected function tearDown(): void
    {
        chmod("{$this->base}/vendor", 0755);
        @chmod("{$this->base}/.thallo-update", 0755);
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAHealthySetupIsReady(): void
    {
        $r = $this->preflight()->check();
        self::assertSame([], $r['reasons']);
        self::assertTrue($r['ready']);
        self::assertSame('1.0.0-beta.102', $r['target']);
    }

    /** @return iterable<string, array{string, array, array, string}> */
    public static function reasons(): iterable
    {
        yield 'disabled' => ['disabled', ['enabled' => false], [], ''];
        yield 'outside constraint' => ['outside_constraint', ['latest' => '2.0.0'], [], ''];
        yield 'preload' => ['opcache_preload', ['ini' => ['opcache.preload' => '/app/preload.php']], [], ''];
        yield 'frozen' => ['opcache_frozen', ['ini' => ['opcache.validate_timestamps' => '0']], [], ''];
        yield 'persistent' => ['persistent_runtime', ['sapi' => 'cli-server'], [], ''];
        yield 'runner writes' => ['not_writable', [], ['writable' => false], ''];
        yield 'composer' => ['composer_missing', [], ['composer_ok' => false], ''];
        yield 'shape' => ['composer_config_unsupported', [], ['shape_problems' => ['x']], ''];
        yield 'backup' => ['backup_unavailable', [], ['pg_restore' => false], ''];
        yield 'disk' => ['low_disk', [], ['free_bytes' => 1, 'needed_bytes' => 2], ''];
        yield 'web' => ['web_verify_unreachable', [], ['web' => null], ''];
        yield 'stale runner' => ['runner_missing', [], ['checked_at' => 1], ''];
        yield 'guard' => ['guard_missing', [], [], 'public/index.php'];
        $old = ['unguarded_processes' => [['pid' => 4242, 'args' => 'php glueful import']]];
        $insecure = ['layout_problems' => ['.thallo-update is not a real directory']];
        yield 'insecure layout' => ['insecure_layout', [], $insecure, ''];
        yield 'unguarded processes' => ['unguarded_processes', [], $old, ''];
        yield 'restart not confirmed' => ['restart_required', [], ['guard_ready_at' => null], ''];
        $early = ['guard_installed_at' => 50, 'guard_ready_at' => 40];
        yield 'confirmed before the guard' => ['restart_required', [], $early, ''];
    }

    /** @dataProvider reasons */
    public function testEachReason(string $reason, array $opts, array $report, string $strip): void
    {
        $this->report($report);
        if ($strip !== '') {
            file_put_contents("{$this->base}/{$strip}", '<?php');
        }
        $r = $this->preflight($opts)->check();
        self::assertContains($reason, $r['reasons']);
        self::assertFalse($r['ready']);
    }

    public function testCodeTheWebProcessCanWriteIsRefused(): void
    {
        chmod("{$this->base}/vendor", 0777);
        self::assertContains('web_can_write_code', $this->preflight()->check()['reasons']);
    }

    /**
     * Simple mode: PHP-FPM runs as the user that owns the code (CloudPanel and most control panels), so
     * it can write the code and the private update folder. Separating the two users protects nothing
     * there: no cron runner, no restart confirmation, no folder modes, and PHP-FPM itself checks the
     * server. A cron runner's report, if one still runs, is not read.
     */
    public function testSimpleModeSkipsTheChecksThatOnlySeparateTwoUsers(): void
    {
        $this->simple();
        $this->report([
            'checked_at' => 1,
            'composer_ok' => false,
            'web' => null,
            'layout_problems' => ['.thallo-update must be mode 0700'],
            'unguarded_processes' => [['pid' => 506, 'args' => 'php-fpm: master process']],
            'guard_ready_at' => null,
        ]);
        $r = $this->preflight(['launcher' => $this->launcher()])->check();
        self::assertSame([], $r['reasons']);
        self::assertTrue($r['ready']);
    }

    public function testSimpleModeChecksTheServerItselfAndKeepsTheAnswerFiveMinutes(): void
    {
        $this->simple();
        $calls = 0;
        $launcher = new RunnerLauncher(
            $this->base,
            'php',
            '/usr/local/bin/composer',
            static fn (): bool => true,
            function () use (&$calls): array {
                $calls++;
                return $this->healthy(['composer_ok' => false]);
            }
        );
        self::assertContains('composer_missing', $this->preflight(['launcher' => $launcher])->check()['reasons']);
        self::assertContains('composer_missing', $this->preflight(['launcher' => $launcher])->check()['reasons']);
        self::assertSame(1, $calls, 'the second answer comes from the kept report');
        self::assertFileExists("{$this->base}/.thallo-update/runner-simple.json");
    }

    public function testSimpleModeKeepsTheChecksThatProtectTheSite(): void
    {
        $this->simple();
        file_put_contents("{$this->base}/public/index.php", '<?php');
        $r = $this->preflight(['launcher' => $this->launcher(), 'ini' => ['opcache.validate_timestamps' => '0']])
            ->check();
        self::assertContains('guard_missing', $r['reasons']);
        self::assertContains('opcache_frozen', $r['reasons']);
        self::assertFalse($r['ready']);
    }

    public function testSimpleModeNeedsAWayToStartTheRunner(): void
    {
        $this->simple();
        self::assertContains('launch_unavailable', $this->preflight()->check()['reasons']);
        unlink("{$this->base}/bin/thallo-updater"); // provision has not installed it
        $reasons = $this->preflight(['launcher' => $this->launcher()])->check()['reasons'];
        self::assertContains('launch_unavailable', $reasons);
    }

    /** PHP-FPM can write the code but not the private folder: the folders belong to another user. */
    public function testCodeWritableWithoutThePrivateFolderIsNotSimpleMode(): void
    {
        $this->simple();
        chmod("{$this->base}/.thallo-update", 0500);
        $reasons = $this->preflight(['launcher' => $this->launcher()])->check()['reasons'];
        self::assertContains('web_can_write_code', $reasons);
    }

    /** PHP-FPM writes the code and the private update folder, and the runner is installed. */
    private function simple(): void
    {
        chmod("{$this->base}/vendor", 0777);
        @mkdir("{$this->base}/.thallo-update", 0700);
        @mkdir("{$this->base}/bin");
        file_put_contents("{$this->base}/bin/thallo-updater", '<?php');
    }

    private function launcher(array $report = []): RunnerLauncher
    {
        return new RunnerLauncher(
            $this->base,
            'php',
            '/usr/local/bin/composer',
            static fn (): bool => true,
            fn (): array => $this->healthy($report)
        );
    }

    private function healthy(array $over = []): array
    {
        return array_merge([
            'checked_at' => 10_000,
            'writable' => true,
            'composer_ok' => true,
            'shape_problems' => [],
            'pg_dump' => true,
            'pg_restore' => true,
            'free_bytes' => 10,
            'needed_bytes' => 1,
            'web' => null,
        ], $over);
    }

    private function report(array $over): void
    {
        file_put_contents("{$this->base}/.thallo-update-control/runner.json", json_encode(array_merge([
            'checked_at' => 10_000,
            'writable' => true,
            'composer_ok' => true,
            'shape_problems' => [],
            'pg_dump' => true,
            'pg_restore' => true,
            'free_bytes' => 10,
            'needed_bytes' => 1,
            'unguarded_processes' => [],
            'guard_installed_at' => 1,
            'guard_ready_at' => 2,
            'layout_problems' => [],
            'web' => ['status' => 200, 'admission_ok' => true, 'state_writable' => true],
        ], $over)));
    }

    private function preflight(array $o = []): UpdatePreflight
    {
        $defaults = ['opcache.enable' => '1', 'opcache.validate_timestamps' => '1', 'opcache.preload' => ''];
        $ini = array_merge($defaults, $o['ini'] ?? []);
        return new UpdatePreflight(
            ['enabled' => $o['enabled'] ?? true],
            ['current' => '1.0.0-beta.101', 'latest' => $o['latest'] ?? '1.0.0-beta.102', 'available' => true],
            $this->base,
            static fn (string $k): string => $ini[$k] ?? '',
            static fn (): string => $o['sapi'] ?? 'fpm-fcgi',
            static fn (): int => 10_100,
            $o['launcher'] ?? null,
        );
    }

    public function testARequestWaitingInTheInboxIsAnUpdateInProgress(): void
    {
        file_put_contents("{$this->base}/storage/framework/updates/start.json", '{"target":"1.0.0-beta.102"}');
        self::assertContains('in_progress', $this->preflight()->check()['reasons']);
    }

    public function testThePublishedStatusSaysWhenRecoveryIsNeeded(): void
    {
        $status = '{"operation":{"status":"recovery_required"}}';
        file_put_contents("{$this->base}/.thallo-update-control/status.json", $status);
        self::assertContains('recovery_required', $this->preflight()->check()['reasons']);
    }
}
