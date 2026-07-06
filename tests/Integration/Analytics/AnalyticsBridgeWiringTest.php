<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Content\Events\EntryPublished;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionRowCreated;

final class AnalyticsBridgeWiringTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    public function testCollectionEventsBecomeAnalyticsFacts(): void
    {
        $events = $this->container()->get(EventService::class);

        $events->dispatch(new CollectionCreated('posts', 'admin', 'u-1'));
        $events->dispatch(new CollectionRowCreated('posts', 'row-1', ['uuid' => 'row-1'], new Actor('admin', 'u-1')));

        self::assertSame(1, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.collection.created')->count());
        self::assertSame(1, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'collections.row.created')->count());

        // Distinct active user recorded for the admin actor (normalized to 'user').
        self::assertSame(1, (int) $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->count());
    }

    public function testContentEntryEventsBecomeAnalyticsFacts(): void
    {
        $events = $this->container()->get(EventService::class);

        // BaseEntryEvent(string $entry, string $type, ?string $locale, ?int $version, ?string $actor).
        $events->dispatch(new EntryPublished('entry-1', 'article', null, null, 'u-7'));

        $fact = $this->connection()->table('analytics_facts')
            ->where('event', 'content.entry.published')->first();
        self::assertNotNull($fact);
        self::assertSame('content', $fact['category']);
        self::assertSame('content_type', $fact['subject_type']);
        self::assertSame('article', $fact['subject_id']); // ->type
        self::assertSame('u-7', $fact['actor_id']);        // ->actor

        // Per-content-type breakdown rollup exists alongside the __total__ row.
        self::assertSame(1, (int) $this->connection()->table('analytics_daily')
            ->where('event', 'content.entry.published')->where('subject', 'article')->count());
    }
}
