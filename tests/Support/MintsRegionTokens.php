<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Content\Preview\RegionPreviewToken;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;

/** Mint a regions-stage token with the app's preview key, for tests. */
trait MintsRegionTokens
{
    protected function regionToken(string $session = 'sess0000test', ?string $page = null, int $ttl = 600): string
    {
        $signer = new class ($this->container()->get(ApplicationContext::class)) {
            use ResolvesPreviewKey;

            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function key(): string
            {
                return $this->previewKey($this->context);
            }
        };
        return RegionPreviewToken::mint($session, $page, 'en', time() + $ttl, $signer->key());
    }
}
