<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\RebuildOutcome;

/**
 * Switching engines needs no Rebuild press: an active index that belongs to the other engine is
 * never read or written, the kind answers rebuilding, and the next reconcile rebuilds it.
 */
final class EngineSwitchTest extends AppTestCase
{
    public function testAnIndexFromTheOtherEngineIsRebuiltOnItsOwn(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));

        // The site ran on Meilisearch before: its row names a Meilisearch index.
        $this->connection()->table('search_index_state')->where('kind', '=', 'entries')
            ->update(['active_target' => 'content_v2_entries_g1']);

        self::assertSame('rebuilding', $kit->locator()->readMode('entries'));
        self::assertSame([], $kit->locator()->currentTargets('entries'), 'never written');
        self::assertSame('engine', $kit->demand()->pending('entries'));

        $kit->clock->advance(5);
        $kit->reconciler()->runWorkspace(false);
        self::assertSame('pg', $kit->state->row('entries')['active_target']);
        self::assertSame('ready', $kit->locator()->readMode('entries'));
        self::assertNull($kit->demand()->pending('entries'));
    }
}
