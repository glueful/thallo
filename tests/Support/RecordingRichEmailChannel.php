<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Results\NotificationResult;

/**
 * A REAL {@see RichNotificationChannel} registered under the `email` name, recording every
 * `sendNotification()` call and returning a caller-supplied {@see NotificationResult}.
 *
 * Payment links Task 12: the payment-request mailer must reach `sendNotification()` on the
 * registered channel DIRECTLY — never `NotificationService::send()`, which persists the payload
 * (and would therefore store a live bearer token). This double is what makes "exactly one call,
 * to exactly that method, with exactly this data" an assertion rather than an inspection, while
 * keeping a genuine SMTP transport out of the suite.
 *
 * Deliberately NOT `final`: a test that needs to land a real, committed database mutation at the
 * exact instant the transport would be working (proving a compare-and-set race) subclasses this
 * and wraps `sendNotification()`, rather than reimplementing the whole channel contract.
 */
class RecordingRichEmailChannel implements RichNotificationChannel
{
    /** @var list<array{notifiable:Notifiable, data:array<string,mixed>}> */
    public array $calls = [];

    public function __construct(private readonly NotificationResult $result)
    {
    }

    public function getChannelName(): string
    {
        return 'email';
    }

    /** @param array<string,mixed> $data */
    public function send(Notifiable $notifiable, array $data): bool
    {
        return $this->sendNotification($notifiable, $data)->success;
    }

    /** @param array<string,mixed> $data */
    public function sendNotification(Notifiable $notifiable, array $data): NotificationResult
    {
        $this->calls[] = ['notifiable' => $notifiable, 'data' => $data];

        return $this->result;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
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
}
