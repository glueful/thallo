<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updates\InstalledVersion;

/**
 * What Composer installed, as the update notice's "current". In this repository thallo-core is a
 * symlinked path package (the development checkout), which reports development rather than a
 * version; a package Composer never installed reports the same.
 */
final class InstalledVersionTest extends TestCase
{
    public function testTheDevelopmentCheckoutIsRecognised(): void
    {
        $installed = InstalledVersion::of('glueful/thallo-core');

        self::assertTrue($installed['development']);
    }

    public function testAPackageThatIsNotInstalledReportsNoVersion(): void
    {
        self::assertSame(['version' => null, 'development' => true], InstalledVersion::of('nobody/nothing'));
    }

    public function testAnInstalledDependencyReportsItsVersionWithoutTheLeadingV(): void
    {
        $installed = InstalledVersion::of('glueful/framework');

        self::assertFalse($installed['development']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $installed['version']);
    }
}
