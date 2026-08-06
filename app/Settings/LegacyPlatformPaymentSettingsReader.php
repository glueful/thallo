<?php

declare(strict_types=1);

namespace App\Settings;

use Glueful\Encryption\EncryptionService;

/**
 * Platform-payments-settings spec (Task 3): a TEMPORARY, read-only facade over the OLD tenant
 * `settings` table via {@see LegacyPlatformPaymentSettingsRepository}. Used (a) as Task 4's
 * override's fallback for `payvia.*` reads until a migration marker is written, and (b) by
 * Task 5's migration command for enumeration/verification alongside the repository's
 * `deleteExact()` pruning.
 *
 * Recognizes the SAME secret subkey set (`secret_key`, `webhook_secret`) and the SAME
 * AAD convention (= the full settings key string) as
 * {@see \Thallo\Commerce\Settings\SettingsStorePayviaOverride} and
 * {@see PlatformPaymentSettingsStore} — ciphertext written by either of those decrypts through
 * this reader unchanged.
 *
 * NEVER calls `SettingsStore`, `runAsTenant()`, or any current-tenant helper: every read goes
 * straight through the repository's direct, schema-aware queries, so the result can never depend
 * on which tenant happens to be "current" for the request/process doing the read.
 *
 * `conflicts()` is a sanitized diagnostic surface ONLY — it reports which key/tenant pairs have a
 * row outside the resolved default-workspace candidate, but NEVER a value (stored or decrypted).
 */
final class LegacyPlatformPaymentSettingsReader
{
    private const SECRET_SUBKEYS = ['secret_key', 'webhook_secret'];

    public function __construct(
        private readonly LegacyPlatformPaymentSettingsRepository $repository,
        private readonly EncryptionService $encryption,
    ) {
    }

    /** The candidate row's value, decrypted when $key is a secret subkey — null on any miss/failure. */
    public function value(string $key): ?string
    {
        return $this->raw($key)['decrypted_value'] ?? null;
    }

    /**
     * The candidate row's full raw shape: the bytes as persisted, PLUS the decrypted plaintext
     * (secret subkeys only) and whether decryption actually succeeded. Non-secret keys are
     * trivially "decryptable" (decrypted_value === stored_value) — there was never a decryption
     * step that could fail.
     *
     * @return array{
     *     key:string,
     *     tenant_uuid:?string,
     *     stored_value:string,
     *     decrypted_value:?string,
     *     decryptable:bool
     * }|null
     */
    public function raw(string $key): ?array
    {
        $candidate = $this->repository->candidateRaw($key);
        if ($candidate === null) {
            return null;
        }

        $stored = $candidate['stored_value'];
        $tenantUuid = $candidate['tenant_uuid'];

        if (!$this->isSecretKey($key)) {
            return [
                'key' => $key,
                'tenant_uuid' => $tenantUuid,
                'stored_value' => $stored,
                'decrypted_value' => $stored,
                'decryptable' => true,
            ];
        }

        try {
            if (!$this->encryption->isEncrypted($stored)) {
                return $this->undecryptableRow($key, $tenantUuid, $stored);
            }

            return [
                'key' => $key,
                'tenant_uuid' => $tenantUuid,
                'stored_value' => $stored,
                'decrypted_value' => $this->encryption->decrypt($stored, aad: $key),
                'decryptable' => true,
            ];
        } catch (\Throwable) {
            return $this->undecryptableRow($key, $tenantUuid, $stored);
        }
    }

    /**
     * Sanitized cross-key diagnostic: for every distinct key physically present in the underlying
     * table, every row that is NOT the resolved candidate (an other-tenant row, or — with no
     * default pointer set — any row at all, since none is claimed). Only {tenant_uuid, key} ever
     * leaves this surface; stored/decrypted values never do.
     *
     * @return array<string,list<array{tenant_uuid:string,key:string}>>
     */
    public function conflicts(): array
    {
        $out = [];
        foreach ($this->repository->distinctKeys() as $key) {
            $rows = $this->repository->conflictRows($key);
            if ($rows === []) {
                continue;
            }

            $out[$key] = array_map(
                static fn (array $row): array => ['tenant_uuid' => $row['tenant_uuid'], 'key' => $row['key']],
                $rows,
            );
        }

        return $out;
    }

    /**
     * @return array{
     *     key:string,
     *     tenant_uuid:?string,
     *     stored_value:string,
     *     decrypted_value:?string,
     *     decryptable:bool
     * }
     */
    private function undecryptableRow(string $key, ?string $tenantUuid, string $stored): array
    {
        return [
            'key' => $key,
            'tenant_uuid' => $tenantUuid,
            'stored_value' => $stored,
            'decrypted_value' => null,
            'decryptable' => false,
        ];
    }

    /** Same secret SUBKEY-NAME set as {@see PlatformPaymentSettingsStore::isSecretKey()}. */
    private function isSecretKey(string $key): bool
    {
        $lastDot = strrpos($key, '.');
        $subkey = $lastDot === false ? $key : substr($key, $lastDot + 1);

        return in_array($subkey, self::SECRET_SUBKEYS, true);
    }
}
