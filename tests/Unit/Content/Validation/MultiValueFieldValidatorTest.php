<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Validation;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class MultiValueFieldValidatorTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                'multiple' => true, 'max_items' => 3],
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'author'], // single
        ]);
    }

    public function testAcceptsAndDedupesOrderedArray(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), ['category' => ['a1', 'a1', 'b2']]);
        self::assertSame(['a1', 'b2'], $clean['category']); // first-occurrence dedupe, order preserved
    }

    public function testRejectsScalarForMultipleField(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => 'a1']);
    }

    public function testRejectsEmptyOrNullElements(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => ['a1', '']]);
    }

    public function testEnforcesMaxItemsAfterDedupe(): void
    {
        // duplicates collapsing under the cap is fine
        $clean = (new FieldValidator())->validate($this->schema(), ['category' => ['a', 'a', 'b', 'c']]);
        self::assertSame(['a', 'b', 'c'], $clean['category']);
        // 4 distinct > max 3 → fail
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['category' => ['a', 'b', 'c', 'd']]);
    }

    public function testSingleReferenceUnchanged(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), ['author' => 'x9']);
        self::assertSame('x9', $clean['author']);
    }
}
