<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\SignupIntentRepository;
use App\Signup\SignupCoordinator;
use App\Signup\SignupException;
use App\Tests\Support\AppTestCase;
use Glueful\Security\OTP;

final class SignupIntentRepositoryTest extends AppTestCase
{
    private function repository(): SignupIntentRepository
    {
        return $this->container()->get(SignupIntentRepository::class);
    }

    public function testExpiredIntentIsHardDeletedWithCredentials(): void
    {
        $uuid = $this->repository()->create($this->fields('-1 hour'));
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $uuid,
            'otp_hash' => 'hash',
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->connection()->transaction(fn () => $this->repository()->lockForUpdate($uuid));

        self::assertNull($result);
        self::assertNull($this->repository()->find($uuid));
        self::assertNull($this->connection()->table('signup_verifiers')
            ->where('intent_uuid', '=', $uuid)->first());
    }

    public function testConsumeScrubsTransferableCredentialsAndRecordsOutcome(): void
    {
        $uuid = $this->repository()->create($this->fields('+1 hour'));
        $this->connection()->table('signup_continuations')->insert([
            'intent_uuid' => $uuid,
            'current_hash' => hash('sha256', 'secret'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->repository()->consume($uuid, 'activated');

        $row = $this->repository()->find($uuid);
        self::assertSame('consumed', $row['status']);
        self::assertSame('activated', $row['completion_outcome']);
        self::assertNull($row['password_hash']);
        self::assertNull($row['request_ip_hash']);
        self::assertNull($this->connection()->table('signup_continuations')
            ->where('intent_uuid', '=', $uuid)->first());
    }

    public function testGuardedTransitionRejectsStaleState(): void
    {
        $uuid = $this->repository()->create($this->fields('+1 hour'));
        self::assertFalse($this->repository()->transition($uuid, 'email_verified', 'provisioning'));
        self::assertTrue($this->repository()->transition($uuid, 'pending', 'email_verified'));
    }

    public function testFailedVerificationCommitsTheAttemptCounter(): void
    {
        $uuid = $this->repository()->create($this->fields('+1 hour'));
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $uuid,
            'otp_hash' => OTP::hashOTP('123456'),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        try {
            $this->container()->get(SignupCoordinator::class)->verify($uuid, '000000');
            self::fail('Expected invalid verification to fail.');
        } catch (SignupException $exception) {
            self::assertSame(422, $exception->status);
        }
        $row = $this->connection()->table('signup_verifiers')
            ->where('intent_uuid', '=', $uuid)->first();
        self::assertSame(1, (int) $row['attempts']);
    }

    /** @return array<string,mixed> */
    private function fields(string $expiry): array
    {
        return [
            'kind' => 'member',
            'origin' => 'anonymous',
            'email' => 'person@example.test',
            'username' => 'person',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'password_hash' => 'transferable',
            'tenant_uuid' => 'tenant000001',
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '127.0.0.1'),
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime($expiry)),
        ];
    }
}
