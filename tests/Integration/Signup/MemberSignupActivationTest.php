<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\SignupCoordinator;
use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Security\OTP;

final class MemberSignupActivationTest extends AppTestCase
{
    private ?string $createdTenant = null;
    private ?string $createdUser = null;

    public function testVerifiedIntentCreatesProfileMembershipAndScrubsCredentials(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $tenantUuid = 'sg' . $suffix;
        $email = "activated-{$suffix}@example.test";
        $username = 'activated' . $suffix;
        $this->seedTenant($tenantUuid, $suffix);
        $this->connection()->table('settings')->insert([
            'key' => 'signup.members.enabled',
            'value' => '1',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->connection()->table('settings')->insert([
            'key' => 'signup.members.role',
            'value' => 'viewer',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $intentUuid = $this->container()->get(SignupIntentRepository::class)->create([
            'kind' => 'member',
            'origin' => 'anonymous',
            'email' => $email,
            'username' => $username,
            'first_name' => 'Activated',
            'last_name' => 'Member',
            'password_hash' => password_hash('correct-horse', PASSWORD_BCRYPT),
            'tenant_uuid' => $tenantUuid,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '192.0.2.10'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $intentUuid,
            'otp_hash' => OTP::hashOTP('123456'),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->container()->get(SignupCoordinator::class)->verify($intentUuid, '123456');

        self::assertSame('active', $result['status']);
        $user = $this->connection()->table('users')->where('email', '=', $email)->first();
        $this->createdTenant = $tenantUuid;
        $this->createdUser = (string) $user['uuid'];
        self::assertNotNull($user['email_verified_at']);
        $profile = $this->connection()->table('profiles')->where('user_uuid', '=', $user['uuid'])->first();
        self::assertSame('Activated', $profile['first_name']);
        self::assertSame('Member', $profile['last_name']);
        $membership = $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('user_uuid', '=', $user['uuid'])
            ->first();
        self::assertSame('viewer', $membership['role']);
        self::assertSame('active', $membership['status']);
        $intent = $this->container()->get(SignupIntentRepository::class)->find($intentUuid);
        self::assertSame('consumed', $intent['status']);
        self::assertSame('activated', $intent['completion_outcome']);
        self::assertNull($intent['password_hash']);
        self::assertNull($this->connection()->table('signup_verifiers')
            ->where('intent_uuid', '=', $intentUuid)->first());
    }

    protected function tearDown(): void
    {
        if ($this->createdTenant !== null) {
            $this->connection()->table('tenant_memberships')
                ->where('tenant_uuid', '=', $this->createdTenant)->forceDelete();
            $this->connection()->table('tenants')->where('uuid', '=', $this->createdTenant)->forceDelete();
        }
        if ($this->createdUser !== null) {
            $this->connection()->table('profiles')->where('user_uuid', '=', $this->createdUser)->forceDelete();
            $this->connection()->table('users')->where('uuid', '=', $this->createdUser)->forceDelete();
        }
        parent::tearDown();
    }

    private function seedTenant(string $tenantUuid, string $suffix): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => 'signup-' . $suffix,
            'name' => 'Signup Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
