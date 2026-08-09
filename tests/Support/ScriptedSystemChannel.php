<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Thallo\Contracts\Settings\SystemChannel;

/**
 * A fully-functional in-memory {@see SystemChannel} that can be SCRIPTED to misbehave at an exact
 * point in a caller's read/write sequence — the only way to prove the platform-payments migration's
 * post-copy verification and compare-and-delete pruning actually hold under concurrent/corrupting
 * change, rather than merely being written down.
 *
 * Two scripting hooks, both deliberately narrow:
 *
 *  - {@see tamperOnPut()} — rewrites the bytes ACTUALLY stored for a key. Models a copy that lands
 *    corrupted (rotated key, truncating column, hostile writer): the caller wrote a valid
 *    ciphertext, but what is now at rest will not decrypt. Verification must catch this.
 *  - {@see fireOnFirstGetAfterPut()} — arms on the first `put()` of $armKey and then fires ONCE, on
 *    the next `get()` of $hookKey, BEFORE that read returns. Arming on a put (in practice the
 *    migration marker) is what makes the hook land in a specific PHASE of a run rather than at a
 *    brittle "Nth call" offset: the migration command writes the marker as its last write, so a
 *    hook armed on the marker fires inside the PRUNE pass — i.e. between the row the pruner read
 *    for verification and the compare-and-delete it issues from it. That is exactly the
 *    concurrent-change window a compare-and-delete exists to lose.
 *
 * Unscripted, this behaves as an ordinary in-memory store. {@see $putOrder} / {@see $getOrder}
 * record call ORDER (unlike {@see RecordingSystemChannel}'s key => last-value map), which is what
 * "the marker is written LAST" is asserted against.
 */
final class ScriptedSystemChannel implements SystemChannel
{
    /** @var array<string,string> */
    private array $values = [];

    /** @var list<string> every put() key, in order */
    public array $putOrder = [];

    /** @var list<string> every get() key, in order */
    public array $getOrder = [];

    /** @var array<string,\Closure(string):string> key => rewrite applied to the stored bytes */
    private array $tampering = [];

    /** @var array<string,int> key => remaining get() calls that must throw */
    private array $throwingGets = [];

    private ?string $armKey = null;
    private ?string $hookKey = null;
    private ?\Closure $hook = null;
    private bool $armed = false;
    private bool $fired = false;

    /** Store $key's value as $rewrite() returns it — the caller still believes it wrote its own bytes. */
    public function tamperOnPut(string $key, \Closure $rewrite): void
    {
        $this->tampering[$key] = $rewrite;
    }

    /** Arm on the first put() of $armKey; fire $hook exactly once, on the next get() of $hookKey. */
    public function fireOnFirstGetAfterPut(string $armKey, string $hookKey, \Closure $hook): void
    {
        $this->armKey = $armKey;
        $this->hookKey = $hookKey;
        $this->hook = $hook;
    }

    /**
     * Make the next $times get($key) calls THROW — a transient channel fault (deadlock, timeout)
     * rather than a permanent outage: after the budget is spent the key reads normally again, which
     * is what makes "I could not read it once" different from "it is not there".
     */
    public function throwOnGetOf(string $key, int $times = 1): void
    {
        $this->throwingGets[$key] = $times;
    }

    /** Seed a value without recording it as a caller-issued put(). */
    public function seed(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function get(string $key): ?string
    {
        $this->getOrder[] = $key;

        if (($this->throwingGets[$key] ?? 0) > 0) {
            $this->throwingGets[$key]--;
            throw new \RuntimeException('scripted transient system-channel fault');
        }

        if ($this->armed && !$this->fired && $this->hook !== null && $key === $this->hookKey) {
            $this->fired = true;
            ($this->hook)();
        }

        return $this->values[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $this->putOrder[] = $key;
        $rewrite = $this->tampering[$key] ?? null;
        $this->values[$key] = $rewrite === null ? $value : $rewrite($value);

        if ($this->armKey !== null && $key === $this->armKey) {
            $this->armed = true;
        }
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }
}
