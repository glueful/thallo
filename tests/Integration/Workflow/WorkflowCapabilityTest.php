<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class WorkflowCapabilityTest extends AppTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.workflow'),
            'thallo.workflow must be registered and enabled by default',
        );
    }

    public function testSelfReviewConfigDefaultsFalse(): void
    {
        self::assertFalse((bool) config($this->appContext(), 'workflow.allow_self_review', null));
    }
}
