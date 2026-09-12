<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use Thallo\Core\Support\ActorHelper;
use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ActorHelperTest extends TestCase
{
    public function testReadsUuidFromAuthUserIdentity(): void
    {
        $request = new Request();
        $request->attributes->set('auth.user', new UserIdentity('user-uuid-1', ['user'], []));

        self::assertSame('user-uuid-1', ActorHelper::uuidFromRequest($request));
    }

    public function testFallsBackToPostAuthUserArrayWhenEnricherAbsent(): void
    {
        $request = new Request();
        // No `auth.user` (enricher unbound) — only the always-present `user` array attribute.
        $request->attributes->set('user', ['uuid' => 'user-uuid-2', 'email' => 'a@b.test']);

        self::assertSame('user-uuid-2', ActorHelper::uuidFromRequest($request));
    }

    public function testReturnsNullWhenNeitherAttributeCarriesAUuid(): void
    {
        self::assertNull(ActorHelper::uuidFromRequest(new Request()));

        $uuidless = new Request();
        $uuidless->attributes->set('user', ['email' => 'x@y.test']);
        self::assertNull(ActorHelper::uuidFromRequest($uuidless));
    }
}
