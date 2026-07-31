<?php

declare(strict_types=1);

namespace Thallo\Account\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PUT /v1/admin/settings/accounts`
 * ({@see \Thallo\Account\Http\AccountSettingsController::update()}).
 *
 * A full replace of the redirect pair: a blank (or absent) field CLEARS that override so the fixed
 * default shows through; a non-blank value is validated through {@see \Thallo\Account\
 * AccountReturnPath} in the controller before it is stored.
 */
final class UpdateAccountSettingsData implements RequestData
{
    public function __construct(
        /** Post-login redirect override; blank clears it (the fixed `/account` default shows through). */
        #[Rule('string')]
        public readonly ?string $after_login = null,
        /** Post-logout redirect override; blank clears it (the fixed `/account/login` default shows through). */
        #[Rule('string')]
        public readonly ?string $after_logout = null,
    ) {
    }
}
