<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Fonts;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Fonts\FontId;

/** A stored typeface is a stable ID: a reserved built-in or a library family's 12-character ID. */
final class FontIdTest extends TestCase
{
    public function testReservedBuiltInsAreValid(): void
    {
        foreach (['theme', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system'] as $id) {
            self::assertTrue(FontId::isValid($id), $id);
            self::assertTrue(FontId::isReserved($id), $id);
        }
    }

    public function testALibraryIdIsTwelveLettersAndDigits(): void
    {
        self::assertTrue(FontId::isValid('Ab3dE5fG7hJ9'));
        self::assertFalse(FontId::isReserved('Ab3dE5fG7hJ9'));
    }

    public function testAnythingElseIsNotAnId(): void
    {
        $notIds = ['abc', 'inherit', 'reset', 'ab_cdefghijk', 'Ab3dE5fG7hJ9x', '<script>', '', "Ab3dE5fG7hJ9\n"];
        foreach ($notIds as $id) {
            self::assertFalse(FontId::isValid($id), json_encode($id) ?: '');
        }
    }
}
