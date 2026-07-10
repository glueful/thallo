<?php

declare(strict_types=1);

namespace App\Content\Starter;

enum StarterApplyResult
{
    case Applied;
    case SkippedCollision;
}
