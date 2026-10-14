<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class GuardVerifyTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private string $token;
    private string $secret;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-verify-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        mkdir($this->base . '/bootstrap', 0777, true);
        mkdir($this->base . '/vendor', 0777, true);
        $app = '<?php return new class { public function getContainer(): object { return new \stdClass(); } };';
        file_put_contents($this->base . '/bootstrap/app.php', $app);
        mkdir($this->base . '/.thallo-update-control', 0750);
        touch($this->base . '/.thallo-update-control/admission.lock');
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, static fn (): int => 1);
        $this->token = bin2hex(random_bytes(32));
        $this->ops->start('2', '1', 'u', hash('sha256', $this->token));
        $this->secret = bin2hex(random_bytes(32));
        file_put_contents($this->paths->verifySecret(), $this->secret . "\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testProbeReportsTheWebProcessesView(): void
    {
        $r = $this->verify($this->secret, 'probe');
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true);
        self::assertArrayHasKey('writable_code', $data);
        self::assertArrayHasKey('validate_timestamps', $data['opcache']);
        self::assertTrue($data['admission_ok']);
        self::assertArrayHasKey('control_writable', $data);
        self::assertArrayHasKey('private_readable', $data);
        // The PHP-FPM master that answered, so the runner can tell it from another PHP-FPM on the
        // server; outside PHP-FPM (this test runs on the command line) there is none.
        self::assertArrayHasKey('fpm_master', $data);
        self::assertNull($data['fpm_master']);
    }

    public function testBootReportsTheVersionAndTheContainer(): void
    {
        $data = json_decode($this->verify($this->secret, 'boot')->body, true);
        self::assertTrue($data['container']);
        self::assertIsString($data['version']);
        self::assertSame([], $data['missing_extensions']);
    }

    public function testTheRecoveryTokenCannotVerify(): void
    {
        self::assertSame(403, $this->verify($this->token, 'boot')->status);
        self::assertSame(403, $this->verify('', 'probe')->status);
    }

    public function testTheSecretCannotReadStatus(): void
    {
        $g = $this->guard();
        $asToken = ['x-thallo-update-token' => $this->secret];
        self::assertSame(403, $g->web('GET', '/_update/status', $asToken, '')->status);
        self::assertSame(403, $g->web('POST', '/_update/recover', $asToken, '{"option":"rollback"}')->status);
    }

    public function testVerifyIsAnsweredWhileTheBarrierIsHeld(): void
    {
        $barrier = new Barrier($this->paths);
        $barrier->close();
        $held = $barrier->acquire(0, static fn (): int => 0, static function (): void {
        });
        self::assertNotNull($held);
        self::assertSame(200, $this->verify($this->secret, 'probe')->status, 'verification never waits on the barrier');
    }

    private function verify(string $secret, string $mode): \Thallo\Core\Updater\GuardResponse
    {
        $body = (string) json_encode(['mode' => $mode]);
        return $this->guard()->web('POST', '/_update/verify', ['x-thallo-verify' => $secret], $body);
    }

    private function guard(): Guard
    {
        return new Guard($this->paths, new Barrier($this->paths), static function (): void {
        });
    }
}
