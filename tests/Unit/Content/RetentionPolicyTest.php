<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Retention\InvalidRetentionPolicyException;
use Thallo\Core\Content\Retention\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testNullAndEmptyValuesDisableTheirDimension(): void
    {
        $policy = RetentionPolicy::fromValues(null, '');

        self::assertNull($policy->keep);
        self::assertNull($policy->maxAgeDays);
        self::assertFalse($policy->isEnabled());
    }

    public function testPositiveIntegersEnablePolicy(): void
    {
        $policy = RetentionPolicy::fromValues('10', 90);

        self::assertSame(10, $policy->keep);
        self::assertSame(90, $policy->maxAgeDays);
        self::assertTrue($policy->isEnabled());
    }

    /**
     * @dataProvider invalidValues
     */
    public function testInvalidValuesFailLoud(string $field, mixed $value): void
    {
        $this->expectException(InvalidRetentionPolicyException::class);
        $this->expectExceptionMessage($field);

        if ($field === 'keep') {
            RetentionPolicy::fromValues($value, null);
        } else {
            RetentionPolicy::fromValues(null, $value);
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'keep zero' => ['keep', '0'];
        yield 'keep negative' => ['keep', '-1'];
        yield 'keep non-numeric' => ['keep', 'many'];
        yield 'keep float' => ['keep', '1.5'];
        yield 'age zero' => ['max_age_days', 0];
        yield 'age negative' => ['max_age_days', -30];
        yield 'age non-numeric' => ['max_age_days', 'soon'];
        yield 'age float' => ['max_age_days', '2.5'];
    }
}
