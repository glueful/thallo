<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/** block_script() — closed catalog + per-render dedupe (modern-blocks spec §1). */
final class BlockScriptTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testClosedCatalogEmitsOncePerRenderAndRearmsOnReset(): void
    {
        $ext = $this->ext();
        $ext->resetPerRenderState();

        $tag = (string) $ext->blockScript('gallery');
        self::assertSame(
            '<script defer src="/_thallo/runtime/block-gallery.js"></script>',
            $tag,
        );
        // Dedupe within one render.
        self::assertSame('', (string) $ext->blockScript('gallery'));
        // Independent name still emits.
        self::assertSame(
            '<script defer src="/_thallo/runtime/block-animated-text.js"></script>',
            (string) $ext->blockScript('animated-text'),
        );
        // Closed catalog: unknown names (incl. traversal shapes) emit nothing.
        self::assertSame('', (string) $ext->blockScript('shop'));
        self::assertSame('', (string) $ext->blockScript('../runtime'));
        self::assertSame('', (string) $ext->blockScript(''));

        // Fragment boundary: reset re-arms emission (spec §1 — dedupe is a bandwidth
        // optimization; the asset's own IIFE guard is the correctness authority).
        $ext->resetPerRenderState();
        self::assertNotSame('', (string) $ext->blockScript('gallery'));
    }

    public function testEmittedAssetsExistAndAreServedFingerprinted(): void
    {
        // Every catalog entry must be a real pack asset RuntimeAssetMap can serve.
        $map = $this->container()->get(\Thallo\Render\Templates\RuntimeAssetMap::class);
        foreach (RenderContextExtension::BLOCK_SCRIPT_ASSETS as $name) {
            self::assertNotNull(
                $map->fingerprintedName('block-' . $name . '.js'),
                "block-{$name}.js missing from the runtime asset map",
            );
        }
    }
}
