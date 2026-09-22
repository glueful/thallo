<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\SiteContext;
use Thallo\Seo\Meta\SeoMetaResolver;

/**
 * Settings › General › Site name reached nothing a visitor sees: templates and og:site_name read
 * RENDER_SITE_NAME, the SEO title read SEO_SITE_NAME, and the admin's field fed only the starter
 * header. Renaming the site in the admin changed no page. The setting is now the one source.
 */
final class SiteNameTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->container()->get(SettingsStore::class)->forget('site_name');
        parent::tearDown();
    }

    public function testTheAdminsSiteNameIsWhatTemplatesAndTheSeoTitleSee(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['site_name' => 'Acme Studio']);

        self::assertSame('Acme Studio', SiteContext::build($this->appContext(), 'en')['name']);

        // The resolver is a shared service built before the rename: it must read the name now,
        // not the one it was made with.
        $resolver = $this->container()->get(SeoMetaResolver::class);
        $template = new \ReflectionMethod(SeoMetaResolver::class, 'applyTemplate');
        self::assertSame('About — Acme Studio', $template->invoke($resolver, 'About'));

        $this->container()->get(GeneralSettings::class)->save(['site_name' => 'Acme']);
        self::assertSame('About — Acme', $template->invoke($resolver, 'About'));
    }
}
