<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;

/**
 * A no-op WebhookDispatcher substitute that records every dispatch() call so a test can
 * assert the EXACT event name + payload the DispatchWebhookListener emitted — without
 * touching the database or the queue (the real dispatcher auto-migrates tables and pushes
 * delivery jobs).
 *
 * Implements WebhookDispatcherInterface so it satisfies any consumer resolving the
 * interface, and is swapped in for the concrete WebhookDispatcher::class binding (the
 * listener resolves WebhookDispatcher::class from the container per-invocation).
 */
final class RecordingWebhookDispatcher implements WebhookDispatcherInterface
{
    /** @var list<array{event: string, data: array<string, mixed>, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    public function dispatch(string $event, array $data, array $options = []): array
    {
        $this->calls[] = ['event' => $event, 'data' => $data, 'options' => $options];
        return [];
    }
}
