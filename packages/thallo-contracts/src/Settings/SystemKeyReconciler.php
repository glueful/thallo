<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/** Moves legacy system settings out of tenant-owned storage before schema widening. */
interface SystemKeyReconciler
{
    /** @return list<string> keys reconciled and removed from legacy storage */
    public function reconcile(): array;
}
