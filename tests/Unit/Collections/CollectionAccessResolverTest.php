<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Collections\Http\CollectionAccessResolver;
use Thallo\Collections\Schema\AccessPolicy;
use Thallo\Collections\Schema\CollectionDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The api-key branch of the access decision. The session branch needs a booted container
 * (PermissionManager) and is covered by the integration flow tests; the api-key branch is
 * pure — the request carries `api_key_scopes` and the resolver never touches the container.
 *
 * The load-bearing contract: collections are default-deny. A key with NO scopes must hold
 * no collection capabilities, even though the framework's ApiKeyService::scopeSatisfies()
 * treats an empty grant list as "full access" (legacy-key semantics).
 */
final class CollectionAccessResolverTest extends TestCase
{
    public function testEmptyScopeApiKeyIsDeniedOnScopedCollection(): void
    {
        $request = $this->apiKeyRequest(scopes: []);

        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'read'));
        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'write'));
        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'delete'));
    }

    public function testMatchingScopeIsAllowedAndOthersDenied(): void
    {
        $request = $this->apiKeyRequest(scopes: ['collections.posts.read']);

        self::assertTrue($this->resolver()->allows($request, $this->definition(), 'posts', 'read'));
        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'write'));
        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'delete'));
    }

    public function testUnprefixedScopeDoesNotGrant(): void
    {
        // Capabilities are namespaced `collections.{name}.{op}`. A bare `posts.read`
        // scope (e.g. another subsystem's scope family) must not open the collection —
        // previously a collection named like a foreign scope family failed open.
        $request = $this->apiKeyRequest(scopes: ['posts.read']);

        self::assertFalse($this->resolver()->allows($request, $this->definition(), 'posts', 'read'));
    }

    public function testPublicOperationNeedsNoScopes(): void
    {
        $def = $this->definition(new AccessPolicy(
            read: AccessPolicy::PUBLIC,
            write: AccessPolicy::SCOPED,
            delete: AccessPolicy::SCOPED,
        ));
        $request = $this->apiKeyRequest(scopes: []);

        self::assertTrue($this->resolver()->allows($request, $def, 'posts', 'read'));
        self::assertFalse($this->resolver()->allows($request, $def, 'posts', 'write'));
    }

    public function testUnknownCollectionIsTreatedAsScoped(): void
    {
        // Null definition = scoped (never public). The scope gate still applies; the
        // caller resolves the 404 for a matching scope on a nonexistent collection.
        $scoped = $this->apiKeyRequest(scopes: ['collections.ghost.read']);
        self::assertFalse($this->resolver()->allows($this->apiKeyRequest(scopes: []), null, 'ghost', 'read'));
        self::assertTrue($this->resolver()->allows($scoped, null, 'ghost', 'read'));
    }

    private function apiKeyRequest(array $scopes): Request
    {
        $request = new Request();
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $scopes);

        return $request;
    }

    private function resolver(): CollectionAccessResolver
    {
        $context = new ApplicationContext(basePath: \dirname(__DIR__, 3), environment: 'testing');

        return new CollectionAccessResolver(
            $context,
            new AuthenticationManager(new JwtAuthenticationProvider($context)),
        );
    }

    private function definition(?AccessPolicy $policy = null): CollectionDefinition
    {
        return new CollectionDefinition(
            uuid: 'col_test0000000001',
            name: 'posts',
            label: 'Posts',
            tableName: 'coll_posts',
            storageMode: 'table',
            fields: [],
            schemaVersion: 1,
            status: 'active',
            accessPolicy: $policy ?? AccessPolicy::default(),
            fieldOrder: [],
        );
    }
}
