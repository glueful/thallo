<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingRichEmailChannel;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Results\NotificationResult;
use Glueful\Notifications\Services\ChannelManager;
use Thallo\Commerce\Email\CommerceEmailTemplates;
use Thallo\Commerce\Email\PaymentRequestMailer;
use Thallo\Commerce\Email\PaymentRequestSendResult;
use Thallo\Commerce\Email\RichEmailAvailability;
use Thallo\Commerce\Http\EmailSettingsController;

/**
 * Payment links Task 12 (payment-links spec §2.4): the dedicated, synchronous
 * {@see PaymentRequestMailer}, the shared {@see RichEmailAvailability} authority behind it and
 * behind `/meta`'s mandatory `email_available` flag, and the `payment_request` template whose
 * stored body carries the EXISTING validated `action_url` placeholder and never a token.
 *
 * ## The custody rule this class exists to pin
 *
 * The URL the mailer sends embeds a live bearer token. `NotificationService::send()` PERSISTS the
 * full notification payload (`NotificationService.php:164` — `$this->repository->save(...)`), so
 * routing this send through it would write the tokened URL into the `notifications` table and,
 * for any async channel, into the queue payload. The mailer therefore calls the registered
 * `email` channel's `sendNotification()` DIRECTLY — the same pattern the email extension's own
 * template test-send uses — and this file's persistence audit sweeps every notification and queue
 * table for the token after a real send.
 *
 * ## Missing rich channel is a REFUSAL, never a boot failure
 *
 * An install without `glueful/email-notification` has no `email` channel at all; one with a
 * legacy bool-only channel has one that cannot report a provider message id or a typed failure.
 * Both are the SAME state to this pack: `email_available=false`, and a typed send refusal at
 * request time. Nothing here may throw at construction.
 */
final class PaymentRequestMailerTest extends AppTestCase
{
    private const TOKEN = 'b1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6b1b2';
    private const URL = 'https://shop.example/checkout/pay/' . self::TOKEN;

    /** Every table a notification send could conceivably persist into. */
    private const PERSISTENCE_SENTINEL_TABLES = [
        'notifications',
        'notification_deliveries',
        'notification_preferences',
        'notification_templates',
        'notification_retry_queue',
        'queue_jobs',
        'queue_failed_jobs',
        'queue_batches',
    ];

    protected function tearDown(): void
    {
        $this->store()->forget('thallo-commerce.email.payment_request.enabled');
        parent::tearDown();
    }

    // ==================================================================
    // RichEmailAvailability — the ONE authority
    // ==================================================================

    public function testAvailabilityIsTrueWhenTheRegisteredEmailChannelIsRich(): void
    {
        $availability = new RichEmailAvailability($this->channelManagerWith($this->recordingChannel()));

        self::assertTrue($availability->isAvailable());
        self::assertInstanceOf(RichNotificationChannel::class, $availability->richChannel());
    }

    public function testAvailabilityIsFalseWhenNoEmailChannelIsRegisteredAtAll(): void
    {
        $availability = new RichEmailAvailability(new ChannelManager());

        self::assertFalse($availability->isAvailable());
        self::assertNull($availability->richChannel());
    }

    public function testAvailabilityIsFalseForALegacyBoolOnlyEmailChannel(): void
    {
        $availability = new RichEmailAvailability($this->channelManagerWith($this->legacyChannel()));

        self::assertFalse($availability->isAvailable(), 'a bool-only channel is not a RICH channel');
        self::assertNull($availability->richChannel());
    }

    public function testAvailabilityIsFalseAndNeverThrowsWhenTheChannelManagerIsAbsentEntirely(): void
    {
        $availability = new RichEmailAvailability(null);

        self::assertFalse($availability->isAvailable());
        self::assertNull($availability->richChannel());
    }

    public function testTheLiveContainerBindsTheAvailabilityAuthorityAndTheInstallHasARichEmailChannel(): void
    {
        $availability = $this->container()->get(RichEmailAvailability::class);

        self::assertInstanceOf(RichEmailAvailability::class, $availability);
        self::assertTrue(
            $availability->isAvailable(),
            'this install ships glueful/email-notification, whose EmailChannel is a RichNotificationChannel',
        );
    }

    // ==================================================================
    // The mailer
    // ==================================================================

    public function testASuccessfulSendCallsSendNotificationDirectlyWithTheTemplateKeyAndActionUrl(): void
    {
        $channel = $this->recordingChannel();
        $this->enableTemplate();

        $result = $this->mailer($channel)->send('Buyer@Example.com', self::URL, $this->placeholders());

        self::assertTrue($result->sent);
        self::assertNull($result->errorCode);
        self::assertSame('provider-message-42', $result->providerMessageId);

        self::assertCount(1, $channel->calls);
        $data = $channel->calls[0]['data'];
        self::assertSame(PaymentRequestMailer::TEMPLATE_KEY, $data['template_name']);
        self::assertSame(self::URL, $data['template_data']['action_url']);
        self::assertSame('ORD-1042', $data['template_data']['order_number']);
        self::assertSame('2026-08-19', $data['template_data']['expires_at']);
        self::assertSame('Buyer@Example.com', $channel->calls[0]['notifiable']->routeNotificationFor('email'));
    }

    public function testAFailedSendReturnsATypedResultCarryingNoTransportExceptionText(): void
    {
        $channel = $this->failingChannel();
        $this->enableTemplate();

        $result = $this->mailer($channel)->send('buyer@example.com', self::URL, $this->placeholders());

        self::assertFalse($result->sent);
        self::assertSame(PaymentRequestSendResult::SEND_FAILED, $result->errorCode);
        self::assertNull($result->providerMessageId);
        self::assertSame(
            ['sent', 'error_code', 'provider_message_id'],
            array_keys($result->toArray()),
            'the safe result is a CLOSED shape — no transport message, no exception text',
        );
        self::assertStringNotContainsString('smtp', strtolower(json_encode($result->toArray()) ?: ''));
    }

    public function testAThrowingChannelIsSwallowedIntoTheSameTypedFailure(): void
    {
        $this->enableTemplate();

        $result = $this->mailer($this->throwingChannel())->send('buyer@example.com', self::URL, $this->placeholders());

        self::assertFalse($result->sent);
        self::assertSame(PaymentRequestSendResult::SEND_FAILED, $result->errorCode);
    }

    public function testAMissingRichChannelIsARefusalRatherThanAnExceptionOrABootFailure(): void
    {
        $this->enableTemplate();
        $mailer = new PaymentRequestMailer($this->appContext(), new RichEmailAvailability(new ChannelManager()));

        $result = $mailer->send('buyer@example.com', self::URL, $this->placeholders());

        self::assertFalse($result->sent);
        self::assertSame(PaymentRequestSendResult::EMAIL_UNAVAILABLE, $result->errorCode);
    }

    public function testAnUnusableRecipientAddressIsRefusedBeforeTheChannelIsTouched(): void
    {
        $channel = $this->recordingChannel();
        $this->enableTemplate();

        $result = $this->mailer($channel)->send('not-an-address', self::URL, $this->placeholders());

        self::assertFalse($result->sent);
        self::assertSame(PaymentRequestSendResult::NO_RECIPIENT, $result->errorCode);
        self::assertSame([], $channel->calls);
    }

    public function testTheMailerNeverRoutesThroughNotificationServiceSend(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../packages/thallo-commerce/src/Email/PaymentRequestMailer.php'
        );

        self::assertStringNotContainsString(
            'use Glueful\Notifications\Services\NotificationService;',
            $source,
            'NotificationService::send() persists the payload — the tokened URL must never reach it',
        );
        self::assertStringContainsString('->sendNotification(', $source);

        $parameters = (new \ReflectionClass(PaymentRequestMailer::class))
            ->getConstructor()?->getParameters() ?? [];
        foreach ($parameters as $parameter) {
            self::assertNotSame(
                \Glueful\Notifications\Services\NotificationService::class,
                (string) $parameter->getType(),
                'the mailer must not be able to reach the persisting pipeline at all',
            );
        }
    }

    // ==================================================================
    // Persistence audit — the sentinel sweep
    // ==================================================================

    public function testNoNotificationOrQueueTableCarriesTheTokenAfterARealSend(): void
    {
        $this->enableTemplate();
        $before = $this->sentinelRowCounts();

        $result = $this->mailer($this->recordingChannel())->send('buyer@example.com', self::URL, $this->placeholders());
        self::assertTrue($result->sent);

        self::assertSame($before, $this->sentinelRowCounts(), 'a payment-request send must persist NOTHING');
        foreach (self::PERSISTENCE_SENTINEL_TABLES as $table) {
            self::assertFalse(
                $this->tableContains($table, self::TOKEN),
                "table {$table} must be free of the payment-link token after a send",
            );
        }
    }

    // ==================================================================
    // Template + toggle
    // ==================================================================

    public function testTheTemplateIsRegisteredWithTheActionUrlAndExpiryPlaceholders(): void
    {
        $definition = $this->container()->get(EmailTemplateRegistry::class)->find(PaymentRequestMailer::TEMPLATE_KEY);

        self::assertNotNull($definition, 'the payment_request template must be registered');
        self::assertSame(CommerceEmailTemplates::OWNER, $definition->owner);

        $names = array_map(static fn ($placeholder): string => $placeholder->name, $definition->placeholders);
        self::assertContains('action_url', $names, 'the link uses the EXISTING validated action_url placeholder');
        self::assertContains('expires_at', $names, 'the expiry chip is part of the editable template');
        self::assertNotContains('token', $names);
        self::assertNotContains('payment_url', $names, 'no new URL placeholder may be introduced');
    }

    public function testTheStoredTemplateBodyAndSubjectContainNoTokenAndOnlyThePlaceholder(): void
    {
        $definition = $this->container()->get(EmailTemplateRegistry::class)->find(PaymentRequestMailer::TEMPLATE_KEY);
        self::assertNotNull($definition);

        $stored = $definition->defaultSubject . "\n" . $definition->defaultBody;

        self::assertStringContainsString('{{action_url}}', $definition->defaultBody);
        self::assertStringNotContainsString('http', $stored, 'the stored template must carry no literal URL at all');
        self::assertStringNotContainsString(self::TOKEN, $stored);
    }

    public function testTheTemplateKeyIsRegisteredInTheEmailSettingsSwitchRegistry(): void
    {
        self::assertContains('payment_request', EmailSettingsController::TEMPLATES);
        self::assertContains(PaymentRequestMailer::TEMPLATE_KEY, CommerceEmailTemplates::KEYS);
    }

    public function testTheToggleDefaultsToFalseFromThePackConfig(): void
    {
        self::assertFalse(
            (bool) config($this->appContext(), 'thallo-commerce.email.payment_request.enabled', true),
            'the pack config must EXPLICITLY set false — the controller fallback is true',
        );
        self::assertFalse($this->mailer($this->recordingChannel())->enabled());
    }

    public function testTheToggleOffRefusesTheSendBeforeTheChannelIsTouched(): void
    {
        $channel = $this->recordingChannel();

        $result = $this->mailer($channel)->send('buyer@example.com', self::URL, $this->placeholders());

        self::assertFalse($result->sent);
        self::assertSame(PaymentRequestSendResult::TEMPLATE_DISABLED, $result->errorCode);
        self::assertSame([], $channel->calls);
    }

    public function testAStoredOverrideTurnsTheToggleOn(): void
    {
        $this->enableTemplate();

        self::assertTrue($this->mailer($this->recordingChannel())->enabled());
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function mailer(NotificationChannel $channel): PaymentRequestMailer
    {
        return new PaymentRequestMailer(
            $this->appContext(),
            new RichEmailAvailability($this->channelManagerWith($channel)),
        );
    }

    private function channelManagerWith(NotificationChannel $channel): ChannelManager
    {
        $manager = new ChannelManager();
        $manager->registerChannel($channel);

        return $manager;
    }

    /** @return array<string,string> */
    private function placeholders(): array
    {
        return [
            'order_number' => 'ORD-1042',
            'total' => '$89.00',
            'store_name' => 'Thallo',
            'expires_at' => '2026-08-19',
        ];
    }

    private function enableTemplate(): void
    {
        $this->store()->putMany(['thallo-commerce.email.payment_request.enabled' => '1']);
    }

    private function store(): \App\Settings\SettingsStore
    {
        return $this->container()->get(\App\Settings\SettingsStore::class);
    }

    /** @return array<string,int> */
    private function sentinelRowCounts(): array
    {
        $counts = [];
        $schema = $this->connection()->getSchemaBuilder();
        foreach (self::PERSISTENCE_SENTINEL_TABLES as $table) {
            $counts[$table] = $schema->hasTable($table)
                ? (int) $this->connection()->table($table)->count()
                : -1;
        }

        return $counts;
    }

    private function tableContains(string $table, string $needle): bool
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (!$schema->hasTable($table)) {
            return false;
        }
        $rows = $this->connection()->table($table)->limit(500)->get();

        return str_contains(strtolower(json_encode($rows) ?: ''), strtolower($needle));
    }

    private function recordingChannel(): RecordingRichEmailChannel
    {
        return new RecordingRichEmailChannel(NotificationResult::success('provider-message-42'));
    }

    private function failingChannel(): RecordingRichEmailChannel
    {
        return new RecordingRichEmailChannel(NotificationResult::failure(
            'transport_exception',
            'SMTP connect to smtp.example:587 refused (credentials: hunter2)',
        ));
    }

    private function throwingChannel(): NotificationChannel
    {
        return new class implements RichNotificationChannel {
            public function getChannelName(): string
            {
                return 'email';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                return $this->sendNotification($notifiable, $data)->success;
            }

            public function sendNotification(Notifiable $notifiable, array $data): NotificationResult
            {
                throw new \RuntimeException(
                    'transport blew up quoting ' . ($data['template_data']['action_url'] ?? '')
                );
            }

            /** @return array<string,mixed> */
            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            /** @return array<string,mixed> */
            public function getConfig(): array
            {
                return [];
            }
        };
    }

    private function legacyChannel(): NotificationChannel
    {
        return new class implements NotificationChannel {
            public function getChannelName(): string
            {
                return 'email';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                return true;
            }

            /** @return array<string,mixed> */
            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            /** @return array<string,mixed> */
            public function getConfig(): array
            {
                return [];
            }
        };
    }
}
