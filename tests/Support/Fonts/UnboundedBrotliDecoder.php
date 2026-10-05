<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Core\Content\Fonts\Brotli\BrotliDecoder;
use Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder;
use Thallo\Core\Content\Fonts\UnreadableFont;

/**
 * The negative control for the bounded-allocation probe: decodes everything, THEN checks the length —
 * the allocation pattern the safety test must reject.
 */
final class UnboundedBrotliDecoder implements BrotliDecoder
{
    public function decode(string $compressed, int $maxOutput): string
    {
        $all = (new PurePhpBrotliDecoder())->decode($compressed, PHP_INT_MAX);
        if (strlen($all) > $maxOutput) {
            throw new UnreadableFont('This font is too large to read');
        }
        return $all;
    }
}
