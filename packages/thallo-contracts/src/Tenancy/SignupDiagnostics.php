<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

interface SignupDiagnostics
{
    /** @return array{status:string,detail:mixed} */
    public function check(): array;
}
