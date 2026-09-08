<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * A migration is handed a schema builder whose connection is THE database being migrated. Seven
 * pack permission seeds opened their own `new Connection()` instead, which reads the live
 * environment — on a fresh create-project that is the sample's placeholder user, and provision
 * failed at migrate with "role your_database_user does not exist". No migration may open its
 * own connection; use `$schema->getConnection()`.
 */
final class MigrationsUseTheInjectedConnectionTest extends TestCase
{
    public function testNoMigrationOpensItsOwnConnection(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/migrations/*.php') ?: [],
            glob($root . '/packages/*/migrations/*.php') ?: [],
        );
        self::assertNotEmpty($files);

        $ownConnection = '/new\s+\\\\?(Glueful\\\\Database\\\\)?Connection\s*\(/';
        $offenders = [];
        foreach ($files as $file) {
            if (preg_match($ownConnection, (string) file_get_contents($file)) === 1) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, "migrations must use \$schema->getConnection(), not their own");
    }
}
