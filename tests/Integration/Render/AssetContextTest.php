<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/**
 * Default-theme-font spec §3: the render-scoped asset context. setAssetContext(null,
 * null) restores constructor-backed live-theme behavior; an alternate context wins for
 * BOTH URL composition and (Task 3) existence checks; the combined reset clears it.
 * Real request-to-request isolation is proved separately in PreviewSessionTest.
 */
final class AssetContextTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testNullNullContextIsConstructorBackedLiveBehavior(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();
        // Live behavior: /theme-assets base + ?t=…&v=… busters for a real theme file.
        $url = $ext->asset('site.css');
        self::assertStringStartsWith('/theme-assets/site.css?t=', $url);
        self::assertStringContainsString('&v=', $url);
    }

    public function testAlternateContextOverridesBaseAndSkipsBusters(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();
        $ext->setAssetContext('/_preview-assets/tok123', sys_get_temp_dir());
        self::assertSame('/_preview-assets/tok123/site.css', $ext->asset('site.css'));
    }

    public function testResetClearsAPreviewContextBackToLive(): void
    {
        $ext = $this->ext();
        $ext->setAssetContext('/_preview-assets/tok123', sys_get_temp_dir());
        $ext->resetPerRenderState(); // preview → live: live must not see the preview base
        self::assertStringStartsWith('/theme-assets/site.css?t=', $ext->asset('site.css'));

        // live → preview: the preview must not inherit live buster behavior either.
        $ext->setAssetContext('/_preview-assets/tok456', sys_get_temp_dir());
        self::assertSame('/_preview-assets/tok456/site.css', $ext->asset('site.css'));
        $ext->resetPerRenderState();
    }
}
