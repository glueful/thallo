<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Events\OrderCanceled;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Notifications\Services\NotificationService;
use Thallo\Commerce\Email\CommerceEmailTemplates;
use Thallo\Commerce\Email\SendOrderEmails;

/**
 * Store-settings spec §4: the four order-lifecycle emails. Definitions land in the email
 * extension's registry (⇒ they appear, editable, in Settings › Email with zero new UI); the
 * listener sends at-most-once per template×order and NEVER lets a mail failure escape into the
 * commerce flow that dispatched the event.
 */
final class CommerceOrderEmailsTest extends AppTestCase
{
    public function testDefinitionsAreRegisteredWithOwnerAndPlaceholders(): void
    {
        $registry = $this->container()->get(EmailTemplateRegistry::class);

        foreach (CommerceEmailTemplates::KEYS as $key) {
            $definition = $registry->find($key);
            self::assertNotNull($definition, "missing template definition {$key}");
            self::assertSame(CommerceEmailTemplates::OWNER, $definition->owner);
            $names = array_map(static fn ($p) => $p->name, $definition->placeholders);
            self::assertSame(
                ['order_number', 'customer_email', 'total', 'status', 'store_name'],
                $names,
            );
        }
    }

    public function testEachOrderEventSendsItsTemplateWithIdempotencyKey(): void
    {
        $sent = [];
        $listener = $this->listener($this->capturingService($sent));

        $order = $this->order();
        $listener->onOrderPlaced(new OrderPlaced($order));
        $listener->onOrderPaid(new OrderPaid($order));
        $listener->onOrderFulfilled(new OrderFulfilled($order));
        $listener->onOrderCanceled(new OrderCanceled($order));

        self::assertCount(4, $sent);
        self::assertSame(
            [
                'commerce.order_confirmation',
                'commerce.order_paid',
                'commerce.order_fulfilled',
                'commerce.order_canceled',
            ],
            array_map(static fn (array $call): string => $call['data']['template_name'], $sent),
        );
        foreach ($sent as $call) {
            self::assertSame('commerce_order_email', $call['type']);
            self::assertSame(['email'], $call['options']['channels']);
            self::assertSame(
                'commerce-email:' . $call['data']['template_name'] . ':ord-uuid-001',
                $call['options']['idempotency_key'],
            );
            self::assertSame('ORD-1042', $call['data']['order_number']);
            self::assertSame('buyer@example.com', $call['data']['customer_email']);
            // Grand total formatted for customers via ShopMoney (symbol form under ext-intl).
            self::assertSame('$89.00', $call['data']['total']);
            self::assertSame('buyer@example.com', $call['recipient']->routeNotificationFor('email'));
        }
    }

    public function testDisabledTemplateSwitchSkipsThatSendOnly(): void
    {
        $store = $this->container()->get(\App\Settings\SettingsStore::class);
        $store->putMany(['thallo-commerce.email.order_paid.enabled' => '0']);

        try {
            $sent = [];
            $listener = $this->listener($this->capturingService($sent));

            $order = $this->order();
            $listener->onOrderPlaced(new OrderPlaced($order));
            $listener->onOrderPaid(new OrderPaid($order));
            $listener->onOrderCanceled(new OrderCanceled($order));

            // order_paid is switched off — the other templates are untouched.
            self::assertSame(
                ['commerce.order_confirmation', 'commerce.order_canceled'],
                array_map(static fn (array $call): string => $call['data']['template_name'], $sent),
            );
        } finally {
            $store->forget('thallo-commerce.email.order_paid.enabled');
        }
    }

    public function testMailFailureNeverEscapesTheListener(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->method('send')->willThrowException(new \RuntimeException('transport down'));

        $listener = $this->listener($service);
        $listener->onOrderPlaced(new OrderPlaced($this->order()));

        // Reaching this line IS the assertion: the throw was swallowed (and logged).
        $this->addToAssertionCount(1);
    }

    public function testOrderWithoutUsableEmailSkipsSilently(): void
    {
        $sent = [];
        $listener = $this->listener($this->capturingService($sent));

        $listener->onOrderPlaced(new OrderPlaced($this->order(['email' => ''])));
        $listener->onOrderPaid(new OrderPaid($this->order(['email' => 'not-an-email'])));

        self::assertSame([], $sent);
    }

    // -----------------------------------------------------------------
    // Admin-origin confirmation gate (spec §2.5.9): this is the ONE OrderPlaced sender an
    // out-of-the-box install actually runs (registered only when `commerce.email.enabled` is
    // false — see CommerceIntegrationServiceProvider::registerOrderEmails()), so it must mirror
    // OrderMailListener::onOrderPlaced()'s admin-origin gate for the toggle to mean anything.
    // -----------------------------------------------------------------

    public function testAdminOriginConfirmationIsSkippedWhenEngineToggleIsOff(): void
    {
        $this->withConfig('commerce.order_confirmation', false, function () {
            $sent = [];
            $listener = $this->listener($this->capturingService($sent));

            $listener->onOrderPlaced(new OrderPlaced($this->order(['origin' => 'admin'])));

            self::assertSame([], $sent);
        });
    }

    public function testAdminOriginConfirmationStillSendsWhenEngineToggleIsOnWithAnEmail(): void
    {
        $this->withConfig('commerce.order_confirmation', true, function () {
            $sent = [];
            $listener = $this->listener($this->capturingService($sent));

            $listener->onOrderPlaced(new OrderPlaced($this->order(['origin' => 'admin'])));

            self::assertCount(1, $sent);
            self::assertSame('commerce.order_confirmation', $sent[0]['data']['template_name']);
        });
    }

    /**
     * Storefront/legacy behavior is byte-identical regardless of the engine toggle: the gate is
     * consulted ONLY for `origin === 'admin'`.
     */
    public function testStorefrontOriginConfirmationIgnoresTheEngineToggleEitherWay(): void
    {
        foreach ([true, false] as $toggle) {
            $this->withConfig('commerce.order_confirmation', $toggle, function () use ($toggle) {
                $sent = [];
                $listener = $this->listener($this->capturingService($sent));

                $listener->onOrderPlaced(new OrderPlaced($this->order(['origin' => 'storefront'])));

                self::assertCount(1, $sent, "storefront must send regardless of toggle={$toggle}");
            });
        }

        // An order with no `origin` key at all reads as storefront (pre-migration-022 legacy row).
        $this->withConfig('commerce.order_confirmation', false, function () {
            $sent = [];
            $listener = $this->listener($this->capturingService($sent));
            $order = $this->order();
            unset($order['origin']);

            $listener->onOrderPlaced(new OrderPlaced($order));

            self::assertCount(1, $sent, 'an order with no origin at all must read as storefront');
        });
    }

    /**
     * A null-email admin walk-in order must produce ZERO send attempts, even with the engine
     * toggle on — the existing blank-email guard in {@see SendOrderEmails::send()} already covers
     * this (a null `email` normalizes to `''` before the usability check), pinned here for the
     * admin-origin path specifically.
     */
    public function testNullEmailAdminOrderProducesZeroSendAttemptsRegardlessOfTheToggle(): void
    {
        foreach ([true, false] as $toggle) {
            $this->withConfig('commerce.order_confirmation', $toggle, function () use ($toggle) {
                $sent = [];
                $listener = $this->listener($this->capturingService($sent));

                $listener->onOrderPlaced(new OrderPlaced($this->order(['origin' => 'admin', 'email' => null])));

                self::assertSame([], $sent, "toggle={$toggle}");
            });
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Primes the (process-shared) ApplicationContext's config cache for the duration of
     * $callback, then restores it — mirrors {@see \App\Tests\Integration\DeliveryFlowTest::
     * forceDefaultPerPage()}'s established idiom: reflection is the surgical option here because
     * `commerce.order_confirmation` is read LAZILY (only when an event actually fires), so
     * `bootAppWithConfigOverride()`'s temporary override FILE is already restored/deleted by the
     * time any test would first trigger that read (task-12-report.md documents this exact gotcha
     * for the sibling lazily-read `commerce.currency` key), and `overrideConfig()` itself refuses
     * to run at all once the shared app has booted.
     */
    private function withConfig(string $key, bool $value, callable $callback): void
    {
        $context = $this->appContext();
        $ref = new \ReflectionProperty($context, 'configCache');
        $ref->setAccessible(true);
        /** @var array<string,mixed> $previous */
        $previous = $ref->getValue($context);

        $patched = $previous;
        $patched[$key] = $value;
        $ref->setValue($context, $patched);

        try {
            $callback();
        } finally {
            $ref->setValue($context, $previous);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function order(array $overrides = []): array
    {
        // Array-union precedence: the LEFT operand wins, so overrides go first.
        return $overrides + [
            'uuid' => 'ord-uuid-001',
            'order_number' => 'ORD-1042',
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'grand_total' => 8900,
            'status' => 'paid',
        ];
    }

    private function listener(NotificationService $service): SendOrderEmails
    {
        return new SendOrderEmails($this->appContext(), $service);
    }

    /** @param list<array<string,mixed>> $sent captured by reference */
    private function capturingService(array &$sent): NotificationService
    {
        $service = $this->createMock(NotificationService::class);
        $service->method('send')->willReturnCallback(
            function (string $type, $recipient, string $subject, array $data, array $options) use (&$sent): array {
                $sent[] = [
                    'type' => $type,
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'data' => $data,
                    'options' => $options,
                ];

                return ['status' => 'success', 'channels' => ['email' => ['status' => 'success']]];
            },
        );

        return $service;
    }
}
