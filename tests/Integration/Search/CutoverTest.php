<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\Cutover;
use Thallo\Search\Lifecycle\RebuildOutcome;

/**
 * Cutting over from the legacy index (search block spec §3.5.6, §3.5.9): entries keep answering
 * from the old documents until the new index is ready, then every kind moves in one step; Postgres
 * legacy rows go per workspace; and other kinds return nothing until their own build is promoted.
 */
final class CutoverTest extends AppTestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function engines(): iterable
    {
        yield 'postgres' => ['pg'];
        yield 'meilisearch' => ['meili'];
    }

    public function testAnUpgradeKeepsEveryEntryExactlyOnce(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->source->items = ['legacyone01' => ['en' => 'Rose'], 'legacytwo02' => ['en' => 'Lily']];
        foreach (['legacyone01' => 'Rose', 'legacytwo02' => 'Lily'] as $id => $title) {
            $this->legacyRow($id, $title);
        }

        $this->runWithCutover($kit);

        self::assertSame('v2', $kit->state->row('entries')['format']);
        self::assertSame(0, $this->connection()->table('search_documents')->whereNull('kind')->count());
        $ids = array_map(
            static fn (array $r): string => (string) $r['source_id'],
            $this->connection()->table('search_documents')->where('kind', '=', 'entries')->get(),
        );
        sort($ids);
        self::assertSame(['legacyone01', 'legacytwo02'], $ids, 'every entry exactly once');
    }

    /** @dataProvider engines */
    public function testEntriesStaySearchableThroughAnInterruptedUpgrade(string $engine): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection(), $engine);
        $kit->source->items = ['a' => ['en' => 'Rose']];
        $kit->state->ensure('entries');
        $claim = $kit->state->claimBuild('entries', 120, 0, 1); // the upgrade's first build dies
        self::assertNotNull($claim);
        self::assertSame('legacy', $kit->locator()->readMode('entries'), 'a single store keeps reading legacy');

        $kit->clock->advance(121);
        $this->runWithCutover($kit);
        self::assertSame('v2', $kit->locator()->readMode('entries'));
    }

    public function testProductsAreEmptyUntilBuiltAfterTheFlip(): void
    {
        $products = new ArraySource('products', ['thallo.commerce']);
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->registry->register($products);
        $kit->source->items = ['a' => ['en' => 'Rose']];
        $products->items = ['P1' => ['*' => 'Attar']];

        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));
        (new Cutover(
            $kit->state,
            $kit->store,
            $kit->locator(),
            new \Thallo\Search\Lifecycle\Workspace($this->appContext()),
            $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class),
            $this->connection(),
            'content',
        ))->flipIfReady();

        self::assertSame('v2', $kit->locator()->readMode('entries'));
        self::assertSame('empty', $kit->locator()->readMode('products'), 'available, but nothing built yet');
        self::assertSame('v2', $kit->state->ensure('products')['format'], 'a kind row created after the flip is v2');

        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('products'));
        self::assertSame('v2', $kit->locator()->readMode('products'));
    }

    public function testNothingFlipsWhileEntriesAreNotReady(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->state->ensure('entries');
        $kit->state->claimBuild('entries', 120, 0, 1);
        $this->legacyRow('legacyone01', 'Rose');
        $this->cutover($kit)->flipIfReady();
        self::assertSame('legacy', $kit->state->row('entries')['format']);
        self::assertSame(1, $this->connection()->table('search_documents')->whereNull('kind')->count());
    }

    private function runWithCutover(LifecycleKit $kit): void
    {
        $kit->reconciler()->runWorkspace(false);
        $this->cutover($kit)->flipIfReady();
    }

    private function cutover(LifecycleKit $kit): Cutover
    {
        return new Cutover(
            $kit->state,
            $kit->store,
            $kit->locator(),
            new \Thallo\Search\Lifecycle\Workspace($this->appContext()),
            $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class),
            $this->connection(),
            'content',
        );
    }

    private function legacyRow(string $id, string $title): void
    {
        $this->connection()->table('search_documents')->insert([
            'doc_id' => $id . '_en', 'entry_uuid' => $id, 'locale' => 'en', 'content_type_uuid' => 't1',
            'content_type_slug' => 'post', 'href' => '/' . $id, 'title' => $title, 'body' => $title,
            'ts_config' => 'english', 'generation' => 0,
        ]);
    }
}
