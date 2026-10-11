<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\RecoveryOptions;

/** Which recovery choices the runner offers, from its own journal (spec §16.2). */
final class RecoveryOptionsTest extends TestCase
{
    public function testOnlyReopenWhenNothingLiveChanged(): void
    {
        self::assertSame(['reopen'], RecoveryOptions::for($this->op([])));
    }

    public function testRollbackWhenSomeForwardRenameIsLive(): void
    {
        self::assertSame(['rollback', 'reopen'], RecoveryOptions::for($this->op(['swap.1', 'swap.2'])));
    }

    public function testRetryOnlyWhenTheWholeSwapIsLive(): void
    {
        $whole = $this->op(['swap.1', 'swap.2', 'swap.3', 'swap.4']);
        self::assertSame(['rollback', 'retry_provision', 'reopen'], RecoveryOptions::for($whole));
    }

    public function testNoRetryOnceAnyPartOfTheSwapWasReversed(): void
    {
        $op = $this->op(['swap.1', 'swap.2', 'swap.3', 'swap.4']);
        $op['journal'][] = [
            'seq' => 5, 'kind' => 'reverse', 'reverses' => 4,
            'label' => 'undo.swap.4', 'from' => 'a', 'to' => 'b', 'phase' => 'done',
        ];
        self::assertSame(['rollback', 'reopen'], RecoveryOptions::for($op));
    }

    public function testNothingIsOfferedOutsideRecovery(): void
    {
        self::assertSame([], RecoveryOptions::for(['status' => 'running', 'journal' => []] + $this->op([])));
    }

    /** @param list<string> $done */
    private function op(array $done): array
    {
        $journal = [];
        foreach ($done as $i => $label) {
            $journal[] = [
                'seq' => $i + 1, 'kind' => 'forward', 'reverses' => null,
                'label' => $label, 'from' => 'a', 'to' => 'b', 'phase' => 'done',
            ];
        }
        return ['status' => 'recovery_required', 'journal' => $journal];
    }
}
