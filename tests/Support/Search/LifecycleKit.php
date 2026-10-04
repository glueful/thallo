<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Psr\Log\NullLogger;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Search\Lifecycle\DemandResolver;
use Thallo\Search\Lifecycle\Drainer;
use Thallo\Search\Lifecycle\IndexRetirement;
use Thallo\Search\Lifecycle\Rebuilder;
use Thallo\Search\Lifecycle\Reconciler;
use Thallo\Search\Lifecycle\SearchDemand;
use Thallo\Search\Lifecycle\SearchIndexLocator;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\KindAvailability;
use Thallo\Search\Sources\DefaultSearchSourceRegistry;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\MeilisearchIndexStore;
use Thallo\Search\Store\PostgresIndexStore;

/**
 * The search index lifecycle wired for tests, on Postgres or the fake Meilisearch, with an array
 * source and capabilities the test switches by hand.
 */
final class LifecycleKit
{
    /** @var list<array<string, mixed>> wake-ups the reconciler queued */
    public array $wakes = [];

    private const LABELS = ['thallo.commerce' => 'Commerce', 'thallo.search' => 'Search'];

    public FixedClock $clock;
    public StateRepository $state;
    public ArraySource $source;
    public DefaultSearchSourceRegistry $registry;
    public FakeMeilisearch $meili;
    public IndexStore $store;
    /** @var array<string, bool> capability id => on; absent means on */
    public array $capabilities = [];
    /** @var list<array<string, mixed>> */
    public array $pushed = [];
    public ?\Throwable $pushFailure = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        string $engine = 'pg',
        ?ArraySource $source = null,
    ) {
        $this->clock = new FixedClock();
        $this->state = new StateRepository($db, $this->clock);
        $this->source = $source ?? new ArraySource();
        $this->registry = new DefaultSearchSourceRegistry();
        $this->registry->register($this->source);
        $this->meili = new FakeMeilisearch();
        $this->store = $engine === 'pg'
            ? new PostgresIndexStore($db, $this->state)
            : new MeilisearchIndexStore($this->meili, 20, 5);
    }

    public function availability(): KindAvailability
    {
        return new KindAvailability(
            $this->registry,
            fn (string $id): bool => $this->capabilities[$id] ?? true,
            static fn (string $id): string => self::LABELS[$id] ?? $id,
        );
    }

    public function locator(): SearchIndexLocator
    {
        return new SearchIndexLocator(
            $this->state,
            new Workspace($this->context),
            $this->store instanceof PostgresIndexStore ? SearchIndexLocator::POSTGRES : SearchIndexLocator::MEILISEARCH,
            'content',
        );
    }

    public function demand(): DemandResolver
    {
        return new DemandResolver(
            $this->registry,
            $this->context->getContainer()->get(SystemChannel::class),
            $this->state,
        );
    }

    public function retirement(): IndexRetirement
    {
        return new IndexRetirement($this->state, $this->store, $this->locator(), $this->registry, 120);
    }

    public function rebuilder(int $batch = 100): Rebuilder
    {
        return new Rebuilder(
            $this->state,
            $this->store,
            $this->registry,
            $this->locator(),
            $this->demand(),
            $this->retirement(),
            120,
            $batch,
            new NullLogger(),
        );
    }

    public function drainer(): Drainer
    {
        return new Drainer($this->state, $this->store, $this->registry, $this->locator(), 60, 10, 5, new NullLogger());
    }

    public function reconciler(?\Thallo\Search\Lifecycle\WakeGate $gate = null): Reconciler
    {
        return new Reconciler(
            $this->availability(),
            $this->demand(),
            $this->state,
            $this->rebuilder(),
            $this->retirement(),
            $this->drainer(),
            new Workspace($this->context),
            $this->context->getContainer()->get(SystemChannel::class),
            new NullLogger(),
            function (array $data): void {
                $this->wakes[] = $data;
            },
            $gate,
        );
    }

    public function requests(): SearchDemand
    {
        return new SearchDemand(
            $this->state,
            $this->availability(),
            $this->db,
            new Workspace($this->context),
            function (array $data): void {
                if ($this->pushFailure !== null) {
                    throw $this->pushFailure;
                }
                $this->pushed[] = $data;
            },
        );
    }
}
