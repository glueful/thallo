<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Purge\PurgeRunRepository;

final class PurgePipelineTest extends AppTestCase
{
    /** @var list<string> */
    private array $runs = [];

    protected function tearDown(): void
    {
        foreach ($this->runs as $uuid) {
            $statement = $this->connection()->getPDO()->prepare(
                'DELETE FROM thallo_tenant_purge_runs WHERE uuid = ?'
            );
            $statement->execute([$uuid]);
        }
        parent::tearDown();
    }

    public function testLeaseOwnerAloneCanCheckpointAndComplete(): void
    {
        $repository = $this->container()->get(PurgeRunRepository::class);
        $context = $this->appContext();
        $run = $repository->create($context, 'purgeTENANT1', null);
        $this->runs[] = $run;

        self::assertTrue($repository->claimDispatch($context, $run));
        self::assertTrue($repository->claimRun($context, $run, 'worker-A'));
        $repository->checkpoint($context, $run, 'worker-A', 'thallo.tables', 'prepared');
        $repository->putArtifacts($context, $run, 'worker-A', 'thallo.media', ['objects' => []]);

        $found = $repository->find($context, $run);
        self::assertSame('running', $found['status']);
        self::assertSame('prepared', json_decode((string) $found['plan'], true)['thallo.tables']);
        self::assertTrue($repository->markCompleted($context, $run, 'worker-A'));
        self::assertFalse($repository->markCompleted($context, $run, 'worker-B'));
    }

    public function testOnlyOneIncompleteRunExistsPerTenant(): void
    {
        $repository = $this->container()->get(PurgeRunRepository::class);
        $context = $this->appContext();
        $run = $repository->create($context, 'purgeTENANT2', null);
        $this->runs[] = $run;

        $this->expectException(\PDOException::class);
        $repository->create($context, 'purgeTENANT2', null);
    }

    public function testSystemLedgerIsNotTenantOwned(): void
    {
        self::assertNotContains(
            'thallo_tenant_purge_runs',
            \Thallo\Tenancy\ThalloTenantTables::tableNames()
        );
    }
}
