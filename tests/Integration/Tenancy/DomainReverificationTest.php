<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtLookup;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtResult;
use Glueful\Helpers\Utils;
use Glueful\Events\EventService;

final class DomainReverificationTest extends RetrofitHarnessTestCase
{
    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach ($this->tenants as $tenantUuid) {
            $pdo->prepare('DELETE FROM tenant_domains WHERE tenant_uuid = ?')->execute([$tenantUuid]);
            $pdo->prepare('DELETE FROM tenant_memberships WHERE tenant_uuid = ?')->execute([$tenantUuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$tenantUuid]);
        }
        $this->tenants = [];
        parent::tearDown();
    }

    public function testFoldedSchemaAndDefaultsExist(): void
    {
        $columns = $this->connection()->getPDO()->query(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'tenant_domains'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['last_checked_at', 'last_check_status', 'consecutive_failures', 'first_failure_at'];
        foreach ($required as $column) {
            self::assertContains($column, $columns);
        }
        self::assertSame('revoked', TenantDomain::VERIFICATION_REVOKED);
        self::assertSame(
            3,
            (int) config($this->appContext(), 'tenancy.domains.reverification.failure_threshold')
        );
    }

    public function testInitialVerificationIsPendingOnlyAndClassifiesDnsResults(): void
    {
        [$tenantUuid, $domainUuid, $host, $token] = $this->seedDomain('pending');
        $mismatch = $this->administration(new DnsTxtResult('success', []));
        self::assertSame('pending', $mismatch->verifyDomain($this->appContext(), $domainUuid));
        $row = $this->domainRow($domainUuid);
        self::assertSame('mismatch', $row['last_check_status']);
        self::assertSame(0, (int) $row['consecutive_failures']);
        self::assertNull($row['first_failure_at']);

        $verified = $this->administration(new DnsTxtResult('success', [$token]));
        self::assertSame('verified', $verified->verifyDomain($this->appContext(), $domainUuid));
        self::assertSame('verified', $this->domainRow($domainUuid)['verification_status']);

        $this->expectException(\DomainException::class);
        $verified->verifyDomain($this->appContext(), $domainUuid);
    }

    public function testFailureRevokesOnlyAfterThresholdAndGraceAndSuccessRestores(): void
    {
        [, $domainUuid, , $token] = $this->seedDomain('verified');
        $events = [];
        $bus = $this->container()->get(EventService::class);
        $bus->addListener(DomainReverificationFailed::class, static function () use (&$events): void {
            $events[] = 'failed';
        });
        $bus->addListener(DomainRevoked::class, static function () use (&$events): void {
            $events[] = 'revoked';
        });
        $bus->addListener(DomainReverified::class, static function () use (&$events): void {
            $events[] = 'reverified';
        });
        $this->connection()->getPDO()->prepare(
            "UPDATE tenant_domains SET consecutive_failures = 2, "
            . "first_failure_at = now() - interval '48 hours' WHERE uuid = ?"
        )->execute([$domainUuid]);

        $revoked = $this->administration(new DnsTxtResult('success', []))
            ->reverifyDomain($this->appContext(), $domainUuid);
        self::assertSame('mismatch', $revoked->outcome);
        self::assertSame('revoked', $revoked->transition);
        self::assertSame('revoked', $revoked->verificationStatus);
        self::assertSame(3, $revoked->consecutiveFailures);
        self::assertNotNull($revoked->checkedAt);
        self::assertSame(['failed', 'revoked'], $events);

        $restored = $this->administration(new DnsTxtResult('success', [$token]))
            ->reverifyDomain($this->appContext(), $domainUuid);
        self::assertSame('restored', $restored->transition);
        self::assertSame('verified', $restored->verificationStatus);
        $row = $this->domainRow($domainUuid);
        self::assertSame(0, (int) $row['consecutive_failures']);
        self::assertNull($row['first_failure_at']);
        self::assertSame(['failed', 'revoked', 'reverified'], $events);
    }

    public function testOuterRollbackSuppressesFailureEvent(): void
    {
        [, $domainUuid] = $this->seedDomain('verified');
        $events = [];
        $this->container()->get(EventService::class)->addListener(
            DomainReverificationFailed::class,
            static function () use (&$events): void {
                $events[] = 'failed';
            }
        );
        try {
            $this->connection()->transaction(function () use ($domainUuid): void {
                $this->administration(new DnsTxtResult('success', []))
                    ->reverifyDomain($this->appContext(), $domainUuid);
                throw new \RuntimeException('roll back');
            });
            self::fail('Expected rollback sentinel.');
        } catch (\RuntimeException $exception) {
            self::assertSame('roll back', $exception->getMessage());
        }

        self::assertSame([], $events);
        self::assertSame(0, (int) $this->domainRow($domainUuid)['consecutive_failures']);
    }

    public function testDnsRunsOutsideTransactionAndOptimisticChangeReturnsStale(): void
    {
        [, $domainUuid, ,] = $this->seedDomain('verified');
        $observation = (object) ['level' => -1];
        $lookup = new class ($this->connection(), $domainUuid, $observation) extends DnsTxtLookup {
            public function __construct(
                private readonly \Glueful\Database\Connection $connection,
                private readonly string $domainUuid,
                private readonly object $observation,
            ) {
            }

            public function lookupStructured(string $name): DnsTxtResult
            {
                $this->observation->level = $this->connection->transactionLevel();
                $this->connection->getPDO()->prepare(
                    'UPDATE tenant_domains SET last_checked_at = now() WHERE uuid = ?'
                )->execute([$this->domainUuid]);
                return new DnsTxtResult('success', []);
            }
        };
        $admin = new ContractTenantDomainAdministration($lookup, new ReleasedHostRepository());

        $result = $admin->reverifyDomain($this->appContext(), $domainUuid);

        self::assertSame(0, $observation->level);
        self::assertSame('stale', $result->outcome);
        self::assertSame(0, (int) $this->domainRow($domainUuid)['consecutive_failures']);
    }

    public function testTokenlessAndPendingDomainsAreIneligible(): void
    {
        [, $pendingUuid] = $this->seedDomain('pending');
        [, $tokenlessUuid] = $this->seedDomain('verified', false);
        $admin = $this->administration(new DnsTxtResult('error'));

        self::assertSame(
            'ineligible',
            $admin->reverifyDomain($this->appContext(), $pendingUuid)->outcome
        );
        self::assertSame(
            'ineligible',
            $admin->reverifyDomain($this->appContext(), $tokenlessUuid)->outcome
        );
    }

    private function administration(DnsTxtResult $result): ContractTenantDomainAdministration
    {
        $lookup = new class ($result) extends DnsTxtLookup {
            public function __construct(private readonly DnsTxtResult $result)
            {
            }

            public function lookupStructured(string $name): DnsTxtResult
            {
                return $this->result;
            }
        };

        return new ContractTenantDomainAdministration($lookup, new ReleasedHostRepository());
    }

    /** @return array{string,string,string,string} */
    private function seedDomain(string $status, bool $withToken = true): array
    {
        $tenantUuid = Utils::generateNanoID(12);
        $domainUuid = Utils::generateNanoID(12);
        $host = strtolower(Utils::generateNanoID(8)) . '.reverify.test';
        $token = bin2hex(random_bytes(16));
        $this->tenants[] = $tenantUuid;
        $pdo = $this->connection()->getPDO();
        $pdo->prepare(
            'INSERT INTO tenants (uuid, slug, name, status) VALUES (?, ?, ?, ?)'
        )->execute([$tenantUuid, 'rv-' . strtolower(Utils::generateNanoID(6)), 'Reverify', 'active']);
        $pdo->prepare(
            'INSERT INTO tenant_domains '
            . '(uuid, tenant_uuid, host, verification_status, status, verification_token) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $domainUuid,
            $tenantUuid,
            $host,
            $status,
            'active',
            $withToken ? $token : null,
        ]);

        return [$tenantUuid, $domainUuid, $host, $token];
    }

    /** @return array<string,mixed> */
    private function domainRow(string $domainUuid): array
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT * FROM tenant_domains WHERE uuid = ?'
        );
        $statement->execute([$domainUuid]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }
}
