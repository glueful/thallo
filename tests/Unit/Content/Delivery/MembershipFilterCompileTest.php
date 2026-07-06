<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Delivery;

use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\InvalidFilterException;
use App\Content\Schema\ContentTypeSchema;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class MembershipFilterCompileTest extends TestCase
{
    private function compiler(array $resolveMap): FilterCompiler
    {
        // Fake resolver implementing the interface — no final-class subclassing needed.
        $resolver = new class ($resolveMap) implements ReferenceTargetResolver {
            /** @param array<string,list<string>> $map keyed by the imploded input values */
            public function __construct(private array $map)
            {
            }
            public function resolve(FieldDescriptor $field, string $locale, array $values): array
            {
                return $this->map[implode(',', $values)] ?? [];
            }
        };
        return new FilterCompiler($resolver);
    }

    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                'multiple' => true, 'filterable' => true],
            ['name' => 'gallery', 'type' => 'asset', 'multiple' => true, 'filterable' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
    }

    public function testReferenceEqResolvesAndContains(): void
    {
        $c = $this->compiler(['news' => ['catnews00001']]);
        $out = $c->compile($this->schema(), ['category' => ['eq' => 'news']], 'en');
        self::assertStringContainsString('@> jsonb_build_array(?::text)', $out['sql']);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category')", $out['sql']);
        self::assertSame(['catnews00001'], $out['bindings']);
    }

    public function testReferenceInResolvesEachToOredContainment(): void
    {
        $c = $this->compiler(['a,b' => ['ua', 'ub']]);
        $out = $c->compile($this->schema(), ['category' => ['in' => 'a,b']], 'en');
        self::assertSame(['ua', 'ub'], $out['bindings']);
        self::assertStringContainsString(' OR ', $out['sql']);
    }

    public function testAssetIsUuidOnlyNoResolution(): void
    {
        $c = $this->compiler([]); // resolver never consulted for assets
        $out = $c->compile($this->schema(), ['gallery' => ['eq' => 'blob00000001']], 'en');
        self::assertSame(['blob00000001'], $out['bindings']);
    }

    public function testNoResolvedTargetsMatchesNothing(): void
    {
        $c = $this->compiler(['ghost' => []]);
        $out = $c->compile($this->schema(), ['category' => ['eq' => 'ghost']], 'en');
        self::assertStringContainsString('1 = 0', $out['sql']);
        self::assertSame([], $out['bindings']);
    }

    public function testOrderedOpRejectedForReference(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compiler([])->compile($this->schema(), ['category' => ['gt' => 'x']], 'en');
    }

    public function testScalarPathStillWorks(): void
    {
        $out = $this->compiler([])->compile($this->schema(), ['price' => ['gt' => '10']], 'en');
        self::assertStringContainsString("(fields ->> 'price')::numeric > ?", $out['sql']);
        self::assertSame([10], $out['bindings']);
    }
}
