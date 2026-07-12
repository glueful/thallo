<?php

declare(strict_types=1);

namespace App\Signup;

use Symfony\Component\HttpFoundation\Request;

final class NullSignupChallenge implements SignupChallenge
{
    public function validate(Request $request): bool
    {
        return true;
    }
}
