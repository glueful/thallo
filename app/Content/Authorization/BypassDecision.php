<?php

declare(strict_types=1);

namespace App\Content\Authorization;

final class BypassDecision
{
    public function __construct(
        public readonly bool $granted,
        public readonly ?string $mode,
        public readonly string $reason,
    ) {
    }
}
