<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeAppearanceSourceTest extends AppTestCase
{
    private function provider(string $a, string $n): ThemeAppearanceProvider
    {
        return new class ($a, $n) implements ThemeAppearanceProvider {
            public function __construct(private string $a, private string $n)
            {
            }
            public function accent(): string
            {
                return $this->a;
            }
            public function neutral(): string
            {
                return $this->n;
            }
        };
    }

    public function testReturnsSavedPair(): void
    {
        $src = new ThemeAppearanceSource($this->provider('emerald', 'zinc'), new NullLogger());
        self::assertSame('emerald', $src->accent());
        self::assertSame('zinc', $src->neutral());
    }

    public function testUnboundProviderFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource(null, new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }

    public function testInvalidStoredValueFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource($this->provider('banana', 'slate'), new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }
}
