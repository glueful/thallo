<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/** Rich text as the plain words a reader sees, sanitised first — for packs that have no Twig. */
interface HtmlTextExtractor
{
    public function text(string $html): string;
}
