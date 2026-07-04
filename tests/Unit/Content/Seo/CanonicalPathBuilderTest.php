<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Seo;

use App\Content\Seo\CanonicalPathBuilder;
use App\Content\Seo\PathRenderer;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use PHPUnit\Framework\TestCase;

final class CanonicalPathBuilderTest extends TestCase
{
    private function builder(): CanonicalPathBuilder
    {
        $locales = $this->createStub(LocaleManagerInterface::class);
        $locales->method('default')->willReturn('en');
        return new CanonicalPathBuilder(new PathRenderer('/{locale}/{type}/{slug}'), $locales);
    }

    /** The 4-way matrix: root/prefixed × default/other locale. */
    public function testPrefixedVsRootAcrossLocales(): void
    {
        $builder = $this->builder();

        self::assertSame('/blog/hello', $builder->pathFor('blog', false, 'en', 'hello'));
        self::assertSame('/fr/blog/bonjour', $builder->pathFor('blog', false, 'fr', 'bonjour'));
        self::assertSame('/about', $builder->pathFor('pages', true, 'en', 'about'));
        self::assertSame('/fr/a-propos', $builder->pathFor('pages', true, 'fr', 'a-propos'));
    }
}
