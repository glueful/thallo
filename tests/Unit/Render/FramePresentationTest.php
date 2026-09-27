<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Layouts\FramePresentation;

/**
 * A layout's Frame over a page with no theme presentation of its own (type layouts plan C1): the
 * shop's product page, live and on the stage, composes the same way — the frame's choices over
 * centered, default chrome, which is what the shop renders today.
 */
final class FramePresentationTest extends TestCase
{
    public function testNoFrameIsTodaysShopPresentation(): void
    {
        self::assertSame(
            [
                'show_title' => true, 'layout' => 'centered', 'header' => 'default', 'footer' => 'default',
                'style_classes' => '',
            ],
            FramePresentation::fixed(null),
        );
        self::assertSame(FramePresentation::fixed(null), FramePresentation::fixed([]));
    }

    public function testTheFramesChoicesApply(): void
    {
        $full = FramePresentation::fixed(['width' => 'full', 'header' => 'hidden', 'footer' => 'hidden']);
        self::assertSame('full', $full['layout']);
        self::assertSame('hidden', $full['header']);
        self::assertSame('hidden', $full['footer']);
        self::assertSame('centered', FramePresentation::fixed(['width' => 'contained'])['layout']);
    }

    public function testAnUnknownValueDegradesToTheDefault(): void
    {
        $odd = FramePresentation::fixed(['width' => 'wide', 'header' => 'faded', 'footer' => 1]);
        self::assertSame('centered', $odd['layout']);
        self::assertSame('default', $odd['header']);
        self::assertSame('default', $odd['footer']);
    }
}
