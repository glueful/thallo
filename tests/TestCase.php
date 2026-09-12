<?php

declare(strict_types=1);

namespace Thallo\Core\Tests;

use Thallo\Core\Tests\Support\TestApplication;
use Glueful\Testing\TestCase as FrameworkTestCase;

abstract class TestCase extends FrameworkTestCase
{
    protected function createApplication(): \Glueful\Application
    {
        // Reuse the single process-shared boot. Booting the framework a second time in the
        // same process would consume the framework's one-shot extension-route loaders and leave
        // the other suites' router without those routes. See TestApplication for details.
        return TestApplication::instance();
    }
}
