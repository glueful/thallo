<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\SettingsStore;
use App\Settings\SystemKeyReconciler;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Task 1 — settings system/site split with verified data-move.
 *
 * System keys (see {@see \App\Settings\SystemKeys}) live in the unscoped system channel
 * ({@see \Thallo\Tenancy\System\SystemFlags}); everything else stays in the soon-to-be-scoped
 * `settings` table. The reconciler moves legacy system-key rows out of `settings` with
 * channel-wins precedence and verify-before-delete, idempotently.
 */
final class SettingsSplitTest extends AppTestCase
{
    private function store(): SettingsStore
    {
        return $this->container()->get(SettingsStore::class);
    }

    private function channel(): SystemChannel
    {
        return $this->container()->get(SystemChannel::class);
    }

    private function reconciler(): SystemKeyReconciler
    {
        return $this->container()->get(SystemKeyReconciler::class);
    }

    /** @return array<string,mixed>|null */
    private function rawSettingsRow(string $key): ?array
    {
        return $this->connection()->table('settings')->where(['key' => $key])->first();
    }

    public function testSystemKeyWriteLandsInChannelNotSettings(): void
    {
        $this->store()->putMany(['scheduler_enabled' => '1']);

        self::assertSame('1', $this->channel()->get('scheduler_enabled'), 'channel holds the system key');
        self::assertNull($this->rawSettingsRow('scheduler_enabled'), 'system key must NOT touch `settings`');
        self::assertSame('1', $this->store()->get('scheduler_enabled'), 'store reads it back through the channel');
    }

    public function testUnknownKeyRoutesToSettings(): void
    {
        $this->store()->putMany(['listing_types' => 'post']);

        self::assertNotNull($this->rawSettingsRow('listing_types'), 'site key lives in `settings`');
        self::assertNull($this->channel()->get('listing_types'), 'site key must NOT reach the channel');
        self::assertSame('post', $this->store()->get('listing_types'));
    }

    public function testReconcilerMovesLegacyRow(): void
    {
        $this->seedLegacySetting('installed', '1');

        $moved = $this->reconciler()->reconcile();

        self::assertContains('installed', $moved);
        self::assertSame('1', $this->channel()->get('installed'), 'value now lives in the channel');
        self::assertNull($this->rawSettingsRow('installed'), 'legacy `settings` row removed');
    }

    public function testChannelWinsAndLegacyRowStillRemoved(): void
    {
        $this->channel()->put('installed', '1');
        $this->seedLegacySetting('installed', '0'); // stale legacy value

        $this->reconciler()->reconcile();

        self::assertSame('1', $this->channel()->get('installed'), 'existing channel value is never clobbered');
        self::assertNull($this->rawSettingsRow('installed'), 'legacy row removed once channel is verified');
    }

    public function testReconcilerIsIdempotent(): void
    {
        $this->seedLegacySetting('installed', '1');
        $this->seedLegacySetting('admin_url', 'https://admin.test');

        $first = $this->reconciler()->reconcile();
        sort($first);
        self::assertSame(['admin_url', 'installed'], $first);

        // Second run: nothing left in `settings`, so nothing moved and nothing deleted.
        $second = $this->reconciler()->reconcile();
        self::assertSame([], $second);
        self::assertSame('1', $this->channel()->get('installed'));
        self::assertSame('https://admin.test', $this->channel()->get('admin_url'));
    }

    private function seedLegacySetting(string $key, string $value): void
    {
        $this->connection()->table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->store()->clearCache();
    }
}
