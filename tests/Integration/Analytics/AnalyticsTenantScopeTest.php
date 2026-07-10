<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\TenantOracleTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Log\AbstractLogger;
use Thallo\Analytics\Facts\ActorHasher;
use Thallo\Analytics\Facts\AnalyticsFact;
use Thallo\Analytics\Facts\AnalyticsRecorder;
use Thallo\Analytics\Query\AnalyticsQuery;

final class AnalyticsTenantScopeTest extends TenantOracleTestCase
{
    private const DAY_A = '2025-06-29';
    private const DAY_B = '2025-06-30';
    private const AT_A = 1751212800.0; // 2025-06-29 UTC
    private const AT_B = 1751299200.0; // 2025-06-30 UTC

    private function fact(string $subjectId, string $actorId, float $at): AnalyticsFact
    {
        return new AnalyticsFact(
            event: 'collections.row.created',
            category: 'collections',
            subjectType: 'collection',
            subjectId: $subjectId,
            actorType: 'user',
            actorId: $actorId,
            occurredAt: $at,
        );
    }

    public function testCountsAndActiveUsersIsolatedPerTenant(): void
    {
        // SAME subject + actor, DIFFERENT days per tenant — avoids the kept narrow unique's
        // (day,event,subject) collision while still proving the scoped read filters cross-tenant
        // rows: a breakdown over [DAY_A, DAY_B] unscoped would SUM both tenants' rows.
        $this->runAsTenant(self::$tenantAUuid, fn () => $this->container()->get(AnalyticsRecorder::class)
            ->record($this->fact('shared-sub', 'shared-actor', self::AT_A)));
        $this->runAsTenant(self::$tenantBUuid, fn () => $this->container()->get(AnalyticsRecorder::class)
            ->record($this->fact('shared-sub', 'shared-actor', self::AT_B)));

        $aBreak = $this->runAsTenant(self::$tenantAUuid, fn () => $this->container()->get(AnalyticsQuery::class)
            ->breakdown('collections.row.created', self::DAY_A, self::DAY_B));
        $bBreak = $this->runAsTenant(self::$tenantBUuid, fn () => $this->container()->get(AnalyticsQuery::class)
            ->breakdown('collections.row.created', self::DAY_A, self::DAY_B));

        // Each tenant sees SUM=1 (only its own day); unscoped it would be 2.
        self::assertSame([['subject' => 'shared-sub', 'count' => 1]], $aBreak);
        self::assertSame([['subject' => 'shared-sub', 'count' => 1]], $bBreak);

        // Active-users distinct count is per-tenant (each recorded the actor on its own day only).
        $aSummary = $this->runAsTenant(self::$tenantAUuid, fn () => $this->container()->get(AnalyticsQuery::class)
            ->summary(self::DAY_A, self::DAY_B));
        self::assertSame(1, $aSummary['active_users']);
    }

    public function testNoTenantContextDropsRawRollupAndWarns(): void
    {
        $spy = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $container = $this->container();
        $recorder = new AnalyticsRecorder(
            $container->get(Connection::class),
            $container->get(ActorHasher::class),
            $spy,
            $container->get(ApplicationContext::class),
            $container->get(CurrentTenantResolver::class),
        );

        // Tenancy on but NO tenant (system bypass): the raw rollup fails closed + is dropped by the
        // best-effort catch — and is LOGGED, so "analytics missing" is never silent.
        $this->runAsSystem(fn () => $recorder->record($this->fact('sub-x', 'actor-x', self::AT_B)));

        self::assertSame(0, (int) $this->connection()->table('analytics_daily')->count());
        self::assertNotEmpty($spy->warnings, 'a dropped analytics write must be logged');
    }
}
