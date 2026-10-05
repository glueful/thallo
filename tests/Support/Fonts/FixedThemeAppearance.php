<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Contracts\Settings\ThemeAppearanceProvider;

/** An appearance with a chosen pairing and Custom's Text and Headings families; the rest default. */
final class FixedThemeAppearance implements ThemeAppearanceProvider
{
    /** @param array{text?: string, headings?: string} $families library family IDs */
    public function __construct(private readonly string $font, private readonly array $families = [])
    {
    }

    public function accent(): string
    {
        return 'blue';
    }

    public function neutral(): string
    {
        return 'slate';
    }

    public function radius(): string
    {
        return 'round';
    }

    public function font(): string
    {
        return $this->font;
    }

    public function background(): string
    {
        return 'plain';
    }

    public function fontFamilies(): array
    {
        return $this->families;
    }
}
