<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Templates\DatabaseTemplateLoader;
use Thallo\Render\Templates\RenderTemplateLoader;
use Thallo\Render\Templates\TemplateLinter;
use Thallo\Render\Templates\TemplatePolicy;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;
use Twig\Error\LoaderError;

final class DbTemplateLoaderTest extends AppTestCase
{
    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    /** A fresh environment over the composite loader for the given theme. */
    private function env(string $theme = 'default'): Environment
    {
        $base = $this->appContext()->getBasePath();
        $factory = new TwigFactory(
            new ThemeLocator($theme, $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
            new DatabaseTemplateLoader(
                $this->repo(),
                $this->container()->get(TemplateLinter::class),
                $theme,
            ),
        );
        return $factory->environment();
    }

    public function testDbOverrideShadowsFilesystemAndDeactivateFallsBack(): void
    {
        $env = $this->env();
        $fsRendered = $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]);

        $this->repo()->save('default', 'entry.twig', 'DBOVERRIDE:{{ entry.fields.title }}', null);
        $loader = $env->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        self::assertSame(
            'DBOVERRIDE:T',
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );

        $this->repo()->deactivate('default', 'entry.twig');
        $loader->resetForRender();
        self::assertSame(
            $fsRendered,
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );
    }

    /** THE ChainLoader regression (spec §3): a miss must not poison later existence. */
    public function testDbOnlyTemplateResolvesAfterAnEarlierMissInTheSameProcess(): void
    {
        $env = $this->env();
        $loader = $env->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);

        self::assertFalse($loader->exists('entry/interview.twig')); // the poisoning miss
        $this->repo()->save('default', 'entry/interview.twig', 'INTERVIEW:{{ entry.x }}', null);
        $loader->resetForRender();
        self::assertTrue($loader->exists('entry/interview.twig'));
        self::assertSame('INTERVIEW:1', $env->render('entry/interview.twig', ['entry' => ['x' => 1]]));
    }

    public function testEverySaveIsANewCompiledCacheEntryAndOldVersionsStayImmutable(): void
    {
        $this->repo()->save('default', 'k.twig', 'one', null);
        $env = $this->env();
        $loader = $env->getLoader();
        $keyOne = $loader->getCacheKey('k.twig');

        $this->repo()->save('default', 'k.twig', 'two', null);
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        self::assertNotSame($keyOne, $loader->getCacheKey('k.twig')); // version in the key
        self::assertSame('two', $env->render('k.twig'));
        self::assertTrue($loader->isFresh('k.twig', 0));

        // Policy version is part of the key (spec §3/§4): a tightening bumps
        // TemplatePolicy::CACHE_VERSION and orphans every compiled DB template.
        self::assertStringContainsString(
            ':policy:' . TemplatePolicy::CACHE_VERSION,
            $loader->getCacheKey('k.twig'),
        );
    }

    /** Compile-time enforcement: a row written AROUND the API never executes. */
    public function testMaliciousRowInsertedViaSqlFailsAtCompileNotAtSave(): void
    {
        // Straight SQL — bypasses the save-time lint entirely.
        $this->repo()->save('default', 'evil.twig', 'placeholder', null);
        $map = $this->repo()->overrideMap('default');
        $this->connection()->table('render_template_versions')
            ->where('uuid', '=', $map['evil.twig'])
            ->update(['source' => "{{ constant('PHP_VERSION') }}"]);

        $env = $this->env();
        $this->expectException(LoaderError::class);
        $env->render('evil.twig');
    }

    public function testNewlyAllowlistedStateFunctionsExecuteThroughDbOverride(): void
    {
        $source = <<<'TWIG'
        {% set preview = is_preview() %}
        {% set claimed = claim_priority_image() %}
        {% set colorMode = color_mode_enabled() %}
        {% set colorScript = color_mode_script() %}
        {% set globalColors = theme_colors_style() %}
        {% set scope = theme_style_scope('blue', 'slate') %}
        {{ colorScript }}{{ globalColors }}{{ scope.class }}{{ scope.style }}DB-RUNTIME-OK
        TWIG;

        $this->repo()->save('default', 'policy-v17-functions.twig', $source, null);
        self::assertStringContainsString(
            'DB-RUNTIME-OK',
            $this->env()->render('policy-v17-functions.twig'),
        );
    }

    public function testNoDbLoaderMeansPureFilesystemBehavior(): void
    {
        $base = $this->appContext()->getBasePath();
        $factory = new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        );
        $env = $factory->environment();
        self::assertNotInstanceOf(RenderTemplateLoader::class, $env->getLoader());

        // An active DB override is INVISIBLE without the DB loader (kill-switch seam).
        $this->repo()->save('default', 'entry.twig', 'DBOVERRIDE:x', null);
        self::assertStringNotContainsString(
            'DBOVERRIDE',
            $env->render('entry.twig', ['entry' => ['fields' => ['title' => 'T']]]),
        );
    }
}
