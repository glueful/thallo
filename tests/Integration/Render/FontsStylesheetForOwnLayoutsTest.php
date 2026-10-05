<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\RequestFontSnapshot;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A theme with a layout of its own, written before the font library (final review): it calls
 * `theme_colors_style()` but not `fonts_stylesheet_url()`, so the uploaded families' faces and
 * utilities must still reach the page — through `theme_colors_style()` itself, once.
 */
final class FontsStylesheetForOwnLayoutsTest extends AppTestCase
{
    private const ID = 'Ab3dE5fG7hJ9';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('font_families')->insert([
            'id' => self::ID, 'name' => 'Brand', 'fallback' => 'serif', 'removed_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->table('font_faces')->insert([
            'id' => 'faceAb3dE5fG', 'family_id' => self::ID, 'blob_uuid' => 'blobAb3dE5fG',
            'weight_min' => 400, 'weight_max' => 400, 'italic' => false, 'variable' => false,
            'unknown' => false, 'created_at' => $now,
        ]);
        $this->container()->get(RequestFontSnapshot::class)->refresh();
    }

    private function render(string $template): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate($template)->render([]);
    }

    public function testALayoutThatNeverAsksStillLinksTheFontsStylesheet(): void
    {
        $html = $this->render('<head>{{ theme_colors_style() }}</head>');
        self::assertSame(1, preg_match_all('~<link rel="stylesheet" href="[^"]*/fonts-[0-9a-f]+\.css">~', $html));
    }

    public function testALayoutThatAsksGetsItOnce(): void
    {
        $html = $this->render(
            '<head><link rel="stylesheet" href="{{ fonts_stylesheet_url() }}">{{ theme_colors_style() }}</head>',
        );
        self::assertSame(1, preg_match_all('~/fonts-[0-9a-f]+\.css~', $html));
    }

    public function testEachRenderDecidesForItself(): void
    {
        $this->render('{{ fonts_stylesheet_url() }}');
        self::assertSame(1, preg_match_all('~/fonts-[0-9a-f]+\.css~', $this->render('{{ theme_colors_style() }}')));
    }

    public function testNoLibraryFamilyLinksNothing(): void
    {
        $this->connection()->table('font_families')->where('id', '=', self::ID)
            ->update(['removed_at' => gmdate('Y-m-d H:i:s')]);
        $this->container()->get(RequestFontSnapshot::class)->refresh();
        self::assertStringNotContainsString('fonts-', $this->render('{{ theme_colors_style() }}'));
    }
}
