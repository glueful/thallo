<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Search\ContentReindexer;

final class RecordingContentReindexer implements ContentReindexer
{
    /** @var list<array{entry: string, locale: ?string}> */
    public array $requests = [];

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        $this->requests[] = [
            'entry' => $entryUuid,
            'locale' => $locale,
        ];
    }
}
