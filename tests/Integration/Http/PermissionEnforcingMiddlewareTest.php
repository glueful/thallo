<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Thallo enforces every permission as `content_permission:<slug>` route middleware, so the
 * framework's permissions:diff reported all of them unenforced. It counts the parameters of the
 * middleware named in permissions.enforcing_middleware; Thallo declares its own.
 */
final class PermissionEnforcingMiddlewareTest extends AppTestCase
{
    public function testThalloDeclaresItsPermissionMiddlewareToTheFramework(): void
    {
        self::assertContains(
            'content_permission',
            (array) config($this->appContext(), 'permissions.enforcing_middleware', [])
        );
    }
}
