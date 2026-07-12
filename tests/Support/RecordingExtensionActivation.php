<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Thallo\Tenancy\Enablement\ExtensionActivationContract;

final class RecordingExtensionActivation implements ExtensionActivationContract
{
    public int $activateCalls = 0;
    public int $deactivateCalls = 0;

    public function __construct(
        private bool $activated = false,
        public bool $failNextActivation = false,
        public bool $failNextDeactivation = false,
    ) {
    }

    public function isInstalled(): bool
    {
        return true;
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }

    public function install(): array
    {
        return [
            'status' => 'installed',
            'blocked' => false,
            'reason' => null,
            'cli' => null,
            'output' => '',
        ];
    }

    public function activate(): void
    {
        $this->activateCalls++;
        if ($this->failNextActivation) {
            $this->failNextActivation = false;
            throw new \RuntimeException('activation failed');
        }
        $this->activated = true;
    }

    public function deactivate(): void
    {
        $this->deactivateCalls++;
        if ($this->failNextDeactivation) {
            $this->failNextDeactivation = false;
            throw new \RuntimeException('deactivation failed');
        }
        $this->activated = false;
    }

    public function migrate(): array
    {
        return ['applied' => [], 'failed' => []];
    }
}
