<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Thallo\Contracts\Settings\SystemChannel;

/**
 * A fully-functional in-memory SystemChannel (it really stores/reads/forgets) that ALSO
 * records every call so a test can assert the EXACT keys SettingsStore routed to the
 * system channel — without touching the real `thallo_system_flags` table or its SystemFlags
 * cache. Used to test SettingsStore's isSystem()-based routing in isolation.
 */
final class RecordingSystemChannel implements SystemChannel
{
    /** @var array<string,string> */
    private array $values = [];

    /** @var list<string> every get() call, in order (including repeats) */
    public array $getCalls = [];

    /** @var array<string,string> every put() call, key => last value written */
    public array $puts = [];

    /** @var list<string> every forget() call, in order */
    public array $forgetCalls = [];

    public function get(string $key): ?string
    {
        $this->getCalls[] = $key;
        return $this->values[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $this->puts[$key] = $value;
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->forgetCalls[] = $key;
        unset($this->values[$key]);
    }
}
