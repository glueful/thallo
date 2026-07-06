<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * A publish gate refused the publish. `reason` is the human-readable sentence shown by
 * clients; `state` is the workflow state when known (drives UI badges without message
 * parsing). Maps to HTTP 409 (valid request, wrong workflow state).
 */
final class PublishBlocked extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $state = null,
    ) {
        parent::__construct($reason);
    }
}
