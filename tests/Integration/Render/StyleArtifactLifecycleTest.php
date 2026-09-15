<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleArtifactCompiler;
use Thallo\Contracts\Style\StyleCompileFailed;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\CompiledStyleArtifacts;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\ThemeLocator;

/**
 * Visual builder spec §2.4: the compiled style artifact is compiled from the active theme's
 * vocabulary, published under storage/cache/style, linked after the theme artifact, served by
 * hash immutably, joins the appearance fingerprint, and is compiled through one contract seam
 * (provision, theme switch) that fails without activating anything.
 */
final class StyleArtifactLifecycleTest extends AppTestCase
{
    private function basePath(): string
    {
        return $this->container()->get(ApplicationContext::class)->getBasePath();
    }

    private function activeHash(): string
    {
        return $this->container()->get(CompiledStyleArtifacts::class)
            ->forTheme($this->container()->get(ThemeLocator::class))['hash'];
    }

    public function testTheHeadLinksTheCompiledArtifactAfterTheThemeArtifact(): void
    {
        $html = (string) $this->handle(Request::create('/', 'GET'))->getContent();
        $head = substr($html, 0, strpos($html, '</head>') ?: 0);

        $file = CompiledStyleArtifacts::fileName($this->activeHash());
        self::assertStringContainsString('href="/theme-assets/' . $file . '"', $head);
        self::assertSame(1, preg_match('~href="/theme-assets/(theme-[0-9a-f]{16}\.css)"~', $head, $m));
        self::assertLessThan(strpos($head, $file), strpos($head, $m[1]), 'the theme artifact first, then settings');
        self::assertSame(3, preg_match_all('~<link rel="stylesheet"~', $head), 'layers, theme, settings');
    }

    public function testTheCompiledArtifactIsServedByHashImmutably(): void
    {
        $hash = $this->activeHash();
        $res = $this->handle(Request::create('/theme-assets/' . CompiledStyleArtifacts::fileName($hash), 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        self::assertStringStartsWith("@layer settings {\n", (string) $res->getContent());
        self::assertStringContainsString('.t-pt-lg {', (string) $res->getContent());
        self::assertFileExists($this->basePath() . '/storage/cache/style/' . CompiledStyleArtifacts::fileName($hash));

        $unknown = $this->handle(Request::create('/theme-assets/settings-0000000000000000.css', 'GET'));
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function testTheHelperNamesTheBoundThemesArtifact(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        self::assertSame(
            '/theme-assets/' . CompiledStyleArtifacts::fileName($this->activeHash()),
            $extension->settingsStylesheetUrl(),
        );
    }

    public function testTheCompiledArtifactHashJoinsTheAppearanceFingerprint(): void
    {
        self::assertStringContainsString('-s' . substr($this->activeHash(), 0, 8), $this->appearanceFingerprint());
    }

    public function testTheCompilerSeamCompilesTheActiveThemeAndRefusesAnUnknownOne(): void
    {
        $compiler = $this->container()->get(StyleArtifactCompiler::class);
        self::assertSame($this->activeHash(), $compiler->compile());
        self::assertSame($this->activeHash(), $compiler->compile('default'));
        $vocabulary = $this->container()->get(ThemeLocator::class)->vocabulary();
        self::assertSame(StyleCompiler::hash($vocabulary), $compiler->compile());

        $this->expectException(StyleCompileFailed::class);
        $compiler->compile('no-such-theme');
    }

    public function testAFailedCompileLeavesThePreviousArtifactIntact(): void
    {
        $before = $this->activeHash();
        $file = $this->basePath() . '/storage/cache/style/' . CompiledStyleArtifacts::fileName($before);
        $css = (string) file_get_contents($file);
        try {
            $this->container()->get(StyleArtifactCompiler::class)->compile('no-such-theme');
            self::fail('expected StyleCompileFailed');
        } catch (StyleCompileFailed) {
        }
        self::assertSame($css, (string) file_get_contents($file));
        self::assertSame($before, $this->activeHash());
    }
}
