<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\SettingsStore;
use App\Settings\SystemKeys;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingSystemChannel;

/**
 * Task 1 (platform-payments-settings spec §2) — SystemKeys prefix routing.
 *
 * Payvia gateway credentials become platform-scoped: every `payvia.`-prefixed settings key
 * must classify as a system key exactly like the exact-match {@see SystemKeys::KEYS} always
 * have — routing get()/putMany()/forget() to the unscoped SystemChannel — AND be excluded
 * from the tenant map all() returns, closing the raw-read path a tenant-scoped `settings`
 * row could otherwise leak through.
 *
 * SettingsStore is constructed directly with a RecordingSystemChannel double (not the real
 * SystemFlags-backed channel resolved from the container) so these tests exercise ONLY
 * SettingsStore's routing logic, independent of the real channel's own storage/caching.
 */
final class SystemKeyPrefixRoutingTest extends AppTestCase
{
    private function store(RecordingSystemChannel $channel): SettingsStore
    {
        return new SettingsStore($this->appContext(), $channel);
    }

    // ---- isSystem() unit matrix -------------------------------------------------------

    public function testIsSystemTrueForExactKeys(): void
    {
        self::assertTrue(SystemKeys::isSystem('admin_url'));
        self::assertTrue(SystemKeys::isSystem('installed'));
    }

    public function testIsSystemTrueForPayviaPrefixedKeys(): void
    {
        self::assertTrue(SystemKeys::isSystem('payvia.default_gateway'));
        self::assertTrue(SystemKeys::isSystem('payvia.gateways.stripe.secret_key'));
    }

    public function testIsSystemPrefixBoundaryExcludesLookalikes(): void
    {
        // Bare 'payvia' (no trailing dot) must NOT match the 'payvia.' prefix.
        self::assertFalse(SystemKeys::isSystem('payvia'));
        // A different key that merely starts with the same letters must NOT match.
        self::assertFalse(SystemKeys::isSystem('payviax.foo'));
    }

    public function testIsSystemFalseForOrdinarySiteKeys(): void
    {
        self::assertFalse(SystemKeys::isSystem('commerce.store_name'));
    }

    // ---- get()/putMany()/forget() routing for payvia.* ---------------------------------

    public function testPayviaKeysRouteGetPutManyForgetToTheSystemChannel(): void
    {
        $channel = new RecordingSystemChannel();
        $store = $this->store($channel);

        $store->putMany([
            'payvia.default_gateway' => 'stripe',
            'payvia.gateways.stripe.secret_key' => 'sk_live_xxx',
        ]);

        self::assertSame(
            ['payvia.default_gateway' => 'stripe', 'payvia.gateways.stripe.secret_key' => 'sk_live_xxx'],
            $channel->puts,
            'putMany() must write payvia.* keys through the system channel',
        );
        self::assertNull(
            $this->connection()->table('settings')->where(['key' => 'payvia.default_gateway'])->first(),
            'payvia.* keys must never land in the tenant `settings` table',
        );

        self::assertSame('stripe', $store->get('payvia.default_gateway'));
        self::assertSame(['payvia.default_gateway'], $channel->getCalls);

        $store->forget('payvia.gateways.stripe.secret_key');
        self::assertSame(['payvia.gateways.stripe.secret_key'], $channel->forgetCalls);
        self::assertNull($store->get('payvia.gateways.stripe.secret_key'));
    }

    public function testPreSeededTenantOwnedPayviaRowIsInvisibleThroughGetAndAll(): void
    {
        // Simulate a pre-migration tenant-owned row that physically still sits in `settings`
        // (e.g. left over from before this routing change, or written around the store).
        $this->connection()->table('settings')->insert([
            'key' => 'payvia.gateways.paystack.secret_key',
            'value' => 'sk_leaked_tenant_row',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $channel = new RecordingSystemChannel();
        $store = $this->store($channel);

        self::assertNull(
            $store->get('payvia.gateways.paystack.secret_key'),
            'a payvia.* row in `settings` must never be read back through get()',
        );
        self::assertArrayNotHasKey(
            'payvia.gateways.paystack.secret_key',
            $store->all(),
            'a payvia.* row in `settings` must be filtered out of the tenant map',
        );
    }

    // ---- exact system keys behave exactly as before ------------------------------------

    public function testExactSystemKeyStillRoutesToTheSystemChannel(): void
    {
        $channel = new RecordingSystemChannel();
        $store = $this->store($channel);

        $store->putMany(['admin_url' => 'https://admin.test']);

        self::assertSame(['admin_url' => 'https://admin.test'], $channel->puts);
        self::assertSame('https://admin.test', $store->get('admin_url'));
        self::assertArrayNotHasKey('admin_url', $store->all());

        $store->forget('admin_url');
        self::assertSame(['admin_url'], $channel->forgetCalls);
    }

    // ---- ordinary (non-payvia, non-exact) keys are unaffected --------------------------

    public function testOrdinaryKeyUnaffectedAcrossAllFourMethods(): void
    {
        $channel = new RecordingSystemChannel();
        $store = $this->store($channel);

        $store->putMany(['commerce.store_name' => 'Acme']);

        // Never reaches the channel.
        self::assertSame([], $channel->puts);
        self::assertSame([], $channel->getCalls);

        self::assertSame('Acme', $store->get('commerce.store_name'));
        self::assertSame('Acme', $store->all()['commerce.store_name'] ?? null);
        self::assertNotNull(
            $this->connection()->table('settings')->where(['key' => 'commerce.store_name'])->first(),
            'ordinary keys must still land in the tenant `settings` table',
        );

        $store->forget('commerce.store_name');
        self::assertSame([], $channel->forgetCalls);
        self::assertNull($store->get('commerce.store_name'));
    }
}
