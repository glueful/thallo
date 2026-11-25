<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Core\Content\Jobs\DocsSyncRecoverJob;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Recovery (docs sync design §6.4): a run whose delivery was lost, or ran out of the queue's
 * retries, is dispatched again by the sync itself, within its own budget of five attempts. It runs
 * from the recurring task, so it needs nobody to open the card.
 */
final class DocsSyncRecoveryTest extends AppTestCase
{
    use DocsSyncTestHelpers;

    private RecordingQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->recordingQueue();
        $this->saveSource();
    }

    protected function tearDown(): void
    {
        $this->cleanDocsSync();
        parent::tearDown();
    }

    private function started(): string
    {
        $run = $this->service($this->queue)->start('commit', null)['id'];
        $this->queue->pushed = [];
        return $run;
    }

    private function recover(): void
    {
        $this->service($this->queue)->recover();
    }

    public function testAReservedRunNeverPushedIsRedispatchedAfterTwoMinutes(): void
    {
        $run = $this->started();
        $this->setRun($run, ['status' => 'reserved', 'last_dispatch_at' => null, 'created_at' => self::ago(30)]);
        $this->recover();
        self::assertSame([], $this->queue->pushed, 'too soon: the request may still be pushing');

        $this->setRun($run, ['created_at' => self::ago(150)]);
        $this->recover();

        self::assertCount(1, $this->queue->pushed);
        $expected = \Thallo\Core\Content\Jobs\DocsSyncJob::payload($run, $this->runRow($run)['workspace']);
        self::assertSame($expected, $this->queue->pushed[0]['data']);
        self::assertSame('dispatched', $this->runRow($run)['status']);
    }

    public function testRedispatchIsBoundedToOncePerTwoMinutes(): void
    {
        $run = $this->started();
        $this->setRun($run, ['status' => 'running', 'attempts' => 1, 'last_dispatch_at' => self::ago(150)]);
        $this->setState(['lease_token' => 'old', 'lease_at' => self::ago(200)]);

        $this->recover();
        $this->recover();

        self::assertCount(1, $this->queue->pushed);
    }

    public function testALiveLeaseIsLeftAloneEvenOnTheFifthAttempt(): void
    {
        $run = $this->started();
        $this->setRun($run, ['status' => 'running', 'attempts' => 5, 'last_dispatch_at' => self::ago(900)]);
        $this->setState(['lease_token' => 'live', 'lease_at' => self::ago(10)]);

        $this->recover();

        self::assertSame([], $this->queue->pushed);
        self::assertSame('running', $this->runRow($run)['status']);
        self::assertSame($run, $this->stateRow()['active_run']);
    }

    public function testAnExpiredFifthAttemptIsInterrupted(): void
    {
        $run = $this->started();
        $this->setRun($run, ['status' => 'running', 'attempts' => 5, 'created' => 3]);
        $this->setState(['lease_token' => 'dead', 'lease_at' => self::ago(200)]);

        $this->recover();

        $row = $this->runRow($run);
        self::assertSame('finished', $row['status']);
        self::assertSame('interrupted', $row['outcome']);
        self::assertSame(3, (int) $row['created'], 'what it wrote is kept');
        self::assertNull($this->stateRow()['active_run']);
        self::assertSame([], $this->queue->pushed);
    }

    public function testAnExpiredFifthAttemptWithCancelRequestedIsCancelled(): void
    {
        $run = $this->started();
        $this->setRun($run, ['status' => 'running', 'attempts' => 5, 'cancel_requested' => true]);
        $this->setState(['lease_token' => 'dead', 'lease_at' => self::ago(200)]);

        $this->recover();

        self::assertSame('cancelled', $this->runRow($run)['outcome']);
    }

    public function testARequestKilledBeforeDispatchIsRecoveredByTheRecurringTask(): void
    {
        // The other-driver path: the reservation committed, the process died before the push.
        $run = $this->started();
        $this->setRun($run, ['status' => 'reserved', 'last_dispatch_at' => null, 'created_at' => self::ago(150)]);

        $this->runRecurringRecovery();

        self::assertCount(1, $this->queue->pushed);
        self::assertSame(DocsSyncJob::class, $this->queue->pushed[0]['job']);
        self::assertSame($run, $this->queue->pushed[0]['data']['run']);
    }

    public function testADeliveryWhoseRetriesRanOutIsRecoveredByTheRecurringTask(): void
    {
        $run = $this->started();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            // An attempt claimed, then died: its delivery is spent and its lease expires.
            $this->setRun($run, ['status' => 'running', 'attempts' => $attempt, 'last_dispatch_at' => self::ago(150)]);
            $this->setState(['lease_token' => 'attempt-' . $attempt, 'lease_at' => self::ago(200)]);
            $this->queue->pushed = [];

            $this->runRecurringRecovery();

            if ($attempt < 5) {
                self::assertCount(1, $this->queue->pushed, "re-dispatched after attempt {$attempt}");
            }
        }
        self::assertSame([], $this->queue->pushed);
        self::assertSame('interrupted', $this->runRow($run)['outcome']);
    }

    private function runRecurringRecovery(): void
    {
        $job = new DocsSyncRecoverJob([], $this->appContext());
        $job->useService($this->service($this->queue));
        $job->handle();
    }
}
