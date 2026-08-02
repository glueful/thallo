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
 *
 * Ceiling history: previous ceiling 12,288 bytes; raised to 14,336 bytes (reviewed
 * increase, 2026-08-01). Reason: "four-element Web Components API plus lifecycle
 * teardown" — Task 6 of the runtime-web-components plan landed the
 * thallo-carousel / thallo-tabs / thallo-navigation / thallo-color-mode-toggle
 * custom elements (the registerElement transactional projection helper plus the
 * explicit color-mode-toggle pipeline exception). Final measured size at that
 * increase: 13,394 bytes gzip -9 (942 bytes of headroom under the new ceiling).
 *
 * Raised to 15,360 bytes (reviewed increase, 2026-08-02). Reason: direction-aware
 * carousel interaction — vertical page scrolling over the slider must never
 * permanently pause autoplay; only horizontal intent (wheel deltaX, touch swipe,
 * mouse press, keyboard) is slide interaction. Shared carousel-core weight, so it
 * belongs in the universal runtime rather than an optional split. Measured size at
 * this increase: 14,798 bytes gzip level 9 (gzip CLI; 14,775 via this gate's
 * gzencode). Remaining headroom: 562 bytes. Any later increase requires another
 * explicit review — do not raise this ceiling automatically.
 *
 * Raised to 16,384 bytes (reviewed increase, 2026-08-02). Reason: configurable
 * transition-duration support, including interruptible slide-mode tweening —
 * the duration setting must behave consistently across slide, fade, and zoom,
 * and native smooth-scroll pace is UA-fixed, so slide mode drives the scroll
 * itself. Measured size at this increase: 15,453 bytes gzip level 9. Remaining
 * headroom: 931 bytes. This approves the current implementation, not arbitrary
 * future polish: any change exceeding 16,384 still requires a separate review.
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
            16_384,
            $compressed,
            "runtime.js is {$compressed} bytes at gzip -9 against a 16KB budget. "
            . 'Growth is fine when it is shared-core weight (raise the budget here, with '
            . 'reasoning); if optional modules now dominate the payload, revisit the '
            . 'splitting decision recorded in the storefront-performance spec §2.',
        );
    }
}
