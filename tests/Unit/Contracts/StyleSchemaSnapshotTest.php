<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\PropertyDefinition;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;

/**
 * The style schema as a committed snapshot (hover state spec §8): the admin's mirror
 * (`admin/src/style/schema.ts`) is checked against the same file, so whichever side falls behind fails.
 */
final class StyleSchemaSnapshotTest extends TestCase
{
    public function testTheSnapshotIsTheSchema(): void
    {
        $rows = array_map(static fn (PropertyDefinition $d): array => [
            'path' => $d->path, 'group' => $d->group, 'responsive' => $d->responsive,
            'token_domain' => $d->tokenDomain, 'choices' => $d->choices,
            'kinds' => array_map(static fn (ValueKind $k): string => $k->value, $d->kinds),
        ], array_values(StyleSchema::properties()));
        $expected = json_encode(
            ['version' => StyleSchema::VERSION, 'properties' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
        self::assertSame(
            $expected,
            rtrim((string) file_get_contents(__DIR__ . '/../../../packages/thallo-contracts/style-schema/v1.json')),
            'the style schema changed: regenerate packages/thallo-contracts/style-schema/v1.json'
                . ' and update admin/src/style/schema.ts',
        );
    }
}
