<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\ExtensionActivation;

final class ExtensionActivationTest extends AppTestCase
{
    public function testInstalledDevelopmentCandidateIsDetectedButNotActivated(): void
    {
        $activation = $this->container()->get(ExtensionActivation::class);

        self::assertTrue($activation->isInstalled());
        self::assertFalse($activation->isActivated());
    }

    public function testInstallOfAlreadyInstalledPackageIsANonBlockingSkip(): void
    {
        $result = $this->container()->get(ExtensionActivation::class)->install();

        self::assertSame('installed', $result['status']);
        self::assertFalse($result['blocked']);
        self::assertNull($result['reason']);
    }
}
