<?php

declare(strict_types=1);

namespace App\Tests\Integration\Capabilities;

use App\Capabilities\CapabilityStateStore;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingSystemChannel;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * The one system-scoped switchboard (schema program Task 7). Read precedence: canonical
 * `capability.<id>.enabled` system key → the legacy `search_enabled` key (thallo.search ONLY) →
 * the thallo.capabilities config map (which this app ships with `thallo.search => false`) →
 * default true. Reads fail SOFT before the system table exists; writes fail EXPLICITLY and read
 * themselves back; the first successful search write retires the legacy key.
 */
final class CapabilityStateStoreTest extends AppTestCase
{
    private RecordingSystemChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingSystemChannel();
    }

    private function store(?SystemChannel $channel = null): CapabilityStateStore
    {
        return new CapabilityStateStore($this->appContext(), $channel ?? $this->channel);
    }

    public function testCanonicalKeyOutranksLegacyAndConfig(): void
    {
        // Config says false, the legacy row says false — the canonical key still wins.
        $this->channel->put('capability.thallo.search.enabled', 'true');
        $this->channel->put('search_enabled', 'false');

        self::assertTrue($this->store()->requested('thallo.search'));
    }

    public function testLegacySearchKeyAnswersOnlyForSearch(): void
    {
        $this->channel->put('search_enabled', 'true');

        self::assertTrue(
            $this->store()->requested('thallo.search'),
            'the legacy row outranks the config map for thallo.search'
        );

        // For every OTHER capability the legacy row means nothing: test.other falls through
        // to the config map (absent) => default true — but prove the legacy key was never
        // consulted for it.
        $this->channel->getCalls = [];
        self::assertTrue($this->store()->requested('test.other'));
        self::assertNotContains('search_enabled', $this->channel->getCalls);
    }

    public function testConfigMapAnswersWhenNoRowExists(): void
    {
        // This app ships thallo.search => false in the thallo.capabilities map.
        self::assertFalse($this->store()->requested('thallo.search'));
    }

    public function testAbsentEverywhereDefaultsToEnabled(): void
    {
        self::assertTrue($this->store()->requested('test.unheard.of'));
    }

    public function testWriteReadsBackAndPersistsUnderTheCanonicalKey(): void
    {
        $this->store()->put('thallo.analytics', false);

        self::assertSame('false', $this->channel->puts['capability.thallo.analytics.enabled'] ?? null);
        self::assertFalse($this->store()->requested('thallo.analytics'));
    }

    public function testFirstSuccessfulSearchWriteRetiresTheLegacyKey(): void
    {
        $this->channel->put('search_enabled', 'true');

        $this->store()->put('thallo.search', true);

        self::assertContains('search_enabled', $this->channel->forgetCalls, 'one authority, not two');
        self::assertNull($this->channel->get('search_enabled'));
        self::assertTrue($this->store()->requested('thallo.search'));
    }

    public function testReadsFailSoftToConfigBeforeTheSystemTableExists(): void
    {
        $throwing = new class implements SystemChannel {
            public function get(string $key): ?string
            {
                throw new \PDOException('relation "thallo_system_flags" does not exist');
            }

            public function put(string $key, string $value): void
            {
                throw new \PDOException('relation "thallo_system_flags" does not exist');
            }

            public function forget(string $key): void
            {
                throw new \PDOException('relation "thallo_system_flags" does not exist');
            }
        };

        self::assertFalse($this->store($throwing)->requested('thallo.search'), 'config map stands');
        self::assertTrue($this->store($throwing)->requested('test.other'), 'default stands');
    }

    public function testWritesFailExplicitlyBeforeTheSystemTableExists(): void
    {
        $throwing = new class implements SystemChannel {
            public function get(string $key): ?string
            {
                return null;
            }

            public function put(string $key, string $value): void
            {
                throw new \PDOException('relation "thallo_system_flags" does not exist');
            }

            public function forget(string $key): void
            {
            }
        };

        $this->expectException(\PDOException::class);
        $this->store($throwing)->put('thallo.analytics', true);
    }

    public function testAWriteThatDoesNotReadBackRefusesToReportSuccess(): void
    {
        $lossy = new class implements SystemChannel {
            public function get(string $key): ?string
            {
                return null; // accepted the write, lost the row
            }

            public function put(string $key, string $value): void
            {
            }

            public function forget(string $key): void
            {
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not persist');
        $this->store($lossy)->put('thallo.analytics', true);
    }
}
