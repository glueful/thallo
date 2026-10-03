<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallResult;
use Glueful\Installer\InstallStep;
use Thallo\Core\Setup\ProvisionInstaller;

/**
 * Stands in for the framework Installer in provision tests: no .env is written and no real install
 * runs. Its callback stands in for the migrations; it can then fail (a failed install) or throw (a
 * crash).
 */
final class FakeProvisionInstaller implements ProvisionInstaller
{
    public int $calls = 0;

    public function __construct(
        private readonly ?\Closure $migrations = null,
        private readonly string $outcome = 'ok', // ok | failed | crash
    ) {
    }

    public function run(string $basePath, ApplicationContext $context, InstallOptions $options): InstallResult
    {
        $this->calls++;
        if ($this->migrations !== null) {
            ($this->migrations)();
        }
        if ($this->outcome === 'crash') {
            throw new \RuntimeException('the process died after its migrations');
        }
        return InstallResult::from([
            $this->outcome === 'ok'
                ? new InstallStep('migrations', InstallStep::OK, 'Migrated (fake).')
                : new InstallStep('migrations', InstallStep::FAILED, 'Failed (fake).'),
        ]);
    }
}
