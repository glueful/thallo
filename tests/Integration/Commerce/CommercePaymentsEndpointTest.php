<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Thallo\Commerce\Http\PaymentsSettingsController;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Store-settings spec §3.6 (Payments tab): GET/PUT /v1/admin/commerce/payments. The behaviours
 * that carry the weight: secrets are WRITE-ONLY (stored encrypted, reported as booleans, never
 * echoed), clear DELETES the row (env fallback shows through), and the full seam chain —
 * a secret stored through this endpoint reaches payvia's own PayviaSettings reader decrypted.
 *
 * This install HAS payvia (enabled in config/extensions.php), so GET reports gateway mode with
 * the config-declared gateways (paystack enabled-by-default, stripe disabled) and no env keys.
 */
final class CommercePaymentsEndpointTest extends AppTestCase
{
    private const KEYS = [
        'payvia.default_gateway',
        'payvia.gateways.paystack.enabled',
        'payvia.gateways.paystack.secret_key',
        'payvia.gateways.paystack.webhook_secret',
        'payvia.gateways.stripe.enabled',
        'payvia.gateways.stripe.secret_key',
        'payvia.gateways.stripe.webhook_secret',
        'payvia.gateways.paystack.base_url',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // Task 1 (platform-payments-settings): every payvia.* key is now system-classified
        // (SystemKeys::PREFIXES), so SettingsStore routes it to the unscoped SystemChannel —
        // physically the `thallo_system_flags` table, not `settings`.
        foreach (self::KEYS as $key) {
            $this->connection()->table('thallo_system_flags')->where(['key' => $key])->delete();
        }
        $this->container()->get(SettingsStore::class)->clearCache();
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    public function testGetReportsGatewayModeWithBooleanSecretStateOnly(): void
    {
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertSame('gateway', $data['mode']);
        self::assertSame('paystack', $data['default_gateway']['value']);

        $byId = array_column($data['gateways'], null, 'id');
        self::assertTrue($byId['paystack']['enabled']['value']);
        self::assertFalse($byId['stripe']['enabled']['value']);
        self::assertTrue($byId['paystack']['default']);
        // No env keys in this install — everything honestly unset.
        self::assertSame(['set' => false, 'source' => null], $byId['paystack']['secret_key']);
        self::assertSame(['set' => false, 'source' => null], $byId['stripe']['webhook_secret']);

        // The copy-able dashboard URL: canonical origin + payvia's root-mounted webhook route.
        self::assertMatchesRegularExpression(
            '#^https?://[^/]+/webhooks/paystack$#',
            (string) $byId['paystack']['webhook_url'],
        );
    }

    public function testSecretWriteStoresCiphertextAndNeverEchoesThePlaintext(): void
    {
        $plaintext = 'sk_test_veryserioussecret123';
        $response = $this->put(['gateways' => ['paystack' => ['secret_key' => $plaintext]]]);

        // Response reports the boolean state — and the plaintext appears NOWHERE in it.
        $data = $this->data($response);
        $byId = array_column($data['gateways'], null, 'id');
        self::assertSame(['set' => true, 'source' => 'settings'], $byId['paystack']['secret_key']);
        self::assertStringNotContainsString($plaintext, (string) $response->getContent());

        // At rest: a ciphertext row, not the plaintext. Payvia keys are system-classified
        // (Task 1), so the row lives in the unscoped `thallo_system_flags` table, not
        // the tenant-scoped `settings` table.
        $row = $this->connection()->table('thallo_system_flags')
            ->where(['key' => 'payvia.gateways.paystack.secret_key'])->first();
        self::assertIsArray($row);
        self::assertNotSame($plaintext, $row['value']);
        self::assertTrue(
            $this->container()->get(EncryptionService::class)->isEncrypted((string) $row['value'])
        );
    }

    public function testStoredSecretReachesPayviaDecryptedThroughTheSeam(): void
    {
        $plaintext = 'sk_test_roundtrip456';
        $this->put(['gateways' => ['paystack' => [
            'secret_key' => $plaintext,
            'enabled' => true,
        ]]]);

        // The full chain: system-channel row (encrypted) → PlatformPayviaSettingsOverride
        // (decrypts) → PayviaSettings::gatewayConfig — what GatewayManager and the drivers
        // actually read.
        $config = PayviaSettings::gatewayConfig($this->appContext(), 'paystack');
        self::assertSame($plaintext, $config['secret_key']);
        self::assertTrue($config['enabled']);
    }

    public function testDefaultGatewayAndEnabledRoundTrip(): void
    {
        $data = $this->data($this->put([
            'default_gateway' => 'stripe',
            'gateways' => [
                'stripe' => ['enabled' => true],
                'paystack' => ['enabled' => false],
            ],
        ]));

        self::assertSame('stripe', $data['default_gateway']['value']);
        self::assertTrue($data['default_gateway']['overridden']);
        $byId = array_column($data['gateways'], null, 'id');
        self::assertTrue($byId['stripe']['enabled']['value']);
        self::assertTrue($byId['stripe']['default']);
        self::assertFalse($byId['paystack']['enabled']['value']);

        // And payvia agrees.
        self::assertSame('stripe', PayviaSettings::defaultGateway($this->appContext()));
        self::assertFalse(PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['enabled']);
    }

    public function testClearDeletesTheSecretRowAndEnvFallbackShowsThrough(): void
    {
        $this->put(['gateways' => ['paystack' => ['secret_key' => 'sk_test_gone']]]);
        $data = $this->data($this->put(['gateways' => ['paystack' => ['secret_key' => null]]]));

        $byId = array_column($data['gateways'], null, 'id');
        // No env key in this install, so clearing lands on honestly-unset.
        self::assertSame(['set' => false, 'source' => null], $byId['paystack']['secret_key']);
        self::assertNull(
            $this->connection()->table('thallo_system_flags')
                ->where(['key' => 'payvia.gateways.paystack.secret_key'])->first()
        );
    }

    public function testAbsentSecretFieldLeavesTheStoredValueUntouched(): void
    {
        $this->put(['gateways' => ['paystack' => ['secret_key' => 'sk_test_keepme']]]);
        // A later save that only toggles enabled must not clear the key (write-only contract).
        $data = $this->data($this->put(['gateways' => ['paystack' => ['enabled' => true]]]));

        $byId = array_column($data['gateways'], null, 'id');
        self::assertSame(['set' => true, 'source' => 'settings'], $byId['paystack']['secret_key']);
        $config = PayviaSettings::gatewayConfig($this->appContext(), 'paystack');
        self::assertSame('sk_test_keepme', $config['secret_key']);
    }

    public function testValidationRejectsUnknownGatewaysAndMalformedFields(): void
    {
        foreach (
            [
                ['default_gateway' => 'rogue'],
                ['gateways' => ['rogue' => ['enabled' => true]]],
                ['gateways' => ['paystack' => ['enabled' => 'yes']]],
                ['gateways' => ['paystack' => ['secret_key' => str_repeat('x', 513)]]],
            ] as $body
        ) {
            try {
                $this->put($body);
                self::fail('Expected ValidationException for ' . json_encode($body));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOverrideWhitelistIgnoresOpsKnobsEvenWithARowPresent(): void
    {
        // A base_url row (however it got there) must never reach payvia — ops knobs are
        // config/env-only (spec §3.6), and the seam's whitelist enforces that structurally.
        $this->container()->get(SettingsStore::class)->putMany([
            'payvia.gateways.paystack.base_url' => 'https://evil.example',
        ]);

        // Task 4 (platform-payments-settings): the seam is now satisfied by the APP-owned
        // App\Settings\PlatformPayviaSettingsOverride, resolved from the container rather than
        // constructed from the (deleted) commerce-pack class. The whitelist it enforces is the
        // same one, ported verbatim.
        self::assertNull(
            $this->container()->get(PayviaSettingsOverride::class)
                ->value($this->appContext(), 'payvia.gateways.paystack.base_url')
        );
        self::assertSame(
            'https://api.paystack.co',
            PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['base_url'],
        );
    }

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

    private function controller(): PaymentsSettingsController
    {
        return $this->container()->get(PaymentsSettingsController::class);
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }
}
