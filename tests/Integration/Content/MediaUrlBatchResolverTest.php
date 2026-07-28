<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineMediaUrlResolver;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\CountingPdoStatement;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;

/**
 * Storefront-v1 Task 3: the batched anonymous media URL seam. `urls()` resolves
 * ≤100 blob uuids through the SAME fail-closed servability predicate as
 * `url()` in ONE query (unservable uuids OMITTED, never null-filled), input is
 * deduped to first occurrences then capped at the first 100 distinct, and
 * `url()` delegates to `urls()` so the predicate cannot drift — pinned here
 * structurally via byte-equality across servable/unservable fixtures.
 */
final class MediaUrlBatchResolverTest extends AppTestCase
{
    private function seedBlob(string $uuid, string $visibility = 'public', string $status = 'active'): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'batch-test-' . $uuid,
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.jpg',
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function resolver(bool $enabled = true, mixed $access = 'upload_only'): EngineMediaUrlResolver
    {
        return new EngineMediaUrlResolver($this->connection(), '/api/v1/blobs', $enabled, $access);
    }

    protected function tearDown(): void
    {
        // The one-query guard installs a counting statement class on the SHARED
        // suite PDO — restore the default so no other test measures through it.
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $this->connection()->table('blobs')->where('name', 'LIKE', 'batch-test-%')->forceDelete();
        parent::tearDown();
    }

    // === Parity with url(): same string in, same omissions out ==================

    public function testServableBlobResolvesToTheSameUrlStringAndUnservablesAreOmittedByBoth(): void
    {
        $this->seedBlob('batchpub0001');
        $this->seedBlob('batchpriv001', visibility: 'private');
        $this->seedBlob('batchdele001', status: 'deleted');

        $resolver = $this->resolver();
        $urls = $resolver->urls(['batchpub0001', 'batchpriv001', 'batchdele001', 'batchmiss001']);

        self::assertSame(['batchpub0001' => '/api/v1/blobs/batchpub0001'], $urls);
        self::assertSame($resolver->url('batchpub0001'), $urls['batchpub0001']);
        foreach (['batchpriv001', 'batchdele001', 'batchmiss001'] as $unservable) {
            self::assertNull($resolver->url($unservable), $unservable);
            self::assertArrayNotHasKey($unservable, $urls, $unservable);
        }
    }

    // === Delegation drift guard: url() ≡ urls([uuid])[uuid] ?? null =============

    public function testUrlAndSingleUuidBatchAreByteEqualAcrossAllFixtures(): void
    {
        $this->seedBlob('batchdelgpub');
        $this->seedBlob('batchdelgprv', visibility: 'private');
        $this->seedBlob('batchdelgdel', status: 'deleted');

        $resolver = $this->resolver();
        foreach (['batchdelgpub', 'batchdelgprv', 'batchdelgdel', 'batchdelgmis'] as $uuid) {
            self::assertSame($resolver->url($uuid), $resolver->urls([$uuid])[$uuid] ?? null, $uuid);
        }

        // Gate-closed resolvers refuse in lockstep: uploads disabled, then every
        // auth-gated access mode (incl. the default install) — url() null AND
        // the batch map empty, before any row could match.
        $gated = [$this->resolver(enabled: false)];
        foreach (['private', true, 'true', 1] as $mode) {
            $gated[] = $this->resolver(access: $mode);
        }
        foreach ($gated as $i => $closed) {
            self::assertNull($closed->url('batchdelgpub'), "gate {$i}");
            self::assertSame([], $closed->urls(['batchdelgpub']), "gate {$i}");
        }
    }

    // === Empty input =============================================================

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->resolver()->urls([]));
    }

    // === Dedupe + first-100 cap ==================================================

    public function testDedupeAppliesBeforeTheCap(): void
    {
        $this->seedBlob('batchdupa001');
        $this->seedBlob('batchdupb001');

        // 100 raw occurrences of A then B: dedupe-first leaves [A, B], both
        // inside the cap — a cap-first implementation would drop B.
        $input = array_fill(0, 100, 'batchdupa001');
        $input[] = 'batchdupb001';

        self::assertSame(
            [
                'batchdupa001' => '/api/v1/blobs/batchdupa001',
                'batchdupb001' => '/api/v1/blobs/batchdupb001',
            ],
            $this->resolver()->urls($input)
        );
    }

    public function testOnlyTheFirstHundredDistinctUuidsResolve(): void
    {
        $this->seedBlob('batchcaplast');
        $this->seedBlob('batchcapover');

        // 99 well-formed fillers, then a servable blob at distinct position 100
        // (kept) and another at 101 (ignored — FIRST 100 after dedupe resolve).
        $input = [];
        for ($i = 1; $i <= 99; $i++) {
            $input[] = sprintf('batchfil%04d', $i);
        }
        $input[] = 'batchcaplast';
        $input[] = 'batchcapover';

        $urls = $this->resolver()->urls($input);
        self::assertArrayHasKey('batchcaplast', $urls);
        self::assertArrayNotHasKey('batchcapover', $urls, 'distinct value 101 must be dropped by the cap');
    }

    // === One-query guard =========================================================

    public function testAHundredUuidCallResolvesInOneQueryWithCorrectOmissions(): void
    {
        $this->seedBlob('batchone0001');
        $this->seedBlob('batchone0050');
        $this->seedBlob('batchone0100');
        $this->seedBlob('batchone0010', visibility: 'private');
        $this->seedBlob('batchone0020', status: 'deleted');

        $input = [];
        for ($i = 1; $i <= 100; $i++) {
            $input[] = sprintf('batchone%04d', $i);
        }
        $resolver = $this->resolver();

        // Warm-up (unmeasured): the framework's soft-delete handler runs a
        // one-time process-cached schema probe on first touch of a table.
        $resolver->urls(['batchone0001']);

        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        $before = CountingPdoStatement::$count;

        $urls = $resolver->urls($input);

        self::assertSame(1, CountingPdoStatement::$count - $before, 'expected exactly one batched query');
        self::assertSame(['batchone0001', 'batchone0050', 'batchone0100'], array_keys($urls));
        self::assertSame('/api/v1/blobs/batchone0050', $urls['batchone0050']);
    }

    // === Provider binding: one object, two interfaces ============================

    public function testBothDeliveryInterfacesResolveToTheSameInstance(): void
    {
        $single = $this->container()->get(MediaUrlResolver::class);
        $batch = $this->container()->get(MediaUrlBatchResolver::class);

        self::assertInstanceOf(MediaUrlBatchResolver::class, $single);
        self::assertSame($single, $batch, 'the provider must bind both interfaces to ONE instance');
    }
}
