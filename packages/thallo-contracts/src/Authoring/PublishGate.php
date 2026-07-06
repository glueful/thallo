<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * Core publish asks every registered gate "may I publish this draft?" before any write.
 * Gates register under the `thallo.publish_gate` container tag; deterministic tag-priority
 * order; the first thrown PublishBlocked stops the publish; unexpected exceptions bubble.
 * No gates registered → publish behaves exactly as before this seam existed.
 */
interface PublishGate
{
    /** @throws PublishBlocked when the draft may not be published. */
    public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void;
}
