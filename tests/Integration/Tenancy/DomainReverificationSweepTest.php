<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Reverification\DomainReverificationSweep;
use Thallo\Tenancy\Reverification\DomainReverificationSweepLock;

final class DomainReverificationSweepTest extends RetrofitHarnessTestCase
{
    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->tenants as $tenantUuid) {
            $pdo->prepare('DELETE FROM tenant_domains WHERE tenant_uuid = ?')->execute([$tenantUuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$tenantUuid]);
        }
        $this->tenants = [];
        parent::tearDown();
    }

    public function testSweepSelectsDueResolvableDomainsAndContinuesAfterFailure(): void
    {
        $first = $this->seed('active', 'verified', 'active', null);
        $second = $this->seed('suspended', 'revoked', 'active', '48 hours');
        $this->seed('active', 'pending', 'active', null);
        $this->seed('active', 'verified', 'disabled', null);
        $this->seed('deleted', 'verified', 'active', null);
        $this->seed('active', 'verified', 'active', '1 hour');

        $called = [];
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('reverifyDomain')->willReturnCallback(
            function ($context, string $uuid) use (&$called, $first): DomainReverificationResult {
                $called[] = $uuid;
                if ($uuid === $first) {
                    throw new \RuntimeException('simulated DNS provider failure');
                }
                return new DomainReverificationResult('verified', 'verified', 'none', 0, 'now');
            }
        );

        $errors = (new DomainReverificationSweep($this->connection(), $domains))
            ->run($this->appContext());

        self::assertSame([$first], $errors);
        self::assertEqualsCanonicalizing([$first, $second], $called);
    }

    public function testDedicatedSessionLockExcludesAnotherRunnerAndReleasesAfterException(): void
    {
        $holder = $this->connection()->newPdo();
        $holder->query(
            "SELECT pg_advisory_lock(hashtextextended('tenancy:reverify:sweep', 0))"
        );
        $lock = new DomainReverificationSweepLock($this->connection());
        $called = false;
        self::assertFalse($lock->run(function () use (&$called): void {
            $called = true;
        }));
        self::assertFalse($called);
        $holder->query(
            "SELECT pg_advisory_unlock(hashtextextended('tenancy:reverify:sweep', 0))"
        );
        unset($holder);

        try {
            $lock->run(static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected callback exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertTrue($lock->run(static function (): void {
        }));
    }

    private function seed(
        string $tenantStatus,
        string $verificationStatus,
        string $domainStatus,
        ?string $checkedAgo
    ): string {
        $tenantUuid = Utils::generateNanoID(12);
        $domainUuid = Utils::generateNanoID(12);
        $this->tenants[] = $tenantUuid;
        $deletedAt = $tenantStatus === 'deleted' ? 'now()' : 'NULL';
        $persistedStatus = $tenantStatus === 'deleted' ? 'deleted' : $tenantStatus;
        $pdo = $this->connection()->getPDO();
        $pdo->exec(sprintf(
            "INSERT INTO tenants (uuid, slug, name, status, deleted_at) VALUES (%s,%s,'Sweep',%s,%s)",
            $pdo->quote($tenantUuid),
            $pdo->quote('sw-' . strtolower(Utils::generateNanoID(6))),
            $pdo->quote($persistedStatus),
            $deletedAt,
        ));
        $lastChecked = $checkedAgo === null
            ? 'NULL'
            : "now() - interval " . $pdo->quote($checkedAgo);
        $pdo->exec(sprintf(
            'INSERT INTO tenant_domains '
            . '(uuid,tenant_uuid,host,verification_status,status,verification_token,last_checked_at) '
            . 'VALUES (%s,%s,%s,%s,%s,%s,%s)',
            $pdo->quote($domainUuid),
            $pdo->quote($tenantUuid),
            $pdo->quote(strtolower(Utils::generateNanoID(8)) . '.sweep.test'),
            $pdo->quote($verificationStatus),
            $pdo->quote($domainStatus),
            $pdo->quote(bin2hex(random_bytes(16))),
            $lastChecked,
        ));

        return $domainUuid;
    }
}
