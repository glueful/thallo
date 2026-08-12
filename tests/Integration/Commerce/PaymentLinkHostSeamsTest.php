<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Support\HttpsUrl;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Payments\PaymentLinkReturnSigner;
use Thallo\Commerce\Payments\ThalloPaymentLinkPublicUrlProvider;
use Thallo\Commerce\Payments\ThalloPaymentLinkReturnUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Task 11 (payment-links spec §2.3): the two HOST SEAMS Thallo binds over Commerce's engine
 * defaults — the admin-mint public URL composer and the checkout return/cancel composer — plus
 * the app.key-derived {@see PaymentLinkReturnSigner} they and the receipt routes share.
 *
 * Both providers compose from {@see CanonicalPublicOriginResolver} ONLY (never a request `Host`),
 * and both answer null rather than non-compliant output when the canonical origin cannot satisfy
 * the engine's contract — which the engine turns into its typed unavailable outcomes.
 */
final class PaymentLinkHostSeamsTest extends AppTestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_payment_links');
        $pdo->exec('DELETE FROM commerce_order_events');
        $pdo->exec('DELETE FROM commerce_orders');
        parent::tearDown();
    }

    // ==================================================================
    // Container wiring
    // ==================================================================

    public function testThalloRebindsBothEngineSeamsOverTheUnavailableDefaults(): void
    {
        self::assertInstanceOf(
            ThalloPaymentLinkPublicUrlProvider::class,
            $this->container()->get(PaymentLinkPublicUrlProvider::class),
        );
        self::assertInstanceOf(
            ThalloPaymentLinkReturnUrlProvider::class,
            $this->container()->get(PaymentLinkReturnUrlProvider::class),
        );
    }

    // ==================================================================
    // Public (mint) URL provider
    // ==================================================================

    public function testComposesTheCanonicalHttpsUrlWithTheTokenAsTheFinalSegment(): void
    {
        $url = $this->publicProvider('https://shop.example')->urlFor($this->appContext(), self::TOKEN);

        self::assertSame('https://shop.example/checkout/pay/' . self::TOKEN, $url);
    }

    public function testTheComposedUrlSatisfiesEveryRuleTheEngineValidatorEnforces(): void
    {
        $url = (string) $this->publicProvider('https://shop.example')->urlFor($this->appContext(), self::TOKEN);
        $parts = (array) parse_url($url);

        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertNotSame('', $parts['host'] ?? '');
        foreach (['user', 'pass', 'port', 'query', 'fragment'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $parts, "the mint URL must carry no {$forbidden}");
        }
        self::assertSame(1, substr_count($url, self::TOKEN));
        $segments = explode('/', (string) ($parts['path'] ?? ''));
        self::assertSame(self::TOKEN, end($segments));
    }

    public function testTheEngineItselfAcceptsTheComposedUrlEndToEnd(): void
    {
        // The strongest possible proof of contract compliance: mintPublic() validates the
        // provider's output BEFORE it writes a row, so a returned URL means it passed.
        $order = $this->seedPayableOrder();

        $minted = $this->serviceWith($this->publicProvider('https://shop.example'))
            ->mintPublic($this->appContext(), '', $order, null, 'actorminter1');

        self::assertStringStartsWith('https://shop.example/checkout/pay/', (string) $minted['url']);
    }

    public function testANonHttpsOriginYieldsNullRatherThanAnUnopenableUrl(): void
    {
        self::assertNull($this->publicProvider('http://localhost')->urlFor($this->appContext(), self::TOKEN));
    }

    public function testAnOriginCarryingAPortYieldsNullBecauseTheEngineForbidsOne(): void
    {
        self::assertNull($this->publicProvider('https://shop.example:8443')->urlFor($this->appContext(), self::TOKEN));
    }

    public function testAMalformedTokenIsRefusedBeforeAnyUrlIsComposed(): void
    {
        $provider = $this->publicProvider('https://shop.example');

        self::assertNull($provider->urlFor($this->appContext(), 'not-a-token'));
        self::assertNull($provider->urlFor($this->appContext(), strtoupper(self::TOKEN)));
        self::assertNull($provider->urlFor($this->appContext(), self::TOKEN . "\n"));
    }

    // ==================================================================
    // Return/cancel URL provider
    // ==================================================================

    public function testComposesSignedAbsoluteHttpsReturnAndCancelHandles(): void
    {
        $linkUuid = Utils::generateNanoID();

        $urls = $this->returnProvider('https://shop.example')->urlsFor($this->appContext(), $linkUuid);

        self::assertIsArray($urls);
        self::assertTrue(HttpsUrl::isAbsoluteHttps($urls['return']));
        self::assertTrue(HttpsUrl::isAbsoluteHttps($urls['cancel']));
        self::assertStringStartsWith('https://shop.example/checkout/pay/return/' . $linkUuid . '/', $urls['return']);
        self::assertStringStartsWith('https://shop.example/checkout/pay/cancel/' . $linkUuid . '/', $urls['cancel']);
        self::assertNotSame($urls['return'], $urls['cancel']);
    }

    public function testTheReturnHandlesCarryTheLinkUuidAndASignatureOnly(): void
    {
        $linkUuid = Utils::generateNanoID();

        $urls = (array) $this->returnProvider('https://shop.example')->urlsFor($this->appContext(), $linkUuid);

        foreach (['return', 'cancel'] as $key) {
            $path = (string) parse_url((string) $urls[$key], PHP_URL_PATH);
            $segments = explode('/', trim($path, '/'));
            self::assertCount(5, $segments, 'checkout/pay/{purpose}/{linkUuid}/{signature}');
            self::assertSame($linkUuid, $segments[3]);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $segments[4]);
            self::assertStringNotContainsString(self::TOKEN, (string) $urls[$key]);
        }
    }

    public function testANonHttpsOriginYieldsNullReturnUrls(): void
    {
        $provider = $this->returnProvider('http://localhost');

        self::assertNull($provider->urlsFor($this->appContext(), Utils::generateNanoID()));
    }

    public function testAnEmptyLinkUuidYieldsNull(): void
    {
        self::assertNull($this->returnProvider('https://shop.example')->urlsFor($this->appContext(), ''));
    }

    // ==================================================================
    // The signer
    // ==================================================================

    public function testSignAndVerifyRoundTripPerPurpose(): void
    {
        $signer = new PaymentLinkReturnSigner();
        $uuid = Utils::generateNanoID();

        $forReturn = PaymentLinkReturnSigner::PURPOSE_RETURN;
        $forCancel = PaymentLinkReturnSigner::PURPOSE_CANCEL;
        $return = $signer->sign($this->appContext(), $forReturn, $uuid);
        $cancel = $signer->sign($this->appContext(), $forCancel, $uuid);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $return);
        self::assertNotSame($return, $cancel);
        self::assertTrue($signer->verify($this->appContext(), $forReturn, $uuid, $return));
        self::assertTrue($signer->verify($this->appContext(), $forCancel, $uuid, $cancel));
        // Purpose separation is structural: a signature is only ever valid for its own route.
        self::assertFalse($signer->verify($this->appContext(), $forCancel, $uuid, $return));
        self::assertFalse($signer->verify($this->appContext(), $forReturn, $uuid, $cancel));
    }

    public function testVerifyRefusesAWrongSubjectAndEveryMalformedSignatureShape(): void
    {
        $signer = new PaymentLinkReturnSigner();
        $uuid = Utils::generateNanoID();
        $signature = $signer->sign($this->appContext(), PaymentLinkReturnSigner::PURPOSE_RETURN, $uuid);

        $purpose = PaymentLinkReturnSigner::PURPOSE_RETURN;
        self::assertFalse($signer->verify($this->appContext(), $purpose, Utils::generateNanoID(), $signature));
        self::assertFalse($signer->verify($this->appContext(), $purpose, $uuid, ''));
        self::assertFalse($signer->verify($this->appContext(), $purpose, $uuid, substr($signature, 0, 32)));
        self::assertFalse($signer->verify($this->appContext(), $purpose, $uuid, strtoupper($signature)));
        self::assertFalse($signer->verify($this->appContext(), $purpose, $uuid, $signature . 'a'));
    }

    public function testAnUnknownPurposeIsRefusedOutright(): void
    {
        $signer = new PaymentLinkReturnSigner();

        $this->expectException(\InvalidArgumentException::class);
        $signer->sign($this->appContext(), 'payment-link-anything-else', Utils::generateNanoID());
    }

    public function testKeyDerivationMirrorsTheBase64AppKeyDisciplineAndFailsClosed(): void
    {
        self::assertSame('raw-key-material', PaymentLinkReturnSigner::deriveKey('raw-key-material'));
        self::assertSame(
            'decoded-key-material',
            PaymentLinkReturnSigner::deriveKey('base64:' . base64_encode('decoded-key-material')),
        );

        // No fallback key of any kind: an absent or undecodable APP_KEY fails CLOSED.
        foreach (['', '   ', 'base64:', 'base64:!!!not-base64!!!'] as $broken) {
            try {
                PaymentLinkReturnSigner::deriveKey($broken);
                self::fail('expected a fail-closed refusal for ' . var_export($broken, true));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('APP_KEY', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function publicProvider(string $origin): ThalloPaymentLinkPublicUrlProvider
    {
        return new ThalloPaymentLinkPublicUrlProvider(
            $this->fixedOrigin($origin),
            $this->container()->get(ShopUrlGenerator::class),
        );
    }

    private function returnProvider(string $origin): ThalloPaymentLinkReturnUrlProvider
    {
        return new ThalloPaymentLinkReturnUrlProvider(
            $this->fixedOrigin($origin),
            $this->container()->get(ShopUrlGenerator::class),
            new PaymentLinkReturnSigner(),
        );
    }

    private function serviceWith(PaymentLinkPublicUrlProvider $publicUrls): PaymentLinkService
    {
        $seam = $this->container()->get(CommerceTenantResolution::class);
        $resolver = new class ($seam) implements CurrentTenantResolver {
            public function __construct(private readonly CommerceTenantResolution $seam)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->seam->tenantUuid($context);
            }
        };

        return new PaymentLinkService(
            $this->container()->get(OrderRepository::class),
            $this->container()->get(PaymentLinkRepository::class),
            $resolver,
            $publicUrls,
        );
    }

    private function seedPayableOrder(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'PLS-' . substr($uuid, 0, 6),
            'status' => 'pending_payment',
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'refunded_total' => 0,
            'email' => 'payer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('c', 64),
            'currency' => 'USD',
            'subtotal' => 1500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1500,
            'placed_at' => null,
            'created_at' => '2026-02-01 09:00:00',
        ]);

        return $uuid;
    }

    private function fixedOrigin(string $origin): CanonicalPublicOriginResolver
    {
        return new class ($origin) implements CanonicalPublicOriginResolver {
            public function __construct(private readonly string $origin)
            {
            }

            public function currentOrigin(ApplicationContext $c): string
            {
                return $this->origin;
            }

            public function originForTenant(ApplicationContext $c, string $tenantUuid): string
            {
                return $this->origin;
            }
        };
    }
}
