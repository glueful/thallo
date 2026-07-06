<?php

declare(strict_types=1);

namespace Thallo\Workflow;

use Thallo\Contracts\Authoring\PublishBlocked;
use Thallo\Contracts\Authoring\PublishGate;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Glueful\Permissions\PermissionManager;

/**
 * The workflow pack's publish gate: allow when the review state is `approved`, or when the
 * actor holds `workflow.bypass`; otherwise throw PublishBlocked (409).
 *
 * The capability check lives HERE (not in registration): container tags come from the
 * compile-time services() map, so this gate is collected by PublishService even when
 * lemma.workflow is disabled — disabled must mean "publish behaves as current core".
 */
final class WorkflowPublishGate implements PublishGate
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly WorkflowStateRepository $states,
        private readonly ?PermissionManager $permissions,
    ) {
    }

    public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
    {
        if (!$this->capabilities->isEnabled('thallo.workflow')) {
            return;
        }
        $state = $this->states->stateOf($entryUuid, $locale);
        if ($state === 'approved') {
            return;
        }
        // Resource mirrors RequirePermission::resourceFor() for locale routes. Evaluated
        // at publish time — a scheduled publish carries the schedule's stored created_by, so
        // revoking a bypass before firing fails the schedule (fail-safe).
        if (
            $actorUuid !== null
            && $this->permissions !== null
            && $this->permissions->can($actorUuid, 'workflow.bypass', "locale:{$locale}", [])
        ) {
            return;
        }
        throw new PublishBlocked(
            "Publishing requires an approved review (current state: {$state}).",
            $state,
        );
    }
}
