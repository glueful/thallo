<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Queue\QueueManager;

/** A queue that records pushes instead of running them. */
final class RecordingQueue extends QueueManager
{
    /** @var list<array{job: string, data: array<string,mixed>, queue: ?string, delay: int}> */
    public array $pushed = [];

    public function __construct()
    {
    }

    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        $this->pushed[] = ['job' => $job, 'data' => $data, 'queue' => $queue, 'delay' => 0];
        return 'job-' . count($this->pushed);
    }

    public function later(
        int $delay,
        string $job,
        array $data = [],
        ?string $queue = null,
        ?string $connection = null,
    ): string {
        $this->pushed[] = ['job' => $job, 'data' => $data, 'queue' => $queue, 'delay' => $delay];
        return 'job-' . count($this->pushed);
    }
}
