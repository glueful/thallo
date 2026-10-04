<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\Reconciler;

/**
 * The single `content` index an older install kept on Meilisearch is read by nothing now (search
 * block spec §3.5.6, amended): the scheduled reconcile deletes it, once, and leaves every per-kind
 * index alone.
 */
final class OldSharedIndexTest extends AppTestCase
{
    public function testTheReconcileDeletesTheOldSharedIndexOnce(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection(), 'meili');
        $kit->source->items = ['a' => ['en' => 'A']];
        $flags = $this->container()->get(SystemChannel::class);
        $flags->forget(Reconciler::OLD_INDEX_FLAG);
        $kit->meili->ensureIndex('content', []);

        $kit->reconciler()->runAll(false);

        self::assertArrayNotHasKey('content', $kit->meili->indexes, 'the old shared index is gone');
        self::assertNotNull($kit->state->row('entries')['active_target']);
        self::assertArrayHasKey((string) $kit->state->row('entries')['active_target'], $kit->meili->indexes);
        self::assertSame('deleted', $flags->get(Reconciler::OLD_INDEX_FLAG));

        // Once: an index of that name made later (by anything) is not the old one, and not looked for.
        $kit->meili->ensureIndex('content', []);
        $kit->reconciler()->runAll(false);
        self::assertArrayHasKey('content', $kit->meili->indexes);
        $flags->forget(Reconciler::OLD_INDEX_FLAG);
    }
}
