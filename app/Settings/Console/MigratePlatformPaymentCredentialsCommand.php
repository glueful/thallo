<?php

declare(strict_types=1);

namespace App\Settings\Console;

use App\Settings\LegacyPlatformPaymentSettingsReader;
use App\Settings\LegacyPlatformPaymentSettingsRepository;
use App\Settings\PlatformPaymentSettingsStore;
use App\Settings\PlatformPayviaSettingsOverride;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Platform-payments-settings spec §2 "Migration" (Task 5): the CONSERVATIVE, operator-run cutover
 * that moves this installation's Payvia gateway credentials off the old tenant `settings` table
 * and onto the unscoped platform system channel — then, as a SEPARATE explicit step, prunes the
 * legacy rows it has proven redundant.
 *
 * These rows are live money: a wrong adoption sends real revenue to the wrong merchant account and
 * a wrong deletion destroys the only copy of a webhook secret. Every rule below exists because the
 * cheap version of it is dangerous:
 *
 *  - PLATFORM VALUES ARE NEVER OVERWRITTEN. An existing platform value wins over the legacy
 *    candidate, full stop. When the two DISAGREE the legacy row is left physically in place with a
 *    diagnostic — "the operator already set the real value here, so the old row is obsolete" is a
 *    guess, and guessing costs a credential.
 *  - SECRETS ARE COPIED AS EXACT CIPHERTEXT BYTES, never re-encrypted: the AAD convention is
 *    identical on both sides (see {@see PlatformPaymentSettingsStore}), so the legacy envelope is
 *    still valid under the new key. {@see PlatformPaymentSettingsStore::importEncryptedForMigration()}
 *    proves the bytes decrypt under this key's AAD before writing them verbatim.
 *  - VERIFICATION OF AN ADOPTED VALUE IS TWO-PART, and both parts must be POSITIVELY satisfied:
 *    the platform store's decrypted value equals the legacy decrypted value, AND (secrets) the raw
 *    system-channel bytes are byte-identical to the legacy ciphertext. A missing or undecryptable
 *    side is a FAILURE — `null == null` is never "verified".
 *  - ANOTHER WORKSPACE'S `payvia.*` ROWS ARE NEVER A CREDENTIAL SOURCE. They are reported (key +
 *    tenant uuid only) and REFUSE completion. `--acknowledge-workspace-conflicts` is authority to
 *    DISCARD them, never to adopt them: their values are not read, compared, or copied anywhere —
 *    their stored bytes are touched only as the compare-and-delete token that proves the row being
 *    deleted is the row that was enumerated. There is deliberately no `--adopt-from` shortcut that
 *    would turn a workspace merchant into the platform merchant.
 *  - `--prune-legacy` IS A SECOND STEP, not a mode of the first: it re-enumerates every candidate,
 *    re-applies the verification above, and deletes through
 *    {@see LegacyPlatformPaymentSettingsRepository::deleteExact()} with the exact bytes it just
 *    read — so a row changed by anything else between verification and deletion affects 0 rows and
 *    aborts loudly instead of destroying the newer value.
 *  - THE COMPLETION MARKER IS WRITTEN LAST ({@see PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY}
 *    ⇒ `'1'`, straight to the {@see SystemChannel}), and only once every candidate key is accounted
 *    for and every conflict is absent or acknowledged. Until it lands, Task 4's override keeps
 *    serving the temporary legacy compatibility path — so a partial or failed run degrades to
 *    "still reading from the old table", never to "no credentials".
 *  - OUTPUT NAMES KEYS AND TENANT UUIDS ONLY. No stored value, decrypted value, ciphertext,
 *    fragment, or exception MESSAGE (which can carry bound SQL parameters) is ever printed —
 *    failures report the exception CLASS. A migration transcript ends up in CI logs and terminal
 *    scrollback; it must be safe there.
 *
 * Reruns are idempotent in both directions: after a PARTIAL run the already-copied ciphertext is
 * recognised as an existing platform value and left byte-for-byte alone, and after a COMPLETED run
 * the command is a no-op that still prunes verified legacy leftovers when asked.
 */
#[AsCommand(
    name: 'thallo:payments:migrate-platform-credentials',
    description: 'Migrate legacy payvia.* settings rows onto the unscoped platform system channel',
)]
final class MigratePlatformPaymentCredentialsCommand extends BaseCommand
{
    /** The only namespace this command reads, adopts, reports, or deletes. */
    private const PAYVIA_PREFIX = 'payvia.';

    /** Same secret SUBKEY-NAME set as the store/reader — these are the encrypted-at-rest keys. */
    private const SECRET_SUBKEYS = ['secret_key', 'webhook_secret'];

    /** The per-gateway editable subkeys, verbatim from Task 4's whitelist. */
    private const GATEWAY_SUBKEYS = ['enabled', 'secret_key', 'webhook_secret'];

    public function __construct(
        ContainerInterface $container,
        ApplicationContext $context,
        private readonly PlatformPaymentSettingsStore $platform,
        private readonly LegacyPlatformPaymentSettingsReader $legacy,
        private readonly LegacyPlatformPaymentSettingsRepository $repository,
        private readonly SystemChannel $system,
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                "Copies this installation's payvia.* gateway credentials from the legacy `settings`\n"
                . "table onto the unscoped platform system channel, verifies every copy, and writes the\n"
                . "completion marker last. Non-destructive by default.\n\n"
                . "  thallo:payments:migrate-platform-credentials\n"
                . "  thallo:payments:migrate-platform-credentials --acknowledge-workspace-conflicts\n"
                . "  thallo:payments:migrate-platform-credentials --prune-legacy\n\n"
                . "Existing platform values are never overwritten. Rows belonging to a workspace other\n"
                . "than the persisted default are never adopted: they refuse completion until you have\n"
                . "set the intended platform values yourself and explicitly acknowledged that those\n"
                . "workspace rows are obsolete. Values are never printed."
            )
            ->addOption(
                'prune-legacy',
                null,
                InputOption::VALUE_NONE,
                'Second step: delete legacy rows that re-verify against the platform value',
            )
            ->addOption(
                'acknowledge-workspace-conflicts',
                null,
                InputOption::VALUE_NONE,
                'Treat other-workspace payvia.* rows as obsolete (discard them; never adopt them)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prune = (bool) $input->getOption('prune-legacy');
        $acknowledge = (bool) $input->getOption('acknowledge-workspace-conflicts');

        $keys = $this->candidateKeys();
        $this->line('Platform payment credentials — candidate keys: ' . count($keys));

        $failures = 0;
        foreach ($keys as $key) {
            if (!$this->reconcile($key)) {
                $failures++;
            }
        }

        $conflicts = $this->reportConflicts($acknowledge);

        if ($failures > 0) {
            $this->line('');
            $this->error(sprintf(
                '%d key(s) could not be accounted for. Marker NOT written; legacy compatibility '
                . 'reads stay enabled and nothing was pruned.',
                $failures,
            ));

            return self::FAILURE;
        }

        if ($conflicts > 0 && !$acknowledge) {
            $this->line('');
            $this->error(sprintf(
                '%d payvia.* row(s) belong to another workspace and were NOT adopted. Set the '
                . 'intended platform values first, then re-run with --acknowledge-workspace-conflicts. '
                . 'Marker NOT written.',
                $conflicts,
            ));

            return self::FAILURE;
        }

        // LAST write of the run: every candidate key is accounted for and every conflict is absent
        // or acknowledged, so the compatibility path can be switched off. Pruning below deletes
        // legacy rows only — it never writes to the channel, so the marker stays the final write.
        $this->system->put(PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY, '1');
        $this->line('  marker     ' . PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY);

        if (!$prune) {
            $this->success(
                'Migration complete. Legacy rows were left in place; re-run with --prune-legacy to remove them.'
            );

            return self::SUCCESS;
        }

        $pruneFailures = $this->prunePass($acknowledge);
        if ($pruneFailures > 0) {
            $this->line('');
            $this->error(sprintf(
                '%d legacy row(s) could not be pruned safely and were left in place. The platform '
                . 'values themselves are migrated and marked; re-run --prune-legacy once the reported '
                . 'keys are resolved.',
                $pruneFailures,
            ));

            return self::FAILURE;
        }

        $this->success('Migration complete and legacy rows pruned.');

        return self::SUCCESS;
    }

    // ---- pass 1: adopt / preserve -------------------------------------------------------------

    /**
     * Account for ONE candidate key. Returns false only when the key genuinely cannot be resolved
     * — which is what withholds the completion marker from the whole run.
     */
    private function reconcile(string $key): bool
    {
        $legacyRow = $this->legacy->raw($key);
        $platformValue = $this->platform->get($key);
        $platformStored = $this->rawPlatformBytes($key);

        // A stored platform row whose value will not decrypt (rotated key, tampered bytes). It is
        // NOT "absent": overwriting it would destroy the only copy of whatever is in it, and the
        // legacy row is not automatically its replacement. Refuse and let an operator look.
        if ($platformValue === null && $platformStored !== null && trim($platformStored) !== '') {
            $this->line('  FAILED     ' . $key . ' (existing platform value is unreadable — refusing to overwrite)');

            return false;
        }

        if ($platformValue !== null && trim($platformValue) !== '') {
            $this->reportPreserved($key, $legacyRow, $platformValue);

            return true;
        }

        if ($legacyRow === null) {
            $this->line('  absent     ' . $key);

            return true;
        }

        if ($legacyRow['decryptable'] !== true || $legacyRow['decrypted_value'] === null) {
            $this->line('  FAILED     ' . $key . ' (legacy value is unreadable — nothing verifiable to adopt)');

            return false;
        }

        try {
            if (self::isSecretKey($key)) {
                // Verbatim ciphertext, proven to decrypt under this key's AAD before it is written.
                $this->platform->importEncryptedForMigration($key, $legacyRow['stored_value']);
            } else {
                $this->platform->putMany([$key => $legacyRow['decrypted_value']]);
            }
        } catch (\Throwable $e) {
            $this->line('  FAILED     ' . $key . ' (copy rejected: ' . self::reason($e) . ')');

            return false;
        }

        if (!$this->verifyCopy($key, $legacyRow)) {
            $this->line('  FAILED     ' . $key . ' (post-copy verification failed)');

            return false;
        }

        $this->line('  adopted    ' . $key . $this->tenantSuffix($legacyRow['tenant_uuid']));

        return true;
    }

    /**
     * @param array{key:string,tenant_uuid:?string,stored_value:string,decrypted_value:?string,
     *     decryptable:bool}|null $row
     */
    private function reportPreserved(string $key, ?array $row, string $platformValue): void
    {
        if ($row === null) {
            $this->line('  preserved  ' . $key);

            return;
        }

        if ($row['decryptable'] !== true || $row['decrypted_value'] === null) {
            $this->line('  preserved  ' . $key . ' (legacy row is unreadable and is left in place)');

            return;
        }

        if (hash_equals($row['decrypted_value'], $platformValue)) {
            $this->line('  preserved  ' . $key . ' (legacy row agrees; prunable)');

            return;
        }

        $this->line('  preserved  ' . $key . ' (legacy row DIFFERS — platform value wins and the row is kept)');
    }

    /**
     * The two-part check, both halves POSITIVE. Nothing here may pass because two reads were both
     * null: each side must produce a real value, they must be equal, and a secret's stored bytes
     * must be the legacy ciphertext byte-for-byte (proof that no re-encryption happened).
     *
     * @param array{key:string,tenant_uuid:?string,stored_value:string,decrypted_value:?string,decryptable:bool} $row
     */
    private function verifyCopy(string $key, array $row): bool
    {
        $legacyValue = $row['decrypted_value'];
        if ($legacyValue === null) {
            return false;
        }

        $platformValue = $this->platform->get($key);
        if ($platformValue === null || !hash_equals($legacyValue, $platformValue)) {
            return false;
        }

        $platformStored = $this->rawPlatformBytes($key);

        return $platformStored !== null && hash_equals($row['stored_value'], $platformStored);
    }

    // ---- pass 2: prune ------------------------------------------------------------------------

    /**
     * The separate second step. Every row is re-enumerated and re-verified HERE — the pass-1 result
     * is not trusted, because the marker write sits between them and anything could have moved. A
     * row is deleted only through compare-and-delete with the exact bytes just read, so a value
     * that changed in that window loses the race loudly instead of being destroyed.
     *
     * @return int number of rows that could not be pruned safely
     */
    private function prunePass(bool $acknowledge): int
    {
        $failures = 0;

        foreach ($this->candidateKeys() as $key) {
            $row = $this->legacy->raw($key);
            if ($row === null) {
                continue;
            }

            if ($row['decryptable'] !== true || $row['decrypted_value'] === null) {
                $this->line('  kept       ' . $key . ' (legacy row is unreadable — never deleted unverified)');
                continue;
            }

            $platformValue = $this->platform->get($key);
            $platformStored = $this->rawPlatformBytes($key);
            if ($platformValue === null || $platformStored === null) {
                $this->line('  FAILED     ' . $key . ' (platform value missing or unreadable at prune time)');
                $failures++;
                continue;
            }

            if (!hash_equals($row['decrypted_value'], $platformValue)) {
                $this->line(
                    '  kept       ' . $key . ' (platform value differs — the legacy row is not assumed obsolete)'
                );
                continue;
            }

            if (self::isSecretKey($key) && !hash_equals($row['stored_value'], $platformStored)) {
                $this->line(
                    '  kept       ' . $key . ' (ciphertext is not byte-identical — not the row that was adopted)'
                );
                continue;
            }

            $failures += $this->deleteRow($key, $row['tenant_uuid'], $row['stored_value'], 'pruned    ');
        }

        if ($acknowledge) {
            $failures += $this->discardConflictRows();
        }

        return $failures;
    }

    /**
     * Acknowledged other-workspace rows. Their stored bytes are used ONLY as the compare-and-delete
     * token — never decrypted, never compared to a platform value, never adopted.
     *
     * @return int number of rows that could not be discarded safely
     */
    private function discardConflictRows(): int
    {
        $failures = 0;
        foreach ($this->repository->conflictRowsForPrefix(self::PAYVIA_PREFIX) as $row) {
            $failures += $this->deleteRow($row['key'], $row['tenant_uuid'], $row['stored_value'], 'discarded ');
        }

        return $failures;
    }

    /** @return int 0 when the row was deleted, 1 when the compare-and-delete refused */
    private function deleteRow(string $key, ?string $tenantUuid, string $expectedStoredValue, string $label): int
    {
        try {
            $this->repository->deleteExact($key, $tenantUuid, $expectedStoredValue);
        } catch (\Throwable $e) {
            $this->line(
                '  FAILED     ' . $key . $this->tenantSuffix($tenantUuid)
                . ' (compare-and-delete refused: ' . self::reason($e) . ')'
            );

            return 1;
        }

        $this->line('  ' . $label . ' ' . $key . $this->tenantSuffix($tenantUuid));

        return 0;
    }

    // ---- diagnostics --------------------------------------------------------------------------

    /**
     * Sanitized conflict report from Task 3's reader — {tenant_uuid, key} only, SQL-scoped to the
     * `payvia.` prefix so unrelated multi-tenant settings can never be mistaken for a payments
     * conflict. Returns how many rows were reported.
     */
    private function reportConflicts(bool $acknowledge): int
    {
        $count = 0;
        foreach ($this->legacy->conflicts() as $rows) {
            foreach ($rows as $row) {
                $this->line(
                    '  ' . ($acknowledge ? 'discardable' : 'CONFLICT   ')
                    . ' ' . $row['key'] . $this->tenantSuffix($row['tenant_uuid'])
                );
                $count++;
            }
        }

        return $count;
    }

    private function tenantSuffix(?string $tenantUuid): string
    {
        return $tenantUuid === null ? '' : ' [workspace ' . $tenantUuid . ']';
    }

    /**
     * The exception CLASS, never its message: driver-level messages can echo bound SQL parameters,
     * which for these keys are credentials.
     */
    private static function reason(\Throwable $e): string
    {
        return $e::class;
    }

    // ---- key universe -------------------------------------------------------------------------

    /**
     * Task 4's whitelist, enumerated: `payvia.default_gateway` plus
     * `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for every id present in the
     * `payvia.gateways` CONFIG map. A migration can only account for keys the override would
     * actually serve, so this list and that whitelist must not drift apart.
     *
     * @return list<string>
     */
    private function candidateKeys(): array
    {
        $keys = ['payvia.default_gateway'];

        /** @var array<mixed,mixed> $configured */
        $configured = (array) config($this->context, 'payvia.gateways', []);
        foreach (array_keys($configured) as $id) {
            if (!is_string($id) || preg_match('/^[a-z0-9_-]+$/', $id) !== 1) {
                continue;
            }
            foreach (self::GATEWAY_SUBKEYS as $subkey) {
                $keys[] = 'payvia.gateways.' . $id . '.' . $subkey;
            }
        }

        return $keys;
    }

    /**
     * The bytes AS PERSISTED on the platform side (never the decrypted value) — the other half of
     * the two-part verification. Read straight from the channel, since the store deliberately
     * decrypts on the way out. Null-safe: a channel throwable is "no bytes", which fails
     * verification rather than aborting the run.
     */
    private function rawPlatformBytes(string $key): ?string
    {
        try {
            return $this->system->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Same secret SUBKEY-NAME rule as {@see PlatformPaymentSettingsStore}. */
    private static function isSecretKey(string $key): bool
    {
        $lastDot = strrpos($key, '.');
        $subkey = $lastDot === false ? $key : substr($key, $lastDot + 1);

        return in_array($subkey, self::SECRET_SUBKEYS, true);
    }
}
