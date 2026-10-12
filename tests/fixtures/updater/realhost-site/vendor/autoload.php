<?php

declare(strict_types=1);

// The stub site's autoloader: just enough for the staged-autoloader probe (Stager::verify).

namespace Thallo\Core\Updates;

if (!class_exists(UpdateChecker::class, false)) {
    final class UpdateChecker
    {
    }
}
