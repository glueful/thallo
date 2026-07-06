<?php

declare(strict_types=1);

namespace Thallo\Workflow;

/** The requested transition is not legal from the current state. Maps to HTTP 409. */
final class IllegalTransition extends \RuntimeException
{
    public function __construct(string $message, public readonly string $state)
    {
        parent::__construct($message);
    }
}
