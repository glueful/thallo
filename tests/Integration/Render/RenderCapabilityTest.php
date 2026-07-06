<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class RenderCapabilityTest extends AppTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.render'),
            'lemma.render must be registered and enabled by default',
        );
    }

    public function testConfigDefaults(): void
    {
        $ctx = $this->appContext();
        self::assertSame('default', config($ctx, 'lemma_render.theme', null));
        self::assertSame('', config($ctx, 'lemma_render.homepage_entry', null));
        self::assertContains('theme-assets', (array) config($ctx, 'lemma_render.reserved_prefixes', []));
        self::assertContains('sitemap.xml', (array) config($ctx, 'lemma_render.reserved_exact', []));
    }
}
