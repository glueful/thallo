<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Render\Style\CascadeResolver;

/**
 * Visual builder spec §1.6 and §3.3: the breakpoint-first cascade, proved against the fixture set
 * both runtimes must reproduce byte-equivalently (`packages/thallo-render/resolver-fixtures/v1`).
 */
final class CascadeResolverFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../packages/thallo-render/resolver-fixtures/v1';

    /** @return iterable<string, array{0: array<string,mixed>}> */
    public static function cases(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.json') ?: [] as $file) {
            $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($doc['cases'] as $case) {
                yield basename($file, '.json') . ' / ' . $case['name'] => [$case];
            }
        }
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $case
     */
    public function testFixture(array $case): void
    {
        $def = StyleSchema::property($case['property']);
        self::assertNotNull($def, $case['property']);

        $resolved = (new CascadeResolver())->resolve($case['property'], $case['classes'], $case['instance'], $def);

        $normalised = [];
        foreach ($resolved as $bp => $resolution) {
            $normalised[$bp] = $resolution->toArray();
        }
        self::assertSame(
            json_encode($case['expect'], JSON_PRETTY_PRINT),
            json_encode($normalised, JSON_PRETTY_PRINT),
        );
    }

    public function testBothRuntimesReadTheSameFixtureFiles(): void
    {
        $spec = (string) file_get_contents(__DIR__ . '/../../../admin/src/__tests__/style-resolver.spec.ts');
        self::assertStringContainsString("resolver-fixtures/v1", $spec, 'the TypeScript spec reads the same folder');
        self::assertNotEmpty(glob(self::FIXTURES . '/*.json'));
    }
}
