<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\AppTestCase;
use Thallo\Workflow\IllegalTransition;
use Thallo\Workflow\WorkflowForbidden;
use Thallo\Workflow\WorkflowService;

final class WorkflowTransitionsTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    private function svc(): WorkflowService
    {
        return $this->container()->get(WorkflowService::class);
    }

    public function testHappyPathSubmitApprove(): void
    {
        $o = $this->svc()->submit('entryaaa0001', 'en', 'author000001', null);
        self::assertSame('in_review', $o['state']);
        self::assertSame('author000001', $o['submitted_by']);

        $o = $this->svc()->approve('entryaaa0001', 'en', 'review000001', 'lgtm');
        self::assertSame('approved', $o['state']);
        self::assertSame('review000001', $o['reviewed_by']);
        self::assertSame(
            ['submit', 'approve'],
            array_column(array_reverse($o['history']), 'action'),
        );
    }

    public function testRequestChangesThenResubmit(): void
    {
        $this->svc()->submit('entryaaa0002', 'en', 'author000001', null);
        $o = $this->svc()->requestChanges('entryaaa0002', 'en', 'review000001', 'fix the intro');
        self::assertSame('changes_requested', $o['state']);
        self::assertSame('fix the intro', $o['history'][0]['note']);

        $o = $this->svc()->submit('entryaaa0002', 'en', 'author000001', 'fixed');
        self::assertSame('in_review', $o['state']);
    }

    public function testIllegalTransitionsThrow(): void
    {
        try {
            $this->svc()->approve('entryaaa0003', 'en', 'review000001', null);
            self::fail('expected IllegalTransition');
        } catch (IllegalTransition $e) {
            self::assertSame('draft', $e->state);
        }
        $this->svc()->submit('entryaaa0003', 'en', 'author000001', null);
        $this->expectException(IllegalTransition::class);
        $this->svc()->submit('entryaaa0003', 'en', 'author000001', null);
    }

    public function testSelfReviewBlocked(): void
    {
        $this->svc()->submit('entryaaa0004', 'en', 'author000001', null);
        $this->expectException(WorkflowForbidden::class);
        $this->svc()->approve('entryaaa0004', 'en', 'author000001', null);
    }

    public function testWithdrawRules(): void
    {
        $this->svc()->submit('entryaaa0005', 'en', 'author000001', null);
        try {
            $this->svc()->withdraw('entryaaa0005', 'en', 'stranger0001', false);
            self::fail('expected WorkflowForbidden');
        } catch (WorkflowForbidden) {
            $this->addToAssertionCount(1);
        }
        $o = $this->svc()->withdraw('entryaaa0005', 'en', 'author000001', false);
        self::assertSame('draft', $o['state']);
        self::assertNull($o['submitted_by'], 'withdraw clears submission attribution');

        // A reviewer who is not the submitter may withdraw too.
        $this->svc()->submit('entryaaa0005', 'en', 'author000001', null);
        $o = $this->svc()->withdraw('entryaaa0005', 'en', 'review000001', true);
        self::assertSame('draft', $o['state']);
    }

    public function testEditInvalidation(): void
    {
        $this->svc()->submit('entryaaa0006', 'en', 'author000001', null);
        $this->svc()->invalidateOnEdit('entryaaa0006', 'en', 'author000001');
        self::assertSame('draft', $this->svc()->overview('entryaaa0006', 'en')['state']);

        // approved is invalidated too
        $this->svc()->submit('entryaaa0006', 'en', 'author000001', null);
        $this->svc()->approve('entryaaa0006', 'en', 'review000001', null);
        $this->svc()->invalidateOnEdit('entryaaa0006', 'en', 'author000001');
        self::assertSame('draft', $this->svc()->overview('entryaaa0006', 'en')['state']);

        // changes_requested SURVIVES edits (spec: submit is the only transition that clears it)
        $this->svc()->submit('entryaaa0006', 'en', 'author000001', null);
        $this->svc()->requestChanges('entryaaa0006', 'en', 'review000001', 'more');
        $this->svc()->invalidateOnEdit('entryaaa0006', 'en', 'author000001');
        self::assertSame('changes_requested', $this->svc()->overview('entryaaa0006', 'en')['state']);
    }

    public function testRecordPublishConsumesApprovalAndRecordsBypass(): void
    {
        // approved → published
        $this->svc()->submit('entryaaa0007', 'en', 'author000001', null);
        $this->svc()->approve('entryaaa0007', 'en', 'review000001', null);
        $this->svc()->recordPublish('entryaaa0007', 'en', 'admin0000001');
        $o = $this->svc()->overview('entryaaa0007', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published', $o['history'][0]['action']);

        // in_review → published_with_bypass
        $this->svc()->submit('entryaaa0008', 'en', 'author000001', null);
        $this->svc()->recordPublish('entryaaa0008', 'en', 'admin0000001');
        $o = $this->svc()->overview('entryaaa0008', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published_with_bypass', $o['history'][0]['action']);
    }
}
