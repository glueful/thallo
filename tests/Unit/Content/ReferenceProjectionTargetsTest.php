<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Locks the read-tolerance contract the membership filter depends on: a stored scalar string is
 * treated as a 1-element list, arrays pass through (filtering empties), and null/'' yield nothing.
 * (Validation stays strict on save — Task 2 — but stored/legacy values are read tolerantly here.)
 */
final class ReferenceProjectionTargetsTest extends TestCase
{
    public function testScalarStringBecomesSingleton(): void
    {
        self::assertSame(['a1'], ReferenceProjectionRepository::targets('a1'));
    }

    public function testArrayPassesThroughFilteringEmpties(): void
    {
        self::assertSame(['a1', 'b2'], ReferenceProjectionRepository::targets(['a1', '', 'b2']));
    }

    public function testNullAndEmptyYieldNoTargets(): void
    {
        self::assertSame([], ReferenceProjectionRepository::targets(null));
        self::assertSame([], ReferenceProjectionRepository::targets(''));
    }
}
