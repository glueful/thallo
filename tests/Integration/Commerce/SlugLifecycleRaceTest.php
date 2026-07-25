<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Shop\PackSlugLifecycleAuthority;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-2 Task 8: {@see PackSlugLifecycleAuthority} — the pack's implementation of
 * Commerce's `SlugLifecycleAuthority` seam (storefront-rendering spec §4) — plus the slug
 * ledger's single-connection functional contract and its old-slug 301 redirect through
 * {@see \Thallo\Commerce\Http\Shop\ShopCatalogController::product()}.
 *
 * Two-connection PostgreSQL races mirror `ProductLinkRaceTest`'s harness shape exactly
 * (`launchRaceChild()`/`collectRaceChild()` via `proc_open`, a SECOND real {@see Connection}
 * manually holding the SAME advisory locks the real authority would, `usleep(300_000)` to let
 * the child reach and block on the held lock before releasing) — the child fixture
 * ({@see __DIR__}/../../fixtures/product_slug_race_child.php) boots the REAL Thallo application
 * so {@see CatalogService} resolves its real bound `SlugLifecycleAuthority`
 * ({@see PackSlugLifecycleAuthority}) exactly as production would.
 *
 * WHY these two race shapes (design spec §4's "current/history cross-table race", "neither
 * unique constraint is claimed to do that alone"):
 *
 *  - create-vs-rename, both claiming the SAME currently-FREE slug: Commerce's own pre-lock
 *    precheck (`findIncludingDeletedBySlug`) is unavoidably racy on its own — both sides can
 *    pass it before either commits, since neither the target slug NOR a ledger reservation for
 *    it exists yet. The advisory lock serializes the two `prepareCreate()`/`prepareRename()`
 *    calls; the LOSER's re-read (this class's `rejectIfLiveElsewhere()`) then reliably observes
 *    the winner's now-committed row and rejects with a clean {@see ValidationException} —
 *    never a raw unique-constraint `\PDOException` bubbling out of the eventual product write.
 *  - rename-vs-rename, two DIFFERENT products both claiming the SAME currently-free slug: the
 *    identical race, proving the same current/current arbitration holds regardless of whether
 *    the competing claim is a create or another rename, and that the loser's rename is a clean
 *    no-op (old slug never touched, no partial ledger write — "a serialized, consistent
 *    ledger").
 *
 * A literal two-way slug SWAP (A: x->y racing B: y->x) is not modeled here: Commerce's own
 * pre-lock precheck for EACH side's target slug reads the OTHER product's CURRENTLY-COMMITTED
 * row, so a genuine swap can never complete as two independent transactions regardless of
 * locking — at least one side's own precheck (run before this class is ever invoked) rejects
 * it outright. That is expected, pre-existing Commerce behavior, not this task's surface.
 *
 * Tenant is mode (b) (widened schema + a persisted default tenant), mirroring
 * `ProductLinkRaceTest`/`ShopCatalogTest`'s identical convention in this same directory.
 */
final class SlugLifecycleRaceTest extends AppTestCase
{
    private const TENANT = 'slugracetnt1';

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
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    // ==================================================================
    // Single-connection functional contract (storefront-rendering spec §4)
    // ==================================================================

    public function testRenameReservesTheOldSlugInTheLedger(): void
    {
        $product = $this->seedProduct('reserve-old');

        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'reserve-new']);

        self::assertSame($product, $this->ledgerProductUuid('reserve-old'));
        self::assertNull($this->ledgerProductUuid('reserve-new'), 'the NEW slug must never itself be reserved');
    }

    public function testCreateOntoAReservedSlugIsRejected(): void
    {
        $product = $this->seedProduct('create-reserve-old');
        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'create-reserve-new']);
        self::assertSame($product, $this->ledgerProductUuid('create-reserve-old'));

        try {
            $this->seedProduct('create-reserve-old');
            self::fail('Expected create onto a reserved slug to be rejected.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('slug'));
        }
        // The reservation must be untouched by the rejected attempt.
        self::assertSame($product, $this->ledgerProductUuid('create-reserve-old'));
    }

    public function testRenameOntoAReservedSlugIsRejected(): void
    {
        $owner = $this->seedProduct('rename-reserve-old');
        $this->catalog()->updateProduct($this->appContext(), $owner, ['slug' => 'rename-reserve-new']);
        self::assertSame($owner, $this->ledgerProductUuid('rename-reserve-old'));

        $other = $this->seedProduct('rename-reserve-other');
        try {
            $this->catalog()->updateProduct($this->appContext(), $other, ['slug' => 'rename-reserve-old']);
            self::fail('Expected rename onto a reserved slug to be rejected.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('slug'));
        }
        // $other must be untouched -- still at its original slug.
        $row = $this->connection()->table('commerce_products')->where('uuid', '=', $other)->first();
        self::assertSame('rename-reserve-other', $row['slug']);
        self::assertSame($owner, $this->ledgerProductUuid('rename-reserve-old'));
    }

    public function testABARoundTripCleansUpTheStaleLedgerRow(): void
    {
        $product = $this->seedProduct('aba-shoes');

        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'aba-boots']);
        self::assertSame($product, $this->ledgerProductUuid('aba-shoes'));

        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'aba-shoes']);

        // The A -> B -> A round trip must leave NO stale reservation for the slug the product
        // owns again outright, and must reserve the intermediate slug instead.
        self::assertNull($this->ledgerProductUuid('aba-shoes'));
        self::assertSame($product, $this->ledgerProductUuid('aba-boots'));

        $row = $this->connection()->table('commerce_products')->where('uuid', '=', $product)->first();
        self::assertSame('aba-shoes', $row['slug']);
    }

    public function testOldSlugUrlRedirectsToCanonicalWhichServes200(): void
    {
        $this->seedProduct('redirect-old');
        $product = $this->productUuidBySlugDirect('redirect-old');
        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'redirect-new']);

        $first = $this->handle(Request::create('/shop/products/redirect-old', 'GET'));
        self::assertSame(301, $first->getStatusCode());
        $expected = $this->container()->get(ShopUrlGenerator::class)->product('redirect-new');
        self::assertSame($expected, $first->headers->get('Location'));

        $second = $this->handle(Request::create((string) $first->headers->get('Location'), 'GET'));
        self::assertSame(200, $second->getStatusCode());
    }

    public function testOldSlugPointingAtATombstonedProductIsNonRevealing404NotARedirect(): void
    {
        $this->seedProduct('redirect-tombstone-old');
        $product = $this->productUuidBySlugDirect('redirect-tombstone-old');
        $this->catalog()->updateProduct($this->appContext(), $product, ['slug' => 'redirect-tombstone-new']);
        $this->catalog()->deleteProduct($this->appContext(), $product);

        $response = $this->handle(Request::create('/shop/products/redirect-tombstone-old', 'GET'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testLedgerRowMatchingALiveProductsCurrentSlugLosesToTheLiveProduct(): void
    {
        // A data anomaly the current-slug-first ordering must defend against regardless of
        // HOW it arose (storefront-rendering spec §4's loop-safety clause): a ledger row whose
        // slug value collides with a DIFFERENT product's live current slug. Seeded directly —
        // bypassing the authority entirely — so this proves the CONTROLLER's own resolution
        // order, not just that the authority never produces this state in practice.
        $liveOwner = $this->seedProduct('collide-slug');
        $otherProduct = $this->seedProduct('collide-decoy');
        $this->connection()->table('thallo_commerce_product_slugs')->insert([
            'tenant_uuid' => self::TENANT,
            'slug' => 'collide-slug',
            'product_uuid' => $otherProduct,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $response = $this->handle(Request::create('/shop/products/collide-slug', 'GET'));

        self::assertSame(200, $response->getStatusCode(), 'the live product must win outright, never a 301');
        $expected = $this->container()->get(ShopUrlGenerator::class)->product('collide-slug');
        self::assertStringContainsString('rel="canonical" href="' . $expected . '"', (string) $response->getContent());
    }

    // ==================================================================
    // TWO-CONNECTION races: create vs rename, same currently-free slug
    // ==================================================================

    public function testConcurrentCreateAndRenameClaimingTheSameFreeSlugOrderingCreateWins(): void
    {
        $slug = $this->uniqueSlug('race-cxr-target');
        $renamer = $this->seedProduct($this->uniqueSlug('race-cxr-renamer-old'));
        $oldSlug = $this->currentSlug($renamer);

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $newUuid = Utils::generateNanoID();
        $this->manualCreate($connA, $newUuid, $slug);

        // The REAL subprocess renames an EXISTING different product onto the same free slug —
        // its own lock claim (sharing the target slug key) blocks entirely on connection A.
        $handle = $this->launchRaceChild('rename', ['productUuid' => $renamer, 'slug' => $slug]);
        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'the rename must lose once the slug is live elsewhere: '
            . json_encode($result));
        self::assertSame(ValidationException::class, $result['exceptionClass']);

        // Winner: the manually-created product now live-owns the target slug.
        $row = $this->connection()->table('commerce_products')->where('uuid', '=', $newUuid)->first();
        self::assertNotNull($row);
        self::assertSame($slug, $row['slug']);
        // Loser: the renamer's row and slug are untouched -- no partial write.
        $renamerRow = $this->connection()->table('commerce_products')->where('uuid', '=', $renamer)->first();
        self::assertSame($oldSlug, $renamerRow['slug']);
        self::assertNull($this->ledgerProductUuid($oldSlug), 'a rejected rename must never reserve its old slug');
    }

    public function testConcurrentCreateAndRenameClaimingTheSameFreeSlugOrderingRenameWins(): void
    {
        $slug = $this->uniqueSlug('race-rxc-target');
        $renamer = $this->seedProduct($this->uniqueSlug('race-rxc-renamer-old'));
        $oldSlug = $this->currentSlug($renamer);

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $this->manualRename($connA, $renamer, $oldSlug, $slug);

        // The REAL subprocess tries to CREATE a brand-new product at the same free slug -- its
        // own lock claim blocks entirely on connection A's held lock.
        $handle = $this->launchRaceChild('create', ['slug' => $slug]);
        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'the create must lose once the slug is live elsewhere: '
            . json_encode($result));
        self::assertSame(ValidationException::class, $result['exceptionClass']);

        // Winner: the renamer now live-owns the target slug, and its OLD slug is reserved.
        $renamerRow = $this->connection()->table('commerce_products')->where('uuid', '=', $renamer)->first();
        self::assertSame($slug, $renamerRow['slug']);
        self::assertSame($renamer, $this->ledgerProductUuid($oldSlug));
        // Loser: no product row was ever created at the contested slug.
        $rows = $this->connection()->table('commerce_products')
            ->where('tenant_uuid', '=', self::TENANT)->where('slug', '=', $slug)->get();
        self::assertCount(1, $rows, 'exactly one product may own the contested slug after the race');
    }

    // ==================================================================
    // TWO-CONNECTION races: rename vs rename, same currently-free slug
    // ==================================================================

    public function testConcurrentRenamesClaimingTheSameFreeSlugOrderingAWins(): void
    {
        $this->runConcurrentRenameRace(aWinsFirst: true);
    }

    public function testConcurrentRenamesClaimingTheSameFreeSlugOrderingBWins(): void
    {
        $this->runConcurrentRenameRace(aWinsFirst: false);
    }

    private function runConcurrentRenameRace(bool $aWinsFirst): void
    {
        $slug = $this->uniqueSlug('race-rxr-target');
        $productA = $this->seedProduct($this->uniqueSlug('race-rxr-a-old'));
        $productB = $this->seedProduct($this->uniqueSlug('race-rxr-b-old'));
        $oldA = $this->currentSlug($productA);
        $oldB = $this->currentSlug($productB);
        [$winner, $winnerOld, $loser, $loserOld] = $aWinsFirst
            ? [$productA, $oldA, $productB, $oldB]
            : [$productB, $oldB, $productA, $oldA];

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $this->manualRename($connA, $winner, $winnerOld, $slug);

        // The REAL subprocess renames the OTHER product onto the same free slug -- blocks
        // entirely on connection A's held lock for the shared target-slug key.
        $handle = $this->launchRaceChild('rename', ['productUuid' => $loser, 'slug' => $slug]);
        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'the loser must be rejected: ' . json_encode($result));
        self::assertSame(ValidationException::class, $result['exceptionClass']);

        // Consistent ledger: exactly the winner's old slug is reserved; the loser's old slug
        // was NEVER reserved (its rename was rejected before any ledger write).
        self::assertSame($winner, $this->ledgerProductUuid($winnerOld));
        self::assertNull($this->ledgerProductUuid($loserOld));
        $rows = $this->connection()->table('thallo_commerce_product_slugs')
            ->where('tenant_uuid', '=', self::TENANT)->get();
        self::assertCount(1, $rows, 'exactly one ledger row must exist after the race');

        $winnerRow = $this->connection()->table('commerce_products')->where('uuid', '=', $winner)->first();
        self::assertSame($slug, $winnerRow['slug']);
        $loserRow = $this->connection()->table('commerce_products')->where('uuid', '=', $loser)->first();
        self::assertSame($loserOld, $loserRow['slug'], 'the loser must be untouched -- still at its old slug');
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function catalog(): CatalogService
    {
        return $this->container()->get(CatalogService::class);
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . (++self::$seq);
    }

    private function seedProduct(string $slug): string
    {
        $product = $this->catalog()->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);

        return (string) $product['uuid'];
    }

    private function currentSlug(string $productUuid): string
    {
        $row = $this->connection()->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertNotNull($row);

        return (string) $row['slug'];
    }

    private function productUuidBySlugDirect(string $slug): string
    {
        $row = $this->connection()->table('commerce_products')
            ->where('tenant_uuid', '=', self::TENANT)->where('slug', '=', $slug)->first();
        self::assertNotNull($row);

        return (string) $row['uuid'];
    }

    private function ledgerProductUuid(string $slug): ?string
    {
        $row = $this->connection()->table('thallo_commerce_product_slugs')
            ->where('tenant_uuid', '=', self::TENANT)->where('slug', '=', $slug)->first();

        return $row === null ? null : (string) $row['product_uuid'];
    }

    /** Manually replicates a CREATE's critical section on a caller-controlled connection. */
    private function manualCreate(Connection $connection, string $productUuid, string $slug): void
    {
        (new PackSlugLifecycleAuthority($connection))
            ->prepareCreate($this->appContext(), self::TENANT, $productUuid, $slug);
        $connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => self::TENANT,
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
        ]);
    }

    /** Manually replicates a RENAME's critical section on a caller-controlled connection. */
    private function manualRename(Connection $connection, string $productUuid, string $old, string $new): void
    {
        (new PackSlugLifecycleAuthority($connection))
            ->prepareRename($this->appContext(), self::TENANT, $productUuid, $old, $new);
        $connection->table('commerce_products')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('uuid', '=', $productUuid)
            ->update(['slug' => $new]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(string $action, array $args): array
    {
        $args += ['tenant' => self::TENANT];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/product_slug_race_child.php',
                $action,
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
