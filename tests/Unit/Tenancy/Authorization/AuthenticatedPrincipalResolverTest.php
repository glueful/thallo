<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\AuthenticatedPrincipalResolver;
use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticatedPrincipalResolverTest extends TestCase
{
    public function testUserIdentityAndArrayShapesProduceEquivalentContext(): void
    {
        $resolver = new AuthenticatedPrincipalResolver();
        $identity = new Request();
        $identity->attributes->set('auth.user', new UserIdentity(
            uuid: 'user00000001',
            roles: ['administrator'],
            scopes: ['content.*'],
        ));
        $identity->attributes->set('jwt.claims', ['issuer' => 'test']);

        $array = new Request();
        $array->attributes->set('user', [
            'uuid' => 'user00000001',
            'roles' => ['administrator'],
            'claims' => ['scopes' => ['content.*']],
        ]);
        $array->attributes->set('jwt.claims', ['issuer' => 'test']);

        $identityPrincipal = $resolver->resolve($identity);
        $arrayPrincipal = $resolver->resolve($array);

        self::assertSame($identityPrincipal, $arrayPrincipal);
        self::assertNotNull($identityPrincipal);
        self::assertSame(
            $resolver->aegisContext($identity, $identityPrincipal),
            $resolver->aegisContext($array, $arrayPrincipal),
        );
    }

    public function testMissingOrEmptyIdentityFailsClosed(): void
    {
        $resolver = new AuthenticatedPrincipalResolver();
        self::assertNull($resolver->resolve(new Request()));

        $request = new Request();
        $request->attributes->set('user', ['uuid' => '']);
        self::assertNull($resolver->resolve($request));
    }
}
