<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;

final class GuardRoutesTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private string $token;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-routes-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        mkdir($this->base . '/storage/logs', 0777, true);
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, static fn (): int => 1);
        [$op, $this->token] = $this->ops->start('2', '1', 'u');
        $lines = array_map(static fn ($i) => "line {$i}", range(1, 300));
        file_put_contents($this->paths->log($op['id']), implode("\n", $lines));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testThePageLoadsWithoutAToken(): void
    {
        $r = $this->guard()->web('GET', '/_update', [], '');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('sessionStorage', $r->body);
        self::assertStringNotContainsString('<script src=', $r->body, 'self-contained: nothing loaded from elsewhere');
    }

    public function testStatusNeedsTheTokenAndHidesSecrets(): void
    {
        self::assertSame(403, $this->guard()->web('GET', '/_update/status', [], '')->status);
        $wrong = ['x-thallo-update-token' => 'nope'];
        self::assertSame(403, $this->guard()->web('GET', '/_update/status', $wrong, '')->status);
        $r = $this->guard()->web('GET', '/_update/status', ['x-thallo-update-token' => $this->token], '');
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true);
        self::assertSame('requested', $data['operation']['status']);
        self::assertArrayNotHasKey('token_hash', $data['operation']);
        self::assertSame(200, substr_count($data['log'], "\n") + 1, 'the last 200 log lines');
        self::assertStringEndsWith('line 300', $data['log']);
    }

    public function testStatusWorksWhileTheSiteIsClosedAndTheBarrierHeld(): void
    {
        $barrier = new Barrier($this->paths);
        $barrier->close();
        $held = $barrier->acquire(0, static fn (): int => 0, static function (): void {
        });
        self::assertNotNull($held);
        $r = $this->guard()->web('GET', '/_update/status', ['x-thallo-update-token' => $this->token], '');
        self::assertSame(200, $r->status);
    }

    public function testRecoveryRequests(): void
    {
        $h = ['x-thallo-update-token' => $this->token];
        self::assertSame(409, $this->recover($h, '{"option":"rollback"}'), 'not in recovery');
        $run = $this->ops->claim($this->ops->current()['id'], 1, 1);
        $this->ops->finish($run['id'], $run['owner'], Status::RECOVERY_REQUIRED, 'x');
        self::assertSame(422, $this->recover($h, '{"option":"reopen"}'), 'reopen needs the typed confirmation');
        self::assertSame(202, $this->recover($h, '{"option":"reopen","confirm":"REOPEN"}'));
        self::assertSame('reopen', $this->ops->current()['recovery_request']);
        self::assertSame(403, $this->guard()->web('POST', '/_update/recover', [], '{"option":"rollback"}')->status);
    }

    /** @param array<string,string> $headers */
    private function recover(array $headers, string $body): int
    {
        return $this->guard()->web('POST', '/_update/recover', $headers, $body)->status;
    }

    private function guard(): Guard
    {
        return new Guard($this->paths, $this->ops, new Barrier($this->paths), static function (): void {
        });
    }
}
