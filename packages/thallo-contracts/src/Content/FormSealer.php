<?php

declare(strict_types=1);

namespace Thallo\Contracts\Content;

/**
 * Seals a `form` block into an encrypted+authenticated descriptor token (form-block
 * spec §4). The render pack calls describe() while rendering; the submit endpoint
 * calls open(). Cross-pack seam: declared here, implemented by the app.
 */
interface FormSealer
{
    /**
     * Derive + seal a `form` block in ONE pass, returning a SealedForm {token, descriptor}
     * so the renderer reads fields/honeypot/key from the SAME result — never re-opening the
     * encrypted token in the render path. Null when un-routable/underivable (disabled notice).
     *
     * Returns a SealedForm value object (app VO; contracts stays app-free).
     *
     * @param array<string,mixed> $block  The block instance {id,type,data}.
     * @param array<string,mixed>|null $entry
     */
    public function describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug): ?object;

    /** Open a token at submit time. Null when tampered, malformed, or expired. Returns a FormDescriptor. */
    public function open(string $token): ?object;
}
