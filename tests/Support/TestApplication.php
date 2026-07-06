<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Application;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;

/**
 * Process-shared application boot for the whole test run.
 *
 * Why this exists — a test-isolation hazard in the framework's route loading:
 *
 * Extension routes are registered in each ServiceProvider::boot() via
 * {@see \Glueful\Extensions\ServiceProvider::loadRoutesFrom()}, which records every loaded
 * route file in a *function-local* `static $loaded` keyed by realpath. That static survives for
 * the entire PHP process and — unlike the framework's own RouteManifest — has no reset hook.
 * Booting the framework a SECOND time in the same process therefore silently drops every
 * extension-provided route (e.g. thallo-collections' `/v1/collections/*`) from the second boot's
 * router: loadRoutesFrom() sees the file as "already loaded" and returns before registering it.
 *
 * The full `composer test` run mixes the framework-booting Feature test
 * ({@see \App\Tests\TestCase}) with the AppTestCase suites. When these booted independently,
 * whichever booted first consumed the one-shot route loaders and the other was left with a
 * router missing all extension routes (collections requests 404'd).
 *
 * Sharing a SINGLE booted Application across every base class collapses the run to one boot, so
 * the one-shot route loaders fire exactly once into the single router everyone dispatches
 * through. This is the correct isolation boundary: there is no supported way to reset the
 * framework's per-file route-load latch, so the suites must not boot twice.
 */
final class TestApplication
{
    private static ?Application $app = null;

    /**
     * Boot the application once per process and return the shared instance.
     */
    public static function instance(): Application
    {
        if (self::$app === null) {
            $root = dirname(__DIR__, 2);

            // First (and only) boot of the process: start from clean framework route state and
            // drop any stale compiled route cache so every routes/*.php file loads fresh.
            RouteManifest::reset();
            foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $file) {
                @unlink($file);
            }

            // Schema is created by `composer test:migrate` before PHPUnit runs.
            self::$app = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot();
        }

        return self::$app;
    }
}
