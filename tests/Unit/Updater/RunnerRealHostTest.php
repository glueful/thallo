<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\RealHost;
use Thallo\Core\Updater\Runner;
use Thallo\Core\Updater\Status;

/**
 * The runner through RealHost: real child processes, real inherited descriptors and a real HTTP
 * server answering the verification route. FakeHost cannot show a closed handle handed to a child,
 * an old log line read as a fresh answer, or which locks reach a child; these tests can.
 *
 * @requires extension posix
 */
final class RunnerRealHostTest extends TestCase
{
    use UpdaterSite;

    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        foreach (['pg_dump', 'pg_restore'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                self::markTestSkipped($tool . ' not installed (the runner\'s preflight requires it)');
            }
        }
        $this->base = sys_get_temp_dir() . '/thallo-realhost-' . bin2hex(random_bytes(4));
        $site = dirname(__DIR__, 2) . '/fixtures/updater/realhost-site';
        exec('cp -R ' . escapeshellarg($site) . ' ' . escapeshellarg($this->base));
        foreach (['storage/framework/updates', 'storage/logs'] as $dir) {
            @mkdir($this->base . '/' . $dir, 0777, true);
        }
        $versions = '{"from":"1.0.0-beta.101","target":"1.0.0-beta.102"}';
        file_put_contents($this->base . '/storage/versions.json', $versions);
        $this->layOutUpdaterSite($this->base);
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, static fn (): int => time());
        $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner', hash('sha256', 'token'));
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        if (isset($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    public function testReopeningThroughRealHostPassesNoClosedDescriptor(): void
    {
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status'], (string) $op['error'] . "\n" . $this->log());
        self::assertSame('new', file_get_contents($this->base . '/vendor/marker'));
        // The cleanup after the barrier's release runs real children (rm): handed a closed handle,
        // proc_open would fail and the operation folder would still exist.
        self::assertDirectoryDoesNotExist($this->paths->opDir($op['id']));
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testStaleVerificationOutputIsNotSuccessThroughRealHost(): void
    {
        $log = $this->paths->log($this->ops->current()['id']);
        @mkdir(dirname($log), 0700, true);
        file_put_contents($log, '{"version":"1.0.0-beta.102"}' . "\n");
        file_put_contents($this->base . '/storage/verify-exit', '1');
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::RECOVERY_REQUIRED, $op['status']);
        self::assertStringContainsString('exited 1', (string) $op['error']);
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testAFailedCacheClearThroughRealHostKeepsTheSiteClosed(): void
    {
        file_put_contents($this->base . '/storage/clear-exit', '1');
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::RECOVERY_REQUIRED, $op['status']);
        self::assertNull($op['reopen_ready_at']);
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testTheRunLockIsInheritedByChildren(): void
    {
        $this->runner()->tick();
        $runLock = stat($this->paths->runLock())['ino'];
        $barrier = stat($this->paths->admissionLock())['ino'];
        $seen = [];
        foreach (file($this->base . '/storage/fds.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$command, $inodes] = array_pad(explode(' ', $line, 2), 2, '');
            $seen[$command] = array_map('intval', array_filter(explode(',', $inodes)));
        }
        self::assertContains($runLock, $seen['thallo:provision'] ?? [], 'provision inherits the run lock');
        self::assertContains($barrier, $seen['thallo:provision'] ?? [], 'and the barrier, while the site is closed');
    }

    private function runner(): Runner
    {
        $settings = [
            'php' => PHP_BINARY,
            'composer' => $this->base . '/composer',
            'verify_url' => 'http://127.0.0.1:' . $this->port . '/_update/verify',
            'verify_host' => '',
            'keep_backups' => 3,
            'barrier_timeout' => 5,
            'child_timeout' => 60,
            'composer_timeout' => 60,
            'web_settle' => 10,
        ];
        $id = $this->ops->current()['id'];
        // No process group: in-process, stopDescendants would reach PHPUnit's own group.
        return new Runner(
            $this->paths,
            $this->ops,
            new Barrier($this->paths),
            new RealHost($this->paths->log($id), []),
            $settings,
            static fn (): array => [getmypid(), null]
        );
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $null = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $serve = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->base . '/verify-router.php'];
        $this->server = proc_open($serve, $null, $pipes, $this->base);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        self::fail('the verification server did not start');
    }

    private function log(): string
    {
        $op = $this->ops->current();
        return (string) @file_get_contents($this->paths->log($op['id']));
    }
}
