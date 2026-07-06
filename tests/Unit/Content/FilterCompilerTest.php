<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\UnfilterableFieldException;
use App\Content\Delivery\InvalidFilterException;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class FilterCompilerTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true], // NOT filterable
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
            ['name' => 'status', 'type' => 'enum', 'enum' => ['a', 'b', 'c'],
                'filterable' => true, 'filter_type' => 'enum'],
            ['name' => 'active', 'type' => 'boolean', 'filterable' => true, 'filter_type' => 'boolean'],
            ['name' => 'published_at', 'type' => 'datetime', 'filterable' => true, 'filter_type' => 'datetime'],
        ]);
    }

    private function compile(array $filter): array
    {
        $resolver = new class implements ReferenceTargetResolver {
            public function resolve(FieldDescriptor $field, string $locale, array $values): array
            {
                return [];
            }
        };
        return (new FilterCompiler($resolver))->compile($this->schema(), $filter, 'en');
    }

    public function testNumberGreaterThan(): void
    {
        $out = $this->compile(['price' => ['gt' => '10']]);
        self::assertSame("(fields ->> 'price')::numeric > ?", $out['sql']);
        self::assertSame([10], $out['bindings']);
    }

    public function testEnumInList(): void
    {
        $out = $this->compile(['status' => ['in' => 'a,b']]);
        self::assertSame("(fields ->> 'status') IN (?, ?)", $out['sql']);
        self::assertSame(['a', 'b'], $out['bindings']);
    }

    public function testStringEqAndNeq(): void
    {
        $out = $this->compile(['status' => ['eq' => 'a']]);
        self::assertSame("(fields ->> 'status') = ?", $out['sql']);
        self::assertSame(['a'], $out['bindings']);

        $out = $this->compile(['status' => ['neq' => 'a']]);
        self::assertSame("(fields ->> 'status') <> ?", $out['sql']);
        self::assertSame(['a'], $out['bindings']);
    }

    public function testBooleanEq(): void
    {
        $out = $this->compile(['active' => ['eq' => 'true']]);
        self::assertSame("(fields ->> 'active')::boolean = ?", $out['sql']);
        self::assertSame([true], $out['bindings']);
    }

    public function testMultiplePredicatesAreAndJoined(): void
    {
        $out = $this->compile([
            'price' => ['gt' => '10'],
            'status' => ['eq' => 'a'],
        ]);
        self::assertSame(
            "(fields ->> 'price')::numeric > ? AND (fields ->> 'status') = ?",
            $out['sql']
        );
        self::assertSame([10, 'a'], $out['bindings']);
    }

    public function testDatetimeGtBindsNormalizedIsoString(): void
    {
        $out = $this->compile(['published_at' => ['gt' => '2026-06-14T09:30:00+02:00']]);
        // datetime compared as TEXT (mirrors the IMMUTABLE text index expression).
        self::assertSame("(fields ->> 'published_at') > ?", $out['sql']);
        self::assertSame(['2026-06-14T07:30:00Z'], $out['bindings']);
        // and the binding matches the validator's stored normalization.
        self::assertSame(
            [FieldValidator::normalizeDatetime('2026-06-14T09:30:00+02:00')],
            $out['bindings']
        );
    }

    public function testNonFilterableFieldThrows(): void
    {
        $this->expectException(UnfilterableFieldException::class);
        $this->compile(['title' => ['eq' => 'x']]);
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(UnfilterableFieldException::class);
        $this->compile(['nope' => ['eq' => 'x']]);
    }

    public function testWrongOperatorForTypeThrows(): void
    {
        // gt is not allowed for enum (string family)
        $this->expectException(InvalidFilterException::class);
        $this->compile(['status' => ['gt' => 'a']]);
    }

    public function testEqNotAllowedForNumberThrows(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compile(['price' => ['eq' => '10']]);
    }

    public function testMalformedNumberValueThrows(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compile(['price' => ['gt' => 'abc']]);
    }

    public function testMalformedBooleanValueThrows(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compile(['active' => ['eq' => 'maybe']]);
    }

    public function testMalformedDatetimeValueThrows(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compile(['published_at' => ['gt' => 'not-a-date']]);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->compile(['price' => ['between' => '1']]);
    }

    public function testEmptyFilterCompilesToEmpty(): void
    {
        $out = $this->compile([]);
        self::assertSame('', $out['sql']);
        self::assertSame([], $out['bindings']);
    }
}
