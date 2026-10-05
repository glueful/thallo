<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Contracts\Fonts\FontLibraryReader;
use Thallo\Core\Content\Fonts\FontLibrarySnapshot;

/** A font library holding the families a test names, at generation 1. */
final class FixedFontLibrary implements FontLibraryReader
{
    /** @param list<FontFamilyView> $families */
    public function __construct(private readonly array $families)
    {
    }

    /** One uploaded family with one static face served at the blob route. */
    public static function family(
        string $id,
        string $blob,
        int $weight = 400,
        bool $italic = false,
        bool $removed = false,
        string $fallback = 'system-ui',
    ): FontFamilyView {
        return new FontFamilyView($id, 'Family ' . $id, $fallback, $removed, [[
            'blob_uuid' => $blob, 'url' => '/api/v1/blobs/' . $blob, 'weight_min' => $weight,
            'weight_max' => $weight, 'italic' => $italic, 'variable' => false, 'unknown' => false,
        ]]);
    }

    public function snapshot(): FontLibrarySnapshot
    {
        return new FontLibrarySnapshot(1, $this->families);
    }
}
