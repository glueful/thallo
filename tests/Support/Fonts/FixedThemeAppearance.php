<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Contracts\Settings\ThemeAppearanceProvider;

/** An appearance with a chosen pairing and Custom faces; everything else the defaults. */
final class FixedThemeAppearance implements ThemeAppearanceProvider
{
    /** @param array{body?: string, display?: string} $faces */
    public function __construct(private readonly string $font, private readonly array $faces = [])
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

    public function fontFaces(): array
    {
        return $this->faces;
    }
}
