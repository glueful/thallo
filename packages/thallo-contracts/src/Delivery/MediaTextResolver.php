<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * The words a file carries in the media library, for RENDERED pages: its alt text and caption.
 * Only for a file a page may show (the same rule as {@see MediaUrlResolver}); anything else, and
 * a file with none set, answers empty strings.
 */
interface MediaTextResolver
{
    /** @return array{alt: string, caption: string} */
    public function texts(string $uuid): array;
}
