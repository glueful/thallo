<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\DetachTransformation;
use Thallo\Contracts\Style\StyleCapabilities;

/**
 * Visual builder spec §4.4: detaching a style class preserves every managed effective value at
 * every breakpoint. Proved against the fixture set both runtimes reproduce byte-equivalently
 * (`packages/thallo-render/detach-fixtures/v1`).
 */
final class DetachTransformationFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../packages/thallo-render/detach-fixtures/v1';

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
        $result = DetachTransformation::detach(
            $case['classes'],
            $case['instance'],
            $case['detach'],
            StyleCapabilities::fromDeclaration($case['capabilities']),
        );

        self::assertSame(
            json_encode($case['expect'], JSON_PRETTY_PRINT),
            json_encode($result, JSON_PRETTY_PRINT),
        );
    }

    public function testTheTransformationIsPureAndLeavesItsInputsAlone(): void
    {
        $classes = [['id' => 'c1', 'style' => ['radius' => ['type' => 'token', 'value' => 'radius.lg']]]];
        $instance = [];
        DetachTransformation::detach($classes, $instance, 'c1', StyleCapabilities::fromDeclaration(['radius']));
        self::assertSame([], $instance);
        self::assertSame('radius.lg', $classes[0]['style']['radius']['value']);
    }

    public function testBothRuntimesReadTheSameFixtureFiles(): void
    {
        $spec = __DIR__ . '/../../../admin/src/__tests__/style-detach.spec.ts';
        if (is_file($spec)) {
            self::assertStringContainsString('detach-fixtures/v1', (string) file_get_contents($spec));
        }
        self::assertNotEmpty(glob(self::FIXTURES . '/*.json'));
    }
}
