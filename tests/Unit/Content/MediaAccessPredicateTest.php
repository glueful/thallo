<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Delivery\EngineMediaUrlResolver;
use PHPUnit\Framework\TestCase;

final class MediaAccessPredicateTest extends TestCase
{
    public function testMirrorsTheFullRouteStackNotJustTheController(): void
    {
        // DENY: the route middleware attaches auth for ALL of these (spec §3).
        foreach (['private', true, 'true', 1] as $denied) {
            self::assertFalse(
                EngineMediaUrlResolver::anonymousRetrievalAllowed($denied),
                'expected denied for ' . var_export($denied, true),
            );
        }
        // ALLOW: anonymous retrieval modes.
        foreach (['upload_only', 'public', false, 'false'] as $allowed) {
            self::assertTrue(
                EngineMediaUrlResolver::anonymousRetrievalAllowed($allowed),
                'expected allowed for ' . var_export($allowed, true),
            );
        }
    }
}
