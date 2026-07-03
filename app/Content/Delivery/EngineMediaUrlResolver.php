<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Database\Connection;
use Glueful\Lemma\Contracts\Delivery\MediaUrlResolver;

/**
 * Blob-route parity resolver (starter-library spec §3): emits a URL ONLY when the
 * framework blob route would serve it anonymously. The access predicate mirrors the
 * FULL route stack — framework/routes/blobs.php attaches `auth` middleware for
 * uploads.access ∈ {true, 'true', 1} BEFORE UploadController::checkBlobAccess()'s
 * looser `!== 'private'` comparison runs — so URL emission and servability never
 * diverge. Scalars are injected by the provider factory; tests construct variants
 * directly (no config reboots).
 */
final class EngineMediaUrlResolver implements MediaUrlResolver
{
    public function __construct(
        private readonly Connection $db,
        /** api_prefix($context) . '/blobs' — host-relative (spec §3). */
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
    ) {
    }

    /** The route-stack anonymous-retrieval predicate (spec §3, pinned verbatim). */
    public static function anonymousRetrievalAllowed(mixed $access): bool
    {
        return $access !== 'private'
            && $access !== true
            && $access !== 'true'
            && $access !== 1;
    }

    public function url(string $uuid): ?string
    {
        if (!$this->uploadsEnabled || !self::anonymousRetrievalAllowed($this->accessMode)) {
            return null;
        }
        $blob = $this->db->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        return $blob === null ? null : rtrim($this->blobUrlBase, '/') . '/' . $uuid;
    }
}
