<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Tests\Support\AppTestCase;

/** Every route file now lives under core/routes and is loaded by the provider, not discovered from the root. */
final class CoreRoutesTest extends AppTestCase
{
    public function testEveryCoreRouteFileIsRegistered(): void
    {
        self::assertNotNull($this->findRoute('GET', '/admin/config'), 'admin_spa.php');
        self::assertNotNull($this->findRoute('GET', '/v1/admin/block-types'), 'admin.php');
        self::assertNotNull($this->findRoute('GET', '/v1/content/{type}'), 'content.php');
        self::assertNotNull($this->findRoute('POST', '/_forms/submit'), 'forms.php');
        self::assertNotNull($this->findRoute('GET', '/v1/preview/{token}'), 'preview.php');
        self::assertNotNull($this->findRoute('POST', '/v1/signup/continue'), 'signup.php');
    }

    public function testTheRootRoutesDirectoryBelongsToTheOperator(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame([], glob("$root/routes/*.php"), 'no product route file may remain in routes/');
        self::assertFileExists("$root/core/routes/admin.php");
    }
}
