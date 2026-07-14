<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Http\Controllers\PublicOriginController;
use Thallo\Tenancy\Http\Controllers\TenancyResolutionController;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\Resolution\FullResolutionActivation;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The GET/PUT public-origin admin routes must sit behind the system guard chain (auth + tenant_system
 * + content_permission:tenancy.manage) and map to PublicOriginController. The controller returns the
 * status envelope, field-scoped 422s (no coercion of malformed types), and 409 while activation is in
 * progress. Route structure is asserted via the router; behavior via direct controller invocation
 * (there is no authed-admin HTTP harness for these system routes — mirror TenancyEnablementApiTest).
 */
final class PublicOriginApiTest extends AppTestCase
{
    private SystemFlags $flags;
    private ResolutionActivationStore $activationStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = $this->container()->get(SystemFlags::class);
        $this->activationStore = new ResolutionActivationStore($this->flags, $this->connection());
    }

    public function testPublicOriginRoutesAreSystemRoutesBoundToController(): void
    {
        $expected = [
            'GET:/v1/admin/tenancy/public-origin',
            'PUT:/v1/admin/tenancy/public-origin',
        ];
        $found = [];
        foreach ($this->container()->get(Router::class)->getStaticRoutes() as $key => $route) {
            if (!$route instanceof Route || !in_array($key, $expected, true)) {
                continue;
            }
            $found[] = $key;
            self::assertContains('auth', $route->getMiddleware());
            self::assertContains('tenant_system', $route->getMiddleware());
            self::assertContains('content_permission:tenancy.manage', $route->getMiddleware());
            self::assertSame(PublicOriginController::class, $route->getHandler()[0]);
        }
        sort($expected);
        sort($found);
        self::assertSame($expected, $found);
    }

    public function testShowReturnsStatusEnvelope(): void
    {
        $res = $this->controller()->show();
        self::assertSame(200, $res->getStatusCode());
        self::assertArrayHasKey('public_origin', $this->body($res)['data']);
    }

    public function testUpdateRejectsInvalidHostWith422(): void
    {
        $res = $this->controller()->update($this->put([
            'base_domain' => 'apex.example',
            'default_hosts' => ['*.apex.example'],
        ]));
        self::assertSame(422, $res->getStatusCode());
        self::assertArrayHasKey('default_hosts', $this->body($res)['error']['details']);
    }

    public function testUpdateRejectedWith409WhenActivationInProgress(): void
    {
        $this->activationStore->compareAndSet(
            ResolutionActivationStep::INACTIVE,
            ResolutionActivationStep::MAPPING_HOSTS
        );
        $res = $this->controller()->update($this->put([
            'base_domain' => 'apex.example',
            'default_hosts' => ['apex.example'],
        ]));
        self::assertSame(409, $res->getStatusCode());
    }

    public function testUpdateRejectsMalformedTypesWithoutCoercion(): void
    {
        $badBase = $this->controller()->update($this->put([
            'base_domain' => ['apex.example'],
            'default_hosts' => ['apex.example'],
        ]));
        self::assertSame(422, $badBase->getStatusCode());

        $badHost = $this->controller()->update($this->put([
            'base_domain' => 'apex.example',
            'default_hosts' => ['apex.example', 42],
        ]));
        self::assertSame(422, $badHost->getStatusCode());
    }

    public function testResetRouteIsSystemRouteBoundToResolutionController(): void
    {
        foreach ($this->container()->get(Router::class)->getStaticRoutes() as $key => $route) {
            if (!$route instanceof Route || $key !== 'POST:/v1/admin/tenancy/resolution/reset') {
                continue;
            }
            self::assertContains('auth', $route->getMiddleware());
            self::assertContains('tenant_system', $route->getMiddleware());
            self::assertContains('content_permission:tenancy.manage', $route->getMiddleware());
            self::assertSame(TenancyResolutionController::class, $route->getHandler()[0]);
            return;
        }
        self::fail('POST /v1/admin/tenancy/resolution/reset must be registered');
    }

    public function testResetRejectedWhenNotFailed(): void
    {
        $res = $this->resolutionController()->reset(); // step INACTIVE
        self::assertSame(409, $res->getStatusCode());
    }

    public function testResetReturnsResolutionStatusFromFailed(): void
    {
        $this->activationStore->recordFailure(ResolutionActivationStep::MAPPING_HOSTS, 'boom');
        $res = $this->resolutionController()->reset();
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('inactive', $this->body($res)['data']['resolution']['step']);
    }

    // --- harness ------------------------------------------------------------------------------

    private function resolutionController(): TenancyResolutionController
    {
        return new TenancyResolutionController($this->container()->get(FullResolutionActivation::class));
    }

    private function controller(): PublicOriginController
    {
        $file = ['tenancy' => ['public_origin' => ['reserved_labels' => ['www', 'api', 'admin']]]];
        $loader = new class ($file) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $context = new ApplicationContext('/tmp/glueful-public-origin-api-test', 'testing');
        $context->setConfigLoader($loader);

        $store = new PublicOriginStore($context, $this->flags, $this->connection());
        $store->hydrate();
        $service = new PublicOriginService(
            $context,
            $store,
            $this->activationStore,
            new EnablementLock($this->connection()),
        );

        return new PublicOriginController($service);
    }

    /** @param array<string,mixed> $body */
    private function put(array $body): Request
    {
        return Request::create('/v1/admin/tenancy/public-origin', 'PUT', [], [], [], [], (string) json_encode($body));
    }

    /** @return array<string,mixed> */
    private function body(\Glueful\Http\Response $res): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $res->getContent(), true);

        return $decoded;
    }
}
