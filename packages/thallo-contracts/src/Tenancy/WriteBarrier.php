<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

/**
 * Raw-PDO write sites call assertWritable() to honor the retrofit barrier (builder writes are covered
 * automatically by the query interceptor). Throws when the retrofit is in progress.
 */
interface WriteBarrier
{
    public function assertWritable(): void;
}
