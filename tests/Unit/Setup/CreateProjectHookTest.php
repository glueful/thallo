<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * `.env.example` ships in production mode, and production boot refuses live extension discovery:
 * it needs the compiled extension cache. A fresh `composer create-project` therefore has to build
 * that cache right after copying .env, or the very first `php glueful` invocation fails.
 */
final class CreateProjectHookTest extends TestCase
{
    public function testCreateProjectBuildsTheExtensionCacheAfterCopyingEnv(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        $hook = $composer['scripts']['post-create-project-cmd'] ?? [];

        self::assertIsArray($hook);
        $indexOf = static fn (string $needle): int|false => array_search(
            true,
            array_map(static fn ($s): bool => str_contains((string) $s, $needle), $hook),
            true,
        );
        $copy = $indexOf('.env.example');
        $cache = $indexOf('extensions:cache');

        self::assertNotFalse($copy, 'the hook must still create .env from .env.example');
        self::assertNotFalse($cache, 'the hook must build the extension cache');
        self::assertGreaterThan($copy, $cache, 'the cache build must run AFTER .env exists');
    }
}
