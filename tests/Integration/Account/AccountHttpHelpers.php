<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared HTTP-flow and seeding helpers for the account test classes. `AppTestCase` provides no
 * cookie-aware HTTP client and no auth-login helper, so the account suites share these rather than
 * duplicating them. A using class must call {@see cleanupAccountArtifacts()} from its tearDown —
 * setUp truncates neither users nor tenants.
 */
trait AccountHttpHelpers
{
    /** @var list<string> */
    private array $createdEmails = [];
    /** @var list<string> */
    private array $createdTenants = [];

    private function cleanupAccountArtifacts(): void
    {
        foreach ($this->createdEmails as $email) {
            $user = $this->connection()->table('users')->where('email', '=', $email)->first();
            if (is_array($user)) {
                foreach (['tenant_memberships', 'user_roles', 'user_permissions', 'profiles'] as $table) {
                    $this->connection()->table($table)->where('user_uuid', '=', $user['uuid'])->forceDelete();
                }
                $this->connection()->table('users')->where('uuid', '=', $user['uuid'])->forceDelete();
            }
        }
        foreach ($this->createdTenants as $tenant) {
            $this->connection()->table('tenant_memberships')->where('tenant_uuid', '=', $tenant)->forceDelete();
            $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
        }
        $this->createdEmails = [];
        $this->createdTenants = [];
    }

    private function seedUser(string $email, string $password = 'sufficiently-long-secret'): string
    {
        $this->createdEmails[] = $email;

        return $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'status' => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedTenant(): string
    {
        $uuid = 'acct' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'account-' . $uuid,
            'name' => 'Account Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdTenants[] = $uuid;

        return $uuid;
    }

    /** @return array<string, Cookie> */
    private function signInAs(string $email, string $password = 'sufficiently-long-secret'): array
    {
        $this->seedUser($email, $password);

        return $this->cookiesFrom($this->postSameOrigin('/account/login', [
            'email' => $email,
            'password' => $password,
        ]));
    }

    private function userUuidFor(string $email): string
    {
        $row = $this->connection()->table('users')->where('email', '=', $email)->first();

        return is_array($row) ? (string) $row['uuid'] : '';
    }

    private function userExistsByEmail(string $email): bool
    {
        return $this->connection()->table('users')->where('email', '=', $email)->first() !== null;
    }

    /** @return array<string,string> table => user-uuid column */
    private function authorityTables(): array
    {
        return [
            'tenant_memberships' => 'user_uuid',
            'user_roles' => 'user_uuid',
            'user_permissions' => 'user_uuid',
        ];
    }

    /** @param array<string,Cookie|string> $cookies */
    private function get(string $path, array $cookies = []): Response
    {
        return $this->handle($this->buildRequest('GET', $path, [], $cookies, []));
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function post(string $path, array $body = [], array $cookies = [], array $headers = []): Response
    {
        return $this->handle($this->buildRequest('POST', $path, $body, $cookies, $headers));
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function postSameOrigin(string $path, array $body = [], array $cookies = [], array $headers = []): Response
    {
        return $this->post($path, $body, $cookies, $headers + ['Sec-Fetch-Site' => 'same-origin']);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function buildRequest(string $method, string $path, array $body, array $cookies, array $headers): Request
    {
        $cookieValues = [];
        foreach ($cookies as $name => $cookie) {
            $cookieValues[$name] = $cookie instanceof Cookie ? (string) $cookie->getValue() : (string) $cookie;
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create($path, $method, $body, $cookieValues, [], $server);
    }

    /** @return array<string, Cookie> */
    private function cookiesFrom(Response $response): array
    {
        $map = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $map[$cookie->getName()] = $cookie;
        }

        return $map;
    }
}
