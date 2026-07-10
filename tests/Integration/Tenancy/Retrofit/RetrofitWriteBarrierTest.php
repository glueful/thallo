<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

final class RetrofitWriteBarrierTest extends RetrofitHarnessTestCase
{
    private function guard(): RetrofitMaintenanceGuard
    {
        return $this->container()->get(RetrofitMaintenanceGuard::class);
    }

    protected function setUp(): void
    {
        // Lower any barrier a PRIOR test left up BEFORE parent::setUp() truncates owned tables
        // (content_types/settings/regions) — those DELETEs would otherwise trip the interceptor.
        self::$engineApp?->getContainer()->get(RetrofitMaintenanceGuard::class)->end();
        parent::setUp();
        $this->guard()->end();
        // seed a row while the barrier is DOWN
        $this->connection()->getPDO()->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version, created_at)
             VALUES ('ctbar0000001', 's', 'S', 'active', '[]', 1, now()) ON CONFLICT (uuid) DO NOTHING"
        );
    }

    public function testUpdateToOwnedTableBlockedWhileActive(): void
    {
        $this->guard()->begin();
        $this->expectException(RetrofitInProgressException::class);
        // UPDATE is NOT an insert-hook path — the interceptor is what catches it.
        $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->update(['name' => 'Z']);
    }

    public function testDeleteToOwnedTableBlockedWhileActive(): void
    {
        $this->guard()->begin();
        $this->expectException(RetrofitInProgressException::class);
        $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->delete();
    }

    public function testNonOwnedTableAndSelectUnaffected(): void
    {
        $this->guard()->begin();
        $this->connection()->table('tenants')->insert([
            'uuid' => 'tnbar0000001', 'slug' => 'b', 'name' => 'B', 'status' => 'active',
        ]);
        // SELECT passes
        self::assertNotNull(
            $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->first()
        );
    }

    public function testActiveIsProcessLocalAndDoesNotRecurse(): void
    {
        // begin() clears the SystemFlags cache; the NEXT query would, under a DB-reading active(),
        // re-enter the interceptor infinitely. A plain SELECT after begin() must complete (no recursion).
        $this->guard()->begin();
        $this->connection()->getPDO()->exec("SELECT 1"); // raw: sanity
        self::assertNotNull(
            $this->connection()->table('content_types')->where(['uuid' => 'ctbar0000001'])->first()
        );
    }

    public function testCoarseGateSeesFreshPersistedBeginFromAnotherProcess(): void
    {
        // Simulate another process flipping persistence WITHOUT touching this guard's in-memory flag.
        $this->guard()->end();                                           // in-memory + persisted OFF
        $this->container()->get(SystemFlags::class)
            ->put('tenancy.retrofit_active', '1');                        // persisted ON only
        // The hot-path interceptor's in-memory active() is still false here, but the coarse gate re-reads.
        $this->expectException(RetrofitInProgressException::class);
        $this->guard()->assertWritable();
    }

    public function testCoarseGateClearsStaleActiveAfterRemoteEnd(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        // Remote begin → local gate throws AND leaves in-memory active=true.
        $flags->put('tenancy.retrofit_active', '1');
        try {
            $this->guard()->assertWritable();
            self::fail('expected barrier');
        } catch (RetrofitInProgressException) {
        }
        self::assertTrue($this->guard()->active());
        // Remote end → the NEXT gate must clear stale active and let an owned builder write through.
        $flags->forget('tenancy.retrofit_active');
        $this->guard()->assertWritable();          // no throw
        self::assertFalse($this->guard()->active()); // synced down
        $this->connection()->table('content_types')
            ->where(['uuid' => 'ctbar0000001'])->update(['name' => 'ok']); // owned write now succeeds
        self::assertSame('ok', $this->connection()->table('content_types')
            ->where(['uuid' => 'ctbar0000001'])->first()['name']);
    }
}
