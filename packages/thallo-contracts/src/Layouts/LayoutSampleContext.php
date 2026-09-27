<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/**
 * A surface whose frame renders from variables it builds itself rather than from an entry (type
 * layouts spec §5.4, §7.3): the product page's frame renders a product. The layout stage asks it for
 * the sample's variables and renders the frame as it renders any other. Such a surface's
 * {@see LayoutSurface::placeholder()} answers frame variables too.
 */
interface LayoutSampleContext
{
    /** @return array<string,mixed>|null the frame's variables for a published sample; null when it is gone */
    public function sampleContext(string $target, string $sample): ?array;
}
