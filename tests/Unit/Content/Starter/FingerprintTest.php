<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Starter;

use App\Content\Starter\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testAssociativeOrderIsCanonicalAndListOrderIsSignificant(): void
    {
        $first = ['z' => ['b' => 2, 'a' => 1], 'items' => ['one', 'two']];
        $same = ['items' => ['one', 'two'], 'z' => ['a' => 1, 'b' => 2]];
        $reordered = ['z' => ['b' => 2, 'a' => 1], 'items' => ['two', 'one']];

        self::assertSame(Fingerprint::of($first), Fingerprint::of($same));
        self::assertNotSame(Fingerprint::of($first), Fingerprint::of($reordered));
        self::assertSame(
            '9a7c71364f15d3d110c28e5ebdcc49023d5584bd8767beaf5f2ecc23b4d314b3',
            Fingerprint::of($first),
        );
    }
}
