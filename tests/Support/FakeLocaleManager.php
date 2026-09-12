<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;

final class FakeLocaleManager implements LocaleManagerInterface
{
    public function all(): array
    {
        return ['en', 'fr'];
    }

    public function enabled(): array
    {
        return ['en', 'fr'];
    }

    public function default(): string
    {
        return 'en';
    }

    public function fallbackChain(string $locale): array
    {
        return $locale === 'fr' ? ['fr', 'en'] : [$locale];
    }
}
