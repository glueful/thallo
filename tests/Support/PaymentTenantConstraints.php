<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Database\Connection;
use Thallo\Core\Payments\Tenancy\PaymentTables;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;

/**
 * Drops the CHECK an adoption adds to payments' tables: the rest of the suite runs as the single
 * store and writes tenant '' rows, so no test may leave the constraint behind.
 */
final class PaymentTenantConstraints
{
    public static function drop(Connection $connection): void
    {
        $pdo = $connection->getPDO();
        foreach (PaymentTables::workspaceOwned() as $table) {
            if ($pdo->query("SELECT to_regclass('{$table}') IS NOT NULL")->fetchColumn() === true) {
                $pdo->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS " . PaymentTenancyAdoption::CONSTRAINT);
            }
        }
    }
}
