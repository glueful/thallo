<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\AppTestCase;

final class WorkflowMigrationSmokeTest extends AppTestCase
{
    public function testTablesExist(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (['workflow_review_states', 'workflow_transitions'] as $table) {
            self::assertNotNull(
                $pdo->query("SELECT to_regclass('public.{$table}')")->fetchColumn(),
                "{$table} exists after migrations",
            );
        }
    }

    public function testAdministratorHoldsWorkflowPermissions(): void
    {
        foreach (['workflow.review', 'workflow.bypass'] as $slug) {
            $granted = $this->connection()->getPDO()->query(
                "SELECT COUNT(*) FROM role_permissions rp
                   JOIN roles r ON r.uuid = rp.role_uuid
                   JOIN permissions p ON p.uuid = rp.permission_uuid
                  WHERE r.slug = 'administrator' AND p.slug = '{$slug}'"
            )->fetchColumn();
            self::assertSame(1, (int) $granted, "administrator holds {$slug}");
        }
    }
}
