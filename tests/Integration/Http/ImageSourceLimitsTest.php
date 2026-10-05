<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Services\ImageSecurityValidator;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Resized copies (the media library's thumbnails, srcset candidates, a Container's background)
 * are made from the original, which the image security check measures first. Its cap is a guard
 * against decompression bombs, not a size policy: an ordinary banner, or a 12-megapixel phone
 * photo, must pass — a 2170px banner once broke every thumbnail and background made from it.
 */
final class ImageSourceLimitsTest extends AppTestCase
{
    public function testOrdinaryLargeImagesPassTheSourceCheck(): void
    {
        // At least Thallo's default (6000); a site's IMAGE_MAX_WIDTH/HEIGHT may raise it.
        self::assertGreaterThanOrEqual(6000, (int) config($this->appContext(), 'image.limits.max_width'));
        self::assertGreaterThanOrEqual(6000, (int) config($this->appContext(), 'image.limits.max_height'));

        $validator = $this->container()->get(ImageSecurityValidator::class);
        self::assertTrue($validator->validateDimensions(4032, 3024), 'a 12-megapixel phone photo');

        $banner = tempnam(sys_get_temp_dir(), 'banner') . '.png';
        $image = imagecreatetruecolor(2170, 725);
        imagepng($image, $banner);
        try {
            self::assertTrue($validator->validateImageFile($banner, 'png'), 'a 2170px banner');
        } finally {
            @unlink($banner);
        }
    }

    public function testTheCapStillRefusesAnAbsurdlyLargeImage(): void
    {
        $this->expectException(\Throwable::class);
        $this->container()->get(ImageSecurityValidator::class)->validateDimensions(20000, 100);
    }
}
