<?php

declare(strict_types=1);

namespace App\Tests\Unit\Signup;

use App\Signup\SignupException;
use App\Signup\VerifiedAccountActivator;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;

/**
 * The activator is the one primitive both member and customer signup activate through. Its
 * job: everything common up to identity creation, then a purpose-specific continuation, all in
 * one transaction. These tests pin the structural guarantees — kind bound under the row lock,
 * a failing continuation rolling the identity back — that keep "a shopper gets identity, not
 * authority" true by construction rather than by an implementer remembering a rule.
 */
final class VerifiedAccountActivatorTest extends AppTestCase
{
    /** @var list<string> emails whose users must be cleaned up */
    private array $createdEmails = [];
    /** @var list<string> tenants to clean up */
    private array $createdTenants = [];

    protected function tearDown(): void
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
            $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
        }
        parent::tearDown();
    }

    /** Seeds an active tenant and returns its uuid. */
    private function tenantUuid(): string
    {
        $uuid = 'act' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'activator-' . $uuid,
            'name' => 'Activator Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdTenants[] = $uuid;

        return $uuid;
    }

    /** @param array<string,mixed> $overrides */
    private function seedSignupIntent(array $overrides): string
    {
        $email = (string) ($overrides['email'] ?? 'shopper@example.test');
        $this->createdEmails[] = $email;

        return $this->container()->get(\App\Signup\SignupIntentRepository::class)->create(array_merge([
            'origin' => 'anonymous',
            'email' => $email,
            'username' => $email,
            'first_name' => 'Test',
            'last_name' => 'Shopper',
            'password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '203.0.113.10'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $overrides));
    }

    /** Seeds an email-verified intent of the given kind and returns its uuid. */
    private function intent(string $kind, string $email = 'shopper@example.test'): string
    {
        return $this->seedSignupIntent([
            'kind' => $kind,
            'email' => $email,
            'username' => $email,
            'status' => 'email_verified',
            'tenant_uuid' => $this->tenantUuid(),
        ]);
    }

    private function seedUser(string $email, string $username = null): void
    {
        $this->createdEmails[] = $email;
        $this->users()->create([
            'username' => $username ?? $email,
            'email' => $email,
            'password' => password_hash('existing-secret', PASSWORD_BCRYPT),
            'status' => 'active',
        ]);
    }

    private function users(): UserRepository
    {
        return $this->container()->get(UserRepository::class);
    }

    private function activator(): VerifiedAccountActivator
    {
        return $this->container()->get(VerifiedAccountActivator::class);
    }

    private function userExistsByEmail(string $email): bool
    {
        return $this->connection()->table('users')->where('email', '=', $email)->first() !== null;
    }

    private function countUsersByEmail(string $email): int
    {
        return (int) $this->connection()->table('users')->where('email', '=', $email)->count();
    }

    private function signupIntentStatus(string $uuid): ?string
    {
        $intent = $this->container()->get(\App\Signup\SignupIntentRepository::class)->find($uuid);

        return $intent === null ? null : (string) $intent['status'];
    }

    public function testTheContinuationRunsAfterIdentityCreationAndInsideTheTransaction(): void
    {
        $activator = $this->activator();
        $seen = [];

        $result = $activator->activate(
            $this->intent('customer'),
            'continuation-token',
            'customer',
            function (string $userUuid, array $intent, string $tenantUuid) use (&$seen): void {
                // The identity already exists when the continuation runs, which is what lets a
                // member continuation attach a membership to it.
                $seen = ['user_uuid' => $userUuid, 'kind' => $intent['kind'], 'tenant' => $tenantUuid];
            },
        );

        self::assertSame('active', $result['status']);
        self::assertSame($result['user_uuid'], $seen['user_uuid']);
        self::assertSame('customer', $seen['kind']);
    }

    public function testAFailingContinuationRollsTheIdentityBack(): void
    {
        // The whole point of one transaction: a membership that cannot be granted must not
        // leave a half-made account behind.
        $activator = $this->activator();
        $intentUuid = $this->intent('member', 'rollback@example.test');

        try {
            $activator->activate($intentUuid, 'continuation-token', 'member', function (): void {
                throw new SignupException('Workspace signup policy changed before activation.', 409);
            });
            self::fail('Expected the continuation failure to propagate.');
        } catch (SignupException) {
            // expected
        }

        self::assertFalse($this->userExistsByEmail('rollback@example.test'));
        self::assertSame('email_verified', $this->signupIntentStatus($intentUuid), 'intent must not be consumed');
    }

    public function testAKindMismatchIsRefusedBeforeAnyContinuationRuns(): void
    {
        // The structural boundary: a customer intent can never reach the member continuation,
        // and vice versa. Asserted under the row lock, so a concurrent activation cannot slip
        // between the check and the continuation.
        $activator = $this->activator();
        $ran = false;

        $this->expectException(SignupException::class);
        try {
            $activator->activate($this->intent('customer'), 'tok', 'member', function () use (&$ran): void {
                $ran = true;
            });
        } finally {
            self::assertFalse($ran, 'the member continuation must never see a customer intent');
        }
    }

    public function testAConsumedIntentReturnsItsRecordedOutcomeWithoutCreatingASecondIdentity(): void
    {
        $activator = $this->activator();
        $intentUuid = $this->intent('customer', 'twice@example.test');
        $activator->activate($intentUuid, 'tok', 'customer', static fn (): null => null);

        $second = $activator->activate($intentUuid, 'tok', 'customer', static fn (): null => null);

        self::assertSame('consumed', $second['status']);
        self::assertSame(1, $this->countUsersByEmail('twice@example.test'));
    }

    public function testAnExistingEmailBecomesAHandoffRatherThanASecondAccount(): void
    {
        $this->seedUser('taken@example.test');
        $activator = $this->activator();

        $result = $activator->activate(
            $this->intent('customer', 'taken@example.test'),
            'tok',
            'customer',
            static fn (): null => null,
        );

        self::assertSame('consumed', $result['status']);
        self::assertSame('existing_account_handoff', $result['outcome']);
    }

    public function testAUsernameConflictReturnsTheContinuationTokenForRetry(): void
    {
        $this->seedUser('other@example.test', username: 'takenname01');
        $activator = $this->activator();
        $intentUuid = $this->seedSignupIntent([
            'kind' => 'customer',
            'email' => 'fresh@example.test',
            'username' => 'takenname01',
            'status' => 'email_verified',
            'tenant_uuid' => $this->tenantUuid(),
        ]);

        $result = $activator->activate($intentUuid, 'continuation-token', 'customer', static fn (): null => null);

        self::assertSame('conflict', $result['status']);
        self::assertSame('USERNAME_CONFLICT', $result['code']);
        self::assertSame('continuation-token', $result['continuation_token']);
    }
}
