<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofitHarnessTestCase;
use PDO;
use Thallo\Tenancy\Retrofit\RetrofitDdlFactory;
use Thallo\Tenancy\Retrofit\UniquenessPreflight;
use Thallo\Tenancy\Retrofit\UniquenessPreflightException;

/**
 * Exercises the enable-time uniqueness preflight against the NARROW throwaway DB.
 *
 * The clean case runs first, before any pollution; the interruption case then deliberately drops
 * `content_types`' narrow slug unique and inserts two rows sharing a slug — the state a crashed retrofit
 * could leave behind — and asserts the preflight now blocks. Polluting the throwaway DB is harmless: it
 * is dropped in teardown.
 */
final class UniquenessPreflightTest extends RetrofitHarnessTestCase
{
    private function preflight(): UniquenessPreflight
    {
        return $this->container()->get(UniquenessPreflight::class);
    }

    public function testCleanNarrowDatabaseHasNoViolations(): void
    {
        $report = $this->preflight()->check();

        self::assertFalse($report->hasViolations(), $report->summary());
        self::assertSame([], $report->violations());
    }

    public function testDuplicateBusinessKeyIsDetectedAfterNarrowUniqueDropped(): void
    {
        $pdo = $this->connection()->getPDO();
        $ddl = $this->container()->get(RetrofitDdlFactory::class)
            ->for((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        // Drop the narrow slug unique so duplicate slugs can be inserted (mirrors a mid-retrofit crash).
        foreach ($ddl->dropUniqueCandidates('content_types', 'content_types_slug_unique') as $statement) {
            $pdo->exec($statement);
        }

        $pdo->exec(
            "INSERT INTO content_types (uuid, slug, name, status, schema, schema_version, created_at)
             VALUES ('ctdup0000001', 'dup', 'A', 'active', '[]', 1, now()),
                    ('ctdup0000002', 'dup', 'B', 'active', '[]', 1, now())"
        );

        $report = $this->preflight()->check();

        self::assertTrue($report->hasViolations());
        $tables = array_column($report->violations(), 'table');
        self::assertContains('content_types', $tables);

        $violation = null;
        foreach ($report->violations() as $candidate) {
            if ($candidate['table'] === 'content_types') {
                $violation = $candidate;
                break;
            }
        }
        self::assertNotNull($violation);
        self::assertSame(['slug'], $violation['columns']);
        self::assertSame(1, $violation['groups']);

        $this->expectException(UniquenessPreflightException::class);
        $report->throwIfFailed();
    }
}
