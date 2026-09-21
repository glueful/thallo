<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Settings\EngineAdminUrlProvider;

/**
 * Where the admin is, for the links that lead back into it (the preview bar's Edit and Design,
 * the billing return). Thallo serves its own admin at /admin on the site's host, so a site that
 * was told nothing still knows — and a site that was told only its own address, which the setup
 * screen used to save, is not sent to a 404.
 */
final class AdminUrlResolutionTest extends TestCase
{
    private static function resolve(
        string $setting,
        ?string $origin = 'https://example.com',
        string $baseUrl = 'https://example.com',
        bool $bundled = true,
    ): ?string {
        return EngineAdminUrlProvider::resolve($setting, $origin, $baseUrl, $bundled);
    }

    public function testASiteToldNothingUsesItsOwnAdmin(): void
    {
        self::assertSame('https://example.com/admin', self::resolve(''));
        // The address the visitor is actually on beats the one the deploy was given.
        self::assertSame('https://www.example.com/admin', self::resolve('', 'https://www.example.com'));
        // No request (a queued job, a command): the deploy's address.
        self::assertSame('https://example.com/admin', self::resolve('', null, 'https://example.com/'));
    }

    public function testAnAdminHostedElsewhereIsUsedAsGiven(): void
    {
        self::assertSame('https://admin.example.com', self::resolve('https://admin.example.com/'));
        self::assertSame('https://example.com/backoffice', self::resolve('https://example.com/backoffice'));
        self::assertSame('https://example.com/admin', self::resolve('https://example.com/admin/'));
    }

    public function testTheSitesOwnAddressIsNotAnAdminSoItMeansTheAdminOnIt(): void
    {
        // What the setup screen saved until 1.0.0-beta.51: the origin, without /admin.
        self::assertSame('https://example.com/admin', self::resolve('https://example.com'));
        self::assertSame('https://example.com/admin', self::resolve('https://EXAMPLE.com/'));
        // Known by the deploy's address too, when there is no request to compare with.
        self::assertSame('https://example.com/admin', self::resolve('https://example.com', null));
        // Another host's root is not ours to second-guess.
        self::assertSame('https://admin.example.com', self::resolve('https://admin.example.com'));
    }

    public function testASiteThatBringsItsOwnAdminIsNeverGuessedAt(): void
    {
        // thallo.admin.enabled = false: nothing is served at /admin.
        self::assertNull(self::resolve('', bundled: false));
        self::assertSame('https://example.com', self::resolve('https://example.com', bundled: false));
    }

    public function testNothingIsBuiltFromAnAddressThatIsNotOne(): void
    {
        self::assertNull(self::resolve('', null, ''));
        self::assertNull(self::resolve('', null, 'not a url'));
        self::assertNull(self::resolve('', 'javascript:alert(1)', ''));
    }
}
