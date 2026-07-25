<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\CommerceSettingsController;
use Thallo\Commerce\Settings\SettingsStoreCommerceOverride;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Store-settings spec §3.4: GET/PUT /v1/admin/commerce/settings. Controller-level like
 * ProductLinkApiTest (route middleware/permission gating is the authorization matrix's job).
 * The two behaviours that carry the weight: clear-DELETES-the-row (config default shows
 * through, never an ''-shadow), and the currency lock that fires only on an actual CHANGE once
 * any variant exists.
 */
final class CommerceSettingsEndpointTest extends AppTestCase
{
    private const TENANT = 'setttestten1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (SettingsStoreCommerceOverride::EDITABLE_KEYS as $key) {
            $this->connection()->table('settings')->where(['key' => $key])->delete();
        }
        $this->connection()->getPDO()->exec(
            "DELETE FROM commerce_orders WHERE tenant_uuid = '" . self::TENANT . "'"
        );
        $this->connection()->getPDO()->exec(
            "DELETE FROM commerce_variants WHERE tenant_uuid = '" . self::TENANT . "'"
        );
        $this->connection()->getPDO()->exec(
            "DELETE FROM commerce_products WHERE tenant_uuid = '" . self::TENANT . "'"
        );
        $this->container()->get(SettingsStore::class)->clearCache();
    }

    public function testGetReturnsEffectiveDefaultAndOverriddenPerKey(): void
    {
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertFalse($data['currency_locked']);
        self::assertFalse($data['has_priced_products']);
        foreach (SettingsStoreCommerceOverride::EDITABLE_KEYS as $key) {
            self::assertArrayHasKey($key, $data['settings']);
            self::assertFalse($data['settings'][$key]['overridden']);
            self::assertSame($data['settings'][$key]['default'], $data['settings'][$key]['value']);
        }
        self::assertSame('USD', $data['settings']['commerce.currency']['value']);
        self::assertSame('ORD-{seq}', $data['settings']['commerce.orders.number_format']['value']);
    }

    public function testPutStoresValidatedValuesAndGetReflectsThem(): void
    {
        $data = $this->data($this->put([
            'commerce.currency' => 'ghs',              // normalizes to upper case
            'commerce.tax.flat_rate_bps' => 750,
            'commerce.orders.number_format' => 'THL-{seq}',
        ]));

        self::assertSame('GHS', $data['settings']['commerce.currency']['value']);
        self::assertTrue($data['settings']['commerce.currency']['overridden']);
        self::assertSame(750, $data['settings']['commerce.tax.flat_rate_bps']['value']);
        self::assertSame('THL-{seq}', $data['settings']['commerce.orders.number_format']['value']);
        // Untouched keys stay at their defaults, unoverridden.
        self::assertFalse($data['settings']['commerce.cart.ttl_days']['overridden']);
    }

    public function testClearDeletesTheRowAndTheDefaultShowsThrough(): void
    {
        $this->put(['commerce.tax.flat_rate_bps' => 900]);
        $data = $this->data($this->put(['commerce.tax.flat_rate_bps' => null]));

        self::assertSame(0, $data['settings']['commerce.tax.flat_rate_bps']['value']);
        self::assertFalse($data['settings']['commerce.tax.flat_rate_bps']['overridden']);
        // The ROW is gone — not an empty-string shadow.
        self::assertNull(
            $this->connection()->table('settings')
                ->where(['key' => 'commerce.tax.flat_rate_bps'])->first()
        );
    }

    /** @dataProvider invalidValues */
    public function testValidationRejectsOutOfShapeValues(string $key, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        $this->put([$key => $value]);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        return [
            'currency not ISO' => ['commerce.currency', 'EURO'],
            'bps above bound' => ['commerce.tax.flat_rate_bps', 10001],
            'format missing seq' => ['commerce.orders.number_format', 'NO-SEQ-HERE'],
            'expiry below bound' => ['commerce.orders.expiry_minutes', 4],
            'ttl below bound' => ['commerce.cart.ttl_days', 0],
            'threshold above bound' => ['commerce.reports.low_stock_threshold', 1001],
            'non-numeric int field' => ['commerce.cart.ttl_days', 'lots'],
        ];
    }

    public function testBoundsAreInclusive(): void
    {
        $data = $this->data($this->put([
            'commerce.tax.flat_rate_bps' => 10000,
            'commerce.orders.expiry_minutes' => 5,
            'commerce.cart.ttl_days' => 365,
            'commerce.reports.low_stock_threshold' => 0,
        ]));
        self::assertSame(10000, $data['settings']['commerce.tax.flat_rate_bps']['value']);
        self::assertSame(0, $data['settings']['commerce.reports.low_stock_threshold']['value']);
    }

    public function testCurrencyLocksOnActualChangeOnceAnOrderExists(): void
    {
        // Spec §3.4 REVISED (user feedback 2026-07-25): the lock's predicate is recorded MONEY
        // history — orders — never mere catalog contents. A setup store full of draft products
        // stays freely changeable (see the reassignment test below).
        $this->seedOrder();

        $data = $this->data($this->controller()->show(Request::create('/x')));
        self::assertTrue($data['currency_locked']);

        // Same-value save is idempotent — never a 422.
        $this->put(['commerce.currency' => 'USD']);

        // An actual change trips the lock, with the reason on the currency FIELD.
        try {
            $this->put(['commerce.currency' => 'EUR']);
            self::fail('Expected the currency lock ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringContainsString(
                'locked once orders exist',
                (string) ($e->firstError('commerce.currency') ?? ''),
            );
        }
    }

    public function testSetupTimeCurrencyChangeRewritesVariantCodesKeepingAmounts(): void
    {
        // Draft products exist but no orders: changing currency is allowed AND consistent —
        // every variant's currency CODE follows the store (checkout hard-rejects mismatches),
        // while the integer amounts stay exactly as the merchant typed them.
        $this->seedVariant();

        $data = $this->data($this->controller()->show(Request::create('/x')));
        self::assertFalse($data['currency_locked']);
        self::assertTrue($data['has_priced_products']);

        $saved = $this->data($this->put(['commerce.currency' => 'GHS']));
        self::assertSame('GHS', $saved['settings']['commerce.currency']['value']);

        $variant = $this->connection()->table('commerce_variants')
            ->where(['uuid' => 'settvar00001'])->first();
        self::assertSame('GHS', $variant['currency']);
        self::assertSame(1999, (int) $variant['price']);
    }

    public function testSellerIdentityFieldsRoundTripAndClear(): void
    {
        $data = $this->data($this->put([
            'commerce.seller.name' => 'Aurora Lighting Co.',
            'commerce.seller.address' => "12 Osu Lane\nAccra",
            'commerce.seller.tax_id' => 'GH-TIN-0042',
        ]));

        self::assertSame('Aurora Lighting Co.', $data['settings']['commerce.seller.name']['value']);
        self::assertTrue($data['settings']['commerce.seller.name']['overridden']);
        self::assertSame('GH-TIN-0042', $data['settings']['commerce.seller.tax_id']['value']);

        // Clearing returns to the (null-tolerant) config default — '' on the wire.
        $cleared = $this->data($this->put(['commerce.seller.name' => null]));
        self::assertSame('', $cleared['settings']['commerce.seller.name']['value']);
        self::assertFalse($cleared['settings']['commerce.seller.name']['overridden']);
    }

    public function testSellerIdentityLengthBoundsAreEnforced(): void
    {
        $this->expectException(ValidationException::class);
        $this->put(['commerce.seller.tax_id' => str_repeat('x', 65)]);
    }

    public function testPaymentsStatusReportsManualModeWithoutAGatewayExtension(): void
    {
        // This install has no payvia — the honest posture is manual collection, and the block
        // must NEVER carry key material (booleans only, structurally impossible here).
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertSame('manual', $data['payments']['mode']);
        self::assertNull($data['payments']['default_gateway']);
        self::assertSame([], $data['payments']['gateways']);
    }

    public function testCurrencyChangesFreelyOnAnEmptyStore(): void
    {
        $data = $this->data($this->put(['commerce.currency' => 'EUR']));
        self::assertSame('EUR', $data['settings']['commerce.currency']['value']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $body */
    private function put(array $body): Response
    {
        return $this->controller()->update(Request::create(
            '/x',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        ));
    }

    private function controller(): CommerceSettingsController
    {
        return $this->container()->get(CommerceSettingsController::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function seedVariant(): void
    {
        $this->connection()->table('commerce_products')->insert([
            'uuid' => 'settprod0001',
            'tenant_uuid' => self::TENANT,
            'slug' => 'settprod0001',
            'name' => 'Settings lock product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection()->table('commerce_variants')->insert([
            'uuid' => 'settvar00001',
            'tenant_uuid' => self::TENANT,
            'product_uuid' => 'settprod0001',
            'sku' => 'settvar00001',
            'option_values' => '[]',
            'price' => 1999,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);
    }

    private function seedOrder(): void
    {
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => 'settord00001',
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-settord00001',
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1999,
            'grand_total' => 1999,
        ]);
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }
}
