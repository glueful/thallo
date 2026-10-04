<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * A short hash of every registered capability's evaluated enabled state, from the snapshot the
 * request renders with (search block spec §3.6). Cached pages are keyed by it, so a page rendered
 * under one set of features is never served under another.
 */
interface AvailabilityFingerprint
{
    /** 12 hex characters, stable for one evaluated state. */
    public function current(): string;
}
