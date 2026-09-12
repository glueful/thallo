<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap must take the environment from env(), never from the $_ENV array alone: under
 * PHP's default variables_order that array does not carry an APP_ENV the process itself
 * exports (a CI job, a container), and Dotenv's immutable loader leaves such a key alone. CI
 * booted `php glueful extensions:cache` as "development" that way, skipped the testing
 * override of the enabled extensions, and handed the test run a provider cache without the
 * commerce extension. Checked on both copies; SkeletonParityTest keeps them identical.
 */
final class BootstrapEnvironmentTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function bootstraps(): iterable
    {
        $root = dirname(__DIR__, 3);
        yield 'dev repository' => [$root . '/bootstrap/app.php'];
        yield 'skeleton' => [$root . '/skeleton/bootstrap/app.php'];
    }

    /** @dataProvider bootstraps */
    public function testTheEnvironmentIsReadThroughTheEnvHelper(string $file): void
    {
        $source = (string) file_get_contents($file);

        self::assertStringNotContainsString("\$_ENV['APP_ENV']", $source, "{$file} reads the \$_ENV array directly");
        self::assertStringContainsString("->withEnvironment(env('APP_ENV', 'development'))", $source);
    }
}
