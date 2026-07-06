<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Auth\UserIdentity;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Http\ActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit-style tests for ActorResolver kept in the Integration/Collections suite
 * so they run alongside the other collections integration tests.
 */
final class ActorResolverTest extends TestCase
{
    private ActorResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ActorResolver();
    }

    public function testApiKeyBranchReturnsApiKeyActor(): void
    {
        $request = new Request();
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_uuid', 'k-1');

        $actor = $this->resolver->resolve($request);

        self::assertSame('api_key', $actor->type);
        self::assertSame('k-1', $actor->id);
    }

    public function testAuthUserIdentityWithAdminRoleReturnsAdminActor(): void
    {
        $identity = new UserIdentity('u-1', ['administrator']);

        $request = new Request();
        $request->attributes->set('auth.user', $identity);

        $actor = $this->resolver->resolve($request);

        self::assertSame('admin', $actor->type);
        self::assertSame('u-1', $actor->id);
    }

    public function testUserArrayFallbackWithNonAdminRoleReturnsUserActor(): void
    {
        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'u-2', 'roles' => ['editor']]);

        $actor = $this->resolver->resolve($request);

        self::assertSame('user', $actor->type);
        self::assertSame('u-2', $actor->id);
    }
}
