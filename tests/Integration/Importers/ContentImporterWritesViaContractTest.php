<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\AppTestCase;
use Thallo\Importers\CsvContentImporter;

final class ContentImporterWritesViaContractTest extends AppTestCase
{
    /** @return list<class-string> the four importer adapter classes */
    private function adapters(): array
    {
        return [
            \Thallo\Importers\CsvContentImporter::class,
            \Thallo\Importers\MarkdownContentImporter::class,
            \Thallo\Importers\WordpressContentImporter::class,
            \Thallo\Importers\CsvUserImporter::class,
        ];
    }

    /** @return list<string> traits used by $cls and all its parents */
    private function traitsOf(string $cls): array
    {
        $traits = [];
        for ($c = $cls; $c !== false; $c = get_parent_class($c)) {
            $traits = array_merge($traits, array_values(class_uses($c) ?: []));
        }
        return $traits;
    }

    public function testCsvContentImporterIsContractCoupledOnly(): void
    {
        // Structural guard: the refactored adapter must depend on the ContentWriter contract,
        // not on the engine repositories/services it used before.
        $ctor = (new \ReflectionClass(CsvContentImporter::class))->getConstructor();
        $paramTypes = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $ctor?->getParameters() ?? [],
        );
        $joined = implode(',', $paramTypes);
        self::assertStringContainsString('Thallo\\Contracts\\Authoring\\ContentWriter', $joined);
        self::assertStringNotContainsString('App\\Content\\Repositories\\EntryRepository', $joined);
        self::assertStringNotContainsString('App\\Content\\Services\\PublishService', $joined);
        self::assertStringNotContainsString('App\\Content\\Validation\\FieldValidator', $joined);
    }

    public function testEveryAdapterUsesTheCapabilityGuardTrait(): void
    {
        // The backend gate is only meaningful if EVERY adapter applies it — not just one sample.
        // Catches an adapter that forgets `use RequiresImportersCapability;`.
        foreach ($this->adapters() as $cls) {
            self::assertContains(
                \Thallo\Importers\Concerns\RequiresImportersCapability::class,
                $this->traitsOf($cls),
                "{$cls} must use RequiresImportersCapability (backend capability gate)",
            );
        }
    }

    public function testNoAdapterReferencesAppNamespace(): void
    {
        foreach ($this->adapters() as $cls) {
            $src = (string) file_get_contents((new \ReflectionClass($cls))->getFileName());
            // Mirror the authoritative guard in scripts/check-pack-boundaries.php — the
            // [^\w] class must include a leading backslash so a bare FQCN (\App\Foo) is caught.
            self::assertDoesNotMatchRegularExpression(
                '/(^|[^\\w])App\\\\/m',
                $src,
                "{$cls} must not reference App\\",
            );
        }
    }
}
