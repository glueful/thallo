<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptReplay;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Shop\PackCheckoutAttemptAuthority;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Task 10 (storefront-rendering spec §7, CONCURRENCY CORE): TWO-CONNECTION
 * real-PostgreSQL races for {@see PackCheckoutAttemptAuthority}. Mirrors
 * `SlugLifecycleRaceTest`/`ProductLinkRaceTest`'s harness shape exactly
 * (`launchRaceChild()`/`collectRaceChild()` via `proc_open`, a SECOND real {@see Connection}
 * manually holding the SAME advisory lock the real authority would, `usleep(300_000)` to let
 * the child reach and block on the held lock before releasing) — the child fixture
 * ({@see __DIR__}/../../fixtures/checkout_attempt_race_child.php) boots the REAL Thallo
 * application so {@see \Glueful\Extensions\Commerce\Orders\CheckoutService} resolves its real
 * bound `CheckoutAttemptAuthority` ({@see PackCheckoutAttemptAuthority}) exactly as production
 * would.
 *
 * Two race shapes prove "simultaneous first use of one key -> one completed attempt/order and
 * one replay" in BOTH orderings (design spec §7 / task brief):
 *
 *  - {@see self::testManualConnectionWinsProducesOneCompletedAttemptAndOneReplay()}:
 *    a manually-controlled second connection (the only side this harness can deliberately pause
 *    mid-transaction — PHP has no threads, confirmed by Commerce's own
 *    `CheckoutAttemptTransactionVisibilityTest` docblock) holds the advisory lock open while a
 *    REAL subprocess blocks on it, then completes; the subprocess observes a clean replay. This
 *    is the deterministic, fully-controlled proof of the lock+re-read mechanism itself.
 *  - {@see self::testTwoRealSubprocessesProduceOneCompletedAttemptAndOneReplayRegardlessOfWinner()}:
 *    TWO real, wholly independent subprocesses race for the SAME key with NO parent-controlled
 *    winner — whichever one the OS schedules onto the lock first is nondeterministic, so every
 *    assertion is winner-agnostic (both racers must resolve to the identical order/credential,
 *    exactly one order must exist). This is what actually exercises "both orderings": which
 *    physical process wins varies run to run, and the invariant holds either way.
 *
 * Tenant is mode (b) (widened schema + a persisted default tenant), mirroring
 * `SlugLifecycleRaceTest`/`ProductLinkRaceTest`'s identical convention in this same directory.
 */
final class ShopCheckoutRaceTest extends AppTestCase
{
    private const TENANT = 'checkoutrace';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM thallo_commerce_checkout_attempts');
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_order_lines');
        $pdo->exec('DELETE FROM commerce_orders');
        $pdo->exec('DELETE FROM commerce_sequences');
        $pdo->exec('DELETE FROM commerce_cart_lines');
        $pdo->exec('DELETE FROM commerce_carts');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    // ==================================================================
    // Deterministic: a manually-held second connection wins, the real subprocess replays
    // ==================================================================

    public function testManualConnectionWinsProducesOneCompletedAttemptAndOneReplay(): void
    {
        $key = 'race-manual-key-' . (++self::$seq);
        $fingerprint = 'race-manual-fingerprint-' . self::$seq;
        $ctx = new CheckoutAttemptContext($key, $fingerprint);

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $replay = $this->manualClaim($connA, $ctx);
        self::assertNull($replay, 'connA must be the first claimant of a brand-new key');

        // The REAL subprocess places a genuinely different order (its own product/cart) with the
        // SAME idempotency key + fingerprint -- its own claimOrReplay() call blocks entirely on
        // connA's held advisory lock for this (tenant, key).
        $handle = $this->launchRaceChild([
            'sku' => 'race-manual-child-' . self::$seq,
            'price' => 1500,
            'email' => 'race-manual-child@example.com',
            'country' => 'US',
            'idempotencyKey' => $key,
            'fingerprint' => $fingerprint,
        ]);
        usleep(300_000);

        $orderUuid = Utils::generateNanoID();
        $orderRef = 'ORD-RACEM' . str_pad((string) self::$seq, 2, '0', STR_PAD_LEFT);
        $guestToken = TokenHasher::generate();
        $this->manualOrderInsert($connA, $orderUuid, $orderRef, $guestToken['hash'], 'race-manual-winner@example.com');
        $this->manualComplete($connA, $ctx, $orderUuid, $orderRef, $guestToken['raw']);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the blocked child must succeed via replay: ' . json_encode($result),
        );
        self::assertSame(
            $orderUuid,
            $result['orderUuid'],
            'the child must replay connA\'s order, never create its own',
        );
        self::assertSame($orderRef, $result['orderRef']);
        self::assertSame($guestToken['raw'], $result['guestToken'], 'the replay must re-deliver the SAME credential');

        self::assertSame(
            1,
            $this->connection()->table('commerce_orders')->count(),
            'the child must never have inserted a second order',
        );
        $attempt = $this->attemptRow($key);
        self::assertNotNull($attempt);
        self::assertSame('completed', $attempt['status']);
        self::assertSame($orderUuid, $attempt['order_uuid']);
        self::assertSame($orderRef, $attempt['order_ref']);
    }

    /** Same-key, DIFFERENT fingerprint through the same lock-and-re-read path -> 409, both orderings. */
    public function testConcurrentClaimWithADifferentFingerprintRejectsTheBlockedSideEitherOrdering(): void
    {
        $key = 'race-mismatch-key-' . (++self::$seq);
        $ctxA = new CheckoutAttemptContext($key, 'fingerprint-side-a-' . self::$seq);
        $ctxChild = new CheckoutAttemptContext($key, 'fingerprint-side-b-' . self::$seq);

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $replay = $this->manualClaim($connA, $ctxA);
        self::assertNull($replay);

        $handle = $this->launchRaceChild([
            'sku' => 'race-mismatch-child-' . self::$seq,
            'price' => 1500,
            'email' => 'race-mismatch-child@example.com',
            'country' => 'US',
            'idempotencyKey' => $key,
            'fingerprint' => $ctxChild->requestFingerprint,
        ]);
        usleep(300_000);

        $orderUuid = Utils::generateNanoID();
        $orderRef = 'ORD-RACEX' . str_pad((string) self::$seq, 2, '0', STR_PAD_LEFT);
        $guestToken = TokenHasher::generate();
        $this->manualOrderInsert(
            $connA,
            $orderUuid,
            $orderRef,
            $guestToken['hash'],
            'race-mismatch-winner@example.com',
        );
        $this->manualComplete($connA, $ctxA, $orderUuid, $orderRef, $guestToken['raw']);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a different fingerprint on the same key must be rejected: '
            . json_encode($result));
        self::assertSame(
            \Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException::class,
            $result['exceptionClass'],
        );

        self::assertSame(1, $this->connection()->table('commerce_orders')->count());
    }

    // ==================================================================
    // Two real, wholly independent subprocesses -- winner-agnostic invariant, both orderings
    // ==================================================================

    public function testTwoRealSubprocessesProduceOneCompletedAttemptAndOneReplayRegardlessOfWinner(): void
    {
        $key = 'race-dual-key-' . (++self::$seq);
        $fingerprint = 'race-dual-fingerprint-' . self::$seq;

        $handleA = $this->launchRaceChild([
            'sku' => 'race-dual-a-' . self::$seq,
            'price' => 1200,
            'email' => 'race-dual@example.com',
            'country' => 'US',
            'idempotencyKey' => $key,
            'fingerprint' => $fingerprint,
        ]);
        $handleB = $this->launchRaceChild([
            'sku' => 'race-dual-b-' . self::$seq,
            'price' => 1200,
            'email' => 'race-dual@example.com',
            'country' => 'US',
            'idempotencyKey' => $key,
            'fingerprint' => $fingerprint,
        ]);

        $resultA = $this->collectRaceChild($handleA);
        $resultB = $this->collectRaceChild($handleB);

        self::assertTrue($resultA['ok'] ?? false, 'child A must succeed: ' . json_encode($resultA));
        self::assertTrue($resultB['ok'] ?? false, 'child B must succeed: ' . json_encode($resultB));

        self::assertSame(
            $resultA['orderUuid'],
            $resultB['orderUuid'],
            'both simultaneous racers must resolve to the SAME order, whichever one physically won',
        );
        self::assertSame($resultA['orderRef'], $resultB['orderRef']);
        self::assertSame(
            $resultA['guestToken'],
            $resultB['guestToken'],
            'the replay must re-deliver the SAME credential the winner received',
        );

        self::assertSame(
            1,
            $this->connection()->table('commerce_orders')->count(),
            'exactly one order may exist after two simultaneous first uses of one key',
        );
        $attempt = $this->attemptRow($key);
        self::assertNotNull($attempt);
        self::assertSame('completed', $attempt['status']);
        self::assertSame($resultA['orderUuid'], $attempt['order_uuid']);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function attemptRow(string $key): ?array
    {
        return $this->connection()->table('thallo_commerce_checkout_attempts')
            ->where('idempotency_key', '=', $key)->first();
    }

    private function manualClaim(Connection $connection, CheckoutAttemptContext $ctx): ?CheckoutAttemptReplay
    {
        return $this->authorityOn($connection)->claimOrReplay($this->appContext(), self::TENANT, $ctx);
    }

    private function manualComplete(
        Connection $connection,
        CheckoutAttemptContext $ctx,
        string $orderUuid,
        string $orderRef,
        string $rawGuestToken,
    ): void {
        $this->authorityOn($connection)
            ->complete($this->appContext(), self::TENANT, $ctx, $orderUuid, $orderRef, $rawGuestToken);
    }

    private function authorityOn(Connection $connection): PackCheckoutAttemptAuthority
    {
        return new PackCheckoutAttemptAuthority($connection, $this->container()->get(EncryptionService::class));
    }

    /** Manually replicates the order row a real placeOrderAttempt() would have inserted. */
    private function manualOrderInsert(
        Connection $connection,
        string $orderUuid,
        string $orderRef,
        string $guestTokenHash,
        string $email,
    ): void {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => $orderRef,
            'status' => 'pending_payment',
            'email' => $email,
            'user_uuid' => null,
            'guest_token_hash' => $guestTokenHash,
            'currency' => 'USD',
            'subtotal' => 1500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1500,
            'discount_code' => null,
            'shipping_method' => null,
            'addresses' => json_encode(['shipping' => ['country' => 'US']], JSON_THROW_ON_ERROR),
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'marketplace_partitioned' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $args): array
    {
        $args += ['tenant' => self::TENANT];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/checkout_attempt_race_child.php',
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    private function secondConnection(): Connection
    {
        return new Connection([
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
}
