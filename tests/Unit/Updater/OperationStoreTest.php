<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Fenced;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;
use Thallo\Core\Updater\UpdateRefused;

final class OperationStoreTest extends TestCase
{
    private string $base;
    private OperationStore $ops;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-ops-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->ops = new OperationStore(new Paths($this->base), static fn (): int => 1_000);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testStartRecordsTheRequestAndReturnsATokenOnlyOnce(): void
    {
        $token = bin2hex(random_bytes(32));
        $op = $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner-uuid', hash('sha256', $token));
        self::assertSame(Status::REQUESTED, $op['status']);
        self::assertSame(64, strlen($token));
        $stored = (string) file_get_contents($this->base . '/.thallo-update/operation.json');
        self::assertStringNotContainsString($token, $stored, 'only the hash is stored');
        self::assertTrue($this->ops->verifyToken($token));
        self::assertFalse($this->ops->verifyToken(str_repeat('0', 64)));
        self::assertSame(0600, fileperms($this->base . '/.thallo-update/operation.json') & 0777, 'the runner\'s alone');
        self::assertSame(0700, fileperms($this->base . '/.thallo-update') & 0777);
        // What PHP-FPM may read: the published status, without owner, journal or token hash inside it.
        self::assertSame(0640, fileperms($this->base . '/.thallo-update-control/status.json') & 0777);
        $status = json_decode((string) file_get_contents($this->base . '/.thallo-update-control/status.json'), true);
        self::assertSame(hash('sha256', $token), $status['token_hash']);
        self::assertSame([], $status['recovery_options']);
        self::assertArrayNotHasKey('token_hash', $status['operation']);
        self::assertArrayNotHasKey('journal', $status['operation']);
    }

    public function testASecondStartIsRefusedWhileOneIsOpen(): void
    {
        $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
        $this->expectExceptionObject(new UpdateRefused('in_progress'));
        $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
    }

    public function testRecoveryRequiredBlocksANewStart(): void
    {
        $op = $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED, 'provision failed');
        try {
            $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
            self::fail('expected a refusal');
        } catch (UpdateRefused $e) {
            self::assertSame('recovery_required', $e->reason);
        }
    }

    public function testClaimsAreCompareAndSetOnTheExpectedOperation(): void
    {
        $op = $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
        self::assertNull($this->ops->claim('some-other-id', 10, 10), 'a claim names the operation it read');
        $run = $this->ops->claim($op['id'], 10, 10);
        self::assertSame(32, strlen($run['owner']));
        self::assertNull($this->ops->claim($op['id'], 11, 11), 'a second claim gets nothing');
        self::assertFalse($this->ops->cancel($op['id']), 'a claimed operation cannot be cancelled');
    }

    public function testAStaleOwnerCannotWriteAFinishedOrNewerOperation(): void
    {
        $first = $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
        $run = $this->ops->claim($first['id'], 10, 10);
        $this->ops->finish($first['id'], $run['owner'], Status::SUCCEEDED);
        try {
            $this->ops->update($first['id'], $run['owner'], static fn (array $op): array => ['step' => 'late'] + $op);
            self::fail('a finished operation accepts no more writes');
        } catch (Fenced) {
        }
        $second = $this->ops->start('3', '2', 'u', hash('sha256', 'token'));
        foreach ([[$first['id'], $run['owner']], [$second['id'], $run['owner']]] as [$id, $owner]) {
            try {
                $this->ops->finish($id, $owner, Status::FAILED, 'late');
                self::fail('a stale runner cannot touch the successor');
            } catch (Fenced) {
            }
        }
        self::assertSame(Status::REQUESTED, $this->ops->current()['status']);
        self::assertSame($second['id'], $this->ops->current()['id']);
    }

    public function testTakeOverNeedsTheDeadOwner(): void
    {
        $op = $this->ops->start('2', '1', 'u', hash('sha256', 'token'));
        $run = $this->ops->claim($op['id'], 10, 10);
        self::assertNull($this->ops->takeOver($op['id'], 'not-the-owner', 20, 20));
        $new = $this->ops->takeOver($op['id'], $run['owner'], 20, 20);
        self::assertNotSame($run['owner'], $new['owner']);
        self::assertSame(20, $new['pgid'], 'the finder records its own process group');
        $this->expectException(Fenced::class);
        $this->ops->update($op['id'], $run['owner'], static fn (array $o): array => $o);
    }

    public function testRecoveryRequestsNeedTheTokenAndTheState(): void
    {
        $token = bin2hex(random_bytes(32));
        $op = $this->ops->start('2', '1', 'u', hash('sha256', $token));
        self::assertFalse($this->ops->requestRecovery($token, 'reopen'), 'not in recovery');
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED);
        self::assertFalse($this->ops->requestRecovery($token, 'rollback'), 'nothing in the journal to roll back');
        self::assertFalse($this->ops->requestRecovery($token, 'retry_provision'), 'no live swap to retry on');
        $this->journal($op['id'], ['swap.1']);
        self::assertFalse($this->ops->requestRecovery(str_repeat('a', 64), 'rollback'), 'wrong token');
        self::assertFalse($this->ops->requestRecovery($token, 'format-disk'), 'unknown option');
        self::assertFalse($this->ops->requestRecovery($token, 'retry_provision'), 'a half swap cannot be retried');
        self::assertTrue($this->ops->requestRecovery($token, 'rollback'));
        $rec = $this->ops->claimRecovery($op['id'], 30, 30);
        self::assertSame('rollback', $rec['recovering']);
        self::assertSame(30, $rec['pgid'], 'recovery records fresh ownership');
        self::assertNotSame($run['owner'], $rec['owner']);
    }

    public function testRotatingTheTokenInvalidatesTheOldOne(): void
    {
        $old = bin2hex(random_bytes(32));
        $op = $this->ops->start('2', '1', 'u', hash('sha256', $old));
        $new = $this->ops->rotateToken($op['id']);
        self::assertFalse($this->ops->verifyToken($old));
        self::assertTrue($this->ops->verifyToken((string) $new));
        self::assertNull($this->ops->rotateToken('other-id'));
    }

    /** Gives a recovery_required operation journal entries, as the runner's journal would have. */
    private function journal(string $id, array $labels): void
    {
        $file = $this->base . '/.thallo-update/operation.json';
        $op = json_decode((string) file_get_contents($file), true);
        self::assertSame($id, $op['id']);
        foreach ($labels as $i => $label) {
            $op['journal'][] = [
                'seq' => $i + 1, 'kind' => 'forward', 'reverses' => null,
                'label' => $label, 'from' => 'a', 'to' => 'b', 'phase' => 'done',
            ];
        }
        file_put_contents($file, json_encode($op));
    }
}
