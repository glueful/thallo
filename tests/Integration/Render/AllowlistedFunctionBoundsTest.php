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

    /**
     * hex_color (gate-audit amendment, task 7): the bounded PHP replacement for the
     * |matches "/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/" check blocks/style.twig used to
     * run directly. Same shape: 3 or 6 hex digits after '#', nothing else.
     */
    public function testHexColorAcceptsValidRejectsInvalid(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);

        self::assertSame('#abc', $extension->hexColor('#abc'));
        self::assertSame('#A1B2C3', $extension->hexColor('#A1B2C3'));

        self::assertSame('', $extension->hexColor('red'));
        self::assertSame('', $extension->hexColor('#abcd'));
        self::assertSame('', $extension->hexColor('#zzz'));
        self::assertSame('', $extension->hexColor(['#abc']));
        self::assertSame('', $extension->hexColor('#abc; injection'));
    }

    /**
     * numeric_clamp (gate-audit amendment, task 7): the bounded PHP replacement for the
     * |matches "/^[0-9]+(\.[0-9]+)?$/" + max()/min() pair blocks/style.twig used to run
     * directly for --shadow-strength. Non-numeric input is null (no CSS var emitted),
     * not 0 — the caller must be able to distinguish "no value" from "clamped to floor".
     */
    public function testNumericClampClampsAndNullsNonNumeric(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);

        self::assertSame(200.0, $extension->numericClamp('250', 0, 200));
        self::assertSame(0.0, $extension->numericClamp('-5', 0, 200));
        self::assertSame(12.5, $extension->numericClamp('12.5', 0, 200));
        self::assertNull($extension->numericClamp('abc', 0, 200));
        self::assertNull($extension->numericClamp(['x'], 0, 200));
    }
}
