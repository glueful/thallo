<?php

declare(strict_types=1);

namespace Thallo\Contracts\Navigation;

/** One place a menu is shown: a region (header, footer) or an entry, named as the admin names it. */
final class MenuUse
{
    public const REGION = 'region';
    public const ENTRY = 'entry';

    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $label,
        /** The entry's content type slug; null for a region. */
        public readonly ?string $contentType = null,
    ) {
    }
}
