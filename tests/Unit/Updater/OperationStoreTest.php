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
        [$op, $token] = $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner-uuid');
        self::assertSame(Status::REQUESTED, $op['status']);
        self::assertSame(64, strlen($token));
        $stored = (string) file_get_contents($this->base . '/storage/framework/updates/operation.json');
        self::assertStringNotContainsString($token, $stored, 'only the hash is stored');
        self::assertTrue($this->ops->verifyToken($token));
        self::assertFalse($this->ops->verifyToken(str_repeat('0', 64)));
        $mode = fileperms($this->base . '/storage/framework/updates/operation.json');
        self::assertSame('0640', substr(sprintf('%o', $mode), -4));
    }

    public function testASecondStartIsRefusedWhileOneIsOpen(): void
    {
        $this->ops->start('2', '1', 'u');
        $this->expectExceptionObject(new UpdateRefused('in_progress'));
        $this->ops->start('2', '1', 'u');
    }

    public function testRecoveryRequiredBlocksANewStart(): void
    {
        [$op] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED, 'provision failed');
        try {
            $this->ops->start('2', '1', 'u');
            self::fail('expected a refusal');
        } catch (UpdateRefused $e) {
            self::assertSame('recovery_required', $e->reason);
        }
    }

    public function testClaimsAreCompareAndSetOnTheExpectedOperation(): void
    {
        [$op] = $this->ops->start('2', '1', 'u');
        self::assertNull($this->ops->claim('some-other-id', 10, 10), 'a claim names the operation it read');
        $run = $this->ops->claim($op['id'], 10, 10);
        self::assertSame(32, strlen($run['owner']));
        self::assertNull($this->ops->claim($op['id'], 11, 11), 'a second claim gets nothing');
        self::assertFalse($this->ops->cancel($op['id']), 'a claimed operation cannot be cancelled');
    }

    public function testAStaleOwnerCannotWriteAFinishedOrNewerOperation(): void
    {
        [$first] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($first['id'], 10, 10);
        $this->ops->finish($first['id'], $run['owner'], Status::SUCCEEDED);
        try {
            $this->ops->update($first['id'], $run['owner'], static fn (array $op): array => ['step' => 'late'] + $op);
            self::fail('a finished operation accepts no more writes');
        } catch (Fenced) {
        }
        [$second] = $this->ops->start('3', '2', 'u');
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
        [$op] = $this->ops->start('2', '1', 'u');
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
        [$op, $token] = $this->ops->start('2', '1', 'u');
        self::assertFalse($this->ops->requestRecovery($token, 'reopen'), 'not in recovery');
        $run = $this->ops->claim($op['id'], 10, 10);
        $this->ops->finish($op['id'], $run['owner'], Status::RECOVERY_REQUIRED);
        self::assertFalse($this->ops->requestRecovery(str_repeat('a', 64), 'rollback'), 'wrong token');
        self::assertFalse($this->ops->requestRecovery($token, 'format-disk'), 'unknown option');
        self::assertTrue($this->ops->requestRecovery($token, 'rollback'));
        $rec = $this->ops->claimRecovery($op['id'], 30, 30);
        self::assertSame('rollback', $rec['recovering']);
        self::assertSame(30, $rec['pgid'], 'recovery records fresh ownership');
        self::assertNotSame($run['owner'], $rec['owner']);
    }

    public function testRotatingTheTokenInvalidatesTheOldOne(): void
    {
        [$op, $old] = $this->ops->start('2', '1', 'u');
        $new = $this->ops->rotateToken($op['id']);
        self::assertFalse($this->ops->verifyToken($old));
        self::assertTrue($this->ops->verifyToken((string) $new));
        self::assertNull($this->ops->rotateToken('other-id'));
    }
}
