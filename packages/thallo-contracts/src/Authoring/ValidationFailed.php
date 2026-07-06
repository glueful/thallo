<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * Thrown by content validation/write when a payload fails the content type's schema. Lives in
 * contracts so packs can catch it without referencing the engine's exception class.
 */
interface ValidationFailed extends \Throwable
{
    /** @return array<string,string> field name => error message */
    public function errors(): array;
}
