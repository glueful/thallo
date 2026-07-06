<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Content\Services\PublishService;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Integration\Workflow\Concerns\GrantsPermissions;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Authoring\PublishBlocked;
use Thallo\Contracts\Capability\Capability;
use Thallo\Workflow\WorkflowPublishGate;
use Thallo\Workflow\WorkflowService;
use Thallo\Workflow\WorkflowStateRepository;

final class WorkflowPublishGateTest extends AppTestCase
{
    use GrantsPermissions;
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    public function testUnapprovedPublishIsBlocked(): void
    {
        // The seed actor holds workflow.bypass suite-wide (AppTestCase bootstrap) so fixture
        // publishes pass the gate; the blocking assertion uses a bypass-less actor.
        $entry = $this->seedBilingualPublishedEntry();
        try {
            $this->container()->get(PublishService::class)->publish($entry, 'en', 'nobody000001');
            self::fail('expected PublishBlocked');
        } catch (PublishBlocked $e) {
            self::assertSame('draft', $e->state);
            self::assertStringContainsString('approved review', $e->reason);
        }
    }

    public function testApprovedPublishSucceedsAndBypassWorks(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $wf = $this->container()->get(WorkflowService::class);

        // approved → publishes (actor has no bypass)
        $wf->submit($entry, 'en', 'author000001', null);
        $wf->approve($entry, 'en', 'review000001', null);
        $version = $this->container()->get(PublishService::class)->publish($entry, 'en', 'author000001');
        self::assertNotSame('', $version);

        // bypass holder → publishes from bare draft
        $admin = 'byp' . substr(hash('sha256', __FUNCTION__), 0, 9);
        $this->grantPermission($admin, 'workflow.bypass');
        $version = $this->container()->get(PublishService::class)->publish($entry, 'en', $admin);
        self::assertNotSame('', $version);
    }

    public function testGateAllowsEverythingWhenCapabilityDisabled(): void
    {
        // Simulate the switchboard-disabled capability: the gate must short-circuit (tags are
        // compile-time, so it is collected even when disabled — the check lives inside).
        $registry = new DefaultCapabilityRegistry(['lemma.workflow' => false]);
        $registry->register(new Capability('lemma.workflow'));
        $gate = new WorkflowPublishGate(
            $registry,
            $this->container()->get(WorkflowStateRepository::class),
            null,
        );
        $gate->assertCanPublish('anyentry0001', 'en', null); // must not throw
        $this->addToAssertionCount(1);
    }
}
