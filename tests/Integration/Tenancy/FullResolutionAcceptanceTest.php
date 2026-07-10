<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantRequestMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Runtime\TenantProfileMiddleware;

final class FullResolutionAcceptanceTest extends TestCase
{
    public function testProfileIsInertBeforeActivation(): void
    {
        $context = $this->context(false);
        $result = (new TenantProfileMiddleware($context))->handle(
            Request::create('https://unmapped.test/'),
            static fn (): string => 'bootstrap',
            'public'
        );

        self::assertSame('bootstrap', $result);
    }

    public function testActiveProfileDelegatesThroughTheNeutralExtensionContract(): void
    {
        $seen = [];
        $delegate = new class ($seen) implements TenantRequestMiddleware {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $this->seen = array_values(array_map('strval', $params));
                return $next($request);
            }
        };
        $context = $this->context(true, $delegate);
        $result = (new TenantProfileMiddleware($context))->handle(
            Request::create('https://tenant.test/'),
            static fn (): string => 'resolved',
            'public',
            'soft'
        );

        self::assertSame('resolved', $result);
        self::assertSame(['public', 'soft'], $seen);
    }

    public function testActivationFailsClosedWhenExtensionDelegateIsUnavailable(): void
    {
        $response = (new TenantProfileMiddleware($this->context(true)))->handle(
            Request::create('https://tenant.test/'),
            static fn (): string => 'must-not-run',
            'public'
        );

        self::assertSame(503, $response->getStatusCode());
    }

    private function context(
        bool $ready,
        ?TenantRequestMiddleware $delegate = null
    ): ApplicationContext {
        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $readiness = new class ($ready) implements FullTenantResolutionReadiness {
            public function __construct(private readonly bool $ready)
            {
            }

            public function isReady(ApplicationContext $context): bool
            {
                return $this->ready;
            }
        };
        $context->setContainer(new class ($readiness, $delegate) implements ContainerInterface {
            public function __construct(
                private readonly FullTenantResolutionReadiness $readiness,
                private readonly ?TenantRequestMiddleware $delegate,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    FullTenantResolutionReadiness::class => $this->readiness,
                    TenantRequestMiddleware::class => $this->delegate
                        ?? throw new \RuntimeException('Missing delegate'),
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return $id === FullTenantResolutionReadiness::class
                    || ($id === TenantRequestMiddleware::class && $this->delegate !== null);
            }
        });

        return $context;
    }
}
