<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Indexing;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The fail-closed CLOSED SHAPE of EnsureFilterIndexesJob::handle(): only an explicit `null` tenant_uuid
 * may take the unscoped path. Every other value — missing key, empty, whitespace, non-string, malformed
 * — MUST throw before reconcile() can read the tenant-owned content_types. A valid tenant_uuid with no
 * TenantContextRunner binding MUST also throw (reconciling directly would read owned data unscoped).
 *
 * These are the THROW cases; reconcile() is never reached, so the container's Connection /
 * ContentTypeRepository stand-ins are never actually invoked. The tenancy-off (`null`) direct-reconcile
 * path is proved against the real engine in the retrofit harness (Task 13 covers the two-tenant path).
 */
final class EnsureFilterIndexesJobHandleTest extends TestCase
{
    /**
     * @param array<string,mixed> $payload
     */
    private function runHandle(array $payload, bool $bindRunner = false): void
    {
        $container = new class ($bindRunner) implements ContainerInterface {
            public function __construct(private readonly bool $bindRunner)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === SystemFlags::class) {
                    return new class {
                        public function enforcementActive(): bool
                        {
                            return true;
                        }
                    };
                }
                if ($id === TenantContextRunner::class && $this->bindRunner) {
                    // A tolerant fake — never actually invoked in the THROW cases.
                    return new class implements TenantContextRunner {
                        public function runAsTenant(string $tenantUuid, callable $fn): mixed
                        {
                            return $fn();
                        }

                        public function runAsSystem(callable $fn): mixed
                        {
                            return $fn();
                        }

                        public function forEachTenant(callable $fn): void
                        {
                        }
                    };
                }

                // Connection / ContentTypeRepository stand-ins: reconcile() is never reached in the
                // THROW cases, so these opaque objects are never used.
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return match ($id) {
                    Connection::class, ContentTypeRepository::class => true,
                    TenantContextRunner::class => $this->bindRunner,
                    default => false, // no LoggerInterface, no WriteBarrier
                };
            }
        };

        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $context->setContainer($container);

        (new EnsureFilterIndexesJob(['content_type_uuid' => 'ct0000000001'] + $payload, $context))->handle();
    }

    public function testMissingTenantKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant_uuid is required');
        $this->runHandle([]); // no 'tenant_uuid' key at all
    }

    public function testEmptyStringTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid tenant_uuid');
        $this->runHandle(['tenant_uuid' => '']);
    }

    public function testWhitespaceTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runHandle(['tenant_uuid' => '            ']);
    }

    public function testNonStringTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runHandle(['tenant_uuid' => 12345678]);
    }

    public function testMalformedTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Wrong length (too short) and wrong charset (dash) both fail the 12-char nano-id shape.
        $this->runHandle(['tenant_uuid' => 'not-a-nanoid']);
    }

    public function testTooLongTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runHandle(['tenant_uuid' => 'abcdefghijklmnop']); // 16 chars
    }

    public function testValidTenantWithoutRunnerBindingThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tenant runner unavailable');
        // Valid 12-char nano-id, but no TenantContextRunner bound → fail closed (never reconcile unscoped).
        $this->runHandle(['tenant_uuid' => 'abc123XYZ789'], bindRunner: false);
    }
}
