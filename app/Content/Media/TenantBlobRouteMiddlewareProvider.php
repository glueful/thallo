<?php

declare(strict_types=1);

namespace App\Content\Media;

use Glueful\Uploader\Contracts\BlobRouteAction;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;

final class TenantBlobRouteMiddlewareProvider implements BlobRouteMiddlewareProvider
{
    public function middlewareFor(BlobRouteAction $action): array
    {
        return $action === BlobRouteAction::VIEW
            ? ['tenant_profile:public,soft']
            : ['tenant_profile:admin'];
    }
}
