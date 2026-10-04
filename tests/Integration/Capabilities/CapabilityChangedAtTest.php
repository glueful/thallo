<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Every capability switch records the state version it advanced to (search block spec §3.5.7), in
 * the same transaction, so a consumer that compares against it notices an off/on cycle it never
 * observed — and a rolled-back switch records nothing.
 */
final class CapabilityChangedAtTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key IN ('capability.state_version', "
            . "'capability.test.flip.enabled', 'capability.test.flip.changed_at')"
        );
        parent::tearDown();
    }

    public function testAWriteRecordsTheVersionItAdvancedTo(): void
    {
        $store = $this->container()->get(CapabilityStateStore::class);
        $version = $this->container()->get(CapabilityStateVersion::class);

        $store->put('test.flip', true);
        $v1 = $version->current();
        self::assertSame($v1, $this->flags()->get('capability.test.flip.changed_at'));

        $store->put('test.flip', false);
        $store->put('test.flip', true);
        self::assertSame((string) ((int) $v1 + 2), $this->flags()->get('capability.test.flip.changed_at'));
    }

    public function testARolledBackWriteRecordsNothing(): void
    {
        $before = $this->flags()->get('capability.test.flip.changed_at');
        try {
            $this->connection()->transaction(function (): void {
                $this->container()->get(CapabilityStateStore::class)->put('test.flip', true);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $this->flags()->get('capability.test.flip.changed_at'));
    }

    private function flags(): SystemFlags
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->clearCache();
        return $flags;
    }
}
