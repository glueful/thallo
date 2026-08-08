<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\Console\MigratePlatformPaymentCredentialsCommand;
use App\Settings\LegacyPlatformPaymentSettingsReader;
use App\Settings\LegacyPlatformPaymentSettingsRepository;
use App\Settings\PlatformPaymentSettingsStore;
use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ScriptedSystemChannel;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 5 (platform-payments-settings spec §2 "Migration" + the §3 migration matrix) —
 * {@see MigratePlatformPaymentCredentialsCommand}, the CONSERVATIVE cutover from the old tenant
 * `settings` table to the unscoped platform system channel.
 *
 * The properties under test are the ones that make the migration safe to run against a live
 * installation's real money:
 *  - a platform value that already exists is NEVER overwritten, and a legacy row that disagrees
 *    with it is left in place with a diagnostic instead of being guessed obsolete;
 *  - a secret is copied as EXACT CIPHERTEXT BYTES (AAD unchanged, never re-encrypted) and verified
 *    TWO ways before anything is deleted — the platform store's decrypted value must equal the
 *    legacy decrypted value AND the raw system-channel bytes must be byte-identical to the legacy
 *    ciphertext. A missing/undecryptable side is a FAILURE, never `null == null`;
 *  - another workspace's `payvia.*` rows are reported and REFUSE completion; acknowledging them is
 *    authority to DISCARD them, never to adopt them (their values are never read or compared);
 *  - `--prune-legacy` is a separate second step that re-enumerates, re-verifies and compare-and-
 *    deletes with the exact bytes it just verified, so a concurrent change aborts the delete;
 *  - the completion marker is the LAST write and only happens on a fully-accounted run, so any
 *    failure leaves compatibility reads live;
 *  - the output names KEYS and TENANT UUIDS only — never a value, plaintext or ciphertext.
 *
 * Every test drives the REAL command through {@see CommandTester} against an ISOLATED temporary
 * legacy table (created/dropped by the real schema builder, both schema eras) and an in-memory
 * {@see SystemChannel}: the shared `settings` table is never altered, and the channel doubles are
 * what let a copy be corrupted or a row be changed mid-run.
 */
final class PlatformPaymentMigrationTest extends AppTestCase
{
    private const MARKER = PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY;
    private const SECRET_KEY = 'payvia.gateways.paystack.secret_key';
    private const WEBHOOK_KEY = 'payvia.gateways.paystack.webhook_secret';
    private const GATEWAY_KEY = 'payvia.default_gateway';
    private const ENABLED_KEY = 'payvia.gateways.paystack.enabled';

    private string $preTable = '';
    private string $postTable = '';

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->preTable = 'migrate_payvia_pre_' . $suffix;
        $this->postTable = 'migrate_payvia_post_' . $suffix;

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

    // ---- wiring ----------------------------------------------------------------------------

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

    /**
     * The REAL command, wired to an isolated legacy table and an injectable system channel.
     * Production autowires exactly these collaborators; only their targets differ here.
     */
    private function command(string $table, SystemChannel $channel): MigratePlatformPaymentCredentialsCommand
    {
        $repository = $this->repository($table);

        return new MigratePlatformPaymentCredentialsCommand(
            $this->container(),
            $this->appContext(),
            new PlatformPaymentSettingsStore($channel, $this->encryption()),
            new LegacyPlatformPaymentSettingsReader($repository, $this->encryption()),
            $repository,
            $channel,
        );
    }

    /**
     * @param array<string,bool> $options
     * @return array{int,string}
     */
    private function runCommand(string $table, SystemChannel $channel, array $options = []): array
    {
        $tester = new CommandTester($this->command($table, $channel));
        $status = $tester->execute($options, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
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

    /** @return array<string,mixed>|null */
    private function postRow(string $tenantUuid, string $key): ?array
    {
        return $this->connection()->table($this->postTable)
            ->where(['tenant_uuid' => $tenantUuid, 'key' => $key])
            ->first();
    }

    /** @return array<string,mixed>|null */
    private function preRow(string $key): ?array
    {
        return $this->connection()->table($this->preTable)->where(['key' => $key])->first();
    }

    private function cipher(string $key, string $plaintext): string
    {
        return $this->encryption()->encrypt($plaintext, aad: $key);
    }

    /**
     * Flip a byte in the MIDDLE of the envelope (never the tail): still ciphertext-SHAPED, but the
     * auth tag no longer verifies. A trailing-byte flip is unreliable — base64's last character
     * carries unused padding bits, so two characters can decode to the identical byte.
     */
    private function tamper(string $ciphertext): string
    {
        $mid = intdiv(strlen($ciphertext), 2);
        $replacement = $ciphertext[$mid] === 'A' ? 'B' : 'A';

        return substr($ciphertext, 0, $mid) . $replacement . substr($ciphertext, $mid + 1);
    }

    // ---- matrix: adoption / preservation ----------------------------------------------------

    public function testPlatformValueIsPreservedAndTheDefaultLegacyRowIsNeverAdopted(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $channel = new ScriptedSystemChannel();
        $platformCipher = $this->cipher(self::SECRET_KEY, 'sk_platform_kept');
        $channel->seed(self::SECRET_KEY, $platformCipher);
        $this->insertPost('tenant-a', self::SECRET_KEY, $this->cipher(self::SECRET_KEY, 'sk_platform_kept'));

        [$status, $output] = $this->runCommand($this->postTable, $channel);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame($platformCipher, $channel->get(self::SECRET_KEY), 'platform bytes must be untouched');
        self::assertSame(self::MARKER, $channel->putOrder[count($channel->putOrder) - 1]);
        // Non-destructive by default: nothing was pruned without --prune-legacy.
        self::assertNotNull($this->postRow('tenant-a', self::SECRET_KEY));
    }

    public function testPreRetrofitUnscopedRowsAreAdoptedWithByteIdenticalCiphertext(): void
    {
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_live_pre_retrofit');
        $this->insertPre(self::SECRET_KEY, $legacyCipher);
        $this->insertPre(self::GATEWAY_KEY, 'paystack');
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->preTable, $channel);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame($legacyCipher, $channel->get(self::SECRET_KEY), 'ciphertext must be copied VERBATIM');
        self::assertSame('paystack', $channel->get(self::GATEWAY_KEY));
        $store = new PlatformPaymentSettingsStore($channel, $this->encryption());
        self::assertSame('sk_live_pre_retrofit', $store->get(self::SECRET_KEY));
        self::assertSame('1', $channel->get(self::MARKER));
    }

    public function testPostRetrofitDefaultWorkspaceRowsAreAdopted(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $legacyCipher = $this->cipher(self::WEBHOOK_KEY, 'whsec_default_workspace');
        $this->insertPost('tenant-a', self::WEBHOOK_KEY, $legacyCipher);
        $this->insertPost('tenant-a', self::ENABLED_KEY, '1');
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->postTable, $channel);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame($legacyCipher, $channel->get(self::WEBHOOK_KEY));
        self::assertSame('1', $channel->get(self::ENABLED_KEY));
        self::assertSame('1', $channel->get(self::MARKER));
    }

    /**
     * The schema era comes from the DATABASE (the repository's hasColumn() introspection), never
     * from tenancy state: a persisted default-workspace pointer is set here, yet the PRE-RETROFIT
     * table's unscoped row is still the candidate and there are no "other workspace" conflicts.
     */
    public function testSchemaShapeIsDetectedFromTheDatabaseNotTheTenancyPointer(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $this->insertPre(self::GATEWAY_KEY, 'paystack');
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->preTable, $channel);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame('paystack', $channel->get(self::GATEWAY_KEY));
        self::assertSame('1', $channel->get(self::MARKER));
    }

    public function testPreExistingPlatformValueDifferingFromLegacyKeepsItsRowThroughPrune(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $channel = new ScriptedSystemChannel();
        $platformCipher = $this->cipher(self::SECRET_KEY, 'sk_platform_authoritative');
        $channel->seed(self::SECRET_KEY, $platformCipher);
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_legacy_different');
        $this->insertPost('tenant-a', self::SECRET_KEY, $legacyCipher);

        [$status, $output] = $this->runCommand($this->postTable, $channel, ['--prune-legacy' => true]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame($platformCipher, $channel->get(self::SECRET_KEY));
        $row = $this->postRow('tenant-a', self::SECRET_KEY);
        self::assertNotNull($row, 'a legacy row that disagrees with the platform value is never guessed obsolete');
        self::assertSame($legacyCipher, (string) $row['value']);
        self::assertStringContainsString(self::SECRET_KEY, $output);
        self::assertSame('1', $channel->get(self::MARKER));
    }

    // ---- matrix: cross-workspace conflicts ---------------------------------------------------

    public function testOtherWorkspaceConflictIsReportedAndRefusesCompletion(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $this->insertPost('tenant-a', self::GATEWAY_KEY, 'paystack');
        $this->insertPost('tenant-b', self::GATEWAY_KEY, 'stripe');
        $this->insertPost('tenant-b', self::SECRET_KEY, $this->cipher(self::SECRET_KEY, 'sk_other_workspace'));
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->postTable, $channel);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($channel->get(self::MARKER), 'a refused run must leave compatibility reads live');
        self::assertStringContainsString('tenant-b', $output);
        self::assertStringContainsString(self::SECRET_KEY, $output);
        // NEVER adopted: the other workspace's secret is not a platform credential.
        self::assertNull($channel->get(self::SECRET_KEY));
        self::assertNotNull($this->postRow('tenant-b', self::GATEWAY_KEY));
    }

    public function testAcknowledgedConflictsCompleteWithoutAdoptionAndPruneRemovesTheExactRows(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $otherCipher = $this->cipher(self::SECRET_KEY, 'sk_other_workspace');
        $this->insertPost('tenant-b', self::GATEWAY_KEY, 'stripe');
        $this->insertPost('tenant-b', self::SECRET_KEY, $otherCipher);
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->postTable, $channel, [
            '--acknowledge-workspace-conflicts' => true,
            '--prune-legacy' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertSame('1', $channel->get(self::MARKER));
        // Acknowledgement is authority to DISCARD, never to adopt.
        self::assertNull($channel->get(self::SECRET_KEY));
        self::assertNull($channel->get(self::GATEWAY_KEY));
        self::assertNull($this->postRow('tenant-b', self::SECRET_KEY));
        self::assertNull($this->postRow('tenant-b', self::GATEWAY_KEY));
        self::assertStringContainsString('tenant-b', $output);
    }

    // ---- matrix: verification failures --------------------------------------------------------

    public function testCorruptedLegacySourceFailsVerificationAndLeavesTheMarkerAbsent(): void
    {
        $this->insertPre(self::SECRET_KEY, $this->tamper($this->cipher(self::SECRET_KEY, 'sk_unreadable')));
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->preTable, $channel, ['--prune-legacy' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($channel->get(self::MARKER));
        self::assertNull($channel->get(self::SECRET_KEY), 'an unverifiable source is never copied');
        self::assertNotNull($this->preRow(self::SECRET_KEY), 'nothing is pruned on a failed run');
        self::assertStringContainsString(self::SECRET_KEY, $output);

        // Marker absent ⇒ the temporary compatibility path is still live, so a deployment that hit
        // this failure keeps processing payments from the legacy table.
        $this->insertPre(self::GATEWAY_KEY, 'paystack');
        $override = new PlatformPayviaSettingsOverride(
            new PlatformPaymentSettingsStore($channel, $this->encryption()),
            $channel,
            new LegacyPlatformPaymentSettingsReader($this->repository($this->preTable), $this->encryption()),
        );
        self::assertSame('paystack', $override->value($this->appContext(), self::GATEWAY_KEY));
    }

    public function testTamperedPlatformCopyFailsVerificationAndAbortsPrune(): void
    {
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_live_copy_corrupted');
        $this->insertPre(self::SECRET_KEY, $legacyCipher);
        $channel = new ScriptedSystemChannel();
        // The copy lands corrupted: the command wrote valid ciphertext, what is at rest is not.
        $channel->tamperOnPut(self::SECRET_KEY, fn(string $v): string => $this->tamper($v));

        [$status, $output] = $this->runCommand($this->preTable, $channel, ['--prune-legacy' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($channel->get(self::MARKER));
        self::assertNotNull($this->preRow(self::SECRET_KEY), 'an unverified copy must never prune its source');
        self::assertStringContainsString(self::SECRET_KEY, $output);
    }

    /**
     * The SECOND half of the two-part verification, isolated: here the copy that lands is a
     * perfectly valid ciphertext of the SAME plaintext — decrypted equality holds — but it is not
     * the legacy envelope. That is a re-encryption, which the spec forbids (the migration must move
     * bytes, not rewrite them), and byte comparison is the only thing that can see it.
     */
    public function testACopyThatWasReEncryptedRatherThanCopiedVerbatimFailsVerification(): void
    {
        $this->insertPre(self::SECRET_KEY, $this->cipher(self::SECRET_KEY, 'sk_live_verbatim_only'));
        $channel = new ScriptedSystemChannel();
        $channel->tamperOnPut(
            self::SECRET_KEY,
            fn(): string => $this->cipher(self::SECRET_KEY, 'sk_live_verbatim_only'),
        );

        [$status, $output] = $this->runCommand($this->preTable, $channel, ['--prune-legacy' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($channel->get(self::MARKER));
        self::assertNotNull($this->preRow(self::SECRET_KEY), 'a re-encrypted copy must never prune its source');
        self::assertStringContainsString(self::SECRET_KEY, $output);
    }

    public function testLegacyRowChangedAfterVerificationMakesCompareAndDeleteFailAndTheRowRemains(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_live_concurrent');
        $this->insertPost('tenant-a', self::SECRET_KEY, $legacyCipher);
        $channel = new ScriptedSystemChannel();
        // The marker is a successful run's LAST write, so arming on it puts this hook inside the
        // PRUNE pass: the row changes between the bytes the pruner verified and the delete it
        // issues from them.
        $rewritten = $this->cipher(self::SECRET_KEY, 'sk_live_rotated_mid_prune');
        $channel->fireOnFirstGetAfterPut(self::MARKER, self::SECRET_KEY, function () use ($rewritten): void {
            $this->connection()->table($this->postTable)
                ->where(['tenant_uuid' => 'tenant-a', 'key' => self::SECRET_KEY])
                ->update(['value' => $rewritten]);
        });

        [$status, $output] = $this->runCommand($this->postTable, $channel, ['--prune-legacy' => true]);

        self::assertSame(Command::FAILURE, $status);
        $row = $this->postRow('tenant-a', self::SECRET_KEY);
        self::assertNotNull($row, 'compare-and-delete must lose the race, not delete blindly');
        self::assertSame($rewritten, (string) $row['value']);
        self::assertStringContainsString(self::SECRET_KEY, $output);
    }

    // ---- matrix: marker ordering + idempotency ------------------------------------------------

    public function testMarkerIsTheLastWriteOfASuccessfulRun(): void
    {
        $this->insertPre(self::SECRET_KEY, $this->cipher(self::SECRET_KEY, 'sk_live_ordering'));
        $this->insertPre(self::GATEWAY_KEY, 'paystack');
        $channel = new ScriptedSystemChannel();

        [$status, $output] = $this->runCommand($this->preTable, $channel);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertNotSame([], $channel->putOrder);
        self::assertSame(self::MARKER, $channel->putOrder[count($channel->putOrder) - 1]);
        self::assertSame(1, count(array_filter($channel->putOrder, fn($k): bool => $k === self::MARKER)));
    }

    public function testPartialRerunIsIdempotent(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_live_partial');
        $this->insertPost('tenant-a', self::SECRET_KEY, $legacyCipher);
        $this->insertPost('tenant-b', self::GATEWAY_KEY, 'stripe');
        $channel = new ScriptedSystemChannel();

        // Run 1 refuses (unacknowledged conflict) AFTER adopting the default-workspace key.
        [$first] = $this->runCommand($this->postTable, $channel);
        self::assertSame(Command::FAILURE, $first);
        self::assertNull($channel->get(self::MARKER));
        self::assertSame($legacyCipher, $channel->get(self::SECRET_KEY));

        // Run 2 completes. The already-adopted ciphertext must be byte-identical — never rewritten.
        $ack = ['--acknowledge-workspace-conflicts' => true];
        [$second, $output] = $this->runCommand($this->postTable, $channel, $ack);
        self::assertSame(Command::SUCCESS, $second, $output);
        self::assertSame($legacyCipher, $channel->get(self::SECRET_KEY));
        self::assertSame('1', $channel->get(self::MARKER));
    }

    public function testCompletedRerunIsIdempotentAndPruneStillHandlesVerifiedLeftovers(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $legacyCipher = $this->cipher(self::SECRET_KEY, 'sk_live_leftover');
        $this->insertPost('tenant-a', self::SECRET_KEY, $legacyCipher);
        $this->insertPost('tenant-a', self::GATEWAY_KEY, 'paystack');
        $channel = new ScriptedSystemChannel();

        [$first, $firstOut] = $this->runCommand($this->postTable, $channel);
        self::assertSame(Command::SUCCESS, $first, $firstOut);
        self::assertSame('1', $channel->get(self::MARKER));
        self::assertNotNull($this->postRow('tenant-a', self::SECRET_KEY), 'default runs never delete');

        [$second, $secondOut] = $this->runCommand($this->postTable, $channel, ['--prune-legacy' => true]);
        self::assertSame(Command::SUCCESS, $second, $secondOut);
        self::assertSame($legacyCipher, $channel->get(self::SECRET_KEY), 'a rerun re-encrypts nothing');
        self::assertNull($this->postRow('tenant-a', self::SECRET_KEY));
        self::assertNull($this->postRow('tenant-a', self::GATEWAY_KEY));

        // A third run with everything pruned is still a clean, marked no-op.
        [$third, $thirdOut] = $this->runCommand($this->postTable, $channel, ['--prune-legacy' => true]);
        self::assertSame(Command::SUCCESS, $third, $thirdOut);
        self::assertSame('1', $channel->get(self::MARKER));
        self::assertSame($legacyCipher, $channel->get(self::SECRET_KEY));
    }

    // ---- console registration -----------------------------------------------------------------

    /**
     * The command is only useful if an operator can actually run it: it must resolve from the
     * booted container (autowired through its explicit constructor, which the BaseCommand-shaped
     * commands around it do not have) and carry the name the runbook will type.
     */
    public function testTheCommandIsRegisteredAndAutowirableFromTheContainer(): void
    {
        $command = $this->container()->get(MigratePlatformPaymentCredentialsCommand::class);

        self::assertInstanceOf(MigratePlatformPaymentCredentialsCommand::class, $command);
        self::assertSame('thallo:payments:migrate-platform-credentials', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('prune-legacy'));
        self::assertTrue($command->getDefinition()->hasOption('acknowledge-workspace-conflicts'));
    }

    // ---- matrix: the output must never carry secret material ----------------------------------

    public function testOutputNeverContainsSecretMaterial(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenant-a');
        $plaintexts = [
            'sk_live_adopted_material',
            'whsec_adopted_material',
            'sk_live_platform_material',
            'sk_live_other_workspace_material',
            'whsec_unreadable_material',
            'whsec_platform_material',
        ];
        $adopted = $this->cipher(self::SECRET_KEY, $plaintexts[0]);
        $webhook = $this->cipher(self::WEBHOOK_KEY, $plaintexts[1]);
        $platform = $this->cipher('payvia.gateways.stripe.secret_key', $plaintexts[2]);
        $other = $this->cipher(self::SECRET_KEY, $plaintexts[3]);
        $unreadable = $this->tamper($this->cipher('payvia.gateways.stripe.webhook_secret', $plaintexts[4]));
        $platformWebhook = $this->cipher('payvia.gateways.stripe.webhook_secret', $plaintexts[5]);

        $this->insertPost('tenant-a', self::SECRET_KEY, $adopted);
        $this->insertPost('tenant-a', self::WEBHOOK_KEY, $webhook);
        $this->insertPost('tenant-a', self::GATEWAY_KEY, 'paystack');
        // An undecryptable legacy row shadowed by a live platform value: a diagnostic, not a
        // failure — and its bytes must not reach the output either.
        $this->insertPost('tenant-a', 'payvia.gateways.stripe.webhook_secret', $unreadable);
        $this->insertPost('tenant-b', self::SECRET_KEY, $other);
        $channel = new ScriptedSystemChannel();
        $channel->seed('payvia.gateways.stripe.secret_key', $platform);
        $channel->seed('payvia.gateways.stripe.webhook_secret', $platformWebhook);

        // Every mode: the refusal path, the acknowledged path, and the pruning path.
        [, $refused] = $this->runCommand($this->postTable, $channel);
        $ack = ['--acknowledge-workspace-conflicts' => true];
        [, $acknowledged] = $this->runCommand($this->postTable, $channel, $ack);
        [, $pruned] = $this->runCommand($this->postTable, $channel, [
            '--acknowledge-workspace-conflicts' => true,
            '--prune-legacy' => true,
        ]);
        $display = $refused . "\n" . $acknowledged . "\n" . $pruned;

        $forbidden = array_merge(
            $plaintexts,
            [$adopted, $webhook, $platform, $other, $unreadable, $platformWebhook],
        );
        foreach ($forbidden as $secret) {
            self::assertDoesNotMatchRegularExpression(
                '/' . preg_quote($secret, '/') . '/',
                $display,
                'command output must never carry secret material',
            );
            // Also sweep for any substantial FRAGMENT of a ciphertext (a truncated/wrapped leak).
            foreach (str_split($secret, 24) as $chunk) {
                if (strlen($chunk) < 24) {
                    continue;
                }
                self::assertStringNotContainsString($chunk, $display, 'no ciphertext fragment may leak');
            }
        }

        // What it MUST print: key names and tenant uuids.
        self::assertStringContainsString(self::SECRET_KEY, $display);
        self::assertStringContainsString('tenant-b', $display);
    }
}
