<?php

declare(strict_types=1);

namespace Thallo\Account\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the account-settings show/update responses
 * ({@see \Thallo\Account\Http\AccountSettingsController}): the fixed, allowlisted inventory of
 * themed account pages, the two current redirect overrides (null when no override is stored), and
 * curated per-field redirect suggestions.
 */
final class AccountSettingsResultData implements ResponseData
{
    public function __construct(
        /** @var list<array{label: string, path: string}> */
        public readonly array $pages,
        public readonly ?string $after_login,
        public readonly ?string $after_logout,
        /**
         * Convenience redirect targets per field.
         * @var array{after_login: list<array{label: string, path: string}>,
         *   after_logout: list<array{label: string, path: string}>}
         */
        public readonly array $suggestions,
    ) {
    }
}
