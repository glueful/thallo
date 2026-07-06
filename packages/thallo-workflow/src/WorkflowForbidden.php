<?php

declare(strict_types=1);

namespace Thallo\Workflow;

/** The actor may not perform this transition (self-review, foreign withdraw). Maps to 403. */
final class WorkflowForbidden extends \RuntimeException
{
}
