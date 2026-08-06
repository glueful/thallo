<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\PlatformPaymentSettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Encryption\EncryptionService;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 2 (platform-payments-settings spec §3) — {@see PlatformPaymentSettingsStore}: the app-owned
 * write/read surface over the unscoped {@see SystemChannel}, with SECRET subkeys (`secret_key`,
 * `webhook_secret`) encrypted at rest using the framework {@see EncryptionService} with
 * AAD = the full settings key string — the exact convention
 * {@see \Thallo\Commerce\Settings\SettingsStorePayviaOverride} already uses, ported here so
 * ciphertext written by that legacy path keeps decrypting once storage moves behind this store.
 *
 * Reads are null-never-throw (undecryptable/tampered/storage-throw ⇒ null).
 * `importEncryptedForMigration()` is the deliberately narrow migration door: it accepts ONLY a
 * recognized secret key, proves the given bytes are a real, decryptable ciphertext under that
 * key's AAD, and then writes those EXACT bytes verbatim (no re-encryption) — invalid input must
 * throw BEFORE anything is written.
 */
final class PlatformPaymentSettingsStoreTest extends AppTestCase
{
    private function encryption(): EncryptionService
    {
        return $this->container()->get(EncryptionService::class);
    }

    private function channel(): SystemChannel
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function store(): PlatformPaymentSettingsStore
    {
        return new PlatformPaymentSettingsStore($this->channel(), $this->encryption());
    }

    private function row(string $key): ?string
    {
        $row = $this->connection()->table('thallo_system_flags')->where(['key' => $key])->first();
        return $row === null ? null : (string) $row['value'];
    }

    // ---- putMany(): secrets encrypted, plain values stay plain -------------------------

    public function testSecretWriteStoresCiphertextAndReadRoundTrips(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        $plaintext = 'sk_test_veryserioussecret123';

        $this->store()->putMany([$key => $plaintext]);

        $stored = $this->row($key);
        self::assertIsString($stored);
        self::assertStringNotContainsString($plaintext, $stored, 'secret must be at rest as ciphertext');
        self::assertTrue($this->encryption()->isEncrypted($stored));

        self::assertSame($plaintext, $this->store()->get($key));
    }

    public function testNonSecretKeysStoredPlain(): void
    {
        $key = 'payvia.default_gateway';

        $this->store()->putMany([$key => 'stripe']);

        self::assertSame('stripe', $this->row($key), 'non-secret values must be stored plain, not ciphertext');
        self::assertSame('stripe', $this->store()->get($key));
    }

    // ---- forget() ------------------------------------------------------------------------

    public function testForgetDeletesTheRow(): void
    {
        $key = 'payvia.gateways.testgw.webhook_secret';
        $this->store()->putMany([$key => 'whsec_gone']);
        self::assertNotNull($this->row($key));

        $this->store()->forget($key);

        self::assertNull($this->row($key));
        self::assertNull($this->store()->get($key));
    }

    // ---- reads are null-never-throw ------------------------------------------------------

    public function testTamperedStoredCiphertextReadsAsNullNotThrow(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        $this->store()->putMany([$key => 'sk_test_original']);

        $stored = $this->row($key);
        self::assertIsString($stored);
        // Flip the trailing byte: still parses as an encrypted-looking value (same shape/prefix)
        // but the auth tag no longer matches, so decryption must fail closed.
        $tampered = substr($stored, 0, -1) . (str_ends_with($stored, 'A') ? 'B' : 'A');
        $this->channel()->put($key, $tampered);

        self::assertNull($this->store()->get($key));
    }

    public function testSystemChannelThrowOnGetReturnsNullNotThrow(): void
    {
        $throwing = new class implements SystemChannel {
            public function get(string $key): ?string
            {
                throw new \RuntimeException('storage unavailable');
            }

            public function put(string $key, string $value): void
            {
            }

            public function forget(string $key): void
            {
            }
        };

        $store = new PlatformPaymentSettingsStore($throwing, $this->encryption());

        self::assertNull($store->get('payvia.gateways.testgw.secret_key'));
        self::assertNull($store->get('payvia.default_gateway'));
    }

    // ---- AAD compatibility with the legacy commerce path + byte-exact migration import ---

    public function testLegacyCiphertextWithSameAadImportsAndDecryptsThroughTheStore(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        $plaintext = 'sk_legacy_roundtrip_456';

        // Simulate ciphertext exactly as SettingsStorePayviaOverride's write side would have
        // produced it: the REAL EncryptionService, AAD = the full settings key string. This is
        // not a stub — it is the same encrypt() call the legacy path makes.
        $legacyCiphertext = $this->encryption()->encrypt($plaintext, aad: $key);

        $this->store()->importEncryptedForMigration($key, $legacyCiphertext);

        // Byte-for-byte: the imported row must be the EXACT ciphertext, not a re-encryption.
        self::assertSame($legacyCiphertext, $this->row($key));
        self::assertSame($plaintext, $this->store()->get($key));
    }

    // ---- importEncryptedForMigration(): invalid input throws BEFORE writing anything -----

    public function testImportRejectsANonSecretKeyWithoutWriting(): void
    {
        $key = 'payvia.default_gateway';
        $ciphertext = $this->encryption()->encrypt('stripe', aad: $key);

        try {
            $this->store()->importEncryptedForMigration($key, $ciphertext);
            self::fail('expected an exception for a non-secret key');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->row($key), 'nothing may be written when the key is rejected');
    }

    public function testImportRejectsPlaintextWithoutWriting(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';

        try {
            $this->store()->importEncryptedForMigration($key, 'sk_plain_not_encrypted');
            self::fail('expected an exception for plaintext input');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->row($key));
    }

    public function testImportRejectsMalformedCiphertextWithoutWriting(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        $real = $this->encryption()->encrypt('sk_real', aad: $key);
        // Corrupt the envelope shape itself (truncate it) so isEncrypted() must reject it.
        $malformed = substr($real, 0, (int) (strlen($real) / 2));

        try {
            $this->store()->importEncryptedForMigration($key, $malformed);
            self::fail('expected an exception for malformed ciphertext');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->row($key));
    }

    public function testImportRejectsWrongAadCiphertextWithoutWriting(): void
    {
        $key = 'payvia.gateways.testgw.secret_key';
        // Well-formed ciphertext, but bound (via AAD) to a DIFFERENT key than the one it's
        // being imported under — the auth tag must fail to verify against $key's AAD.
        $wrongAadCiphertext = $this->encryption()
            ->encrypt('sk_bound_elsewhere', aad: 'payvia.gateways.other.secret_key');

        try {
            $this->store()->importEncryptedForMigration($key, $wrongAadCiphertext);
            self::fail('expected an exception for wrong-AAD ciphertext');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->row($key));
    }
}
