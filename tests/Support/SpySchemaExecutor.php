<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;

/** Records executor delegation and plays back a configurable terminal operation (or throws). */
final class SpySchemaExecutor extends ExtensionSchemaExecutor
{
    /** @var list<array{op: string, package: string, actor: string}> */
    public array $calls = [];
    public ?\Throwable $throws = null;
    public string $status = ExtensionOperation::STATUS_SUCCEEDED;
    public ?string $failedMigration = null;
    public ?string $error = null;

    // phpcs:ignore
    public function __construct()
    {
        // Spy: none of the collaborators are needed.
    }

    public function enable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        return $this->respond('enable', $package, $actor);
    }

    public function disable(
        string $package,
        string $actor,
        bool $dryRun = false,
        bool $backup = false
    ): ExtensionOperation {
        return $this->respond('disable', $package, $actor);
    }

    public function migrateProtected(string $package, string $actor): ExtensionOperation
    {
        return $this->respond('protected_migrate', $package, $actor);
    }

    private function respond(string $op, string $package, string $actor): ExtensionOperation
    {
        $this->calls[] = ['op' => $op, 'package' => $package, 'actor' => $actor];
        if ($this->throws !== null) {
            throw $this->throws;
        }
        return new ExtensionOperation(
            7,
            $package,
            $op,
            'terminal',
            $this->status,
            $actor,
            $this->failedMigration,
            $this->error
        );
    }
}
