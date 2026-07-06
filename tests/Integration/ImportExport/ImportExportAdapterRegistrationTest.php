<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\ContentExporter;
use App\Content\ImportExport\ContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;

final class ImportExportAdapterRegistrationTest extends AppTestCase
{
    public function testContentExporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ExporterRegistry::class);

        self::assertInstanceOf(ContentExporter::class, $registry->get('lemma.content'));
    }

    public function testContentImporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ImporterRegistry::class);

        self::assertInstanceOf(ContentImporter::class, $registry->get('lemma.content'));
    }
}
