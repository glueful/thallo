<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `PDOStatement` subclass that counts every `execute()` call — installed via
 * `PDO::ATTR_STATEMENT_CLASS` on the suite connection's PDO (works on pgsql
 * exactly as on SQLite) to give query-count guard tests a ground-truth
 * statement count without the framework's debug-gated `QueryLogger`.
 *
 * Unlike the Commerce origin of this pattern (fresh in-memory SQLite per
 * test), thallo's suite shares ONE process-wide pgsql connection, so the
 * counter is cumulative: run any warm-up traffic first, SNAPSHOT `$count`,
 * then assert on the DELTA — never reset-to-zero-and-assert-absolute.
 * Restore `PDO::ATTR_STATEMENT_CLASS` to `\PDOStatement::class` in tearDown.
 */
final class CountingPdoStatement extends \PDOStatement
{
    public static int $count = 0;

    public function execute(?array $params = null): bool
    {
        self::$count++;

        return parent::execute($params);
    }
}
