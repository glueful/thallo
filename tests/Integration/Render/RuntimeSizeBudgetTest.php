<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * Storefront-performance spec §2: the single-runtime posture's visibility gate. The
 * budget is NOT a hard architectural ceiling — it exists so growth is a conscious
 * decision. If this fails: either the growth is optional-module weight that now
 * materially dominates the payload (THEN revisit splitting, per the spec's receipts) or
 * it is shared-core weight (raise the budget in the same commit, with reasoning).
 */
final class RuntimeSizeBudgetTest extends TestCase
{
    public function testRuntimeStaysWithinItsCompressedBudget(): void
    {
        $path = dirname(__DIR__, 3) . '/packages/thallo-render/runtime/runtime.js';
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source);

        $compressed = strlen((string) gzencode($source, 9));
        self::assertLessThanOrEqual(
            12_288,
            $compressed,
            "runtime.js is {$compressed} bytes at gzip -9 against a 12KB budget. "
            . 'Growth is fine when it is shared-core weight (raise the budget here, with '
            . 'reasoning); if optional modules now dominate the payload, revisit the '
            . 'splitting decision recorded in the storefront-performance spec §2.',
        );
    }
}
