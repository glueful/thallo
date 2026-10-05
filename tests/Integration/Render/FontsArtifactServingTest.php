<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\FontsArtifact;
use Thallo\Render\Style\FontsArtifacts;
use Thallo\Render\Style\RequestFontSnapshot;

/**
 * The fonts stylesheet from library to page (block typeface spec §3.4; plan Task 6): one snapshot
 * drives the CSS, the link and the page cache's fingerprint; the file is written before any page
 * links it, served immutably by hash, and an old hash keeps serving its own bytes after an edit.
 */
final class FontsArtifactServingTest extends AppTestCase
{
    private function family(string $id, string $fallback = 'serif'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('blobs')->insert([
            'uuid' => 'blob' . substr($id, 0, 8), 'name' => 'f.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $id . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => $now,
        ]);
        $this->connection()->table('font_families')->insert([
            'id' => $id, 'name' => 'Family', 'fallback' => $fallback, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->table('font_faces')->insert([
            'id' => 'face' . substr($id, 0, 8), 'family_id' => $id, 'blob_uuid' => 'blob' . substr($id, 0, 8),
            'weight_min' => 400, 'weight_max' => 400, 'italic' => false, 'variable' => false, 'unknown' => false,
            'created_at' => $now,
        ]);
        // A new request reads the library again.
        $this->container()->get(RequestFontSnapshot::class)->refresh();
    }

    private function extension(): RenderContextExtension
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        return $extension;
    }

    private function currentHash(): string
    {
        $snapshot = $this->container()->get(RequestFontSnapshot::class)->current();
        self::assertNotNull($snapshot);
        return FontsArtifact::hash($snapshot);
    }

    public function testNoLibraryNoLink(): void
    {
        self::assertNull($this->extension()->fontsStylesheetUrl());
        $html = (string) $this->handle(Request::create('/', 'GET'))->getContent();
        self::assertStringNotContainsString('fonts-', $html);
        self::assertStringNotContainsString('-l', substr($this->appearanceFingerprint(), -20));
    }

    public function testOneSnapshotDrivesCssLinkAndFingerprint(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $hash = $this->currentHash();
        self::assertSame('/theme-assets/' . FontsArtifacts::fileName($hash), $this->extension()->fontsStylesheetUrl());
        self::assertStringContainsString('-l' . substr($hash, 0, 8), $this->appearanceFingerprint());

        $html = (string) $this->handle(Request::create('/', 'GET'))->getContent();
        $head = substr($html, 0, strpos($html, '</head>') ?: 0);
        $link = '<link rel="stylesheet" href="/theme-assets/' . FontsArtifacts::fileName($hash) . '">';
        self::assertStringContainsString($link, $head);
        self::assertLessThan(
            strpos($head, FontsArtifacts::fileName($hash)),
            strpos($head, 'settings-'),
            'linked right after the settings artifact',
        );
    }

    public function testPublishBeforeLink(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $url = (string) $this->extension()->fontsStylesheetUrl();
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        self::assertNotEmpty(glob($base . '/storage/cache/fonts/*/' . basename($url)) ?: []);
    }

    public function testTheStylesheetIsServedByHashImmutably(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $url = (string) $this->extension()->fontsStylesheetUrl();
        $res = $this->handle(Request::create($url, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        $utility = '.t-font-Ab3dE5fG7hJ9{font-family:"thallo-font-Ab3dE5fG7hJ9"';
        self::assertStringContainsString($utility, (string) $res->getContent());
        $unknown = $this->handle(Request::create('/theme-assets/fonts-0000000000000000.css', 'GET'));
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function testAnOldHashServesItsOwnVersionAfterAnEdit(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $old = (string) $this->extension()->fontsStylesheetUrl();
        $oldBytes = (string) $this->handle(Request::create($old, 'GET'))->getContent();

        $this->family('Zz3dE5fG7hJ9', 'monospace');
        $new = (string) $this->extension()->fontsStylesheetUrl();
        self::assertNotSame($old, $new);

        $res = $this->handle(Request::create($old, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame($oldBytes, (string) $res->getContent());
        self::assertStringNotContainsString('Zz3dE5fG7hJ9', (string) $res->getContent());
        $newBytes = (string) $this->handle(Request::create($new, 'GET'))->getContent();
        self::assertStringContainsString('Zz3dE5fG7hJ9', $newBytes);
    }

    public function testAThemePreviewServesTheStylesheetUncached(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $file = basename((string) $this->extension()->fontsStylesheetUrl());
        $token = $this->container()->get(\Thallo\Core\Content\Preview\PreviewMinter::class)
            ->mint('entry0000001', 'en', null, 'default');
        $res = $this->handle(Request::create('/_thallo/preview-assets/' . $token . '/' . $file, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        self::assertStringContainsString('thallo-font-Ab3dE5fG7hJ9', (string) $res->getContent());
    }

    public function testTheStageLinksTheSameHashUnderItsPreviewAssets(): void
    {
        $this->family('Ab3dE5fG7hJ9');
        $public = basename((string) $this->extension()->fontsStylesheetUrl());
        $extension = $this->extension();
        $extension->setAssetContext('/_thallo/preview-assets/token123', null);
        self::assertSame('/_thallo/preview-assets/token123/' . $public, $extension->fontsStylesheetUrl());
    }
}
