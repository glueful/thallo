<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\AdminUpdateController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updates\UpdatePreflight;

/**
 * Updating from the admin, PHP-FPM's side: owner only (system.config, which only the superuser install
 * role holds), and it only checks, files a request and cancels one the runner has not claimed.
 */
final class AdminUpdateApiTest extends AppTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/thallo-admin-update-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'preflight' => ['GET', '/v1/admin/updates/preflight'];
        yield 'start' => ['POST', '/v1/admin/updates'];
        yield 'cancel' => ['DELETE', '/v1/admin/updates/current'];
    }

    /** @dataProvider routes */
    public function testEveryRouteIsOwnerOnly(string $method, string $path): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} must be registered");
        self::assertContains('auth', $route['middleware']);
        self::assertContains('tenant_system', $route['middleware']);
        self::assertContains('content_permission:system.config', $route['middleware']);
    }

    /** @dataProvider routes */
    public function testAnonymousRequestsAreRejected(string $method, string $path): void
    {
        self::assertSame(401, $this->handle($this->jsonRequest($method, $path))->getStatusCode());
    }

    public function testStartFilesTheRequestAndReturnsTheTokenOnce(): void
    {
        $controller = $this->controller(ready: true);
        $response = $controller->start($this->owner());
        self::assertSame(202, $response->getStatusCode());
        $update = json_decode((string) $response->getContent(), true)['data']['update'];
        self::assertSame(64, strlen($update['token']));
        $op = json_decode((string) file_get_contents($this->base . '/storage/framework/updates/operation.json'), true);
        self::assertSame('requested', $op['status']);
        self::assertSame('1.0.0-beta.102', $op['target']);
        self::assertSame('owner-uuid', $op['requested_by']);
        self::assertStringNotContainsString($update['token'], json_encode($op), 'only its hash is stored');

        $again = $controller->start($this->owner());
        self::assertSame(409, $again->getStatusCode());
        $body = json_decode((string) $again->getContent(), true);
        self::assertSame(['in_progress'], $body['error']['details']['reasons']);
    }

    public function testANotReadyPreflightRefusesWithItsReasons(): void
    {
        $response = $this->controller(ready: false)->start($this->owner());
        self::assertSame(409, $response->getStatusCode());
        $reasons = json_decode((string) $response->getContent(), true)['error']['details']['reasons'];
        self::assertSame(['opcache_preload'], $reasons);
        self::assertFileDoesNotExist($this->base . '/storage/framework/updates/operation.json');
    }

    public function testCancelOnlyBeforeTheRunnerClaims(): void
    {
        $controller = $this->controller(ready: true);
        $controller->start($this->owner());
        self::assertSame(200, $controller->cancel()->getStatusCode());
        self::assertSame(409, $controller->cancel()->getStatusCode(), 'nothing left to cancel');

        $controller->start($this->owner());
        $ops = new OperationStore(new Paths($this->base), static fn (): int => time());
        $ops->claim($ops->current()['id'], 1, null);
        self::assertSame(409, $controller->cancel()->getStatusCode(), 'a claimed update cannot be cancelled');
    }

    private function controller(bool $ready): AdminUpdateController
    {
        $ops = new OperationStore(new Paths($this->base), static fn (): int => time());
        return new AdminUpdateController($this->preflight($ready), $ops);
    }

    /**
     * A preflight whose verdict the test chooses: ready, or refused for one reason. It is the real
     * class, fed a status and a runner report that produce exactly that verdict.
     */
    private function preflight(bool $ready): UpdatePreflight
    {
        $updates = $this->base . '/storage/framework/updates';
        file_put_contents($this->base . '/composer.json', '{"require":{"glueful/thallo-core":"^1.0.0-beta.101"}}');
        @mkdir($this->base . '/bootstrap');
        @mkdir($this->base . '/public');
        @mkdir($this->base . '/vendor', 0555);
        file_put_contents($this->base . '/bootstrap/update-guard.php', '<?php');
        file_put_contents($this->base . '/public/index.php', '<?php // update-guard.php');
        file_put_contents($this->base . '/glueful', '<?php // update-guard.php');
        file_put_contents($updates . '/runner.json', json_encode([
            'checked_at' => time(),
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
            'web' => ['status' => 200, 'admission_ok' => true, 'state_writable' => true],
        ]));
        return new UpdatePreflight(
            ['enabled' => true],
            ['current' => '1.0.0-beta.101', 'latest' => '1.0.0-beta.102', 'available' => true],
            $this->base,
            static fn (string $k): string => match ($k) {
                'opcache.enable', 'opcache.validate_timestamps' => '1',
                'opcache.preload' => $ready ? '' : '/app/preload.php',
                default => '',
            },
            static fn (): string => 'fpm-fcgi',
            static fn (): int => time(),
        );
    }

    private function owner(): Request
    {
        $request = Request::create('/v1/admin/updates', 'POST');
        $request->attributes->set('user', ['uuid' => 'owner-uuid', 'roles' => [], 'scopes' => []]);
        return $request;
    }
}
