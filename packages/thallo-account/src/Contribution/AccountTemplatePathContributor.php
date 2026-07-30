<?php

declare(strict_types=1);

namespace Thallo\Account\Contribution;

use Thallo\Render\Contribution\TemplatePathContributor;

/**
 * Contributes `packages/thallo-account/templates/` into Render's theme resolution chain, so the
 * `account/*.twig` pages resolve BETWEEN the active app theme (which may override an account
 * template) and the render pack's own default fallback. Registered INSIDE the `thallo.accounts`
 * capability gate (see {@see \Thallo\Account\AccountServiceProvider::boot()}): with the capability
 * off these templates must not exist in the Twig loader, exactly mirroring the shop pack's
 * capability-boundary pin.
 */
final class AccountTemplatePathContributor implements TemplatePathContributor
{
    public function contributorId(): string
    {
        return 'thallo-account.templates';
    }

    public function priority(): int
    {
        return 100;
    }

    /** @return list<string> */
    public function templatePaths(): array
    {
        // src/Contribution/ -> package root -> /templates
        return [dirname(__DIR__, 2) . '/templates'];
    }
}
