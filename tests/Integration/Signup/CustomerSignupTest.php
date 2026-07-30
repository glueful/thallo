<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\CustomerSignupService;
use App\Signup\MemberSignupService;
use App\Signup\SignupCoordinator;
use App\Signup\SignupException;
use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Security\OTP;

/**
 * Customer signup reaches the same activator through the coordinator's kind dispatch, and comes
 * out the other side as a global identity with zero workspace authority. These tests pin that
 * outcome, the neutral-registration boundary, and — under real PostgreSQL — that two shoppers
 * racing on one email resolve deterministically without a constraint violation escaping as a 500.
 */
final class CustomerSignupTest extends AppTestCase
{
    /** @var list<string> */
    private array $createdEmails = [];
    /** @var list<string> */
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
            $this->connection()->table('tenant_memberships')->where('tenant_uuid', '=', $tenant)->forceDelete();
            $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
        }
        parent::tearDown();
    }

    /** @return array{string,string} [intentUuid, otp] */
    private function beginCustomerSignup(string $email): array
    {
        $tenant = $this->seedTenant();
        $this->createdEmails[] = $email;
        $intentUuid = $this->container()->get(SignupIntentRepository::class)->create([
            'kind' => 'customer',
            'origin' => 'anonymous',
            'email' => $email,
            'username' => $email,
            'first_name' => 'Test',
            'last_name' => 'Shopper',
            'password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
            'tenant_uuid' => $tenant,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '203.0.113.10'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->seedVerifier($intentUuid);

        return [$intentUuid, '123456'];
    }

    /** @return array{string,string} [intentUuid, otp] */
    private function beginMemberSignup(string $email): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = $this->seedTenant();
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
        $this->container()->get(\App\Settings\SettingsStore::class)->clearCache();
        $this->createdEmails[] = $email;
        $intentUuid = $this->container()->get(SignupIntentRepository::class)->create([
            'kind' => 'member',
            'origin' => 'anonymous',
            'email' => $email,
            'username' => 'member' . $suffix,
            'first_name' => 'Member',
            'last_name' => 'Person',
            'password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
            'tenant_uuid' => $tenant,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '203.0.113.10'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->seedVerifier($intentUuid);

        return [$intentUuid, '123456'];
    }

    private function seedTenant(): string
    {
        $uuid = 'cust' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'customer-' . $uuid,
            'name' => 'Customer Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdTenants[] = $uuid;

        return $uuid;
    }

    private function seedVerifier(string $intentUuid): void
    {
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $intentUuid,
            'otp_hash' => OTP::hashOTP('123456'),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,string> table => user-uuid column */
    private function authorityTables(): array
    {
        // Pinned to the tables that confer authority today (verified against the schema). A table
        // missed here is authority this test cannot see.
        return [
            'tenant_memberships' => 'user_uuid',
            'user_roles' => 'user_uuid',
            'user_permissions' => 'user_uuid',
        ];
    }

    private function userExistsByEmail(string $email): bool
    {
        return $this->connection()->table('users')->where('email', '=', $email)->first() !== null;
    }

    private function countUsersByEmail(string $email): int
    {
        return (int) $this->connection()->table('users')->where('email', '=', $email)->count();
    }

    private function userUuidFor(string $email): string
    {
        $row = $this->connection()->table('users')->where('email', '=', $email)->first();

        return is_array($row) ? (string) $row['uuid'] : '';
    }

    private function usernameFor(string $email): string
    {
        $row = $this->connection()->table('users')->where('email', '=', $email)->first();

        return is_array($row) ? (string) $row['username'] : '';
    }

    /** @return array<string,mixed> */
    private function profileFor(string $userUuid): array
    {
        $row = $this->connection()->table('profiles')->where('user_uuid', '=', $userUuid)->first();

        return is_array($row) ? $row : [];
    }

    private function membershipCountFor(string $userUuid): int
    {
        return (int) $this->connection()->table('tenant_memberships')->where('user_uuid', '=', $userUuid)->count();
    }

    private function signupIntentField(string $intentUuid, string $field): mixed
    {
        return $this->container()->get(SignupIntentRepository::class)->find($intentUuid)[$field] ?? null;
    }

    public function testAVerifiedCustomerIntentActivatesThroughTheCoordinator(): void
    {
        // Before the third branch existed this fell through to workspace provisioning, whose
        // kind guard 404s — a correct OTP answered with "your signup does not exist".
        [$intentUuid, $otp] = $this->beginCustomerSignup('shopper@example.test');

        $result = $this->container()->get(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('active', $result['status']);
        self::assertTrue($this->userExistsByEmail('shopper@example.test'));
    }

    public function testACustomerActivationGrantsNoWorkspaceAuthority(): void
    {
        // The acceptance criterion of this whole plan.
        [$intentUuid, $otp] = $this->beginCustomerSignup('noauthority@example.test');
        $result = $this->container()->get(SignupCoordinator::class)->verify($intentUuid, $otp);
        $userUuid = (string) $result['user_uuid'];

        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) $this->connection()->table($table)->where($column, '=', $userUuid)->count(),
                "{$table} must hold no rows for a shopper"
            );
        }
    }

    public function testAMemberIntentStillActivatesAsAMember(): void
    {
        // The dispatch change must not disturb the existing branch.
        [$intentUuid, $otp] = $this->beginMemberSignup('member@example.test');

        $result = $this->container()->get(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('active', $result['status']);
        self::assertGreaterThan(0, $this->membershipCountFor((string) $result['user_uuid']));
    }

    public function testACustomerIntentCannotBeDrivenThroughTheMemberService(): void
    {
        [$intentUuid] = $this->beginCustomerSignup('crosswire@example.test');

        $this->expectException(SignupException::class);
        $this->container()->get(MemberSignupService::class)->activate($intentUuid, 'tok');
    }

    public function testTheUsernameIsTheEmail(): void
    {
        // No derivation, so no collision handling and no retry: the email is already unique, and
        // a shopper who wants something else can change it from their account later.
        [$intentUuid, $otp] = $this->beginCustomerSignup('username@example.test');

        $this->container()->get(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('username@example.test', $this->usernameFor('username@example.test'));
    }

    public function testFirstAndLastNameAreStoredSeparately(): void
    {
        [$intentUuid, $otp] = $this->beginCustomerSignup('names@example.test');
        // The seed uses Test/Shopper; assert both columns are populated independently.
        $result = $this->container()->get(SignupCoordinator::class)->verify($intentUuid, $otp);
        $profile = $this->profileFor((string) $result['user_uuid']);

        self::assertSame('Test', $profile['first_name']);
        self::assertSame('Shopper', $profile['last_name']);
    }

    public function testRegistrationIsNeutralForNewAndExistingEmails(): void
    {
        // Enumeration neutrality at the service boundary: the caller cannot tell whether the
        // address was already registered. begin() calls singleStore->resolve(), which in the
        // tenancy-disabled test state reads the default-tenant flag — establish it.
        $tenant = $this->seedTenant();
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)
            ->put('tenancy.default_tenant_uuid', $tenant);
        $this->createdEmails[] = 'already@example.test';
        $this->container()->get(\Glueful\Extensions\Users\Repositories\UserRepository::class)->create([
            'username' => 'already@example.test',
            'email' => 'already@example.test',
            'password' => password_hash('existing-secret', PASSWORD_BCRYPT),
            'status' => 'active',
        ]);
        $this->createdEmails[] = 'brandnew@example.test';

        // The email channel is registered-but-non-delivering in the test environment, so both
        // paths reach the mailer and fail delivery. The spec requires neutrality across known,
        // unknown AND mail-delivery-failure — so the security property under test here is that a
        // fresh email (verifier issue) and a taken email (existing-account notice) fail
        // IDENTICALLY: same exception, same status, same message. A caller cannot use the
        // response to probe whether an address is registered. (Successful-path shape neutrality
        // is exercised end to end at the HTTP boundary in Task 5's AccountFlowTest.)
        $fresh = $this->captureBeginException('brandnew@example.test');
        $taken = $this->captureBeginException('already@example.test');

        self::assertInstanceOf(SignupException::class, $fresh);
        self::assertInstanceOf(SignupException::class, $taken);
        self::assertSame($fresh->status, $taken->status);
        self::assertSame($fresh->getMessage(), $taken->getMessage());
    }

    private function captureBeginException(string $email): ?SignupException
    {
        try {
            $this->container()->get(CustomerSignupService::class)->begin(
                ['email' => $email, 'password' => 'sufficiently-long-secret', 'first_name' => 'X', 'last_name' => 'Y'],
                '203.0.113.10',
            );

            return null;
        } catch (SignupException $exception) {
            return $exception;
        }
    }

    /**
     * Two intents for the SAME address, both past EVERY duplicate read in
     * UserRepository::create(), both inserting. The database decides; the loser must land on
     * existing_account_handoff rather than a 500, and neither may leave authority rows behind.
     *
     * The barrier is a PostgreSQL BEFORE INSERT trigger, not a service-level hook. That placement
     * is load-bearing: UserRepository::create() repeats emailExists() and usernameExists() after
     * the activator's pre-check, so pausing above the repository would let the child observe the
     * parent's committed row and never exercise the unique constraint. Reaching the
     * pg_stat_activity advisory wait is the red/green authority that the child passed those reads.
     */
    public function testConcurrentActivationsForOneEmailCreateExactlyOneUser(): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL for a real unique-constraint race.');
        }

        [$a, $otpA] = $this->beginCustomerSignup('twice@example.test');
        [$b, $otpB] = $this->beginCustomerSignup('twice@example.test');

        $suffix = (string) getmypid();
        $applicationName = 'thallo_customer_race_' . $suffix;
        $function = 'thallo_test_pause_customer_insert_' . $suffix;
        $trigger = 'thallo_test_customer_insert_' . $suffix;
        $lockExpression = "hashtextextended('thallo:customer-email-race:{$suffix}', 0)";

        // A SECOND real connection owns the session-level advisory lock. The trigger blocks only
        // the child connection (identified by application_name), after UserRepository has issued
        // the INSERT and therefore after both of its duplicate reads.
        $control = $this->secondConnection()->getPDO();
        $child = null;
        $lockHeld = false;
        try {
            $control->exec(
                "CREATE FUNCTION {$function}() RETURNS trigger AS \$\$
                 BEGIN
                     IF current_setting('application_name', true) = '{$applicationName}'
                        AND NEW.email = 'twice@example.test' THEN
                         PERFORM pg_advisory_lock({$lockExpression});
                         PERFORM pg_advisory_unlock({$lockExpression});
                     END IF;
                     RETURN NEW;
                 END;
                 \$\$ LANGUAGE plpgsql"
            );
            $control->exec(
                "CREATE TRIGGER {$trigger} BEFORE INSERT ON users
                 FOR EACH ROW EXECUTE FUNCTION {$function}()"
            );
            $control->exec("SELECT pg_advisory_lock({$lockExpression})");
            $lockHeld = true;

            $child = $this->launchVerificationChild($b, $otpB, $applicationName);

            // Poll pg_stat_activity until the child is waiting inside the trigger. This is the
            // proof that it passed the activator check AND UserRepository's repeated
            // emailExists()/usernameExists() checks.
            $this->waitForAdvisoryLockWait($control, $applicationName, $child);

            // Parent inserts and commits while the child is parked immediately before its own
            // physical insert. Releasing the advisory lock then makes the child's INSERT hit the
            // database unique constraint deterministically.
            $first = $this->container()->get(SignupCoordinator::class)->verify($a, $otpA);
            $control->exec("SELECT pg_advisory_unlock({$lockExpression})");
            $lockHeld = false;
            $second = $this->collectVerificationChild($child);
            $child = null;
        } finally {
            if ($lockHeld) {
                $control->exec("SELECT pg_advisory_unlock({$lockExpression})");
            }
            if ($child !== null) {
                $this->terminateVerificationChild($child);
            }
            $control->exec("DROP TRIGGER IF EXISTS {$trigger} ON users");
            $control->exec("DROP FUNCTION IF EXISTS {$function}()");
        }

        // Exactly one user, whichever won.
        self::assertSame(1, $this->countUsersByEmail('twice@example.test'));

        // Both reached a deterministic outcome and no unique violation escaped as a 500.
        self::assertNull($second['exceptionClass'], (string) ($second['message'] ?? ''));
        $outcomes = [$first['status'], $second['status']];
        sort($outcomes);
        self::assertSame(['active', 'consumed'], $outcomes);
        self::assertSame('existing_account_handoff', $second['status'] === 'consumed'
            ? $second['outcome']
            : $first['outcome']);

        // And still no authority for either.
        $winnerUuid = $this->userUuidFor('twice@example.test');
        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) $this->connection()->table($table)->where($column, '=', $winnerUuid)->count(),
                "{$table} must hold no rows for a shopper"
            );
        }
    }

    private function secondConnection(): \Glueful\Database\Connection
    {
        return new \Glueful\Database\Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ]);
    }

    /** @return array{0: resource, 1: array<int,resource>} */
    private function launchVerificationChild(string $intentUuid, string $otp, string $applicationName): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/customer_signup_race_child.php',
                json_encode([
                    'intentUuid' => $intentUuid,
                    'otp' => $otp,
                    'applicationName' => $applicationName,
                ], JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $child
     */
    private function waitForAdvisoryLockWait(\PDO $control, string $applicationName, array $child): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            self::assertTrue(
                proc_get_status($child[0])['running'],
                'the child exited before reaching the advisory-lock wait — it never hit the insert boundary',
            );
            $stmt = $control->prepare(
                "SELECT count(*) FROM pg_stat_activity
                 WHERE application_name = :name AND wait_event_type = 'Lock' AND wait_event = 'advisory'"
            );
            $stmt->execute([':name' => $applicationName]);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }
            usleep(50_000);
        }
        self::fail('Timed out waiting for the child to block on the advisory lock.');
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $child
     * @return array<string,mixed>
     */
    private function collectVerificationChild(array $child): array
    {
        [$process, $pipes] = $child;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "child produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    /** @param array{0: resource, 1: array<int,resource>} $child */
    private function terminateVerificationChild(array $child): void
    {
        [$process, $pipes] = $child;
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}
