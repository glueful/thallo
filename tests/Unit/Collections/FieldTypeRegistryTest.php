<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;
use Thallo\Contracts\Schema\FieldTypeDefinition;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private function def(string $key): FieldTypeDefinition
    {
        return new class ($key) implements FieldTypeDefinition {
            public function __construct(private string $k)
            {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function label(): string
            {
                return 'L';
            }

            public function valueShape(): string
            {
                return 'scalar';
            }

            public function validationRules(): array
            {
                return [];
            }

            public function adminWidget(): string
            {
                return 'text';
            }

            public function capabilities(): array
            {
                return ['filterable' => true];
            }
        };
    }

    public function testRegisterGetHasAll(): void
    {
        $r = new DefaultFieldTypeRegistry();
        $r->register($this->def('collections.string'));
        self::assertTrue($r->has('collections.string'));
        self::assertSame('collections.string', $r->get('collections.string')->key());
        self::assertArrayHasKey('collections.string', $r->all());
    }

    public function testDuplicateKeyThrows(): void
    {
        $r = new DefaultFieldTypeRegistry();
        $r->register($this->def('content.text'));
        $this->expectException(\InvalidArgumentException::class);
        $r->register($this->def('content.text'));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new DefaultFieldTypeRegistry())->get('nope');
    }
}
