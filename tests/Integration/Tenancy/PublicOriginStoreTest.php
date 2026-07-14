<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\System\SystemFlags;

/**
 * PublicOriginStore persists the admin-set public origin in SystemFlags (real Postgres) and
 * hydrates it over config at boot. The store takes a fresh, unbooted ApplicationContext so
 * hydrate()'s boot-only overrideConfig() is exercised the way provider boot() drives it in
 * production; the flags + connection are the real container-resolved services on the test DB.
 */
final class PublicOriginStoreTest extends AppTestCase
{
    private SystemFlags $flags;
    private ApplicationContext $context;
    /** @var array<string,mixed> file-config tree the next makeStore() hydrates from */
    private array $pendingFile = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = $this->container()->get(SystemFlags::class);
        $this->pendingFile = [];
    }

    /** Seed a file-config fallback value (dot path) applied to the next makeStore(). */
    private function contextWithConfig(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $nested = $value;
        foreach (array_reverse($segments) as $segment) {
            $nested = [$segment => $nested];
        }
        $this->pendingFile = array_replace_recursive($this->pendingFile, $nested);
    }

    /** @param array<string,mixed> $file additional file-config tree merged over pending fallback */
    private function makeStore(array $file = []): PublicOriginStore
    {
        $tree = array_replace_recursive($this->pendingFile, $file);
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
        $this->context = new ApplicationContext('/tmp/glueful-public-origin-test', 'testing');
        $this->context->setConfigLoader($loader);

        return new PublicOriginStore($this->context, $this->flags, $this->connection());
    }

    public function testWriteChangedPersistsAndBumpsRevisionOnlyWhenChanged(): void
    {
        $store = $this->makeStore();
        self::assertNull($store->persistedBaseDomain());

        self::assertTrue($store->writeChanged('apex.example', ['apex.example', 'alt.example']));
        self::assertSame('apex.example', $store->persistedBaseDomain());
        self::assertSame(['apex.example', 'alt.example'], $store->persistedHosts());
        $rev1 = $this->flags->get('tenancy.public_origin.revision');
        self::assertNotNull($rev1);

        // Unchanged write: no revision bump, returns false.
        self::assertFalse($store->writeChanged('apex.example', ['apex.example', 'alt.example']));
        self::assertSame($rev1, $this->flags->get('tenancy.public_origin.revision'));

        // Changed write: bumps.
        self::assertTrue($store->writeChanged('apex.example', ['apex.example']));
        self::assertNotSame($rev1, $this->flags->get('tenancy.public_origin.revision'));
    }

    public function testStaleWhenRevisionChangesAfterHydration(): void
    {
        $store = $this->makeStore();               // constructor captures current (null) revision
        self::assertFalse($store->isStale());      // no persisted revision yet
        $store->writeChanged('apex.example', ['apex.example']); // bumps revision this process hydrated null
        self::assertTrue($store->isStale());
        $this->expectException(EnablementException::class);
        $store->assertFreshForActivation();
    }

    public function testHydrateOverridesConfigWhenSet(): void
    {
        $this->flags->put('tenancy.public_origin.base_domain', 'apex.example');
        $this->flags->put('tenancy.public_origin.default_hosts', 'apex.example,alt.example');
        $store = $this->makeStore();
        $store->hydrate();
        self::assertSame('apex.example', $this->context->getConfig('tenancy.public_origin.base_domain'));
        self::assertSame(
            ['apex.example', 'alt.example'],
            $this->context->getConfig('tenancy.public_origin.default_hosts')
        );
    }

    public function testClearingPersistedBaseFallsBackToThePreHydrationConfig(): void
    {
        $this->contextWithConfig('tenancy.public_origin.base_domain', 'fallback.example');
        $this->flags->put('tenancy.public_origin.base_domain', 'persisted.example');
        $store = $this->makeStore();
        $store->hydrate();

        self::assertSame('fallback.example', $store->fallbackBaseDomain());
        self::assertSame('persisted.example', $store->desiredBaseDomain());
        self::assertSame('persisted.example', $store->appliedBaseDomain());

        $store->writeChanged(null, ['fallback.example']);
        self::assertSame('fallback.example', $store->desiredBaseDomain());
        self::assertSame('persisted.example', $store->appliedBaseDomain());
    }
}
