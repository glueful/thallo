<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Thallo\Core\Content\DocsSync\DocsSyncConflict;
use Thallo\Core\Content\DocsSync\DocsSyncSource;
use Thallo\Core\Content\DocsSync\DocsSyncStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * The site's docs source and its single run slot (docs sync design §4, §6.0): one state row whose
 * conditional updates fence every change to a run.
 */
final class DocsSyncStoreTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->table('docs_sync_state')->where('site', '=', 'site')->delete();
        $this->connection()->table('docs_sync_runs')->whereNotNull('id')->delete();
        parent::tearDown();
    }

    private function store(): DocsSyncStore
    {
        return $this->container()->get(DocsSyncStore::class);
    }

    private static function source(array $over = []): DocsSyncSource
    {
        return DocsSyncSource::fromArray($over + [
            'repo' => 'glueful/thallo', 'folder' => 'docs', 'follow' => 'release', 'prereleases' => true,
            'content_type' => 'docs', 'publish' => true, 'exclude' => [], 'locale' => 'en',
            'edit_branch' => 'main',
        ]);
    }

    private function holdSlot(string $run, ?string $token = null): void
    {
        $this->store()->state();
        $this->connection()->table('docs_sync_state')->where('site', '=', 'site')->update([
            'active_run' => $run, 'lease_token' => $token, 'lease_at' => $token === null ? null : gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function insertRun(string $id, string $status): void
    {
        $this->connection()->table('docs_sync_runs')->insert([
            'id' => $id, 'revision' => 1, 'source' => '{}', 'mode' => 'commit', 'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testSavingASourceBumpsTheRevisionAndClearsSynced(): void
    {
        $store = $this->store();
        $first = $store->saveSource(self::source());
        $this->connection()->table('docs_sync_state')->where('site', '=', 'site')
            ->update(['synced_run' => 'run000000001']);

        $second = $store->saveSource(self::source(['folder' => 'guides']));

        self::assertSame($first + 1, $second);
        $state = $store->state();
        self::assertSame($second, $state['revision']);
        self::assertNull($state['synced_run']);
        self::assertSame('guides', $state['source']?->folder);
    }

    public function testTheSourceCannotChangeWhileARunHoldsTheSlot(): void
    {
        $store = $this->store();
        $store->saveSource(self::source());
        $this->holdSlot('run000000001');

        try {
            $store->saveSource(self::source(['folder' => 'other']));
            self::fail('Saving must be refused while a run holds the slot.');
        } catch (DocsSyncConflict) {
        }
        $this->expectException(DocsSyncConflict::class);
        $store->removeSource();
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function badSources(): iterable
    {
        yield 'climbing repo' => [['repo' => '../x'], 'repo'];
        yield 'three segments' => [['repo' => 'a/b/c'], 'repo'];
        yield 'owner too long' => [['repo' => str_repeat('a', 40) . '/b'], 'repo'];
        yield 'folder climbs' => [['folder' => '../docs'], 'folder'];
        yield 'folder absolute' => [['folder' => '/docs'], 'folder'];
        yield 'follow unknown' => [['follow' => 'tag'], 'follow'];
        yield 'branch missing' => [['follow' => 'branch', 'branch' => ''], 'branch'];
    }

    /** @dataProvider badSources */
    public function testSourceValidation(array $over, string $field): void
    {
        try {
            self::source($over);
            self::fail('Expected the source to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    public function testFencesAffectOneRowOnlyForTheOwner(): void
    {
        $store = $this->store();
        $this->holdSlot('run000000001', 'token-a');

        $db = $this->connection();
        self::assertTrue($db->transaction(fn () => $store->ownerFence('run000000001', 'token-a')));
        self::assertTrue($db->transaction(fn () => $store->ownerFence('run000000001', 'token-a')), 'twice in a second');
        self::assertFalse($db->transaction(fn () => $store->ownerFence('run000000001', 'token-b')));
        self::assertFalse($db->transaction(fn () => $store->ownerFence('run000000002', 'token-a')));
        self::assertTrue($db->transaction(fn () => $store->slotFence('run000000001')));

        $db->table('docs_sync_state')->where('site', '=', 'site')->update(['active_run' => null]);
        self::assertFalse($db->transaction(fn () => $store->slotFence('run000000001')));
    }

    public function testStatusOnlyMovesForward(): void
    {
        $store = $this->store();
        $this->insertRun('run000000001', 'finished');
        $this->insertRun('run000000002', 'reserved');

        self::assertFalse($store->advanceStatus('run000000001', 'dispatched'));
        self::assertFalse($store->advanceStatus('run000000001', 'running'));
        self::assertTrue($store->advanceStatus('run000000002', 'dispatched'));
        self::assertFalse($store->advanceStatus('run000000002', 'dispatched'), 'dispatched only from reserved');
        self::assertTrue($store->advanceStatus('run000000002', 'running'));
        self::assertTrue($store->advanceStatus('run000000002', 'running'), 'a later attempt keeps it running');
        self::assertTrue($store->advanceStatus('run000000002', 'finished'));
        self::assertFalse($store->advanceStatus('run000000002', 'finished'));
        $row = $this->connection()->table('docs_sync_runs')->where('id', '=', 'run000000001')->first();
        self::assertSame('finished', $row['status']);
    }

    public function testTablesAreRegisteredForTenancy(): void
    {
        $tables = ThalloTenantTables::all();
        foreach (['docs_sync_state', 'docs_sync_runs', 'docs_sync_run_pages'] as $table) {
            self::assertArrayHasKey($table, $tables);
        }
    }
}
