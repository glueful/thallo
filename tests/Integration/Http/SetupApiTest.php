<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\AdminConfigController;
use App\Http\Controllers\SetupController;
use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration tests for `POST /admin/setup`.
 *
 * Verifies:
 * - First setup creates the admin user and flips the installed marker.
 * - Subsequent requests are permanently locked with 409.
 * - /admin/config reports installed:false before and installed:true after.
 * - The gate reads the persisted installed invariant, not controller-local state.
 *
 * Requires `composer test:migrate` to have been run first (settings + users tables must exist).
 */
final class SetupApiTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean slate on each test: wipe users, Aegis user_roles, and the
        // settings markers so install() is always re-runnable.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, settings CASCADE');
    }

    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    private function controller(): SetupController
    {
        return new SetupController($this->appContext(), $this->service());
    }

    /** @param array<string,mixed> $body */
    private function setupData(array $body): SetupData
    {
        /** @var SetupData $dto */
        $dto = (new RequestDataHydrator())->hydrate(SetupData::class, $body);
        return $dto;
    }

    /** @return array<string,string> */
    private function validBody(): array
    {
        return [
            'site_name'      => 'getlemma.dev',
            'admin_email'    => 'admin@getlemma.dev',
            'admin_password' => 'correct horse battery',
            'locale'         => 'en',
        ];
    }

    public function testFirstSetupCreatesAdminAndFlipsInstalled(): void
    {
        self::assertFalse($this->service()->isInstalled(), 'a fresh install is not installed');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));

        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($this->service()->isInstalled(), 'install() flips the installed marker');

        // The admin now exists and can be looked up by email (created via glueful/users).
        $userRepo = $this->container()->get(UserRepository::class);
        self::assertNotNull(
            $userRepo->findByEmail('admin@getlemma.dev'),
            'the first admin was created',
        );

        // Default chrome regions seeded (global-regions spec §9): header is
        // logo + navigation(main), footer carries the site name as rich_text.
        $regions = $this->container()->get(\App\Content\Regions\RegionRepository::class);
        $header = $regions->find('header');
        self::assertNotNull($header);
        self::assertSame(['logo', 'navigation'], array_column($header['blocks'], 'type'));
        self::assertSame('main', $header['blocks'][1]['data']['menu']);
        $footer = $regions->find('footer');
        self::assertNotNull($footer);
        self::assertSame(['rich_text'], array_column($footer['blocks'], 'type'));
    }

    public function testSecondSetupIsPermanentlyLockedWith409(): void
    {
        $this->controller()->setup($this->setupData($this->validBody()));

        $second = $this->controller()->setup($this->setupData([
            'site_name'      => 'evil.example',
            'admin_email'    => 'attacker@evil.example',
            'admin_password' => 'another password',
            'locale'         => 'en',
        ]));

        self::assertSame(409, $second->getStatusCode(), 'setup self-locks once installed');

        $userRepo = $this->container()->get(UserRepository::class);
        self::assertNull(
            $userRepo->findByEmail('attacker@evil.example'),
            'no second admin is ever created',
        );
    }

    public function testConfigJsonReportsInstalledBeforeAndAfter(): void
    {
        $config = new AdminConfigController($this->appContext(), $this->service());

        $before = json_decode((string) $config->config()->getContent(), true);
        self::assertFalse($before['installed'], '/admin/config reports installed:false before setup');

        $this->controller()->setup($this->setupData($this->validBody()));

        $after = json_decode((string) $config->config()->getContent(), true);
        self::assertTrue($after['installed'], '/admin/config reports installed:true after setup');
    }

    public function testGateIsBoundToTheInstalledInvariantNotASoftCheck(): void
    {
        // Guard test: the lock is the installed/no-admin INVARIANT, not a one-shot flag the
        // controller flips. Install via the service directly (bypassing the controller), then a
        // controller setup must STILL be refused — proving the gate reads isInstalled(), which is
        // backed by the persisted marker / first-admin uniqueness, not controller-local state.
        $this->service()->install('seeded.example', 'seed@example.test', 'seed password', 'en');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSetupRouteIsRegisteredUnauthenticated(): void
    {
        $route = $this->findRoute('POST', '/admin/setup');
        self::assertNotNull($route, 'POST /admin/setup must be registered');
        self::assertNotContains('auth', (array) ($route['middleware'] ?? []), 'setup must be unauthenticated');
    }

    public function testHttpSetupWithConfiguredTokenRefusesWithoutHeader(): void
    {
        $restore = $this->forceSetupToken('s3cret-token');
        try {
            // A real HTTP request (not the null CLI path) with no X-Setup-Token is refused.
            $resp = $this->controller()->setup(
                $this->setupData($this->validBody()),
                Request::create('/admin/setup', 'POST'),
            );
            self::assertSame(403, $resp->getStatusCode());
            self::assertFalse($this->service()->isInstalled(), 'a token-less request must not install');
        } finally {
            $restore();
        }
    }

    public function testHttpSetupWithCorrectTokenSucceeds(): void
    {
        $restore = $this->forceSetupToken('s3cret-token');
        try {
            $resp = $this->controller()->setup(
                $this->setupData($this->validBody()),
                Request::create('/admin/setup', 'POST', [], [], [], ['HTTP_X_SETUP_TOKEN' => 's3cret-token']),
            );
            self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());
            self::assertTrue($this->service()->isInstalled());
        } finally {
            $restore();
        }
    }

    /** Set config `thallo.setup.token` in the process-shared context cache; returns a restore closure. */
    private function forceSetupToken(string $token): \Closure
    {
        $context = $this->appContext();
        $ref = new \ReflectionProperty($context, 'configCache');
        $ref->setAccessible(true);
        /** @var array<string,mixed> $previous */
        $previous = $ref->getValue($context);

        $patched = $previous;
        $patched['thallo.setup.token'] = $token;
        $ref->setValue($context, $patched);

        return static function () use ($ref, $context, $previous): void {
            $ref->setValue($context, $previous);
        };
    }
}
