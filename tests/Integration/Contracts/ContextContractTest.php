<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Context\Context;

final class ContextContractTest extends AppTestCase
{
    public function testContextResolvesAndExposesLocaleAndSettings(): void
    {
        $ctx = $this->container()->get(Context::class);
        self::assertInstanceOf(Context::class, $ctx);

        self::assertNotSame('', $ctx->defaultLocale());
        self::assertContains($ctx->defaultLocale(), $ctx->enabledLocales());

        // Unknown setting returns the provided default.
        self::assertSame('fallback', $ctx->setting('definitely.missing.key', 'fallback'));

        // Path rendering delegates to the SEO PathRenderer.
        self::assertStringContainsString('post', $ctx->renderPath('post', $ctx->defaultLocale(), 'hello'));
    }
}
