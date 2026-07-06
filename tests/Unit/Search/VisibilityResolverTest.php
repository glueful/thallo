<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Search\Query\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityResolverTest extends TestCase
{
    /** @param array<string,array{slug:string,public_delivery:bool}> $types keyed by uuid */
    private function types(array $types): ContentTypeReader
    {
        return new class ($types) implements ContentTypeReader {
            /** @param array<string,array{slug:string,public_delivery:bool}> $types */
            public function __construct(private array $types)
            {
            }
            public function findUuidBySlug(string $slug): ?string
            {
                foreach ($this->types as $uuid => $t) {
                    if ($t['slug'] === $slug) {
                        return $uuid;
                    }
                }
                return null;
            }
            public function isPublicDelivery(string $uuid): bool
            {
                return $this->types[$uuid]['public_delivery'] ?? false;
            }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
                return null;
            }
            public function deliveryTypes(): array
            {
                return $this->types;
            }
        };
    }

    /** @return array<string,array{slug:string,public_delivery:bool}> */
    private function catalog(): array
    {
        return [
            'ct-pub' => ['slug' => 'pages', 'public_delivery' => true],
            'ct-blog' => ['slug' => 'blog', 'public_delivery' => false],
            'ct-news' => ['slug' => 'news', 'public_delivery' => false],
            'ct-secret' => ['slug' => 'secret', 'public_delivery' => false],
        ];
    }

    public function testAnonymousSeesOnlyPublicTypes(): void
    {
        $ctx = (new VisibilityResolver($this->types($this->catalog())))->resolve(null);
        self::assertFalse($ctx->allAccess);
        self::assertSame(['ct-pub'], $ctx->visibleTypeUuids);
    }

    public function testReadContentGrantsAllAccess(): void
    {
        $ctx = (new VisibilityResolver($this->types($this->catalog())))->resolve(['read:content']);
        self::assertTrue($ctx->allAccess);
    }

    public function testEmptyScopesArrayIsFullAccessKey(): void
    {
        // A key with NO scope restriction ([]) satisfies everything (ApiKeyService semantics).
        $ctx = (new VisibilityResolver($this->types($this->catalog())))->resolve([]);
        self::assertTrue($ctx->allAccess);
    }

    public function testScopedSlugsResolveToUuidsPlusPublic(): void
    {
        $resolver = new VisibilityResolver($this->types($this->catalog()));
        $ctx = $resolver->resolve(['read:content:blog', 'read:content:news', 'read:other']);
        self::assertFalse($ctx->allAccess);
        self::assertContains('ct-blog', $ctx->visibleTypeUuids);
        self::assertContains('ct-news', $ctx->visibleTypeUuids);
        self::assertContains('ct-pub', $ctx->visibleTypeUuids); // public types are always visible
        self::assertNotContains('ct-secret', $ctx->visibleTypeUuids);
    }

    public function testWildcardScopeMatchesEveryTypeLikeDelivery(): void
    {
        // Delivery parity: DeliveryAccessMiddleware accepts wildcard scopes via fnmatch, so
        // read:content:* must make every type visible in search too.
        $ctx = (new VisibilityResolver($this->types($this->catalog())))->resolve(['read:content:*']);
        self::assertFalse($ctx->allAccess);
        self::assertSame(array_keys($this->catalog()), $ctx->visibleTypeUuids);
    }

    public function testPartialWildcardMatchesItsSubset(): void
    {
        $ctx = (new VisibilityResolver($this->types($this->catalog())))->resolve(['read:content:n*']);
        self::assertContains('ct-news', $ctx->visibleTypeUuids);
        self::assertContains('ct-pub', $ctx->visibleTypeUuids); // public
        self::assertNotContains('ct-blog', $ctx->visibleTypeUuids);
        self::assertNotContains('ct-secret', $ctx->visibleTypeUuids);
    }

    public function testIsTypeAccessibleMirrorsDeliveryParity(): void
    {
        $resolver = new VisibilityResolver($this->types($this->catalog()));

        $scoped = $resolver->resolve(['read:content:blog']);
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-blog'));    // scoped
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-pub'));     // public_delivery
        self::assertFalse($resolver->isTypeAccessible($scoped, 'ct-secret')); // neither → 403

        $all = $resolver->resolve(['read:content']);
        self::assertTrue($resolver->isTypeAccessible($all, 'ct-secret'));     // all-access
    }
}
