<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;

/**
 * Every change to the enabled-extension list takes one lock (feature activation plan Task 11, F2):
 * on glueful/framework 1.88 the schema executor takes its migration locks and then the
 * extension-state lock, and Thallo's own writers take that same lock. The admin toggle no longer
 * wraps the executor in it, so an admin enable and a CLI enable can't wait on each other, and an
 * activation and an independent package's CLI keep both of their providers.
 *
 * These run real children against the repository's config/extensions.php and extension cache,
 * which each test puts back exactly as it found them.
 */
final class ExtensionStateHandoverTest extends AppTestCase
{
    use ChildProcesses;

    private const AUDIT = 'Glueful\\Extensions\\Audit\\AuditServiceProvider';
    private const BOOKINGS = 'Acme\\Bookings\\BookingsServiceProvider';
    private const MEILISEARCH = 'Glueful\\Extensions\\Meilisearch\\MeilisearchProvider';

    private string $configBefore = '';
    private ?string $cacheBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configBefore = (string) file_get_contents($this->configFile());
        $this->cacheBefore = is_file($this->cacheFile()) ? (string) file_get_contents($this->cacheFile()) : null;
    }

    protected function tearDown(): void
    {
        file_put_contents($this->configFile(), $this->configBefore);
        if ($this->cacheBefore === null) {
            @unlink($this->cacheFile());
        } else {
            file_put_contents($this->cacheFile(), $this->cacheBefore);
        }
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DROP TABLE IF EXISTS acme_bookings');                 // the engine's migration
        $pdo->exec("DELETE FROM migrations WHERE source = 'acme/bookings'");
        foreach (glob(dirname($this->configFile()) . '/extensions.php.bak*') ?: [] as $backup) {
            @unlink($backup);
        }
        parent::tearDown();
    }

    private function configFile(): string
    {
        return dirname(__DIR__, 3) . '/config/extensions.php';
    }

    private function cacheFile(): string
    {
        return dirname(__DIR__, 3) . '/bootstrap/cache/extensions.php';
    }

    /** The enabled list without $provider, as the root config file holds it. */
    private function removeFromConfig(string $provider): void
    {
        $quoted = "'" . str_replace('\\', '\\\\', $provider) . "'";
        $lines = array_filter(
            explode("\n", $this->configBefore),
            static fn (string $line): bool => !str_contains($line, $provider) && !str_contains($line, $quoted),
        );
        file_put_contents($this->configFile(), implode("\n", $lines));
        self::assertNotContains($provider, $this->enabled());
    }

    /** @return list<string> */
    private function enabled(): array
    {
        clearstatcache();
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->configFile(), true);
        }
        return array_values((array) ((require $this->configFile())['enabled'] ?? []));
    }

    public function testAnAdminEnableAndACliEnableInTheConflictingOrderBothFinish(): void
    {
        $this->removeFromConfig(self::AUDIT);

        $cli = $this->startChild('extension_enable_cli_child.php', ['cli', 'glueful/audit']);
        $cli->waitFor('migration-locks-held');                       // migration locks, not the state lock
        putenv('THALLO_TEST_PAUSE_BEFORE_EXECUTOR=1');
        try {
            $admin = $this->startChild('extension_enable_admin_child.php', ['glueful/audit']);
        } finally {
            putenv('THALLO_TEST_PAUSE_BEFORE_EXECUTOR');
        }
        $admin->waitFor('before-executor');                          // holds no migration lock

        $started = microtime(true);
        $cli->signal('go');
        $admin->signal('go');
        $cliOut = $cli->finish(45);
        $adminOut = $admin->finish(45);

        self::assertStringContainsString('status=succeeded', $cliOut, $cliOut);
        self::assertStringContainsString('http=200', $adminOut, $adminOut);
        self::assertLessThan(30, microtime(true) - $started, 'neither waited out a lock timeout');
        self::assertSame(1, count(array_keys($this->enabled(), self::AUDIT, true)), 'enabled once');
    }

    public function testThePauseSeamIsNotInTheProductionController(): void
    {
        putenv('THALLO_TEST_PAUSE_BEFORE_EXECUTOR=1');
        try {
            $child = $this->startChild('extension_toggle_production_child.php');
        } finally {
            putenv('THALLO_TEST_PAUSE_BEFORE_EXECUTOR');
        }
        $out = $child->finish(60);
        self::assertStringContainsString('http=200', $out, $out);
        self::assertStringNotContainsString('before-executor', $out, 'the production controller never pauses');
    }

    public function testAnActivationAndAnIndependentPackageCliKeepBothProviders(): void
    {
        // The third-party fixture's engine (in no testing overlay), prepared as its activation does.
        $this->removeFromConfig(self::MEILISEARCH);

        $engine = $this->startChild('extension_enable_cli_child.php', ['engine']);
        $engine->waitFor('holding');                                 // the activation holds the lock
        $cli = $this->startChild('extension_enable_cli_child.php', ['cli', 'glueful/meilisearch']);
        $cli->waitFor('migration-locks-held');                       // it has none to take; next, the state lock
        $cli->signal('go');
        usleep(500_000);
        self::assertFalse($cli->isFinished(), 'the CLI waits for the activation to release the lock');
        $engine->signal('go');

        $engineOut = $engine->finish(45);
        $cliOut = $cli->finish(45);
        self::assertStringContainsString('status=', $engineOut, $engineOut);
        self::assertStringContainsString('status=succeeded', $cliOut, $cliOut);
        $enabled = $this->enabled();
        self::assertContains(self::BOOKINGS, $enabled, 'the activation kept its provider');
        self::assertContains(self::MEILISEARCH, $enabled, 'the CLI kept its provider');
    }
}
