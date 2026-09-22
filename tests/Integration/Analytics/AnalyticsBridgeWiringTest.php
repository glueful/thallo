<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Analytics;

use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Thallo\Contracts\Settings\SystemChannel;
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

    public function testTurningAnalyticsOffInTheAdminStopsContentAndCollectionFacts(): void
    {
        // The bridge was registered at boot from the config map only, so switching Analytics off
        // under Extensions › Capabilities (a stored switchboard row) left it recording every
        // content and collection event. The stored switch is read when the event arrives.
        $channel = $this->container()->get(SystemChannel::class);
        $key = 'capability.thallo.analytics.enabled';
        $saved = $channel->get($key);
        $channel->put($key, 'false');
        try {
            $events = $this->container()->get(EventService::class);
            $events->dispatch(new EntryPublished('entry-2', 'article', null, null, 'u-7'));
            $events->dispatch(new CollectionCreated('posts', 'admin', 'u-1'));

            self::assertSame(0, (int) $this->connection()->table('analytics_facts')->count());
        } finally {
            $saved === null ? $channel->forget($key) : $channel->put($key, $saved);
        }

        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished('entry-3', 'article', null, null, 'u-7'));
        self::assertSame(1, (int) $this->connection()->table('analytics_facts')
            ->where('event', 'content.entry.published')->count(), 'switched back on, it records again');
    }
}
