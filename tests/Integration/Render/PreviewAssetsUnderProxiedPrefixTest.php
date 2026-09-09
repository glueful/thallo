<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The canvas preview injected `/_preview.css` and `/_preview-bridge.js` at the site root. A
 * web-server rule that serves every `.css`/`.js` URL from disk (CloudPanel's template does)
 * answered those with 404 even after the operator proxied the documented `/theme-assets/*` and
 * `/_thallo/*` prefixes, so the designer loaded unstyled and without its bridge. PHP-served
 * assets all live under `/_thallo/` now: one documented prefix pair covers everything.
 */
final class PreviewAssetsUnderProxiedPrefixTest extends AppTestCase
{
    public function testPreviewSupportAssetsAreRoutedUnderTheThalloPrefix(): void
    {
        self::assertNotNull($this->findRoute('GET', '/_thallo/preview.css'));
        self::assertNotNull($this->findRoute('GET', '/_thallo/preview-bridge.js'));
        self::assertNull($this->findRoute('GET', '/_preview.css'), 'the root-level alias is gone');
        self::assertNull($this->findRoute('GET', '/_preview-bridge.js'));
    }

    public function testTheInjectedTagsPointUnderTheThalloPrefix(): void
    {
        self::assertSame('/_thallo/preview.css', RenderController::PREVIEW_CSS_PATH);
        self::assertSame('/_thallo/preview-bridge.js', RenderController::PREVIEW_BRIDGE_PATH);
    }
}
