<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use App\Content\Delivery\EngineEntryListReader;
use Thallo\Render\RenderContextExtension;

/** Focused safety/bounds pins for the newly DB-template-callable functions (spec §3). */
final class AllowlistedFunctionBoundsTest extends AppTestCase
{
    public function testMediaImageWidthNormalizationDedupesFiltersAndCapsAtEight(): void
    {
        // Direct PHP callers can still pass a long array; resolver work remains capped.
        self::assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            RenderContextExtension::normalizeWidths(range(1, 10000)),
        );
        self::assertSame(
            [320, 640],
            RenderContextExtension::normalizeWidths([320, 640, 320, -1, 0, 'x', 3.5]),
        );
        self::assertSame([], RenderContextExtension::normalizeWidths([]));
    }

    public function testEntriesLimitIsServerClampedToTwelve(): void
    {
        // The clamp lives at the reader seam every template call crosses
        // (EngineEntryListReader::list()) — source-pin it so a refactor that
        // drops the clamp fails here, not in production.
        $src = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/app/Content/Delivery/EngineEntryListReader.php',
        );
        self::assertStringContainsString('max(1, min(12,', $src);
    }
}
