<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Account\Assets\AccountAssetMap;

/**
 * Serves the pack's fingerprinted static assets through ONE route (`/_account/assets/{file}`),
 * mirroring {@see \Thallo\Commerce\Http\Shop\ShopAssetController}:
 *
 *  - a LOGICAL name (`account.js`) 302-redirects to the current fingerprint and is explicitly
 *    `no-store`, so a page cached with the stable alias always resolves after a deploy;
 *  - the exact FINGERPRINTED name is served `immutable`;
 *  - anything else — a stale/invented fingerprint, a traversal attempt — is a 404, never current
 *    bytes under an old hash (that would pin wrong content into an immutable cache entry).
 */
final class AccountAssetController
{
    public function __construct(private readonly AccountAssetMap $assets)
    {
    }

    public function serve(string $file): Response
    {
        $fingerprinted = $this->assets->fingerprintedName($file);
        if ($fingerprinted !== null) {
            // The alias itself is never cached: templates emit it, so a stale page must always be
            // redirected to the CURRENT fingerprint rather than pinned to yesterday's.
            return new RedirectResponse('/_account/assets/' . rawurlencode($fingerprinted), 302, [
                'Cache-Control' => 'no-store',
            ]);
        }

        $path = $this->assets->resolve($file);
        if ($path === null || !is_file($path)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::contentTypeFor($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private static function contentTypeFor(string $file): string
    {
        return str_ends_with($file, '.css')
            ? 'text/css; charset=UTF-8'
            : 'application/javascript; charset=UTF-8';
    }
}
