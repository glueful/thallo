<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\DocsSync;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\DocsSync\DocsSyncWorkspaces;

/** The recurring docs sync tasks run in every workspace; one failing never stops the others. */
final class DocsSyncWorkspacesTest extends TestCase
{
    private function runner(): TenantContextRunner
    {
        return new class implements TenantContextRunner {
            /** @var list<string> */
            public array $entered = [];

            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                $this->entered[] = $tenantUuid;
                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
                foreach (['ws000000001', 'ws000000002'] as $tenant) {
                    $this->runAsTenant($tenant, static fn () => $fn($tenant));
                }
            }
        };
    }

    public function testRecoveryRunsInEveryWorkspaceAndOneFailureDoesNotStopTheOthers(): void
    {
        $runner = $this->runner();
        $ran = [];

        $count = (new DocsSyncWorkspaces($runner, true))->each('recover', function () use (&$ran, $runner): void {
            $current = end($runner->entered);
            if ($current === 'ws000000001') {
                throw new \RuntimeException('the first workspace is broken');
            }
            $ran[] = $current;
        });

        self::assertSame(2, $count);
        self::assertSame(['ws000000002'], $ran);
    }

    public function testASiteWithoutWorkspacesRunsOnce(): void
    {
        $runner = $this->runner();
        $calls = 0;

        (new DocsSyncWorkspaces($runner, false))->each('prune', function () use (&$calls): void {
            $calls++;
        });

        self::assertSame(1, $calls);
        self::assertSame([], $runner->entered);
    }
}
