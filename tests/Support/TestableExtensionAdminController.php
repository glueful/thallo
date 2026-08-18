<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\Controllers\ExtensionAdminController;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\ReadinessState;

/** Seam overrides for the extensions admin surface: executor, host writability, schema inputs. */
final class TestableExtensionAdminController extends ExtensionAdminController
{
    public ?SpySchemaExecutor $executor = null;
    /** @var array{reason: string, detail: string}|null */
    public ?array $hostRefusal = null;
    /** @var array<string, bool> */
    public array $declaredMap = [];
    /** @var array<string, array<string, array{state: ReadinessState, reasons: list<string>}>> */
    public array $readinessMap = [];

    protected function schemaExecutor(): ExtensionSchemaExecutor
    {
        return $this->executor ?? parent::schemaExecutor();
    }

    protected function hostToggleRefusal(): ?array
    {
        return $this->hostRefusal;
    }

    protected function packageIsDeclared(string $package): bool
    {
        return $this->declaredMap[$package] ?? parent::packageIsDeclared($package);
    }

    protected function packageReadiness(string $package): array
    {
        return $this->readinessMap[$package] ?? parent::packageReadiness($package);
    }
}
