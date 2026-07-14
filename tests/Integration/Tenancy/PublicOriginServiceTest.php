<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementLockedException;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\PublicOrigin\PublicOriginValidationException;
use Thallo\Tenancy\PublicOrigin\PublicOriginWriteConflict;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\System\SystemFlags;

/**
 * PublicOriginService validates (Pin 3: HostNormalizer), gates writes to the INACTIVE activation
 * step, and reports desired-vs-applied snapshots with sources. Real Postgres harness: the service's
 * store hydrates from a fresh, unbooted context (matching provider boot()), while flags/connection/
 * activation-store are the real container-resolved services on the test DB.
 */
final class PublicOriginServiceTest extends AppTestCase
{
    private SystemFlags $flags;
    private ResolutionActivationStore $activationStore;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = $this->container()->get(SystemFlags::class);
        $this->activationStore = new ResolutionActivationStore($this->flags, $this->connection());
    }

    /** @param array<string,mixed> $file file-config tree merged over the production reserved-label defaults */
    private function makeService(array $file = []): PublicOriginService
    {
        // Mirror config/tenancy.php's public_origin defaults so reserved-label validation matches prod.
        $base = ['tenancy' => ['public_origin' => ['reserved_labels' => ['www', 'api', 'admin']]]];
        $tree = array_replace_recursive($base, $file);
        $loader = new class ($tree) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $this->context = new ApplicationContext('/tmp/glueful-public-origin-service-test', 'testing');
        $this->context->setConfigLoader($loader);

        $store = new PublicOriginStore($this->context, $this->flags, $this->connection());
        $store->hydrate();

        return new PublicOriginService(
            $this->context,
            $store,
            $this->activationStore,
            new EnablementLock($this->connection()),
        );
    }

    public function testStatusReportsEffectiveValuesAndSource(): void
    {
        // config-file base only, no flags -> source 'config'; hosts unset -> 'unset'
        $svc = $this->makeService(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        $status = $svc->status();
        self::assertSame('file.example', $status['base_domain']);
        self::assertSame('config', $status['base_domain_source']);
        self::assertSame('unset', $status['default_hosts_source']);
    }

    public function testSaveRejectsInvalidHostsWithFieldScoped422(): void
    {
        $svc = $this->makeService([]);
        try {
            $svc->save('apex.example', ['1.2.3.4']); // IP
            self::fail('expected validation exception');
        } catch (PublicOriginValidationException $e) {
            self::assertArrayHasKey('default_hosts', $e->errors);
        }
    }

    public function testSaveAcceptsApexAsDefaultHostButRejectsReservedSubdomain(): void
    {
        $svc = $this->makeService([]);
        $svc->save('apex.example', ['apex.example']);            // apex allowed
        self::assertSame(['apex.example'], $svc->status()['default_hosts']);

        $this->expectException(PublicOriginValidationException::class);
        $svc->save('apex.example', ['www.apex.example']);        // reserved label rejected
    }

    public function testSaveRejectedWhenActivationNotInactive(): void
    {
        $this->activationStore->compareAndSet(
            ResolutionActivationStep::INACTIVE,
            ResolutionActivationStep::MAPPING_HOSTS
        );
        $svc = $this->makeService([]);
        $this->expectException(PublicOriginWriteConflict::class);
        $svc->save('apex.example', ['apex.example']);
    }

    public function testHostOrderIsNotASemanticChange(): void
    {
        $svc = $this->makeService([]);
        $first = $svc->save('apex.example', ['z.example', 'apex.example']);
        $revision = $this->flags->get('tenancy.public_origin.revision');
        $second = $svc->save('apex.example', ['apex.example', 'z.example']);
        self::assertSame($revision, $this->flags->get('tenancy.public_origin.revision'));
        self::assertSame($first['base_domain'], $second['base_domain']);
    }

    public function testChangedSaveReturnsDesiredValuesAndPreservesAppliedSnapshotUntilRestart(): void
    {
        $svc = $this->makeService([
            'tenancy' => ['public_origin' => [
                'base_domain' => 'fallback.example',
                'default_hosts' => ['fallback.example'],
            ]],
        ]);
        $status = $svc->save('new.example', ['new.example']);
        self::assertSame('new.example', $status['base_domain']);
        self::assertSame(['new.example'], $status['default_hosts']);
        self::assertSame('fallback.example', $status['applied_base_domain']);
        self::assertSame(['fallback.example'], $status['applied_default_hosts']);
        self::assertTrue($status['origin_restart_required']);
    }

    public function testContendingSessionGetsLockConflict(): void
    {
        $holder = $this->connection()->newPdo();
        self::assertTrue($holder->query('SELECT pg_try_advisory_lock(4823710)')->fetchColumn());
        $threw = false;
        try {
            $this->makeService([])->save('apex.example', ['apex.example']);
        } catch (EnablementLockedException) {
            $threw = true;
        } finally {
            $holder->exec('SELECT pg_advisory_unlock(4823710)');
        }
        self::assertTrue($threw);
    }
}
