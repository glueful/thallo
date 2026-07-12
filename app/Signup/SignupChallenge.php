<?php

declare(strict_types=1);

namespace App\Signup;

use Symfony\Component\HttpFoundation\Request;

interface SignupChallenge
{
    public function validate(Request $request): bool;
}
