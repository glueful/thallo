<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

final class CapabilityRegistrationTest extends TestCase
{
    public function testProviderFqcnDeclaredInPackComposer(): void
    {
        $path = dirname(__DIR__, 3) . '/packages/thallo-search/composer.json';
        $composer = json_decode((string) file_get_contents($path), true);
        self::assertSame(
            'Thallo\\Search\\SearchServiceProvider',
            $composer['extra']['glueful']['provider'] ?? null,
        );
    }
}
