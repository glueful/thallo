<?php

declare(strict_types=1);

namespace Thallo\Contracts\Fonts;

/** Reads the workspace's font library (block typeface spec §2), for render and the admin alike. */
interface FontLibraryReader
{
    /** One consistent view of the library, taken at one generation. */
    public function snapshot(): FontLibrarySnapshotView;
}
