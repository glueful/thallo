<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Thallo\Importers\CsvUserImporter;

final class CsvUserImporterRelocationTest extends AppTestCase
{
    public function testCsvUserImporterIsResolvableFromContainer(): void
    {
        $importer = $this->container()->get(CsvUserImporter::class);
        self::assertInstanceOf(ImporterInterface::class, $importer);
    }

    public function testCsvUserImporterKeyIsCorrect(): void
    {
        $importer = $this->container()->get(CsvUserImporter::class);
        self::assertSame('csv.users', $importer->key());
    }
}
