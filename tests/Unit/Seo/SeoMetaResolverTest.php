<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Seo\Meta\SeoMetaResolver;
use PHPUnit\Framework\TestCase;

final class SeoMetaResolverTest extends TestCase
{
    private function reader(array $entryFields): ContentDeliveryReader
    {
        return new class ($entryFields) implements ContentDeliveryReader {
            public function __construct(private array $fields)
            {
            }
            public function listPublished(string $t, string $l, int $limit = 20): array
            {
                return [];
            }
            public function findPublished(string $t, string $l, string $s): ?array
            {
                return ['entry_uuid' => 'e-1', 'fields' => $this->fields];
            }
            public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
            {
                return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
            }
        };
    }

    private function resolver(ContentDeliveryReader $reader, ?array $override): SeoMetaResolver
    {
        return new SeoMetaResolver(
            $reader,
            static fn (string $entryUuid, string $locale): ?array => $override,
            fallbacks: [
                'blog' => ['title_field' => 'title', 'description_field' => 'excerpt', 'image_field' => 'cover'],
            ],
            defaults: [
                'site_name' => 'Lemma',
                'default_og_image' => 'https://site.test/og.png',
                'title_template' => '{title} — {site_name}',
            ],
        );
    }

    public function testOverrideWins(): void
    {
        $r = $this->resolver($this->reader(['title' => 'Field Title']), [
            'title' => 'Override Title', 'description' => 'Override desc',
            'og_title' => null, 'og_description' => null, 'og_image' => null,
            'twitter_card' => 'summary_large_image', 'robots' => 'noindex',
        ]);
        $meta = $r->resolve('t-1', 'blog', 'hello', 'en');
        self::assertSame('Override Title', $meta['title']);
        self::assertSame('Override desc', $meta['description']);
        self::assertSame('summary_large_image', $meta['twitter']['card']);
        self::assertSame('noindex', $meta['robots']);
    }

    public function testFallsBackToMappedFieldThenDefaultTemplate(): void
    {
        $r = $this->resolver($this->reader(['title' => 'Field Title', 'excerpt' => 'From field']), null);
        $meta = $r->resolve('t-1', 'blog', 'hello', 'en');
        // title falls back to the mapped field, then title_template applied.
        self::assertSame('Field Title — Lemma', $meta['title']);
        self::assertSame('From field', $meta['description']);
        // no override + no og image field mapped value → site default og image.
        self::assertSame('https://site.test/og.png', $meta['og']['image']);
        self::assertSame('index', $meta['robots']);
    }

    public function testReturnsNullWhenNoPublishedEntry(): void
    {
        $reader = new class implements ContentDeliveryReader {
            public function listPublished(string $t, string $l, int $limit = 20): array
            {
                return [];
            }
            public function findPublished(string $t, string $l, string $s): ?array
            {
                return null;
            }
            public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
            {
                return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
            }
        };
        $r = $this->resolver($reader, null);
        self::assertNull($r->resolve('t-1', 'blog', 'missing', 'en'));
    }
}
