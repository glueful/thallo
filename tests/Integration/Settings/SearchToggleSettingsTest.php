<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Http\Controllers\GeneralSettingsController;
use App\Http\DTOs\UpdateGeneralSettingsData;
use App\Providers\ThalloServiceProvider;
use App\Settings\GeneralSettings;
use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Routing\RouteCache;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Index\ResilientContentReindexer;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings › General search switch (search-toggle): a stored `search_enabled` row —
 * a SYSTEM key, readable before tenant resolution — overrides the deploy-time
 * `thallo.capabilities` map inside ThalloServiceProvider::makeCapabilityRegistry(), so the
 * admin toggle takes effect on the next request with no restart and no `.env` edit.
 */
final class SearchToggleSettingsTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->store()->forget('search_enabled');
        parent::tearDown();
    }

    private function store(): SettingsStore
    {
        return $this->container()->get(SettingsStore::class);
    }

    private function settings(): GeneralSettings
    {
        return $this->container()->get(GeneralSettings::class);
    }

    public function testDefaultFollowsTheCapabilitiesMap(): void
    {
        // No stored row: the committed config/thallo.php map disables thallo.search.
        self::assertFalse($this->settings()->searchEnabled());
        self::assertFalse((bool) $this->settings()->all()['search_enabled']);
    }

    public function testStoredRowIsASystemKeyAndOverridesTheMap(): void
    {
        $this->store()->putMany(['search_enabled' => 'true']);

        // System key: lives in the unscoped channel (boot reads it BEFORE tenant
        // resolution), never in the tenant-scopable `settings` table.
        self::assertNull(
            $this->connection()->table('settings')->where(['key' => 'search_enabled'])->first(),
            'search_enabled must not land in the settings table',
        );
        self::assertTrue($this->settings()->searchEnabled(), 'the stored row overrides the map');

        $this->store()->forget('search_enabled');
        self::assertFalse($this->settings()->searchEnabled(), 'forget clears back to the map default');
    }

    public function testRegistryOverlayReflectsTheStoredRow(): void
    {
        $register = function (): bool {
            $registry = ThalloServiceProvider::makeCapabilityRegistry($this->container());
            $registry->register(new Capability('thallo.search', label: 'Search', description: 'test'));

            return $registry->isEnabled('thallo.search');
        };

        self::assertFalse($register(), 'rowless: the config map default (off) stands');

        $this->store()->putMany(['search_enabled' => 'true']);
        self::assertTrue($register(), 'a stored true row enables the capability');

        $this->store()->putMany(['search_enabled' => 'false']);
        self::assertFalse($register(), 'a stored false row disables it');
    }

    public function testControllerSaveClearsTheRouteCacheOnlyOnChange(): void
    {
        // Route registration is gated at boot, and the compiled route cache is keyed by
        // route-FILE signatures — a capability flip changes no files, so the controller
        // must clear the cache itself when (and only when) the effective value changes.
        $cacheFile = (new RouteCache($this->appContext()))->getCacheFilePath();
        @mkdir(dirname($cacheFile), 0777, true);

        $controller = $this->container()->get(GeneralSettingsController::class);

        file_put_contents($cacheFile, "<?php\nreturn [];\n");
        $res = $controller->update(new UpdateGeneralSettingsData(search_enabled: true));
        self::assertSame(200, $res->getStatusCode());
        self::assertFileDoesNotExist($cacheFile, 'a real flip (off -> on) must clear the route cache');

        file_put_contents($cacheFile, "<?php\nreturn [];\n");
        $controller->update(new UpdateGeneralSettingsData(search_enabled: true));
        self::assertFileExists($cacheFile, 'saving the unchanged value must NOT churn the route cache');
        @unlink($cacheFile);
    }

    public function testToggledOnRouteAndReindexerActivateOnNextBoot(): void
    {
        // The end-to-end promise: store the row, and the NEXT boot registers /v1/search and
        // binds the real reindexer — no restart, no config edit. (The 'thallo' override is an
        // empty no-op file: the helper exists to force a dedicated fresh boot.)
        $this->store()->putMany(['search_enabled' => 'true']);

        $app = self::bootAppWithConfigOverride('thallo', []);

        // Meilisearch is unreachable in tests, so the handler fails closed (503); a running
        // server would 200. Either way it is NOT 404 — the route is registered.
        $status = (new Application($app))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertNotSame(404, $status, 'the stored toggle must register /v1/search on the next boot');

        self::assertInstanceOf(
            ResilientContentReindexer::class,
            $app->getContainer()->get(ContentReindexer::class),
            'the stored toggle must bind the real (resilient) reindexer on the next boot',
        );
    }
}
