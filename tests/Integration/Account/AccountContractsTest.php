<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\ConfigManager;
use Glueful\Security\OTP;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Account\AccountNavigationItem;
use Thallo\Contracts\Account\AccountNavigationRegistry;
use Thallo\Contracts\Account\RecoveryResult;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Contracts\Account\StorefrontAccountRegistration;

/**
 * The account contracts and their app glue. Recovery is exercised at the service level (no HTTP),
 * seeding the reset OTP into the same cache `EmailVerification` reads so the round-trip runs
 * without a live mail transport. The neutrality tests pin the guarantee the contract's shape
 * enforces: a storefront never becomes an account-existence oracle.
 */
final class AccountContractsTest extends AppTestCase
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
            $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
        }
        parent::tearDown();
    }

    private function recovery(): StorefrontAccountRecovery
    {
        return $this->container()->get(StorefrontAccountRecovery::class);
    }

    private function registration(): StorefrontAccountRegistration
    {
        return $this->container()->get(StorefrontAccountRegistration::class);
    }

    private function seedUser(string $email, string $password = 'old-password-value'): string
    {
        $this->createdEmails[] = $email;

        return $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'status' => 'active',
        ]);
    }

    /** Seed the reset OTP into the same cache EmailVerification reads, so verify() can mint a token. */
    private function seedResetOtp(string $email, string $otp = '123456'): void
    {
        // Mirrors EmailVerification's private key derivation: OTP_PREFIX . sanitizeEmailForCacheKey().
        // A change there breaks this loudly, which is the correct failure mode.
        $key = 'email_verification:' . str_replace(['/', '+', '='], ['_', '-', ''], base64_encode($email));
        $this->container()->get(CacheStore::class)->set(
            $key,
            ['otp' => OTP::hashOTP($otp), 'timestamp' => time()],
            900,
        );
    }

    private function passwordMatches(string $email, string $password): bool
    {
        $row = $this->connection()->table('users')->where('email', '=', $email)->first();

        return is_array($row) && password_verify($password, (string) $row['password']);
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

    /** @return array{string,string} [intentUuid, otp] */
    private function seedCustomerIntent(string $email): array
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
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $intentUuid,
            'otp_hash' => OTP::hashOTP('123456'),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [$intentUuid, '123456'];
    }

    public function testRecoveryIsNeutralForKnownAndUnknownEmails(): void
    {
        $this->seedUser('known@example.test');

        $known = $this->recovery()->begin('known@example.test', '203.0.113.10');
        $unknown = $this->recovery()->begin('nobody@example.test', '203.0.113.10');

        self::assertTrue($known->accepted);
        self::assertTrue($unknown->accepted);
        self::assertEquals($known, $unknown, 'the two results must be indistinguishable');
    }

    public function testRecoveryStaysNeutralWithGenericErrorResponsesDisabled(): void
    {
        // The users extension only returns a neutral body when this flag is on. The storefront
        // glue never consults it — it collapses every outcome regardless — so neutrality holds.
        ConfigManager::set('security.auth.generic_error_responses', false);
        $this->seedUser('known2@example.test');

        self::assertEquals(
            $this->recovery()->begin('known2@example.test', '203.0.113.10'),
            $this->recovery()->begin('nobody2@example.test', '203.0.113.10'),
        );
    }

    public function testRecoveryReportsAcceptedEvenWhenDeliveryFails(): void
    {
        // The test-env mail channel is registered but non-delivering, so a known-user begin()
        // genuinely exercises the delivery-failure path — which the glue swallows.
        $this->seedUser('known3@example.test');

        self::assertTrue($this->recovery()->begin('known3@example.test', '203.0.113.10')->accepted);
    }

    public function testTheRecoveryResultCannotExpressWhyItFailed(): void
    {
        // Structural: a future caller cannot start branching on a reason that does not exist.
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(RecoveryResult::class))->getProperties(),
        );

        self::assertSame(['accepted'], $properties);
    }

    public function testRecoveryRoundTripsFromOtpToANewPassword(): void
    {
        $this->seedUser('reset@example.test', 'old-password-value');
        $this->seedResetOtp('reset@example.test');

        $verification = $this->recovery()->verify('reset@example.test', '123456');
        self::assertTrue($verification->verified);
        self::assertNotNull($verification->resetToken);

        self::assertTrue($this->recovery()->complete($verification->resetToken, 'brand-new-password')->accepted);
        self::assertTrue($this->passwordMatches('reset@example.test', 'brand-new-password'));
        self::assertFalse($this->passwordMatches('reset@example.test', 'old-password-value'));
    }

    public function testAWrongRecoveryOtpVerifiesNothingAndYieldsNoToken(): void
    {
        $this->seedUser('badotp@example.test');
        $this->seedResetOtp('badotp@example.test', '111111');

        $verification = $this->recovery()->verify('badotp@example.test', '000000');

        self::assertFalse($verification->verified);
        self::assertNull($verification->resetToken);
    }

    public function testAnUnknownEmailAndAWrongCodeAreIndistinguishableAtVerification(): void
    {
        self::assertEquals(
            $this->recovery()->verify('nobody@example.test', '123456'),
            $this->recovery()->verify('alsonobody@example.test', '654321'),
        );
    }

    public function testAResetTokenIsSingleUse(): void
    {
        $this->seedUser('replay@example.test');
        $this->seedResetOtp('replay@example.test');
        $token = (string) $this->recovery()->verify('replay@example.test', '123456')->resetToken;

        self::assertTrue($this->recovery()->complete($token, 'first-new-password')->accepted);
        // A leaked link must not be usable twice.
        self::assertFalse($this->recovery()->complete($token, 'second-new-password')->accepted);
    }

    public function testCompletingARecoveryRevokesExistingSessions(): void
    {
        // Whoever forced the reset must lose the access the reset exists to revoke. Proven at the
        // seam: a successful complete() revokes ALL of the user's sessions, exactly once, with the
        // right uuid — asserted against a recording SessionStore rather than the live session
        // subsystem, so the test states the security guarantee without depending on that machinery.
        $userUuid = $this->seedUser('revoke@example.test');
        $this->seedResetOtp('revoke@example.test');

        $sessions = $this->createMock(SessionStoreInterface::class);
        $sessions->expects(self::once())->method('revokeAllForUser')->with($userUuid)->willReturn(true);

        $recovery = new \App\Account\AppStorefrontAccountRecovery(
            $this->appContext(),
            $this->container()->get(UserRepository::class),
            $sessions,
            $this->container()->get(LoggerInterface::class),
        );

        $token = (string) $recovery->verify('revoke@example.test', '123456')->resetToken;

        self::assertTrue($recovery->complete($token, 'brand-new-password')->accepted);
    }

    public function testRegistrationRoundTripsThroughTheContract(): void
    {
        // begin()'s live-mail happy path is environment-blocked; verify() is the contract's
        // meaningful mapping — an emailed OTP becomes a created identity.
        [$intentUuid, $otp] = $this->seedCustomerIntent('contract@example.test');

        $verified = $this->registration()->verify($intentUuid, $otp);

        self::assertFalse($verified->pendingVerification);
        self::assertNotNull($verified->userUuid);
        $user = $this->connection()->table('users')->where('email', '=', 'contract@example.test')->first();
        self::assertNotNull($user);
    }

    public function testTheNavigationRegistryOrdersByOrderAscending(): void
    {
        $registry = $this->container()->get(AccountNavigationRegistry::class);
        $registry->register(new AccountNavigationItem('c', 'C', '/account/c', 30, null));
        $registry->register(new AccountNavigationItem('a', 'A', '/account/a', 10, null));
        $registry->register(new AccountNavigationItem('b', 'B', '/account/b', 20, 'thallo.commerce'));

        self::assertSame(['a', 'b', 'c'], array_map(
            static fn (AccountNavigationItem $item): string => $item->id,
            $registry->items(),
        ));
    }

    public function testTheAccountPackDoesNotImportAppSignup(): void
    {
        // The module boundary, enforced rather than documented. Passes trivially until Task 5
        // creates the pack, and becomes meaningful then. RecursiveDirectoryIterator, not
        // glob('**/*.php') — PHP's glob does not recurse.
        $root = dirname(__DIR__, 3) . '/packages/thallo-account/src';
        if (!is_dir($root)) {
            self::assertTrue(true, 'the pack does not exist yet — boundary is vacuously satisfied');

            return;
        }

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (
                $file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'App\\Signup')
            ) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'thallo-account must consume contracts, not App\\Signup');
    }
}
