<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * The entry uuid rendered at `/` (homepage-setting spec §0). SOURCE-AWARE by
 * contract: the implementation must return a runtime-mutable override (e.g. a
 * DB site setting) ONLY when it is currently resolvable to published public
 * content — an unresolvable override is logged and skipped so the deploy-time
 * fallback (env config) shows through. The renderer treats whatever this
 * returns exactly like deploy config: an unresolvable value is a LOUD config
 * error, which by construction can then only be env-sourced. Empty string =
 * no homepage entry (the standalone index renders).
 */
interface HomepageEntryProvider
{
    public function homepageEntry(): string;
}
