<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Seo;

use App\Content\Seo\PathRenderer;
use PHPUnit\Framework\TestCase;

final class PathRendererTest extends TestCase
{
    public function testRendersRelativePathFromTemplate(): void
    {
        $renderer = new PathRenderer('/{locale}/{type}/{slug}');

        self::assertSame('/en/blog/hello', $renderer->render('blog', 'en', 'hello'));
    }

    public function testPrefixesPublicUrlBaseWhenConfigured(): void
    {
        $renderer = new PathRenderer('/{locale}/{type}/{slug}', 'https://site.test/');

        self::assertSame('https://site.test/fr/docs/start', $renderer->render('docs', 'fr', 'start'));
    }

    public function testXDefaultPathOmitsDefaultLocaleSegment(): void
    {
        $renderer = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');

        self::assertSame('/blog/hello', $renderer->renderDefaultLocale('blog', 'hello'));
    }

    public function testCustomTemplateCanIgnoreLocale(): void
    {
        $renderer = new PathRenderer('/content/{type}/{slug}');

        self::assertSame('/content/news/today', $renderer->render('news', 'fr', 'today'));
    }

    public function testRootVariantsIgnoreTheRouteTemplate(): void
    {
        // Root grammar is FIXED /{locale?}/{slug} (spec §5): with a custom
        // template, dropping {type} would print /content/about while the
        // parser accepts /about — so the template is ignored entirely.
        $renderer = new PathRenderer('/content/{type}/{slug}', 'https://site.test/');

        self::assertSame('https://site.test/fr/a-propos', $renderer->renderRoot('fr', 'a-propos'));
        self::assertSame('https://site.test/about', $renderer->renderRootDefaultLocale('about'));
    }

    public function testRootVariantsWithoutBase(): void
    {
        $renderer = new PathRenderer();

        self::assertSame('/fr/a-propos', $renderer->renderRoot('fr', 'a-propos'));
        self::assertSame('/about', $renderer->renderRootDefaultLocale('about'));
    }
}
