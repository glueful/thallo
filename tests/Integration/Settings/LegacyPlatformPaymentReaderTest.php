<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\LegacyPlatformPaymentSettingsReader;
use App\Settings\LegacyPlatformPaymentSettingsRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 3 (platform-payments-settings spec) — {@see LegacyPlatformPaymentSettingsReader}: the
 * TEMPORARY, read-only compatibility path over the OLD tenant `settings` table. Task 4's override
 * falls back to it until a migration marker is written; Task 5's migration command uses the
 * {@see LegacyPlatformPaymentSettingsRepository} it wraps for raw-row enumeration/verification/
 * pruning.
 *
 * Every test constructs the repository/reader against an ISOLATED temporary table created (and
 * dropped) through the real schema builder — the shared production `settings` table is never
 * touched here. Two schema eras are exercised:
 *  - PRE-RETROFIT ($preTable): `key, value, updated_at`, no `tenant_uuid` — one unscoped row.
 *  - POST-RETROFIT ($postTable): `tenant_uuid, key, value, updated_at`, composite key — one row
 *    per tenant per key; the candidate is whichever row belongs to the persisted default
 *    workspace ({@see SystemFlags::defaultTenantUuid()}).
 */
final class LegacyPlatformPaymentReaderTest extends AppTestCase
{
    private string $preTable = '';
    private string $postTable = '';

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->preTable = 'legacy_payvia_pre_' . $suffix;
        $this->postTable = 'legacy_payvia_post_' . $suffix;

        $schema = $this->connection()->getSchemaBuilder();
        $schema->createTable($this->preTable, function ($table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
        });
        $schema->createTable($this->postTable, function ($table) {
            $table->string('tenant_uuid', 191);
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->primary(['tenant_uuid', 'key']);
        });
    }

    protected function tearDown(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        try {
            $schema->dropTableIfExists($this->postTable);
        } finally {
            $schema->dropTableIfExists($this->preTable);
        }

        parent::tearDown();
    }

    // ---- test wiring ----------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function encryption(): EncryptionService
    {
        return $this->container()->get(EncryptionService::class);
    }

    private function repository(string $table): LegacyPlatformPaymentSettingsRepository
    {
        return new LegacyPlatformPaymentSettingsRepository($this->appContext(), $this->flags(), $table);
    }

    private function reader(string $table): LegacyPlatformPaymentSettingsReader
    {
        return new LegacyPlatformPaymentSettingsReader($this->repository($table), $this->encryption());
    }

    private function insertPre(string $key, string $value): void
    {
        $this->connection()->table($this->preTable)->insert([
            'key' => $key,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertPost(string $tenantUuid, string $key, string $value): void
    {
        $this->connection()->table($this->postTable)->insert([
            'tenant_uuid' => $tenantUuid,
            'key' => $key,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Seed a REAL row in the central `tenants` registry and return its uuid. Used ONLY to give the
     * REAL, container-bound {@see TenantContextRunner} (glueful/tenancy's always-on control-plane
     * provider — see config/serviceproviders.php) a tenant it can actually resolve and activate as
     * genuine ambient state via {@see \Glueful\Extensions\Tenancy\Bypass\Tenancy::runAsTenant()}.
     * `tenants` is a central table the base test harness does not truncate, so the caller must
     * clean up via {@see forgetRealTenant()}.
     */
    private function seedRealTenant(string $slug): string
    {
        $uuid = Utils::generateNanoID(12);
        Tenant::create($this->appContext(), [
            'uuid' => $uuid,
            'slug' => $slug,
            'name' => $slug,
            'status' => 'active',
        ]);

        return $uuid;
    }

    private function forgetRealTenant(string $slug): void
    {
        $this->connection()->table('tenants')->where(['slug' => $slug])->forceDelete();
    }

    // ---- schema-shape candidate selection --------------------------------------------------

    public function testPreRetrofitUnscopedRowIsTheCandidate(): void
    {
        $this->insertPre('payvia.default_gateway', 'stripe');

        $reader = $this->reader($this->preTable);

        self::assertSame('stripe', $reader->value('payvia.default_gateway'));
        $raw = $reader->raw('payvia.default_gateway');
        self::assertNotNull($raw);
        self::assertNull($raw['tenant_uuid']);
        self::assertSame('stripe', $raw['stored_value']);
        self::assertSame('stripe', $raw['decrypted_value']);
        self::assertTrue($raw['decryptable']);
    }

    public function testPostRetrofitDefaultWorkspaceRowIsTheCandidate(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $this->insertPost('tenant-a', 'payvia.default_gateway', 'stripe');
        $this->insertPost('tenant-b', 'payvia.default_gateway', 'paystack');

        $reader = $this->reader($this->postTable);

        self::assertSame('stripe', $reader->value('payvia.default_gateway'));
        $raw = $reader->raw('payvia.default_gateway');
        self::assertNotNull($raw);
        self::assertSame('tenant-a', $raw['tenant_uuid']);
        self::assertSame('stripe', $raw['stored_value']);
    }

    public function testOtherWorkspaceRowNeverReturnedByValueButEnumeratedByConflicts(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $this->insertPost('tenant-a', 'payvia.default_gateway', 'stripe');
        $this->insertPost('tenant-b', 'payvia.default_gateway', 'paystack');
        // A non-payvia key with its OWN cross-tenant conflict — conflicts() must be scoped to
        // `payvia.*` (spec §2's diagnostic is payments-only), so this must never surface here even
        // though, mechanically, it is exactly the same "other tenant's row" shape as the payvia one.
        $this->insertPost('tenant-a', 'theme.mode', 'dark');
        $this->insertPost('tenant-b', 'theme.mode', 'light');

        $reader = $this->reader($this->postTable);

        self::assertSame('stripe', $reader->value('payvia.default_gateway'));

        $conflicts = $reader->conflicts();
        self::assertArrayHasKey('payvia.default_gateway', $conflicts);
        $rows = $conflicts['payvia.default_gateway'];
        self::assertCount(1, $rows);
        self::assertSame('tenant-b', $rows[0]['tenant_uuid']);
        self::assertSame('payvia.default_gateway', $rows[0]['key']);
        // Sanitized: no value/stored_value/decrypted_value key ever leaves conflicts().
        self::assertSame(['tenant_uuid', 'key'], array_keys($rows[0]));

        // The non-payvia key's cross-tenant conflict must NOT appear at all.
        self::assertArrayNotHasKey('theme.mode', $conflicts);

        // The candidate's own (default-workspace) row must never show up as a conflict.
        foreach ($conflicts as $keyRows) {
            foreach ($keyRows as $row) {
                self::assertNotSame('tenant-a', $row['tenant_uuid']);
            }
        }
    }

    public function testNoDefaultPointerMeansCandidateIsNull(): void
    {
        // No default_tenant_uuid flag set at all.
        $this->insertPost('tenant-a', 'payvia.default_gateway', 'stripe');

        $reader = $this->reader($this->postTable);

        self::assertNull($reader->value('payvia.default_gateway'));
        self::assertNull($reader->raw('payvia.default_gateway'));
    }

    // ---- ambient tenant-context independence -----------------------------------------------

    /**
     * Proves the property for REAL, not by construction: {@see TenantContextRunner} is resolved
     * from the booted app container — glueful/tenancy's `TenancyControlPlaneProvider` is an
     * always-on entry in config/serviceproviders.php (no dev-link/opt-in gate needed), and it
     * binds the contract to `ContractTenantRunner`, which delegates to the real
     * `Bypass\Tenancy::runAsTenant()`. That call genuinely mutates ambient request state — it
     * stores the resolved {@see Tenant} on the SAME `ApplicationContext` instance
     * ({@see ApplicationContext::setRequestState()}, key `tenancy.tenant`) for the duration of the
     * callback — so if the reader/repository ever started consulting an ambient current-tenant
     * source instead of `SystemFlags::defaultTenantUuid()`, this test would observe it change.
     * `runAsTenant()` requires an ACTIVE tenant it can resolve by uuid/slug, so a real row is
     * seeded into the (untruncated) central `tenants` table for the duration of the test.
     *
     * Only the tenancy CONTROL PLANE is enabled here — not the full scoping/enforcement extension
     * (dev-link-gated elsewhere in this suite) — so no query-guard/insert-stamper hooks are armed;
     * the temp-table queries this test also makes are unaffected by the ambient tenant being set.
     */
    public function testResultIsIndependentOfAmbientTenantContext(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $this->insertPost('tenant-a', 'payvia.default_gateway', 'stripe');
        $this->insertPost('tenant-b', 'payvia.default_gateway', 'paystack');

        $reader = $this->reader($this->postTable);
        $direct = $reader->value('payvia.default_gateway');

        $slug = 'legacy-reader-ambient-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $ambientTenantUuid = $this->seedRealTenant($slug);
        try {
            $runner = $this->container()->get(TenantContextRunner::class);
            $wrapped = $runner->runAsTenant(
                $ambientTenantUuid,
                static fn (): ?string => $reader->value('payvia.default_gateway'),
            );
        } finally {
            $this->forgetRealTenant($slug);
        }

        self::assertSame('stripe', $direct);
        self::assertSame(
            $direct,
            $wrapped,
            'reading inside a REAL runAsTenant(otherWorkspace) must not change the result',
        );
    }

    // ---- undecryptable secrets --------------------------------------------------------------

    public function testUndecryptableSecretReadsNullButRawKeepsADecryptableFalseRow(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        $ciphertext = $this->encryption()->encrypt('sk_test_original', aad: $key);
        // Flip a byte in the MIDDLE of the envelope (never the tail): still looks encrypted (same
        // shape/prefix) but the auth tag no longer verifies, so decryption must fail closed. A
        // trailing-byte flip is unreliable here — base64's last character can carry unused
        // padding bits, so two different characters can decode to the identical byte and leave
        // the real ciphertext untouched.
        $mid = intdiv(strlen($ciphertext), 2);
        $replacement = $ciphertext[$mid] === 'A' ? 'B' : 'A';
        $tampered = substr($ciphertext, 0, $mid) . $replacement . substr($ciphertext, $mid + 1);
        $this->insertPre($key, $tampered);

        $reader = $this->reader($this->preTable);

        self::assertNull($reader->value($key));
        $raw = $reader->raw($key);
        self::assertNotNull($raw);
        self::assertFalse($raw['decryptable']);
        self::assertNull($raw['decrypted_value']);
        self::assertSame($tampered, $raw['stored_value']);
    }

    // ---- deleteExact(): compare-and-delete ---------------------------------------------------

    public function testDeleteExactDeletesThePreRetrofitRowWhenBytesStillMatch(): void
    {
        $key = 'payvia.default_gateway';
        $this->insertPre($key, 'stripe');

        $this->repository($this->preTable)->deleteExact($key, null, 'stripe');

        self::assertNull($this->connection()->table($this->preTable)->where(['key' => $key])->first());
    }

    public function testDeleteExactDeletesThePostRetrofitRowWhenBytesStillMatch(): void
    {
        $key = 'payvia.default_gateway';
        $this->insertPost('tenant-a', $key, 'stripe');

        $this->repository($this->postTable)->deleteExact($key, 'tenant-a', 'stripe');

        self::assertNull(
            $this->connection()->table($this->postTable)
                ->where(['tenant_uuid' => 'tenant-a', 'key' => $key])
                ->first(),
        );
    }

    public function testDeleteExactRefusesAMismatchedTenantLocatorAndLeavesTheRowIntact(): void
    {
        $key = 'payvia.default_gateway';
        $this->insertPost('tenant-a', $key, 'stripe');

        // Deliberately NOT catch(\Throwable): that would also swallow PHPUnit's own
        // AssertionFailedError from a self::fail() below, turning a real test failure into a
        // false pass. Only the exception types deleteExact() can actually throw are caught.
        $threw = false;
        try {
            // tenant-b has no such row: the compare-and-delete WHERE matches 0 rows.
            $this->repository($this->postTable)->deleteExact($key, 'tenant-b', 'stripe');
        } catch (\RuntimeException | \InvalidArgumentException) {
            $threw = true;
        }
        self::assertTrue($threw, 'expected a loud failure for a mismatched tenant locator');

        $row = $this->connection()->table($this->postTable)
            ->where(['tenant_uuid' => 'tenant-a', 'key' => $key])
            ->first();
        self::assertNotNull($row, 'the untouched row must survive a refused delete');
        self::assertSame('stripe', $row['value']);
    }

    public function testDeleteExactRefusesAConcurrentlyChangedValueAndLeavesTheRowIntact(): void
    {
        $key = 'payvia.default_gateway';
        $this->insertPost('tenant-a', $key, 'stripe');

        // Simulate a concurrent write between verification-read and delete: the stored bytes no
        // longer equal what the caller verified.
        $this->connection()->table($this->postTable)
            ->where(['tenant_uuid' => 'tenant-a', 'key' => $key])
            ->update(['value' => 'paystack']);

        $threw = false;
        try {
            $this->repository($this->postTable)->deleteExact($key, 'tenant-a', 'stripe');
        } catch (\RuntimeException | \InvalidArgumentException) {
            $threw = true;
        }
        self::assertTrue($threw, 'expected a loud failure for a concurrently changed value');

        $row = $this->connection()->table($this->postTable)
            ->where(['tenant_uuid' => 'tenant-a', 'key' => $key])
            ->first();
        self::assertNotNull($row, 'the changed row must survive a refused delete');
        self::assertSame('paystack', $row['value']);
    }

    public function testDeleteExactRejectsATenantLocatorAgainstAPreRetrofitTable(): void
    {
        $key = 'payvia.default_gateway';
        $this->insertPre($key, 'stripe');

        $threw = false;
        try {
            $this->repository($this->preTable)->deleteExact($key, 'tenant-a', 'stripe');
        } catch (\RuntimeException | \InvalidArgumentException) {
            $threw = true;
        }
        self::assertTrue($threw, 'expected a loud failure: pre-retrofit tables have no tenant_uuid column');

        self::assertNotNull($this->connection()->table($this->preTable)->where(['key' => $key])->first());
    }

    // ---- repository table-name safety --------------------------------------------------------

    public function testUnsafeTableIdentifierIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LegacyPlatformPaymentSettingsRepository($this->appContext(), $this->flags(), 'settings; DROP TABLE x');
    }
}
