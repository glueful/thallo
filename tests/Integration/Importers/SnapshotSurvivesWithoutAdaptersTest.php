<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Content\ImportExport\ContentExporter;
use App\Content\ImportExport\ContentImporter;
use App\Tests\Support\AppTestCase;

final class SnapshotSurvivesWithoutAdaptersTest extends AppTestCase
{
    public function testSnapshotEngineResolvesIndependentlyOfThePack(): void
    {
        // The snapshot engine is core-owned; it must resolve from the container regardless of
        // the importers pack, and must NOT reference any Thallo\Importers\* class.
        self::assertInstanceOf(ContentExporter::class, $this->container()->get(ContentExporter::class));
        self::assertInstanceOf(ContentImporter::class, $this->container()->get(ContentImporter::class));

        foreach ([ContentExporter::class, ContentImporter::class] as $cls) {
            $src = (string) file_get_contents((new \ReflectionClass($cls))->getFileName());
            self::assertStringNotContainsString('Thallo\\Importers', $src, "$cls must not depend on the pack");
        }
    }
}
