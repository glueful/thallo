<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Http\Controllers\SignupController;
use App\Http\Controllers\TenantRolesController;
use App\Signup\SignupChallenge;
use App\Signup\SignupConfig;
use App\Signup\SignupCoordinator;
use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Tenancy\SignupDiagnostics;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\System\SystemFlags;

final class SignupWiringTest extends AppTestCase
{
    public function testSignupGraphResolvesWithCapabilitiesOff(): void
    {
        self::assertInstanceOf(SignupController::class, $this->container()->get(SignupController::class));
        self::assertInstanceOf(SignupCoordinator::class, $this->container()->get(SignupCoordinator::class));
        self::assertInstanceOf(SignupIntentRepository::class, $this->container()->get(SignupIntentRepository::class));
        self::assertInstanceOf(SignupChallenge::class, $this->container()->get(SignupChallenge::class));
        self::assertFalse($this->container()->get(SignupConfig::class)->workspaceSignupEnabled());
        self::assertSame('ok', $this->container()->get(SignupDiagnostics::class)->check()['status']);
    }

    public function testPublicSignupRoutesAreRegistered(): void
    {
        $paths = array_map(
            static fn ($route): string => $route->getPath(),
            array_values($this->router()->getStaticRoutes()),
        );
        self::assertContains('/v1/signup/member', $paths);
        self::assertContains('/v1/signup/workspace', $paths);
        self::assertContains('/v1/signup/verify', $paths);
    }

    public function testDisabledMemberSignupIsNeutralWithoutSingleStorePointer(): void
    {
        $request = $this->jsonRequest('POST', '/v1/signup/member', [
            'email' => 'neutral@example.test',
            'username' => 'neutraluser',
            'password' => 'correct-horse',
            'first_name' => 'Neutral',
            'last_name' => 'User',
        ]);
        $request->headers->set('Host', 'site.test');
        $request->server->set('REMOTE_ADDR', '192.0.2.44');

        $response = $this->handle($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(0, $this->connection()->table('signup_intents')->count());
    }

    public function testSingleStoreSettingsAndRolesResolveTheDefaultWorkspace(): void
    {
        $tenantUuid = 'sg' . bin2hex(random_bytes(5));
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => 'signup-' . strtolower($tenantUuid),
            'name' => 'Signup Settings',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.default_tenant_uuid', $tenantUuid);
        try {
            $settings = $this->container()->get(SignupController::class)->singleStoreMemberSettings(
                Request::create('/v1/admin/settings/signup', 'GET'),
            );
            self::assertSame(200, $settings->getStatusCode());
            $settingsData = (array) json_decode((string) $settings->getContent(), true);
            self::assertContains('viewer', $settingsData['data']['settings']['eligible_roles']);
            self::assertContains('member', $settingsData['data']['settings']['eligible_roles']);

            $roles = $this->container()->get(TenantRolesController::class)->index(
                Request::create('/v1/admin/settings/signup/roles', 'GET'),
            );
            self::assertSame(200, $roles->getStatusCode());
            $roleData = (array) json_decode((string) $roles->getContent(), true);
            self::assertContains('owner', array_column($roleData['data']['roles'], 'slug'));

            $create = Request::create(
                '/v1/admin/settings/signup/roles',
                'POST',
                content: (string) json_encode(['slug' => 'signup_reader', 'name' => 'Signup Reader']),
            );
            $create->attributes->set('user', ['uuid' => 'user00000001']);
            $created = $this->container()->get(TenantRolesController::class)->create($create);
            self::assertSame(201, $created->getStatusCode());

            $updatedSettings = $this->container()->get(SignupController::class)->singleStoreMemberSettings(
                Request::create('/v1/admin/settings/signup', 'GET'),
            );
            $updatedData = (array) json_decode((string) $updatedSettings->getContent(), true);
            self::assertContains('signup_reader', $updatedData['data']['settings']['eligible_roles']);
        } finally {
            $flags->forget('tenancy.default_tenant_uuid');
            $flags->clearCache();
            $this->connection()->table('tenant_role_overrides')
                ->where('tenant_uuid', '=', $tenantUuid)->forceDelete();
            $this->connection()->table('tenant_roles')
                ->where('tenant_uuid', '=', $tenantUuid)->forceDelete();
            $this->connection()->table('tenant_role_policy')
                ->where('tenant_uuid', '=', $tenantUuid)->forceDelete();
            $this->connection()->table('tenants')->where('uuid', '=', $tenantUuid)->forceDelete();
        }
    }

    public function testSingleStoreSignupAdministrationUsesGlobalRoleAuthority(): void
    {
        foreach (
            [
                ['GET', '/v1/admin/settings/signup'],
                ['PUT', '/v1/admin/settings/signup'],
                ['GET', '/v1/admin/settings/signup/roles'],
                ['POST', '/v1/admin/settings/signup/roles'],
            ] as [$method, $path]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} is not registered.");
            self::assertContains('tenant_system', $route['middleware']);
            self::assertContains('auth', $route['middleware']);
            self::assertContains('content_permission:users.roles.manage', $route['middleware']);
        }
    }
}
