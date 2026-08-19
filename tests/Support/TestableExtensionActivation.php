<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Thallo\Tenancy\Enablement\ExtensionActivation;

/** Executor-seam override: the spy plays back terminal operations or throws. */
final class TestableExtensionActivation extends ExtensionActivation
{
    public ?SpySchemaExecutor $spy = null;

    protected function executor(): ExtensionSchemaExecutor
    {
        return $this->spy ?? parent::executor();
    }
}
