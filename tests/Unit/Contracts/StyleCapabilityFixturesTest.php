<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;

/**
 * Hover state spec §2.2–2.2.1: what a block type offers, expanded once — a hover path only where its
 * target owns the resting path, published in schema order — proved against the fixture set the admin
 * reads too (`packages/thallo-contracts/style-capability-fixtures/v1`).
 */
final class StyleCapabilityFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../packages/thallo-contracts/style-capability-fixtures/v1';

    /** @return array<string,mixed> */
    private static function expansion(): array
    {
        $json = (string) file_get_contents(self::FIXTURES . '/expansion.json');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string,mixed>}> */
    public static function cases(): iterable
    {
        $doc = self::expansion();
        foreach ($doc['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $case
     */
    public function testFixture(array $case): void
    {
        $decl = $case['declaration'];
        if (isset($case['error'])) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($case['error']);
        }
        $caps = StyleCapabilities::fromDeclaration($decl['style_capabilities']);
        $targets = StyleTargets::fromDeclaration($decl['style_targets']);
        self::assertSame([], $targets->validateAgainst($targets->effective($caps)));
        // assertSame on lists: the order is part of the contract (schema order).
        self::assertSame($case['expect'], $targets->stylePaths($caps));
    }

    public function testTheTwoOrderCasesAreEqual(): void
    {
        $doc = self::expansion();
        $by = array_column($doc['cases'], 'expect', 'name');
        self::assertSame($by['order: hover listed first'], $by['order: hover listed last']);
    }

    public function testBothRuntimesReadTheSameFixtureFiles(): void
    {
        $spec = (string) file_get_contents(__DIR__ . '/../../../admin/src/__tests__/style-capabilities.spec.ts');
        self::assertStringContainsString(
            'style-capability-fixtures/v1',
            $spec,
            'the TypeScript spec reads the same folder',
        );
    }
}
