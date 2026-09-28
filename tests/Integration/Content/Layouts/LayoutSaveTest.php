<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Layouts\LayoutChanged;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutChanges;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\FixtureLayoutSurface;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Save and remove (type layouts spec §5.5): the Regions save contract on one document. The write
 * commits first; the session's baseline, the exact-pair clear or the retirement, the resolver and
 * the page cache follow, after the commit and only after it — so nothing anyone sees ever runs
 * ahead of the database, and a rollback leaves every one of them as it was.
 */
final class LayoutSaveTest extends AppTestCase
{
    use ResolvesPreviewKey;
    use SyncsBlockStyleDeclarations;

    private string $postType = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->postType = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        self::$announced = null;
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(LayoutResolver::class)->forget('entry', 'post');
        parent::tearDown();
    }

    // --- the editor's calls -------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function session(): array
    {
        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => 'entry', 'target' => 'post'],
        );
        $response = $this->container()->get(LayoutPreviewController::class)->session($dto);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function apply(string $token, string $marker, ?string $epoch = null, ?int $base = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
            'token' => $token, 'layout' => self::layout($marker), 'epoch' => $epoch, 'base_revision' => $base,
        ]);
        return self::decode($this->container()->get(LayoutPreviewController::class)->apply($dto));
    }

    /**
     * @param array{epoch: string, revision: int}|null $pair
     * @return array{status: int, body: array<string,mixed>}
     */
    private function save(string $token, string $marker, int $expected, ?array $pair = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
            'token' => $token, 'layout' => self::layout($marker), 'expected_lock_version' => $expected,
            'preview_revision' => $pair,
        ]);
        return self::decode($this->admin()->save($dto, Request::create('/x', 'PUT'), 'entry', 'post'));
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function remove(string $token, int $expected): array
    {
        $dto = (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
            'token' => $token, 'expected_lock_version' => $expected,
        ]);
        return self::decode($this->admin()->destroy($dto, Request::create('/x', 'DELETE'), 'entry', 'post'));
    }

    private function admin(): LayoutAdminController
    {
        return $this->container()->get(LayoutAdminController::class);
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private static function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>} */
    private static function layout(string $marker): array
    {
        return ['blocks' => [
            ['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ], 'settings' => []];
    }

    private function claims(string $token): LayoutPreviewToken
    {
        return LayoutPreviewToken::verify($token, $this->previewKey($this->appContext()), time());
    }

    private function store(): LayoutPreviewStore
    {
        return $this->container()->get(LayoutPreviewStore::class);
    }

    private function stage(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        return (string) $response->getContent();
    }

    private function secondConnection(): Connection
    {
        return new Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ]);
    }

    private static function committedVersion(Connection $db): ?int
    {
        $row = $db->table('layouts')->where('surface', '=', 'entry')->where('target', '=', 'post')->first();
        return $row === null ? null : (int) $row['lock_version'];
    }

    /** A saver with these collaborators, the rest from the container. */
    private function saver(
        ?LayoutRepository $repository = null,
        ?LayoutPreviewStore $store = null,
        ?RenderedPageCachePurge $purge = null,
    ): LayoutSaver {
        return new LayoutSaver(
            $this->connection(),
            $this->container()->get(LayoutWriteLock::class),
            $repository ?? $this->container()->get(LayoutRepository::class),
            $this->container()->get(LayoutValidator::class),
            $store ?? $this->store(),
            $this->changes($purge),
        );
    }

    /** The change announcement with this purge, the rest from the container. */
    private function changes(?RenderedPageCachePurge $purge = null): LayoutChanges
    {
        return new LayoutChanges(
            $this->container()->get(LayoutResolver::class),
            $this->container()->get(LayoutSurfaceRegistry::class),
            $purge,
            $this->container()->get(EventService::class),
        );
    }

    /** @var list<array{surface: string, target: string, tenant: ?string, committed: ?int}>|null */
    private static ?array $announced = null;

    private static bool $listening = false;

    /** Record every LayoutChanged from now until tearDown, with the version a second connection sees then. */
    private function recordAnnouncements(): void
    {
        self::$announced = [];
        if (!self::$listening) {
            self::$listening = true;
            $this->container()->get(EventService::class)->addListener(
                LayoutChanged::class,
                function (object $event): void {
                    if (self::$announced === null || !$event instanceof LayoutChanged) {
                        return;
                    }
                    self::$announced[] = [
                        'surface' => $event->surface,
                        'target' => $event->target,
                        'tenant' => $event->tenantUuid,
                        'committed' => self::committedVersion($this->secondConnection()),
                    ];
                },
            );
        }
    }

    /** @param list<string> $log */
    private static function recordingPurge(array &$log): RenderedPageCachePurge
    {
        return new class ($log) implements RenderedPageCachePurge {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function purge(array $tags): void
            {
                $this->log[] = 'purge:' . implode(',', $tags);
            }

            public function purgeAll(): bool
            {
                $this->log[] = 'purge:*';
                return true;
            }
        };
    }

    /** @param list<string> $log */
    private function recordingStore(
        array &$log,
        ?\Closure $onBaseline = null,
        ?\Closure $onClear = null,
    ): LayoutPreviewStore {
        return new class (
            $log,
            $onBaseline,
            $onClear,
            $this->container()->get(CacheStore::class),
            $this->container()->get(TenantCacheSegment::class),
            $this->container()->get(ApplicationContext::class),
        ) extends LayoutPreviewStore {
            /** @param list<string> $log */
            public function __construct(
                private array &$log,
                private readonly ?\Closure $onBaseline,
                private readonly ?\Closure $onClear,
                CacheStore $cache,
                TenantCacheSegment $segment,
                ApplicationContext $context,
            ) {
                parent::__construct($cache, $segment, $context);
            }

            public function putBaseline(string $session, array $document, int $expiresAt): void
            {
                $this->log[] = 'baseline';
                $this->onBaseline?->__invoke();
                parent::putBaseline($session, $document, $expiresAt);
            }

            public function clearIfPair(string $session, string $epoch, int $revision): bool
            {
                $this->log[] = 'clear';
                $this->onClear?->__invoke();
                return parent::clearIfPair($session, $epoch, $revision);
            }

            public function retire(string $session, int $expiresAt): void
            {
                $this->log[] = 'retire';
                parent::retire($session, $expiresAt);
            }
        };
    }

    // --- the proofs ---------------------------------------------------------------------------

    public function testFirstSaveCreatesAndAnswersTheVersion(): void
    {
        $session = $this->session();
        $saved = $this->save($session['token'], 'FIRST', 0);
        self::assertSame(200, $saved['status'], json_encode($saved['body']));
        self::assertSame(1, $saved['body']['data']['layout']['lock_version']);
        self::assertSame('FIRST', $saved['body']['data']['layout']['blocks'][0]['data']['text']);
        self::assertSame(1, self::committedVersion($this->secondConnection()));
    }

    public function testConcurrentFirstSavesOneWins(): void
    {
        $a = $this->session();
        $b = $this->session();
        self::assertSame(200, $this->save($a['token'], 'FROM-A', 0)['status']);
        $lost = $this->save($b['token'], 'FROM-B', 0);
        self::assertSame(409, $lost['status']);
        self::assertSame('LAYOUT_VERSION_CONFLICT', $lost['body']['error']['details']['code']);
        self::assertSame(1, $lost['body']['error']['details']['current']);
    }

    public function testStaleSaveWritesNothing(): void
    {
        $session = $this->session();
        self::assertSame(200, $this->save($session['token'], 'KEPT', 0)['status']);
        self::assertSame(409, $this->save($session['token'], 'STALE', 0)['status']);
        $row = $this->container()->get(LayoutRepository::class)->find('entry', 'post');
        self::assertSame(1, $row['lock_version']);
        self::assertSame('KEPT', $row['blocks'][0]['data']['text']);
    }

    public function testBaselineIsInstalledOnlyAfterTheCommitIsVisible(): void
    {
        // The editor saves what it last applied: the working copy and the save are one document.
        $session = $this->session();
        $applied = $this->apply($session['token'], 'COMMITTED');
        $pair = ['epoch' => $applied['body']['data']['epoch'], 'revision' => 1];
        $s = $this->claims($session['token'])->session;
        $other = $this->secondConnection();
        $seen = [];
        $log = [];
        $store = $this->recordingStore(
            $log,
            function () use ($other, &$seen): void {
                $seen['version'] = self::committedVersion($other);
            },
            function () use ($session, $s, &$seen): void {
                // Between the baseline and the clear: the baseline is already the committed layout,
                // and the stage shows it (or the identical working copy) — never the starter.
                $seen['baseline'] = $this->store()->baseline($s)['layout']['blocks'][0]['data']['text'] ?? null;
                $seen['stage'] = $this->stage($session['token']);
            },
        );
        $result = $this->saver(store: $store)->save(
            $this->claims($session['token']),
            self::layout('COMMITTED')['blocks'],
            [],
            0,
            $pair,
            null,
        );
        self::assertSame(1, $seen['version'], 'the new version is committed before the baseline moves');
        self::assertSame('COMMITTED', $seen['baseline']);
        self::assertStringContainsString('COMMITTED', $seen['stage']);
        self::assertSame(['baseline', 'clear'], $log);
        self::assertTrue($result['preview_cleared']);
        // After the clear the stage renders the committed baseline.
        self::assertNull($this->store()->current($s));
        self::assertStringContainsString('COMMITTED', $this->stage($session['token']));
    }

    public function testAnOuterRollbackDiscardsTheEffects(): void
    {
        $session = $this->session();
        $applied = $this->apply($session['token'], 'WORKING');
        $pair = ['epoch' => $applied['body']['data']['epoch'], 'revision' => 1];
        $resolver = $this->container()->get(LayoutResolver::class);
        self::assertNull($resolver->for('entry', 'post')); // cached: none
        $s = $this->claims($session['token'])->session;
        $baseline = $this->store()->baseline($s);
        $working = $this->store()->current($s);

        foreach (['rollback', 'commit'] as $outcome) {
            $log = [];
            $saver = $this->saver(store: $this->recordingStore($log), purge: self::recordingPurge($log));
            $tm = $this->connection()->getTransactionManager();
            $tm->begin();
            $saver->save($this->claims($session['token']), self::layout('INNER')['blocks'], [], 0, $pair, null);
            // Inside the outer transaction the save has not committed: nothing has moved.
            self::assertSame([], $log, $outcome);
            self::assertSame($baseline, $this->store()->baseline($s));
            self::assertSame($working, $this->store()->current($s));
            self::assertNull($resolver->for('entry', 'post'), 'the resolver still answers from its cache');
            if ($outcome === 'rollback') {
                $tm->rollback();
                self::assertSame([], $log);
                self::assertNull(self::committedVersion($this->secondConnection()));
                self::assertSame($baseline, $this->store()->baseline($s));
                self::assertSame($working, $this->store()->current($s));
                continue;
            }
            $tm->commit();
            self::assertSame(['baseline', 'clear', 'purge:thallo:layout:entry:post'], $log, 'once, in order');
            self::assertSame(1, self::committedVersion($this->secondConnection()));
            self::assertSame('INNER', $resolver->for('entry', 'post')['blocks'][0]['data']['text'], 'forgotten');
        }
    }

    public function testARolledBackSaveLeavesPreviewStateUntouched(): void
    {
        $session = $this->session();
        $applied = $this->apply($session['token'], 'WORKING');
        $pair = ['epoch' => $applied['body']['data']['epoch'], 'revision' => 1];
        $s = $this->claims($session['token'])->session;
        $resolver = $this->container()->get(LayoutResolver::class);
        self::assertNull($resolver->for('entry', 'post'));
        $baseline = $this->store()->baseline($s);
        $working = $this->store()->current($s);
        $lock = $this->container()->get(LayoutWriteLock::class);
        $failing = new class ($this->connection(), $lock) extends LayoutRepository {
            public function saveExpected(
                string $surface,
                string $target,
                array $blocks,
                array $settings,
                int $expected,
                ?string $by,
            ): int {
                parent::saveExpected($surface, $target, $blocks, $settings, $expected, $by);
                throw new \RuntimeException('the disk filled up');
            }

            public function tombstone(string $surface, string $target, int $expected, ?string $by): int
            {
                parent::tombstone($surface, $target, $expected, $by);
                throw new \RuntimeException('the disk filled up');
            }
        };
        $log = [];
        $saver = $this->saver($failing, $this->recordingStore($log), self::recordingPurge($log));
        try {
            $saver->save($this->claims($session['token']), self::layout('LOST')['blocks'], [], 0, $pair, null);
            self::fail('the save must fail');
        } catch (\RuntimeException $e) {
            self::assertSame('the disk filled up', $e->getMessage());
        }
        self::assertSame([], $log, 'no baseline, no clear, no purge');
        self::assertNull(self::committedVersion($this->secondConnection()));
        self::assertSame($baseline, $this->store()->baseline($s));
        self::assertSame($working, $this->store()->current($s));
        self::assertNull($resolver->for('entry', 'post'));

        // A rolled-back remove leaves the session alive and the layout live.
        self::assertSame(200, $this->save($session['token'], 'LIVE', 0)['status']);
        try {
            $saver->remove($this->claims($session['token']), 1, null);
            self::fail('the remove must fail');
        } catch (\RuntimeException $e) {
            self::assertSame('the disk filled up', $e->getMessage());
        }
        self::assertSame([], $log);
        self::assertFalse($this->store()->isRetired($s));
        self::assertSame(1, self::committedVersion($this->secondConnection()));
    }

    public function testClearOnlyOnTheExactPair(): void
    {
        $session = $this->session();
        $first = $this->apply($session['token'], 'ONE');
        $epoch = $first['body']['data']['epoch'];
        self::assertSame(200, $this->apply($session['token'], 'TWO', $epoch, 1)['status']);
        // Saved from revision 1 while revision 2 was already accepted: the later edit stays pending.
        $saved = $this->save($session['token'], 'ONE', 0, ['epoch' => $epoch, 'revision' => 1]);
        self::assertSame(200, $saved['status']);
        self::assertFalse($saved['body']['data']['preview_cleared']);
        $s = $this->claims($session['token'])->session;
        self::assertSame(2, $this->store()->current($s)['revision']);

        $again = $this->save($session['token'], 'TWO', 1, ['epoch' => $epoch, 'revision' => 2]);
        self::assertTrue($again['body']['data']['preview_cleared']);
        self::assertNull($this->store()->current($s));
    }

    /**
     * A save tells packs the layout changed (type layouts spec §7.4) — once, after the commit, never
     * for a save an outer transaction rolls back — naming the workspace only while tenancy is on.
     */
    public function testASaveAnnouncesTheChangeAfterItCommits(): void
    {
        $session = $this->session();
        $this->recordAnnouncements();
        $tm = $this->connection()->getTransactionManager();
        $tm->begin();
        $this->saver()->save($this->claims($session['token']), self::layout('ROLLED')['blocks'], [], 0, null, null);
        $tm->rollback();
        self::assertSame([], self::$announced, 'a rolled-back save announces nothing');

        self::assertSame(200, $this->save($session['token'], 'SAVED', 0)['status']);
        self::assertSame(
            [['surface' => 'entry', 'target' => 'post', 'tenant' => null, 'committed' => 1]],
            self::$announced,
            'once, after the commit, without a tenant while tenancy is off',
        );

        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');
        try {
            self::$announced = [];
            $tenants = new class () implements CurrentTenantResolver {
                public function tenantUuid(ApplicationContext $context): string
                {
                    return 'tnta';
                }
            };
            // The resolver's key is segmented by the same tenant the event names.
            $segment = new TenantCacheSegment($flags, $tenants);
            (new LayoutChanges(
                new LayoutResolver(
                    $this->container()->get(LayoutRepository::class),
                    $this->container()->get(CacheStore::class),
                    $segment,
                    $this->appContext(),
                ),
                $this->container()->get(LayoutSurfaceRegistry::class),
                null,
                $this->container()->get(EventService::class),
                $flags,
                $tenants,
                $this->appContext(),
            ))->announce('entry', 'post');
            self::assertSame('tnta', self::$announced[0]['tenant']);
        } finally {
            $flags->forget('tenancy.enabled');
        }
    }

    /**
     * Rendered pages are purged by the tags the surface declares (spec §7.4): the entry surface's
     * `thallo:layout:entry:{type}`; a surface declaring none — the shop's product page, whose pages
     * live in the shop's cache — issues no rendered-page purge at all, so a driver without tag
     * invalidation never drops every rendered page for it. An unregistered surface purges nothing
     * and is still announced.
     */
    public function testOnlyTheSurfacesDeclaredPageTagsArePurged(): void
    {
        $log = [];
        $changes = $this->changes(self::recordingPurge($log));
        $changes->announce('entry', 'post');
        self::assertSame(['purge:thallo:layout:entry:post'], $log);

        $registry = $this->container()->get(LayoutSurfaceRegistry::class);
        $registry->register(new FixtureLayoutSurface());
        try {
            $log = [];
            $changes->announce('fixture', '@site');
            self::assertSame([], $log, 'no purge, not even purgeAll');
        } finally {
            FixtureLayoutSurface::unregister($registry);
        }

        $this->recordAnnouncements();
        $log = [];
        $changes->announce('fixture', '@site');
        self::assertSame([], $log, 'an unregistered surface purges no rendered page');
        self::assertSame('fixture', self::$announced[0]['surface'] ?? null, 'and is still announced');
    }

    public function testSavePurgesTheSurfaceTagAndForgetsTheResolver(): void
    {
        $this->publishPost();
        self::assertStringNotContainsString('SAVED-MARKER', $this->page('/post/hello'));
        self::assertNull($this->container()->get(LayoutResolver::class)->for('entry', 'post'));

        $session = $this->session();
        self::assertSame(200, $this->save($session['token'], 'SAVED-MARKER', 0)['status']);
        self::assertNotNull($this->container()->get(LayoutResolver::class)->for('entry', 'post'));
        self::assertStringContainsString('SAVED-MARKER', $this->page('/post/hello'), 'the cached page was purged');
    }

    public function testRemoveTombstonesRetiresAndPurges(): void
    {
        $this->publishPost();
        $session = $this->session();
        self::assertSame(200, $this->save($session['token'], 'SAVED-MARKER', 0)['status']);
        self::assertStringContainsString('SAVED-MARKER', $this->page('/post/hello'));

        $removed = $this->remove($session['token'], 1);
        self::assertSame(200, $removed['status'], json_encode($removed['body']));
        self::assertSame(2, $removed['body']['data']['lock_version']);
        $late = $this->apply($session['token'], 'LATE');
        self::assertSame(410, $late['status']);
        self::assertSame('LAYOUT_SESSION_RETIRED', $late['body']['error']['details']['code']);
        self::assertStringNotContainsString('SAVED-MARKER', $this->page('/post/hello'));

        $fresh = $this->session();
        self::assertTrue($fresh['starter']);
        self::assertSame(2, $fresh['layout']['lock_version']);
    }

    public function testRemoveWithAStaleVersionConflicts(): void
    {
        $session = $this->session();
        self::assertSame(200, $this->save($session['token'], 'SAVED', 0)['status']);
        $stale = $this->remove($session['token'], 0);
        self::assertSame(409, $stale['status']);
        self::assertSame(1, $stale['body']['error']['details']['current']);
        self::assertFalse($this->store()->isRetired($this->claims($session['token'])->session));
    }

    public function testTwoTabsOneEditor(): void
    {
        $a = $this->session();
        $b = $this->session();
        self::assertSame(200, $this->save($a['token'], 'FROM-A', 0)['status']);
        self::assertSame(409, $this->save($b['token'], 'FROM-B', 0)['status']);
        // Reload: B opens again, on A's saved layout.
        $reloaded = $this->session();
        self::assertFalse($reloaded['starter']);
        self::assertSame(1, $reloaded['layout']['lock_version']);
        self::assertSame('FROM-A', $reloaded['layout']['blocks'][0]['data']['text']);
    }

    public function testIndexListsEveryTargetWithItsStateWhoSavedItAndWhetherTheCallerMayEdit(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'lpage', 'name' => 'Pages', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $this->connection()->getPDO()->exec(
            'INSERT INTO users (uuid, username, email, status)'
            . " VALUES ('editor000001', 'dana', 'dana@example.test', 'active')"
            // Another suite's cleanup may have soft-deleted the row: bring it back as this test needs it.
            . " ON CONFLICT (uuid) DO UPDATE SET username = 'dana', deleted_at = NULL"
        );
        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = self::layout('SAVED')['blocks'];
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            'post',
            fn (): int => $repo->saveExpected('entry', 'post', $blocks, [], 0, 'editor000001'),
        );
        $index = function (bool $mayEdit): array {
            $authority = new class ($mayEdit) implements PermissionRequirementAuthority {
                public function __construct(private readonly bool $mayEdit)
                {
                }

                public function allows(\Symfony\Component\HttpFoundation\Request $request, array $requirements): bool
                {
                    return $this->mayEdit && $requirements === ['templates.manage'];
                }
            };
            $controller = new LayoutAdminController(
                $this->appContext(),
                $this->container()->get(\Thallo\Contracts\Layouts\LayoutSurfaceRegistry::class),
                $this->container()->get(LayoutRepository::class),
                $this->container()->get(LayoutSaver::class),
                $this->store(),
                null,
                $authority,
            );
            return json_decode((string) $controller->index(Request::create('/x'))->getContent(), true)['data'];
        };

        $data = $index(true);
        self::assertTrue($data['can_edit']);
        $entryRows = array_filter($data['layouts'], static fn (array $row): bool => $row['surface'] === 'entry');
        $byTarget = array_column($entryRows, null, 'target');
        self::assertSame('custom', $byTarget['post']['state']);
        self::assertSame(1, $byTarget['post']['lock_version']);
        self::assertSame('Posts — single post', $byTarget['post']['label']);
        self::assertSame('dana', $byTarget['post']['updated_by_name']);
        self::assertSame('theme', $byTarget['lpage']['state']);
        self::assertNull($byTarget['lpage']['updated_by_name']);
        self::assertTrue($byTarget['lpage']['enabled']);

        self::assertFalse($index(false)['can_edit'], 'without templates.manage the list offers no editor');
    }

    public function testSamplesArePublishedOnly(): void
    {
        $this->publishPost();
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $draft = $entries->createEntry($this->postType, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Only a draft'], 1, 0, 'user00000001');

        $response = $this->admin()->samples(Request::create('/x'), 'entry', 'post');
        $body = json_decode((string) $response->getContent(), true)['data'];
        self::assertSame([['id' => 'posthello001', 'label' => 'Hello']], $body['samples']);
        self::assertSame('posthello001', $body['default']);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            ['GET', '/v1/admin/layouts', 'content_permission:content.view'],
            ['GET', '/v1/admin/layouts/{surface}/{target}/samples', 'content_permission:templates.manage'],
            ['PUT', '/v1/admin/layouts/{surface}/{target}', 'content_permission:templates.manage'],
            ['DELETE', '/v1/admin/layouts/{surface}/{target}', 'content_permission:templates.manage'],
        ];
    }

    /** @dataProvider routes */
    public function testTheRoutesCarryAuthAndTheirPermission(string $method, string $path, string $permission): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} is not registered");
        self::assertContains('auth', $route['middleware']);
        self::assertContains($permission, $route['middleware']);
    }

    private function publishPost(): void
    {
        $db = $this->connection();
        $at = '2026-06-02 09:00:00';
        $db->table('entries')->insert(['uuid' => 'posthello001', 'content_type_uuid' => $this->postType,
            'status' => 'active', 'created_at' => $at, 'updated_at' => $at]);
        $db->table('entry_versions')->insert(['uuid' => 'vosthello001', 'entry_uuid' => 'posthello001',
            'locale' => 'en',
            'version' => 1, 'fields' => json_encode(['title' => 'Hello', 'body' => []]), 'schema_version' => 1,
            'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => 'posthello001', 'locale' => 'en',
            'version_uuid' => 'vosthello001', 'published_at' => $at]);
        (new RouteRepository($db))->assign('posthello001', $this->postType, 'en', 'hello');
    }

    private function page(string $path): string
    {
        $response = $this->handle(Request::create($path, 'GET'));
        self::assertSame(200, $response->getStatusCode(), $path);
        return (string) $response->getContent();
    }
}
