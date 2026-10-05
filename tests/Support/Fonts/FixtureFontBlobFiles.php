<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Core\Content\Fonts\FontBlobFiles;
use Thallo\Core\Content\Fonts\UnreadableFont;

/**
 * Blob files from the fixture set: each blob ID maps to a fixture path, handed out as a temporary
 * copy the way the storage implementation does. `$afterRead` runs once a copy is made — a test uses it
 * to change the blob between the (slow) read and the transaction.
 */
final class FixtureFontBlobFiles implements FontBlobFiles
{
    /** @param array<string, string> $uuidToPath */
    public function __construct(private array $uuidToPath, private readonly ?\Closure $afterRead = null)
    {
    }

    public function map(string $blobUuid, string $path): void
    {
        $this->uuidToPath[$blobUuid] = $path;
    }

    public function localPath(string $blobUuid): string
    {
        $path = $this->uuidToPath[$blobUuid] ?? null;
        if ($path === null) {
            throw new UnreadableFont('That file isn\'t in the media library');
        }
        $copy = (string) tempnam(sys_get_temp_dir(), 'font_');
        copy($path, $copy);
        if ($this->afterRead !== null) {
            ($this->afterRead)($blobUuid);
        }
        return $copy;
    }
}
