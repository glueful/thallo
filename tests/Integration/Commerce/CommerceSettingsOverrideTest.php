<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Thallo\Commerce\Settings\SettingsStoreCommerceOverride;

/**
 * Store-settings spec §3.3: the REAL container chain — pack factory →
 * SettingsStoreCommerceOverride → App\Settings\SettingsStore → `settings` rows — reaches
 * Commerce's CommerceSettings reads, with the contract's null-never-throw fallbacks.
 */
final class CommerceSettingsOverrideTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCommerceSettings();
    }

    protected function tearDown(): void
    {
        $this->forgetCommerceSettings();
        parent::tearDown();
    }

    private function forgetCommerceSettings(): void
    {
        foreach (SettingsStoreCommerceOverride::EDITABLE_KEYS as $key) {
            $this->connection()->table('settings')->where(['key' => $key])->delete();
        }
        $this->container()->get(SettingsStore::class)->clearCache();
    }

    private function putSetting(string $key, string $value): void
    {
        $this->container()->get(SettingsStore::class)->putMany([$key => $value]);
    }

    public function testStoredRowWinsAndForgettingRestoresTheConfigDefault(): void
    {
        $context = $this->appContext();

        self::assertSame('USD', CommerceSettings::currency($context));

        $this->putSetting('commerce.currency', 'GHS');
        self::assertSame('GHS', CommerceSettings::currency($context));

        $this->putSetting('commerce.tax.flat_rate_bps', '750');
        self::assertSame(750, CommerceSettings::taxFlatRateBps($context));

        // Clearing DELETES the row (never an empty-string shadow) — the config default returns.
        $this->container()->get(SettingsStore::class)->forget('commerce.currency');
        self::assertSame('USD', CommerceSettings::currency($context));
    }

    public function testResolverIsBoundThroughThePackFactory(): void
    {
        $resolver = $this->container()->get(CommerceSettingsOverride::class);
        self::assertInstanceOf(SettingsStoreCommerceOverride::class, $resolver);
    }

    public function testNonWhitelistedKeysAreNeverServed(): void
    {
        // Even a stored row for a non-editable commerce key must NOT flow through the seam —
        // rate limits and the like stay config-only (spec §3.1's closed set).
        $this->putSetting('commerce.rate_limits.cart', '999');

        $resolver = $this->container()->get(CommerceSettingsOverride::class);
        self::assertNull($resolver->value($this->appContext(), 'commerce.rate_limits.cart'));

        $this->connection()->table('settings')->where(['key' => 'commerce.rate_limits.cart'])->delete();
    }

    public function testDirectConstructionAnswersThroughTheBoundStore(): void
    {
        // The override resolves the pack-owned CommerceSettingsStore contract from the live
        // container; a bare `new` behaves identically to the factory-built binding.
        $this->putSetting('commerce.cart.ttl_days', '7');
        $resolver = new SettingsStoreCommerceOverride();
        self::assertSame('7', $resolver->value($this->appContext(), 'commerce.cart.ttl_days'));
    }

    public function testBlankRowMeansNoOverride(): void
    {
        // A row that somehow holds '' must read as "no override" — config default shows through.
        $this->connection()->table('settings')->insert([
            'key' => 'commerce.currency',
            'value' => '',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(SettingsStore::class)->clearCache();

        self::assertSame('USD', CommerceSettings::currency($this->appContext()));
    }
}
