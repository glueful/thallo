<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\DocsSync\DocsSyncService;
use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Under real tenancy enforcement (docs sync design §6.1): a queued sync runs in the workspace it
 * was started in, from a worker with no workspace of its own, and writes nothing anywhere else.
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1.
 */
final class DocsSyncWorkspaceTest extends RetrofittedTenantTestCase
{
    use DocsSyncTestHelpers;
    use DocsSyncRunnerHelpers;

    /** Start a run in `$tenant` whose commit and archive are recorded: the worker needs no network. */
    private function preparedRun(string $tenant, array $files): string
    {
        return $this->runAsTenant($tenant, function () use ($files): string {
            $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
            $this->saveSource();
            $run = (string) $this->container()->get(DocsSyncService::class)->start('commit', null)['id'];
            $this->repoFiles = $files;
            $path = "docs-sync/{$run}/prepared.zip";
            $this->container()->get(StorageManager::class)->disk('uploads')->write($path, $this->archiveBytes());
            $this->setRun($run, ['ref' => 'v1.0.0', 'sha' => self::SHA, 'archive_path' => $path]);
            return $run;
        });
    }

    private function deliver(string $run, string $workspace): void
    {
        // Handled with NO tenant context, as a worker starts: the job enters the workspace itself.
        $this->runAsSystem(fn () => (new DocsSyncJob(DocsSyncJob::payload($run, $workspace), $this->appContext()))
            ->handle());
    }

    public function testAFreshWorkerRunsInTheRunsWorkspace(): void
    {
        $run = $this->preparedRun(self::$tenantAUuid, ['alpha.md' => "# Alpha\n"]);

        $this->deliver($run, self::$tenantAUuid);

        self::assertSame('success', $this->runAsTenant(self::$tenantAUuid, fn () => $this->runRow($run)['outcome']));
        self::assertTrue($this->runAsTenant(self::$tenantAUuid, fn () => $this->slugExists('alpha')));
        self::assertSame([], $this->runAsTenant(self::$tenantBUuid, fn () => $this->runRow($run)), 'not B\'s run');
    }

    public function testTwoWorkspacesInARowDoNotLeak(): void
    {
        $a = $this->preparedRun(self::$tenantAUuid, ['alpha.md' => "# Alpha\n"]);
        $b = $this->preparedRun(self::$tenantBUuid, ['beta.md' => "# Beta\n"]);

        $this->deliver($a, self::$tenantAUuid);
        $this->deliver($b, self::$tenantBUuid);

        self::assertTrue($this->runAsTenant(self::$tenantAUuid, fn () => $this->slugExists('alpha')));
        self::assertFalse($this->runAsTenant(self::$tenantAUuid, fn () => $this->slugExists('beta')));
        self::assertTrue($this->runAsTenant(self::$tenantBUuid, fn () => $this->slugExists('beta')));
        self::assertFalse($this->runAsTenant(self::$tenantBUuid, fn () => $this->slugExists('alpha')));
        self::assertSame('success', $this->runAsTenant(self::$tenantBUuid, fn () => $this->runRow($b)['outcome']));
    }

    public function testAMissingWorkspaceFailsTheDeliveryWithoutLooping(): void
    {
        $run = $this->preparedRun(self::$tenantAUuid, ['alpha.md' => "# Alpha\n"]);
        $queued = fn () => count($this->runAsSystem(fn () => $this->connection()->table('queue_jobs')->get()));
        $before = $queued();

        try {
            $this->deliver($run, 'nosuchworksp');
            self::fail('A delivery for a workspace that does not exist fails.');
        } catch (\Throwable) {
        }

        self::assertSame($before, $queued(), 'it queued nothing again');
        self::assertFalse($this->runAsTenant(self::$tenantAUuid, fn () => $this->slugExists('alpha')));
    }
}
