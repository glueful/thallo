<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Providers\CoreServiceProvider;

/** The product's tree is core/; the repo root's app/ is the operator's. */
final class CoreLayoutTest extends TestCase
{
    public function testTheApplicationLivesUnderCore(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertDirectoryExists("$root/core/src/Providers");
        self::assertDirectoryDoesNotExist("$root/app/Providers", 'app/ is the operator\'s directory');
        self::assertSame("$root/core", CoreServiceProvider::corePath());
        self::assertSame("$root/core/routes/admin.php", CoreServiceProvider::corePath('routes/admin.php'));
    }
}
