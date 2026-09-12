<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Workflow;

use Thallo\Core\Tests\Integration\Workflow\Concerns\GrantsPermissions;
use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Workflow\Http\Controllers\WorkflowController;
use Thallo\Workflow\WorkflowService;

/**
 * The review overview tells the admin whether the REQUESTING actor holds workflow.bypass for
 * the locale, so the editor can drop "Submit for review" for someone who publishes directly.
 */
final class WorkflowOverviewBypassTest extends AppTestCase
{
    use GrantsPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    public function testOverviewReportsWhetherTheActorHoldsBypass(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $admin = 'byp' . substr(hash('sha256', __FUNCTION__), 0, 9);
        $this->grantPermission($admin, 'workflow.bypass');

        self::assertTrue($wf->overview('entryovw0001', 'en', $admin)['can_bypass']);
        self::assertFalse($wf->overview('entryovw0001', 'en', 'nobody000001')['can_bypass']);
        self::assertFalse($wf->overview('entryovw0001', 'en', null)['can_bypass']);
    }

    public function testShowReportsTheRequestingActorsBypass(): void
    {
        $admin = 'byp' . substr(hash('sha256', __FUNCTION__), 0, 9);
        $this->grantPermission($admin, 'workflow.bypass');
        $request = Request::create('/workflow/entries/entryovw0002/en');
        $request->attributes->set('user', ['uuid' => $admin]);

        $response = $this->container()->get(WorkflowController::class)->show($request, 'entryovw0002', 'en');
        $data = (array) json_decode((string) $response->getContent(), true);

        self::assertSame('draft', $data['data']['state']);
        self::assertTrue($data['data']['can_bypass']);
    }
}
