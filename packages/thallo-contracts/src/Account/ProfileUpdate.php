<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/** The outcome of saving a profile: saved, or the field errors that stopped it. */
final class ProfileUpdate
{
    /** @param array<string,string> $errors field => message */
    public function __construct(
        public readonly bool $saved,
        public readonly array $errors = [],
    ) {
    }
}
