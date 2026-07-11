<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\Purge\PurgeResourceRegistry;

final class PurgeResourceRegistryTest extends TestCase
{
    public function testDependenciesAreOrderedBeforeDependents(): void
    {
        $registry = new PurgeResourceRegistry();
        $registry->register($this->handler('tables', ['media']));
        $registry->register($this->handler('cache', ['tables']));
        $registry->register($this->handler('media', []));

        $ids = array_map(static fn(PurgeHandler $handler): string => $handler->id(), $registry->ordered());
        self::assertLessThan(array_search('tables', $ids, true), array_search('media', $ids, true));
        self::assertLessThan(array_search('cache', $ids, true), array_search('tables', $ids, true));
    }

    public function testCycleAndUnknownDependencyFailClosed(): void
    {
        $registry = new PurgeResourceRegistry();
        $registry->register($this->handler('a', ['b']));
        $registry->register($this->handler('b', ['a']));

        $this->expectException(\RuntimeException::class);
        $registry->ordered();
    }

    /** @param list<string> $dependencies */
    private function handler(string $id, array $dependencies): PurgeHandler
    {
        return new class ($id, $dependencies) implements PurgeHandler {
            /** @param list<string> $dependencies */
            public function __construct(private readonly string $id, private readonly array $dependencies)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function dependsOn(): array
            {
                return $this->dependencies;
            }

            public function prepare(ApplicationContext $context, string $tenantUuid): array
            {
                return [];
            }

            public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
            {
            }

            public function verify(ApplicationContext $context, string $tenantUuid): bool
            {
                return true;
            }
        };
    }
}
