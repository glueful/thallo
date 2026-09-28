<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Http\Controllers\ContentTypeController;
use Thallo\Core\Content\Http\Controllers\MigrationController;
use Thallo\Core\Content\Http\DTOs\MigrationData;
use Thallo\Core\Content\Layouts\LayoutBindings;
use Thallo\Core\Content\Layouts\LayoutChanges;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\MigrationRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Services\MigrationService;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\RecordsLayoutChanges;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * Layouts follow their content type (type layouts spec §5.7): renaming a field rewrites the blocks
 * that show it; deleting one a layout shows is refused, naming the layout; the rewrite commits with
 * the schema change or not at all; a save cannot bind a field a migration is deleting; deleting the
 * type tombstones its layout. A binding broken anyway renders nothing on the site and says so on
 * the stage.
 */
final class LayoutBindingsTest extends AppTestCase
{
    use RecordsLayoutChanges;
    use SyncsBlockStyleDeclarations;

    private string $postType = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->types()->create([
            'slug' => 'category', 'name' => 'Categories', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
            ],
        ]);
        $this->postType = $this->types()->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
                ['name' => 'subtitle', 'type' => 'string'],
                ['name' => 'blurb', 'type' => 'text', 'format' => 'plain'],
                ['name' => 'body', 'type' => 'blocks'],
                ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                    'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->container()->get(LayoutResolver::class)->forget('entry', 'post');
        parent::tearDown();
    }

    private function types(): ContentTypeRepository
    {
        return $this->container()->get(ContentTypeRepository::class);
    }

    /** @return list<array<string,mixed>> */
    private static function blocks(string $excerptField = 'excerpt'): array
    {
        return [
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
            ['id' => 'layexcer0001', 'type' => 'entry_excerpt', 'data' => ['field' => $excerptField], 'settings' => []],
        ];
    }

    private function saveLayout(): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = self::blocks();
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            'post',
            fn (): int => $repo->saveExpected('entry', 'post', $blocks, [], 0, null),
        );
    }

    private function layout(): array
    {
        return $this->container()->get(LayoutRepository::class)->find('entry', 'post');
    }

    /** @param list<array<string,mixed>> $ops */
    private function migrate(array $ops): \Symfony\Component\HttpFoundation\Response
    {
        $dto = (new RequestDataHydrator())->hydrate(MigrationData::class, ['ops' => $ops]);
        return $this->container()->get(MigrationController::class)->store($dto, Request::create('/x', 'POST'), 'post');
    }

    private function otherConnection(): Connection
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

    public function testRenamingAFieldRewritesItsBindings(): void
    {
        $this->saveLayout();
        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => 'entry', 'target' => 'post'],
        );
        $session = json_decode(
            (string) $this->container()->get(LayoutPreviewController::class)->session($dto)->getContent(),
            true,
        )['data'];
        self::assertSame(1, $session['layout']['lock_version']);

        $this->recordLayoutChanges();
        $renamed = $this->migrate([['op' => 'rename', 'from' => 'excerpt', 'to' => 'summary']]);
        self::assertSame(201, $renamed->getStatusCode());
        self::assertSame(['entry:post'], $this->recordedLayoutChanges(), 'the rewrite is announced');
        $layout = $this->layout();
        self::assertSame('summary', $layout['blocks'][1]['data']['field']);
        self::assertSame(2, $layout['lock_version']);

        // The editor that opened before the rename is behind: its Save meets the new version.
        $save = (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
            'token' => $session['token'], 'layout' => ['blocks' => self::blocks('summary'), 'settings' => []],
            'expected_lock_version' => 1,
        ]);
        $response = $this->container()->get(LayoutAdminController::class)
            ->save($save, Request::create('/x', 'PUT'), 'entry', 'post');
        self::assertSame(409, $response->getStatusCode());
    }

    public function testAnExcerptLeftUnchosenFollowsARenameAndRefusesADelete(): void
    {
        // Saved as the editor saves it — through the saver, whose validation binds the default.
        $token = \Thallo\Core\Content\Preview\LayoutPreviewToken::mint(
            'bindsession01',
            'entry',
            'post',
            null,
            'en',
            time() + 600,
            (new class () {
                use \Thallo\Core\Content\Preview\ResolvesPreviewKey;

                public function of(\Glueful\Bootstrap\ApplicationContext $c): string
                {
                    return $this->previewKey($c);
                }
            })->of($this->appContext()),
        );
        $claims = \Thallo\Core\Content\Preview\LayoutPreviewToken::verify($token, (new class () {
            use \Thallo\Core\Content\Preview\ResolvesPreviewKey;

            public function of(\Glueful\Bootstrap\ApplicationContext $c): string
            {
                return $this->previewKey($c);
            }
        })->of($this->appContext()), time());
        $this->container()->get(\Thallo\Core\Content\Layouts\LayoutSaver::class)->save($claims, [
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => [], 'settings' => []],
            ['id' => 'layexcer0001', 'type' => 'entry_excerpt', 'data' => [], 'settings' => []],
        ], [], 0, null, null);
        self::assertSame('excerpt', $this->layout()['blocks'][1]['data']['field']);

        self::assertSame(422, $this->migrate([['op' => 'delete', 'name' => 'excerpt']])->getStatusCode());
        $renamed = $this->migrate([['op' => 'rename', 'from' => 'excerpt', 'to' => 'summary']]);
        self::assertSame(201, $renamed->getStatusCode());
        self::assertSame('summary', $this->layout()['blocks'][1]['data']['field']);
    }

    /**
     * The rows a type's layouts are listed from can be a moment old — a save or a backfill write
     * lands between the list and the write. Each layout is re-read under its own lock, so a rename
     * and a type deletion still go through instead of failing on a version that moved.
     */
    public function testRenameAndTombstoneReReadEachLayoutUnderItsLock(): void
    {
        $this->saveLayout();
        $lock = $this->container()->get(LayoutWriteLock::class);
        $stale = new class ($this->connection(), $lock) extends LayoutRepository {
            public function forType(string $typeSlug): array
            {
                // As listed before another write bumped each version.
                return array_map(
                    static fn (array $row): array => ['lock_version' => $row['lock_version'] - 1] + $row,
                    parent::forType($typeSlug),
                );
            }
        };
        $bindings = new LayoutBindings(
            $this->connection(),
            $stale,
            $lock,
            $this->container()->get(LayoutSurfaceRegistry::class),
            $this->container()->get(LayoutChanges::class),
        );
        $this->connection()->transaction(fn () => $bindings->renameField('post', 'excerpt', 'summary'));
        self::assertSame('summary', $this->layout()['blocks'][1]['data']['field']);
        self::assertSame(2, $this->layout()['lock_version']);

        $this->connection()->transaction(fn () => $bindings->tombstoneType('post'));
        self::assertNull($this->layout()['blocks']);
        self::assertSame(3, $this->layout()['lock_version']);
    }

    public function testDeletingABoundFieldIsRefusedNamingTheLayout(): void
    {
        $this->saveLayout();
        $before = (int) $this->types()->findBySlug('post')['schema_version'];

        $refused = $this->migrate([['op' => 'delete', 'name' => 'excerpt']]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Posts — single post', (string) $refused->getContent());
        self::assertSame($before, (int) $this->types()->findBySlug('post')['schema_version']);
        self::assertSame(1, $this->layout()['lock_version']);
        self::assertSame('excerpt', $this->layout()['blocks'][1]['data']['field']);

        self::assertSame(201, $this->migrate([['op' => 'delete', 'name' => 'subtitle']])->getStatusCode());
    }

    public function testARolledBackMigrationLeavesLayoutsUnchanged(): void
    {
        $this->saveLayout();
        $purged = [];
        $purge = new class ($purged) implements RenderedPageCachePurge {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function purge(array $tags): void
            {
                $this->log = [...$this->log, ...$tags];
            }

            public function purgeAll(): bool
            {
                $this->log[] = '*';
                return true;
            }
        };
        $lock = $this->container()->get(LayoutWriteLock::class);
        $service = new MigrationService(
            $this->container()->get(ApplicationContext::class),
            $this->connection(),
            $this->types(),
            $this->container()->get(MigrationRepository::class),
            $this->container()->get(QueueManager::class),
            $lock,
            new LayoutBindings(
                $this->connection(),
                $this->container()->get(LayoutRepository::class),
                $lock,
                $this->container()->get(LayoutSurfaceRegistry::class),
                new LayoutChanges(
                    $this->container()->get(LayoutResolver::class),
                    $this->container()->get(LayoutSurfaceRegistry::class),
                    $purge,
                ),
            ),
        );
        // The rename rewrites the layout inside the flip's transaction; the transaction then rolls
        // back (as a flip that throws would roll it back).
        $tm = $this->connection()->getTransactionManager();
        $tm->begin();
        $service->migrate($this->postType, [['op' => 'rename', 'from' => 'excerpt', 'to' => 'summary']], null);
        self::assertSame('summary', $this->layout()['blocks'][1]['data']['field'], 'rewritten inside it');
        $tm->rollback();

        $row = $this->otherConnection()->table('layouts')->where('target', '=', 'post')->first();
        $blocks = json_decode((string) $row['blocks'], true);
        self::assertSame('excerpt', $blocks[1]['data']['field']);
        self::assertSame(1, (int) $row['lock_version']);
        self::assertSame([], $purged, 'no purge');
    }

    /**
     * The Regions arrangement: the migration deleting `excerpt` holds the type's lock; a save that
     * binds `excerpt`, in another process, is seen waiting on it; only then does the migration
     * commit — and the save finds the field gone.
     */
    public function testASaveCannotSlipABindingPastADeletion(): void
    {
        $lock = $this->container()->get(LayoutWriteLock::class);
        $child = null;
        $lock->withinType('post', function () use ($lock, &$child): void {
            $this->container()->get(MigrationService::class)
                ->migrate($this->postType, [['op' => 'delete', 'name' => 'excerpt']], null);
            $child = $this->startWriter('save-binding', ['target' => 'post', 'field' => 'excerpt']);
            $ready = $this->readLine($child['stdout']);
            self::assertTrue($ready['ready'] ?? false, 'the writer did not report ready');
            $this->awaitLockWait((int) $ready['pid'], $lock->typeKey('post'));
        });
        $result = $this->readLine($child['stdout'], 15);
        proc_close($child['proc']);
        self::assertArrayHasKey('invalid', $result, json_encode($result));
        self::assertArrayHasKey('blocks.1.data.field', $result['invalid']);
        self::assertNull($this->container()->get(LayoutRepository::class)->find('entry', 'post'));
    }

    /**
     * A listing or archive save takes its type's lock, as an entry save does: a migration holding
     * `post`'s lock is seen blocking both, and each proceeds only once it releases.
     */
    public function testListingAndArchiveSavesTakeTheTypeLock(): void
    {
        $lock = $this->container()->get(LayoutWriteLock::class);
        foreach ([['listing', 'post'], ['archive', 'post:categories']] as [$surface, $target]) {
            $child = null;
            $lock->withinType('post', function () use ($lock, $surface, $target, &$child): void {
                $child = $this->startWriter('save-at', ['surface' => $surface, 'target' => $target, 'expected' => 99]);
                $ready = $this->readLine($child['stdout']);
                self::assertTrue($ready['ready'] ?? false, 'the writer did not report ready');
                $this->awaitLockWait((int) $ready['pid'], $lock->typeKey('post'));
            });
            $result = $this->readLine($child['stdout'], 15);
            proc_close($child['proc']);
            self::assertSame(['conflict' => true, 'current' => 0], $result, "{$surface}:{$target}");
        }
    }

    public function testTheSchemaSaveStillRefusesDroppingAField(): void
    {
        $this->expectException(SchemaParseException::class);
        $this->types()->updateSchema($this->postType, [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
    }

    /** A card that binds `blurb`, as a listing or archive layout holds it. */
    private static function cardLayout(): array
    {
        return [
            ['id' => 'laylooptitl1', 'type' => 'heading', 'data' => ['text' => 'Latest'], 'settings' => []],
            ['id' => 'layloop00001', 'type' => 'entry_loop', 'data' => ['card' => [
                ['id' => 'laycardsum01', 'type' => 'entry_excerpt', 'data' => ['field' => 'blurb'], 'settings' => []],
            ]], 'settings' => []],
        ];
    }

    /** A stored layout row, as Save or Remove leaves one: blocks null is a tombstone. */
    private function putRow(string $surface, string $target, ?array $blocks, int $version): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('layouts')->insert([
            'id' => \Glueful\Helpers\Utils::generateNanoID(),
            'surface' => $surface,
            'target' => $target,
            'blocks' => $blocks === null ? null : json_encode($blocks, JSON_THROW_ON_ERROR),
            'settings' => '{}',
            'lock_version' => $version,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function row(string $surface, string $target): ?array
    {
        return $this->container()->get(LayoutRepository::class)->find($surface, $target);
    }

    /** A Save through the real saver, from a session minted for `surface:target`. */
    private function saveAt(string $surface, string $target, int $expected): \Symfony\Component\HttpFoundation\Response
    {
        $keys = new class () {
            use \Thallo\Core\Content\Preview\ResolvesPreviewKey;

            public function of(\Glueful\Bootstrap\ApplicationContext $context): string
            {
                return $this->previewKey($context);
            }
        };
        $key = $keys->of($this->appContext());
        $session = 'stale' . substr(md5($surface . $target), 0, 7);
        $token = \Thallo\Core\Content\Preview\LayoutPreviewToken::mint(
            $session,
            $surface,
            $target,
            null,
            'en',
            time() + 600,
            $key,
        );
        $this->container()->get(\Thallo\Core\Content\Preview\LayoutPreviewStore::class)->putBaseline($session, [
            'layout' => ['blocks' => [], 'settings' => []], 'lock_version' => $expected,
            'surface' => $surface, 'target' => $target, 'sample' => null,
        ], time() + 600);
        $dto = (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
            'token' => $token, 'layout' => ['blocks' => self::cardLayout(), 'settings' => []],
            'expected_lock_version' => $expected,
        ]);
        return $this->container()->get(LayoutAdminController::class)
            ->save($dto, Request::create('/x', 'PUT'), $surface, $target);
    }

    public function testAFieldRenameRebindsCards(): void
    {
        $this->putRow('listing', 'post', self::cardLayout(), 2);
        $this->putRow('archive', 'post:categories', self::cardLayout(), 5);
        $this->recordLayoutChanges();
        self::assertSame(201, $this->migrate([['op' => 'rename', 'from' => 'blurb', 'to' => 'teaser']])
            ->getStatusCode());
        foreach ([['listing', 'post', 3], ['archive', 'post:categories', 6]] as [$surface, $target, $version]) {
            $row = $this->row($surface, $target);
            self::assertSame('teaser', $row['blocks'][1]['data']['card'][0]['data']['field'], "{$surface}:{$target}");
            self::assertSame($version, $row['lock_version']);
        }
        $changes = $this->recordedLayoutChanges();
        sort($changes);
        self::assertSame(['archive:post:categories', 'listing:post'], $changes);
    }

    public function testRenamingAnArchivedFieldMovesItsLayout(): void
    {
        $this->putRow('archive', 'post:categories', self::cardLayout(), 3);
        $this->recordLayoutChanges();
        self::assertSame(201, $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']])
            ->getStatusCode());
        $moved = $this->row('archive', 'post:topics');
        self::assertEquals(self::cardLayout(), $moved['blocks']);
        self::assertSame(4, $moved['lock_version']);
        $left = $this->row('archive', 'post:categories');
        self::assertNull($left['blocks'], 'the old name keeps a tombstone');
        self::assertSame(4, $left['lock_version']);
        $changes = $this->recordedLayoutChanges();
        sort($changes);
        self::assertSame(['archive:post:categories', 'archive:post:topics'], $changes);
    }

    public function testTheDestinationsTombstoneKeepsItsVersion(): void
    {
        $this->putRow('archive', 'post:categories', self::cardLayout(), 3);
        $this->putRow('archive', 'post:topics', null, 7);
        self::assertSame(201, $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']])
            ->getStatusCode());
        self::assertSame(8, $this->row('archive', 'post:topics')['lock_version']);
        self::assertEquals(self::cardLayout(), $this->row('archive', 'post:topics')['blocks']);
        self::assertSame(4, $this->row('archive', 'post:categories')['lock_version']);
        self::assertNull($this->row('archive', 'post:categories')['blocks']);
    }

    public function testRenamingBackAdvancesBothNames(): void
    {
        $this->putRow('archive', 'post:categories', self::cardLayout(), 3);
        $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']]);
        $afterFirst = [
            $this->row('archive', 'post:categories')['lock_version'],
            $this->row('archive', 'post:topics')['lock_version'],
        ];
        // The first migration's entry rewrite is a queued job: settle it, as a worker would, so the
        // type takes another migration (its layouts moved at the flip, not in the job).
        $this->connection()->table('entry_schema_migrations')->where('content_type_uuid', '=', $this->postType)
            ->update(['status' => 'completed']);
        self::assertSame(201, $this->migrate([['op' => 'rename', 'from' => 'topics', 'to' => 'categories']])
            ->getStatusCode());
        $back = $this->row('archive', 'post:categories');
        $away = $this->row('archive', 'post:topics');
        self::assertEquals(self::cardLayout(), $back['blocks'], 'the layout is back at its first name');
        self::assertNull($away['blocks']);
        self::assertGreaterThan(max(3, $afterFirst[0]), $back['lock_version']);
        self::assertGreaterThan($afterFirst[1], $away['lock_version']);
    }

    /**
     * An editor who opened either name before the move is behind at both: each Save answers 409 —
     * before the target is validated, so a name that has no archive any more still says "changed".
     */
    public function testStaleEditorsAtBothNamesMeet409(): void
    {
        $this->putRow('archive', 'post:categories', self::cardLayout(), 3);
        $this->putRow('archive', 'post:topics', null, 7);
        $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']]);
        foreach ([['post:categories', 3], ['post:topics', 7]] as [$target, $held]) {
            $before = $this->row('archive', $target);
            $answer = $this->saveAt('archive', $target, $held);
            $body = json_decode((string) $answer->getContent(), true);
            self::assertSame(409, $answer->getStatusCode(), "{$target}: " . $answer->getContent());
            self::assertSame('LAYOUT_VERSION_CONFLICT', $body['error']['details']['code'] ?? null);
            self::assertSame($before, $this->row('archive', $target), "{$target}: nothing written");
        }
    }

    public function testALiveDestinationRefusesTheRename(): void
    {
        $this->putRow('archive', 'post:categories', self::cardLayout(), 3);
        $this->putRow('archive', 'post:topics', self::cardLayout(), 2);
        $refused = $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']]);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getContent());
        self::assertStringContainsString('Posts — Topics archive', (string) $refused->getContent());
        self::assertSame(3, $this->row('archive', 'post:categories')['lock_version']);
        self::assertSame(2, $this->row('archive', 'post:topics')['lock_version']);
    }

    public function testASourceTombstoneMovesNothing(): void
    {
        $this->putRow('archive', 'post:categories', null, 4);
        self::assertSame(201, $this->migrate([['op' => 'rename', 'from' => 'categories', 'to' => 'topics']])
            ->getStatusCode());
        self::assertSame(4, $this->row('archive', 'post:categories')['lock_version']);
        self::assertNull($this->row('archive', 'post:topics'));
    }

    public function testDeletingAFieldAnArchiveOrCardUsesIsRefused(): void
    {
        $this->putRow('listing', 'post', self::cardLayout(), 1);
        $this->putRow('archive', 'post:categories', self::cardLayout(), 1);
        $blurb = $this->migrate([['op' => 'delete', 'name' => 'blurb']]);
        self::assertSame(422, $blurb->getStatusCode(), (string) $blurb->getContent());
        // Named as the Layouts page names them.
        self::assertStringContainsString("'blurb' is shown by", (string) $blurb->getContent());
        self::assertStringContainsString('Posts — listing pages', (string) $blurb->getContent());
        self::assertStringContainsString('Posts — Categories archive', (string) $blurb->getContent());
        $categories = $this->migrate([['op' => 'delete', 'name' => 'categories']]);
        self::assertSame(422, $categories->getStatusCode(), (string) $categories->getContent());
        self::assertStringContainsString("'categories' is shown by", (string) $categories->getContent());
    }

    public function testDeletingATypeTombstonesItsListingAndArchiveLayouts(): void
    {
        $this->saveLayout();
        $this->putRow('listing', 'post', self::cardLayout(), 2);
        $this->putRow('archive', 'post:categories', self::cardLayout(), 5);
        $response = $this->container()->get(ContentTypeController::class)
            ->destroy(Request::create('/x', 'DELETE'), 'post');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $expected = [['entry', 'post', 2], ['listing', 'post', 3], ['archive', 'post:categories', 6]];
        foreach ($expected as [$surface, $target, $version]) {
            $row = $this->row($surface, $target);
            self::assertNull($row['blocks'], "{$surface}:{$target} is a tombstone");
            self::assertSame($version, $row['lock_version']);
        }
    }

    public function testDeletingTheTypeTombstonesItsLayout(): void
    {
        $this->saveLayout();
        $this->recordLayoutChanges();
        $response = $this->container()->get(ContentTypeController::class)
            ->destroy(Request::create('/x', 'DELETE'), 'post');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['entry:post'], $this->recordedLayoutChanges(), 'the tombstone is announced');

        $row = $this->layout();
        self::assertNull($row['blocks'], 'the row is kept, as a tombstone');
        self::assertSame(2, $row['lock_version']);
        $index = $this->container()->get(LayoutAdminController::class)->index(Request::create('/x'));
        $list = json_decode((string) $index->getContent(), true);
        self::assertNotContains('post', array_column($list['data']['layouts'], 'target'));
    }

    public function testABrokenBindingRendersNothingAndSaysSoOnTheStage(): void
    {
        $this->saveLayout();
        // A raw schema write (an import) drops the bound field behind the layout's back.
        $this->connection()->table('content_types')->where('uuid', '=', $this->postType)->update([
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ]),
        ]);
        $this->publish();

        $site = (string) $this->handle(Request::create('/post/hello', 'GET'))->getContent();
        self::assertStringContainsString('thallo-layout--entry', $site);
        self::assertStringNotContainsString('thallo-block-entry_excerpt', $site);

        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => 'entry', 'target' => 'post'],
        );
        $token = json_decode(
            (string) $this->container()->get(LayoutPreviewController::class)->session($dto)->getContent(),
            true,
        )['data']['token'];
        $stage = (string) $this->container()->get(RenderController::class)
            ->preview(Request::create("/_preview/{$token}?canvas=1", 'GET'), $token)->getContent();
        self::assertMatchesRegularExpression('~thallo-block-entry_excerpt thallo-field-empty">Excerpt — ~', $stage);
    }

    private function publish(): void
    {
        $db = $this->connection();
        $at = '2026-06-02 09:00:00';
        $db->table('entries')->insert(['uuid' => 'posthello001', 'content_type_uuid' => $this->postType,
            'status' => 'active', 'created_at' => $at, 'updated_at' => $at]);
        $db->table('entry_versions')->insert(['uuid' => 'vosthello001', 'entry_uuid' => 'posthello001',
            'locale' => 'en', 'version' => 1, 'fields' => json_encode(['title' => 'Hello', 'body' => []]),
            'schema_version' => 1, 'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => 'posthello001', 'locale' => 'en',
            'version_uuid' => 'vosthello001', 'published_at' => $at]);
        (new RouteRepository($db))->assign('posthello001', $this->postType, 'en', 'hello');
    }

    /** @param array<string,mixed> $input @return array{proc: resource, stdout: resource} */
    private function startWriter(string $path, array $input): array
    {
        $script = dirname(__DIR__, 3) . '/Support/bin/layout-writer.php';
        $proc = proc_open(
            [PHP_BINARY, $script, $path],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', sys_get_temp_dir() . '/thallo-layout-writer.err', 'a'],
            ],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        return ['proc' => $proc, 'stdout' => $pipes[1]];
    }

    /** @param resource $stream @return array<string,mixed> */
    private function readLine($stream, int $seconds = 10): array
    {
        $deadline = microtime(true) + $seconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = fgets($stream);
            if ($chunk !== false) {
                $buffer .= $chunk;
                if (str_ends_with($buffer, "\n")) {
                    return json_decode(trim($buffer), true, 512, JSON_THROW_ON_ERROR);
                }
            }
            usleep(20_000);
        }
        self::fail('the writer printed no line within ' . $seconds . 's');
    }

    private function awaitLockWait(int $pid, int $key): void
    {
        $pdo = $this->connection()->newPdo();
        $stmt = $pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted"
            . ' AND classid = 0 AND objid = ? AND objsubid = 1)'
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $stmt->execute([$pid, $key]);
            if ((bool) $stmt->fetchColumn()) {
                return;
            }
            usleep(20_000);
        }
        self::fail("backend {$pid} never waited on the type lock");
    }
}
