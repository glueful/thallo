<?php

declare(strict_types=1);

namespace App\Tests\Distribution;

use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The distribution-defaults smoke (DISTRIBUTION.md charter, CI posture (b)): boots the app
 * against the TRIMMED base `config/extensions.php` — the posture a fresh
 * `composer create-project` receives — and asserts the tiered activation contract holds as
 * users actually get it, rather than being inferred from the committed config.
 *
 * NOT part of the default suites (tests/Distribution is outside the Unit/Integration/Feature
 * dirs on purpose): the everything-on `config/testing/extensions.php` overlay would defeat the
 * point. Run through `composer test:distribution`, whose runner sets the overlay aside
 * (restoring it in a trap), resets the DB, and runs the fresh-install migration path first —
 * so this class also smoke-tests migrate-on-trimmed-posture.
 */
final class DistributionPostureTest extends TestCase
{
    private static ?\Glueful\Bootstrap\ApplicationContext $context = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/config/testing/extensions.php')) {
            self::markTestSkipped(
                'The everything-on testing overlay is present — run this suite via '
                . '`composer test:distribution`, which sets it aside and restores it.'
            );
        }

        RouteManifest::reset();
        \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        self::$context = Framework::create($root)
            ->withConfigDir($root . '/config')
            ->withEnvironment('testing')
            ->boot()
            ->getContext();
    }

    public static function tearDownAfterClass(): void
    {
        self::$context = null;
        RouteManifest::reset();
        \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
    }

    public function testTheFreshInstallListIsTierOnePlusTheSubscriptionsException(): void
    {
        $enabled = (array) config(self::$context, 'extensions.enabled');

        // The bundled billing-engine exception (charter §2) — enabled in a fresh install.
        self::assertContains('Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider', $enabled);
        // Tier 1.
        self::assertContains('Glueful\Extensions\Users\UsersServiceProvider', $enabled);
        self::assertContains('Glueful\Extensions\Aegis\Services\AegisServiceProvider', $enabled);
        self::assertContains('Glueful\Extensions\EmailNotification\EmailNotificationServiceProvider', $enabled);
        // Tier 2 — installed but NOT active in a fresh install.
        self::assertNotContains('Glueful\Extensions\Commerce\CommerceServiceProvider', $enabled);
        self::assertNotContains('Glueful\Extensions\Payvia\PayviaServiceProvider', $enabled);
        self::assertNotContains('Glueful\Extensions\Meilisearch\MeilisearchProvider', $enabled);
        // Tier 3 — enforcement is lifecycle-managed, never a fresh-install default.
        self::assertNotContains('Glueful\Extensions\Tenancy\TenancyServiceProvider', $enabled);
    }

    public function testCommerceSurfacesAreAbsentNotBroken(): void
    {
        $app = new \Glueful\Application(self::$context);

        // The commerce admin surface must REFUSE CLEANLY, never error. Unauthenticated, auth
        // answers before any commerce concern (401 — first smoke run proved this is the real
        // fresh-posture answer); what matters here is that the inert Thallo\Commerce module
        // (interface_exists-guarded without its engine) never turns the request into a 5xx.
        $meta = $app->handle(Request::create('/v1/admin/commerce/meta', 'GET'));
        self::assertContains(
            $meta->getStatusCode(),
            [401, 404],
            'commerce admin surface must refuse cleanly under the trimmed posture'
        );

        $landing = $app->handle(Request::create(
            '/checkout/pay/' . str_repeat('a', 64),
            'GET'
        ));
        self::assertSame(404, $landing->getStatusCode(), 'payment-link landing must be unrouted without payvia');
    }

    public function testTierOneSurfacesRespond(): void
    {
        $app = new \Glueful\Application(self::$context);

        // A tier-1 (Users) auth route is ROUTED — any refusal but never a 404 or 500.
        $login = $app->handle(Request::create('/v1/auth/login', 'POST'));
        self::assertNotSame(404, $login->getStatusCode(), 'tier-1 auth routes must exist in a fresh install');
        self::assertLessThan(500, $login->getStatusCode(), 'an empty login must refuse, not error');

        $health = $app->handle(Request::create('/', 'GET'));
        self::assertLessThan(500, $health->getStatusCode(), 'the root route must not error on a fresh posture');
    }
}
