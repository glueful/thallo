<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Mail;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Thallo\Core\Content\Forms\NotificationFormMailSender;
use Thallo\Core\Support\EmailDelivery;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A mail the email channel accepted is reported as delivered, and one it refused is not. The
 * senders read the notification service's real answer, whose channel detail sits under `sync`.
 */
final class EmailDeliveryTest extends AppTestCase
{
    private ?NotificationChannel $previous = null;

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $this->channels()->replaceChannel($this->previous);
        }
        parent::tearDown();
    }

    public function testAnAcceptedMailIsDelivered(): void
    {
        $this->channel(accepts: true);

        (new NotificationFormMailSender($this->container()->get(NotificationService::class)))
            ->send('owner@example.test', 'New submission', 'Hello');

        $this->addToAssertionCount(1);
    }

    public function testARefusedMailIsNot(): void
    {
        $this->channel(accepts: false);

        $this->expectException(\RuntimeException::class);
        (new NotificationFormMailSender($this->container()->get(NotificationService::class)))
            ->send('owner@example.test', 'New submission', 'Hello');
    }

    public function testADuplicateOfASentMailCountsAsDelivered(): void
    {
        self::assertTrue(EmailDelivery::delivered(['status' => 'duplicate']));
        self::assertFalse(EmailDelivery::delivered(['status' => 'queued']));
        self::assertFalse(EmailDelivery::delivered(['status' => 'failed', 'sync' => ['channels' => []]]));
    }

    private function channel(bool $accepts): void
    {
        $channels = $this->channels();
        $this->previous = $channels->hasChannel('email') ? $channels->getChannel('email') : null;
        $channels->replaceChannel(new class ($accepts) implements NotificationChannel {
            public function __construct(private readonly bool $accepts)
            {
            }

            public function getChannelName(): string
            {
                return 'email';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                return $this->accepts;
            }

            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }
        });
    }

    private function channels(): ChannelManager
    {
        return $this->container()->get(NotificationDispatcher::class)->getChannelManager();
    }
}
