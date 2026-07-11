<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Enablement\TenancyDiagnostics;

final class DomainReverificationDiagnosticsTest extends RetrofitHarnessTestCase
{
    private ?string $tenantUuid = null;

    protected function tearDown(): void
    {
        if ($this->tenantUuid !== null) {
            $pdo = $this->connection()->getPDO();
            $pdo->prepare('DELETE FROM tenant_domains WHERE tenant_uuid = ?')->execute([$this->tenantUuid]);
            $pdo->prepare('DELETE FROM tenants WHERE uuid = ?')->execute([$this->tenantUuid]);
        }
        parent::tearDown();
    }

    public function testDiagnoseFlagsIncoherentStatusWithoutExposingToken(): void
    {
        $this->tenantUuid = Utils::generateNanoID(12);
        $domainUuid = Utils::generateNanoID(12);
        $token = bin2hex(random_bytes(16));
        $pdo = $this->connection()->getPDO();
        $pdo->prepare(
            "INSERT INTO tenants (uuid,slug,name,status) VALUES (?,?,'Diagnose','active')"
        )->execute([$this->tenantUuid, 'diag-' . strtolower(Utils::generateNanoID(5))]);
        $pdo->prepare(
            'INSERT INTO tenant_domains '
            . '(uuid,tenant_uuid,host,verification_status,status,verification_token,last_check_status) '
            . "VALUES (?,?,?,'verified','active',?,'bogus')"
        )->execute([$domainUuid, $this->tenantUuid, 'diag.example.test', $token]);

        $report = $this->container()->get(TenancyDiagnostics::class)->report();
        $section = $report['sections']['domain_reverification'];

        self::assertSame('fail', $section['status']);
        self::assertContains($domainUuid, $section['detail']['incoherent_domain_uuids']);
        self::assertStringNotContainsString($token, json_encode($section, JSON_THROW_ON_ERROR));
    }
}
