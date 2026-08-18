<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Capabilities\ExtensionCapabilityAvailabilityResolver;
use Glueful\Extensions\Schema\ReadinessState;

/** Scripted collaborators: candidates / enabled providers / readiness are plain data here. */
final class ScriptedAvailabilityResolver extends ExtensionCapabilityAvailabilityResolver
{
    /** @var array<string, object{provider: string}> */
    public array $installed = [];
    /** @var list<string> */
    public array $enabled = [];
    /** @var array<string, array<string, array{state: ReadinessState, reasons: list<string>}>> */
    public array $readiness = [];
    public ?\Throwable $bootFailure = null;

    protected function candidates(): array
    {
        return $this->installed;
    }

    protected function enabledProviders(): array
    {
        return $this->enabled;
    }

    protected function packageReadiness(string $package): array
    {
        if ($this->bootFailure !== null) {
            throw $this->bootFailure;
        }
        return $this->readiness[$package] ?? [];
    }
}
