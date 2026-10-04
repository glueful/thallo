<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Lifecycle\Workspace;

/**
 * Whether tenancy is enforced is read once and remembered briefly: a request asking many times
 * reads the flags once, and a long-running worker still sees a change within seconds.
 */
final class WorkspaceFlagsTest extends AppTestCase
{
    public function testTheFlagsAreReadOnceAndReReadAfterAFewSeconds(): void
    {
        $flags = new class implements SystemChannel {
            public int $reads = 0;
            /** @var array<string, string> */
            public array $values = [];

            public function get(string $key): ?string
            {
                $this->reads++;
                return $this->values[$key] ?? null;
            }

            public function put(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->values[$key]);
            }
        };
        $now = 100.0;
        $workspace = new Workspace($this->appContext(), $flags, static function () use (&$now): float {
            return $now;
        });

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($workspace->enforcementActive());
            self::assertNull($workspace->current());
        }
        self::assertSame(1, $flags->reads, 'one read of tenancy.enabled for ten questions');

        $flags->values = ['tenancy.enabled' => '1', 'tenancy.enable_step' => 'on'];
        $now += 6;
        self::assertTrue($workspace->enforcementActive(), 'a change is seen within seconds');
    }
}
