<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Extensions\ExtensionStateCoordinator;

/** Runs the sequence and records what `observe` sees before and after it. */
final class RecordingStateCoordinator implements ExtensionStateCoordinator
{
    /** @var \Closure(): array{bool, bool} */
    public \Closure $observe;
    /** @var list<array{array{bool, bool}, array{bool, bool}}> */
    public array $runs = [];

    public function within(callable $sequence): mixed
    {
        $before = ($this->observe)();
        $result = $sequence();
        $this->runs[] = [$before, ($this->observe)()];
        return $result;
    }
}
