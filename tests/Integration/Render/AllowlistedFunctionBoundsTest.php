<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use App\Content\Delivery\EngineEntryListReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Thallo\Contracts\Delivery\EntryListReader;
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

    /**
     * Behavioral pin (replaces the former source-grep): the clamp lives at the reader
     * seam every template call crosses (EngineEntryListReader::list()) — prove it by
     * actually publishing MORE than 12 entries and asking for far more than that, so a
     * refactor that drops the clamp fails on real output, not on source text.
     */
    public function testEntriesLimitIsServerClampedToTwelveEvenWhenTheCallerAsksForThousands(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = $types->create([
            'slug' => 'clamp-post',
            'name' => 'Clamp Post',
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $publish = new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
        $routes = new RouteRepository($this->connection());

        for ($i = 0; $i < 13; $i++) {
            $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
            $entries->saveDraft($uuid, 'en', ['title' => "Post {$i}"], 1, 0, 'user00000001');
            $routes->assign($uuid, $typeUuid, 'en', "clamp-post-{$i}");
            $publish->publish($uuid, 'en', 'user00000001');
        }

        /** @var EntryListReader $reader */
        $reader = $this->container()->get(EntryListReader::class);
        self::assertInstanceOf(EngineEntryListReader::class, $reader);
        $out = $reader->list('clamp-post', ['limit' => 5000], 'en');

        self::assertCount(12, $out['items']);
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
