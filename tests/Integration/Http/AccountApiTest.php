<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Auth\JWTService;
use Glueful\Auth\PasswordHasher;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\AccountAdminController;
use Thallo\Core\Http\DTOs\ChangePasswordData;
use Thallo\Core\Http\DTOs\UpdateAccountData;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The signed-in user's own account (the admin's Profile and Security pages): their name and photo,
 * and their password. Only ever the caller's account — there is no user id to name.
 */
final class AccountApiTest extends AppTestCase
{
    private function users(): UserRepository
    {
        return $this->container()->get(UserRepository::class);
    }

    private function makeUser(string $password = 'old-password-1'): string
    {
        $suffix = bin2hex(random_bytes(4));
        return $this->users()->create([
            'username' => "acct{$suffix}",
            'email' => "acct{$suffix}@example.test",
            'password' => (new PasswordHasher())->hash($password),
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function request(?string $userUuid, ?string $sid = null): Request
    {
        $request = Request::create('https://admin.test/v1/admin/account', 'PATCH');
        if ($userUuid !== null) {
            $request->attributes->set('user', ['uuid' => $userUuid]);
        }
        if ($sid !== null) {
            $token = JWTService::generate(['sub' => $userUuid, 'sid' => $sid, 'ver' => 1]);
            $request->headers->set('Authorization', "Bearer {$token}");
        }
        return $request;
    }

    private function controller(): AccountAdminController
    {
        return $this->container()->get(AccountAdminController::class);
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function update(?string $user, array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(UpdateAccountData::class, $body);
        $resp = $this->controller()->update($dto, $this->request($user));
        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function password(?string $user, array $body, ?string $sid = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(ChangePasswordData::class, $body);
        $resp = $this->controller()->password($dto, $this->request($user, $sid));
        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    }

    private function session(string $user, string $uuid): void
    {
        $this->connection()->table('auth_sessions')->insert([
            'uuid' => $uuid,
            'user_uuid' => $user,
            'status' => 'active',
            'provider' => 'jwt',
            'session_version' => 1,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function sessionStatus(string $uuid): ?string
    {
        $row = $this->connection()->table('auth_sessions')->where('uuid', $uuid)->first();
        return is_array($row) ? (string) $row['status'] : null;
    }

    public function testTheAccountReadSaysWhetherTwoFactorIsAvailableOnThisInstall(): void
    {
        $user = $this->makeUser();
        $this->users()->updateProfile($user, ['first_name' => 'Ama']);
        $read = function () use ($user): array {
            $resp = $this->controller()->show($this->request($user));
            self::assertSame(200, $resp->getStatusCode());
            return json_decode((string) $resp->getContent(), true)['data'];
        };
        $data = $read();
        self::assertSame($user, $data['uuid']);
        self::assertSame('Ama', $data['profile']['first_name']);
        self::assertArrayNotHasKey('password', $data);
        $available = (bool) config($this->appContext(), 'auth.two_factor.enabled', false);
        self::assertSame($available, $data['two_factor_available']);
        self::assertSame(401, $this->controller()->show($this->request(null))->getStatusCode());
    }

    public function testAUserSetsTheirOwnNameAndPhotoAndClearsThem(): void
    {
        $user = $this->makeUser();
        $result = $this->update($user, [
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'photo_url' => '/v1/blobs/abcdef123456',
        ]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame(
            ['first_name' => 'Ama', 'last_name' => 'Mensah', 'photo_url' => '/v1/blobs/abcdef123456'],
            array_intersect_key(
                $result['body']['data']['profile'],
                array_flip(['first_name', 'last_name', 'photo_url']),
            ),
        );
        $profile = $this->users()->getProfile($user) ?? [];
        self::assertSame('Ama', $profile['first_name'] ?? null);

        // An empty value clears; an absent one leaves the field as it was.
        $cleared = $this->update($user, ['photo_url' => '']);
        self::assertSame(200, $cleared['status']);
        $profile = $this->users()->getProfile($user) ?? [];
        self::assertNull($profile['photo_url'] ?? null);
        self::assertSame('Mensah', $profile['last_name'] ?? null);
    }

    public function testAPhotoIsAnImagePathOrAWebAddressAndNothingElse(): void
    {
        $user = $this->makeUser();
        foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.example/x.png'] as $bad) {
            $result = $this->update($user, ['photo_url' => $bad]);
            self::assertSame(422, $result['status'], $bad);
        }
        self::assertSame(200, $this->update($user, ['photo_url' => 'https://cdn.example.test/me.png'])['status']);
    }

    public function testNobodySignedInGetsNothing(): void
    {
        self::assertSame(401, $this->update(null, ['first_name' => 'X'])['status']);
        $anonymous = $this->password(null, ['current_password' => 'a', 'password' => 'new-password-1']);
        self::assertSame(401, $anonymous['status']);
    }

    public function testAWrongCurrentPasswordOrAShortNewOneIsRefusedAndNothingChanges(): void
    {
        $user = $this->makeUser('old-password-1');
        $wrong = $this->password($user, ['current_password' => 'not-it', 'password' => 'new-password-1']);
        self::assertSame(422, $wrong['status']);
        $errors = $wrong['body']['error']['details'] ?? $wrong['body']['errors'] ?? [];
        self::assertArrayHasKey('current_password', $errors);
        $short = $this->password($user, ['current_password' => 'old-password-1', 'password' => 'short']);
        self::assertSame(422, $short['status']);

        $hash = (string) ($this->users()->findAccountRow($user, ['password'])['password'] ?? '');
        self::assertTrue((new PasswordHasher())->verify('old-password-1', $hash));
    }

    public function testAChangedPasswordSignsOutEveryOtherSessionAndKeepsThisOne(): void
    {
        $user = $this->makeUser('old-password-1');
        $other = $this->makeUser();
        // Session rows outlive a test here: random ids, removed again whatever happens.
        $id = static fn (string $p): string => $p . bin2hex(random_bytes(4));
        [$current, $first, $second, $foreign] = [$id('cur'), $id('oth'), $id('oth'), $id('frn')];
        try {
            $this->session($user, $current);
            $this->session($user, $first);
            $this->session($user, $second);
            $this->session($other, $foreign);

            $result = $this->password(
                $user,
                ['current_password' => 'old-password-1', 'password' => 'new-password-1'],
                $current,
            );
            self::assertSame(200, $result['status'], json_encode($result['body']));
            self::assertSame(2, $result['body']['data']['other_sessions_signed_out']);

            $hash = (string) ($this->users()->findAccountRow($user, ['password'])['password'] ?? '');
            self::assertTrue((new PasswordHasher())->verify('new-password-1', $hash));
            self::assertSame('active', $this->sessionStatus($current));
            self::assertSame('revoked', $this->sessionStatus($first));
            self::assertSame('revoked', $this->sessionStatus($second));
            self::assertSame('active', $this->sessionStatus($foreign), 'another user’s session is not touched');
        } finally {
            $this->connection()->table('auth_sessions')
                ->whereIn('uuid', [$current, $first, $second, $foreign])
                ->delete();
        }
    }

    public function testTheRoutesNeedOnlyASignedInUser(): void
    {
        $routes = [
            ['GET', '/v1/admin/account'],
            ['PATCH', '/v1/admin/account'],
            ['POST', '/v1/admin/account/password'],
        ];
        foreach ($routes as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path}");
            self::assertContains('auth', $route['middleware']);
            self::assertSame(
                [],
                array_values(array_filter(
                    $route['middleware'],
                    static fn ($m): bool => is_string($m) && str_starts_with($m, 'content_permission:'),
                )),
                'your own account needs no permission',
            );
        }
        self::assertContains('rate_limit', $this->findRoute('POST', '/v1/admin/account/password')['middleware']);
    }
}
