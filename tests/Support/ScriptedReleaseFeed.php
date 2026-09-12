<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Core\Updates\ReleaseFeed;

/** A release feed that answers with a scripted list, or throws, and records every call. */
final class ScriptedReleaseFeed implements ReleaseFeed
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<string>|\Throwable $answer */
    public function __construct(private array|\Throwable $answer)
    {
    }

    public function versions(string $package): array
    {
        $this->calls[] = $package;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
