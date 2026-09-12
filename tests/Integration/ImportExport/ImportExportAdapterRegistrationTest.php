<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\ImportExport;

use Thallo\Core\Content\ImportExport\ContentExporter;
use Thallo\Core\Content\ImportExport\ContentImporter;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;

final class ImportExportAdapterRegistrationTest extends AppTestCase
{
    public function testContentExporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ExporterRegistry::class);

        self::assertInstanceOf(ContentExporter::class, $registry->get('thallo.content'));
    }

    public function testContentImporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ImporterRegistry::class);

        self::assertInstanceOf(ContentImporter::class, $registry->get('thallo.content'));
    }
}
