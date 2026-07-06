<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\DTOs\Responses\CapabilityListData;
use Glueful\Http\Response;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Reports the ENABLED capabilities (installed packs not disabled by the switchboard) for the
 * admin SPA, which mounts only the modules whose required capability is reported here. Read-only.
 * Admin-contribution descriptors (nav/routes/settings/field-widgets) are added in Phase C.
 */
final class CapabilityAdminController
{
    public function __construct(private readonly CapabilityRegistry $capabilities)
    {
    }

    /** GET /v1/admin/capabilities */
    #[ApiOperation(
        summary: 'List enabled capabilities',
        description: 'Capabilities provided by installed packs and not disabled by the '
            . 'thallo.capabilities switchboard. Requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, schema: CapabilityListData::class, description: 'Enabled capabilities.')]
    public function index(): Response
    {
        $items = array_map(
            static fn (Capability $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'description' => $c->description,
                'requires' => $c->requires,
            ],
            $this->capabilities->enabled(),
        );

        return Response::success(['capabilities' => array_values($items)], 'Capabilities retrieved.');
    }
}
