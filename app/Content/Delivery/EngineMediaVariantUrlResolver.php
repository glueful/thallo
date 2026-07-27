<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;

/**
 * Batch variant resolver over the SAME blob-servability query as
 * {@see EngineMediaUrlResolver} (storefront-performance spec §3): one lookup yields the
 * base src, the MIME gate, and every ?width= candidate.
 *
 * `resizingCapable` is the runtime half of the capability gate (the processor binding +
 * `uploads.image_processing.enabled`, computed in the provider factory — a compiled
 * container cannot make REGISTRATION conditional on runtime config): incapable means a
 * valid image degrades to {src, srcset: null} — never fabricated ?width= URLs, and null
 * stays reserved for invalid media, exactly the contract's three outcomes.
 */
final class EngineMediaVariantUrlResolver implements MediaVariantUrlResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
        private readonly int $maxWidth,
        private readonly bool $resizingCapable,
    ) {
    }

    public function variants(string $uuid, array $widths): ?array
    {
        if (!$this->uploadsEnabled || !EngineMediaUrlResolver::anonymousRetrievalAllowed($this->accessMode)) {
            return null;
        }
        $blob = $this->db->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        if ($blob === null || !str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/')) {
            return null;
        }

        $src = rtrim($this->blobUrlBase, '/') . '/' . $uuid;
        if (!$this->resizingCapable) {
            return ['src' => $src, 'srcset' => null];
        }

        $candidates = [];
        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width > 0 && $width <= $this->maxWidth) {
                $candidates[] = "{$src}?width={$width} {$width}w";
            }
        }
        return ['src' => $src, 'srcset' => $candidates === [] ? null : implode(', ', $candidates)];
    }
}
