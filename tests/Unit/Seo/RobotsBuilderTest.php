<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use Thallo\Seo\Sitemap\RobotsBuilder;
use PHPUnit\Framework\TestCase;

final class RobotsBuilderTest extends TestCase
{
    public function testRendersGroupsAndSitemapLine(): void
    {
        $b = new RobotsBuilder(
            groups: [
                ['user_agent' => '*', 'allow' => ['/'], 'disallow' => ['/admin']],
                ['user_agent' => 'BadBot', 'allow' => [], 'disallow' => ['/']],
            ],
            origin: 'https://site.test',
        );
        $txt = $b->render();

        self::assertStringContainsString("User-agent: *\n", $txt);
        self::assertStringContainsString("Allow: /\n", $txt);
        self::assertStringContainsString("Disallow: /admin\n", $txt);
        self::assertStringContainsString("User-agent: BadBot\n", $txt);
        self::assertStringContainsString("Disallow: /\n", $txt);
        self::assertStringContainsString('Sitemap: https://site.test/sitemap.xml', $txt);
    }

    public function testHasOriginFalseWhenEmpty(): void
    {
        self::assertFalse((new RobotsBuilder([], ''))->hasOrigin());
    }
}
