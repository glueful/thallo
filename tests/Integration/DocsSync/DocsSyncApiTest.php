<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\DocsSync\DocsSyncRunRepository;
use Thallo\Core\Content\DocsSync\DocsSyncStore;
use Thallo\Core\Content\DocsSync\GithubClient;
use Thallo\Core\Content\Http\Controllers\DocsSyncController;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/** The admin's docs sync API (docs sync design §6, §9). */
final class DocsSyncApiTest extends AppTestCase
{
    use DocsSyncTestHelpers;

    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var array{tag: string, sha: string} the newest tag GitHub reports */
    private array $latest = ['tag' => 'v1.0.0', 'sha' => self::SHA_A];

    private bool $githubDown = false;

    private int $githubRequests = 0;

    private RecordingQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
        $this->queue = $this->recordingQueue();
    }

    protected function tearDown(): void
    {
        $this->cleanDocsSync();
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        $this->container()->get(CacheStore::class)->clear();
        parent::tearDown();
    }

    private function controller(): DocsSyncController
    {
        $github = new GithubClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->githubRequests++;
            if ($this->githubDown) {
                return new MockResponse('', ['error' => 'connection refused']);
            }
            if (str_ends_with($url, '/repos/glueful/thallo')) {
                return new MockResponse((string) json_encode(['private' => false, 'default_branch' => 'main']));
            }
            if (str_ends_with($url, '/repos/acme/secret')) {
                return new MockResponse((string) json_encode(['private' => true, 'default_branch' => 'main']));
            }
            if (str_contains($url, '/git/matching-refs/tags/')) {
                return new MockResponse((string) json_encode([[
                    'ref' => 'refs/tags/' . $this->latest['tag'],
                    'object' => ['sha' => $this->latest['sha'], 'type' => 'commit'],
                ]]));
            }
            return new MockResponse('{}', ['http_code' => 404]);
        }));
        $c = $this->container();
        return new DocsSyncController(
            $this->service($this->queue),
            $c->get(DocsSyncStore::class),
            $c->get(DocsSyncRunRepository::class),
            $github,
            $c->get(CacheStore::class),
        );
    }

    /** @return array<string,mixed> */
    private static function data(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return (array) (json_decode((string) $response->getContent(), true)['data'] ?? []);
    }

    private static function json(string $method, array $body): Request
    {
        return Request::create('/v1/admin/docs-sync', $method, [], [], [], [], (string) json_encode($body));
    }

    private static function source(array $over = []): array
    {
        return $over + [
            'repo' => 'glueful/thallo', 'folder' => 'docs', 'follow' => 'release', 'prereleases' => true,
            'content_type' => 'docs', 'publish' => true, 'exclude' => [],
        ];
    }

    public function testSavingASourceChecksGithubAndFillsTheEditBranch(): void
    {
        $response = $this->controller()->saveSource(self::json('PUT', self::source()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $source = $this->container()->get(DocsSyncStore::class)->state()['source'];
        self::assertSame('main', $source?->editBranch);
    }

    public function testSavingASourceGithubRejectsIsNotSaved(): void
    {
        $response = $this->controller()->saveSource(self::json('PUT', self::source(['repo' => 'acme/secret'])));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('public', (string) $response->getContent());
        self::assertNull($this->container()->get(DocsSyncStore::class)->state()['source']);
    }

    public function testAnInvalidSourceNamesTheField(): void
    {
        $response = $this->controller()->saveSource(self::json('PUT', self::source(['folder' => '../x'])));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('folder', (string) $response->getContent());
    }

    public function testStartCancelAndShow(): void
    {
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));

        $started = $controller->start(self::json('POST', ['mode' => 'commit']));
        self::assertSame(202, $started->getStatusCode());
        $run = (string) self::data($started)['run']['id'];

        $again = $controller->start(self::json('POST', ['mode' => 'commit']));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame(422, $controller->start(self::json('POST', ['mode' => 'publish']))->getStatusCode());

        $shown = self::data($controller->show());
        self::assertSame($run, $shown['active_run']);
        self::assertSame($run, $shown['recent'][0]['id']);

        $cancelled = self::data($controller->cancel($run));
        self::assertSame(['finished' => true, 'outcome' => 'cancelled'], $cancelled);
        self::assertSame([], self::data($controller->runPages($run))['pages']);
    }

    public function testTheNoteComparesCommitsNotTagNames(): void
    {
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));
        self::assertSame('not_synced', self::data($controller->show())['note']['state']);

        // A sync of v1.0.0 at SHA_A lands.
        $run = $this->service($this->queue)->start('commit', null)['id'];
        $this->setRun((string) $run, [
            'status' => 'finished', 'outcome' => 'success', 'ref' => 'v1.0.0', 'sha' => self::SHA_A,
        ]);
        $this->setState(['active_run' => null, 'synced_run' => $run]);
        $cache = $this->container()->get(CacheStore::class);

        $cache->clear();
        self::assertSame('up_to_date', self::data($controller->show())['note']['state']);

        $this->latest = ['tag' => 'v1.0.0', 'sha' => self::SHA_B];
        $cache->clear();
        self::assertSame('tag_moved', self::data($controller->show())['note']['state']);

        $this->latest = ['tag' => 'v1.1.0', 'sha' => self::SHA_B];
        $cache->clear();
        $note = self::data($controller->show())['note'];
        self::assertSame('newer', $note['state']);
        self::assertSame('v1.1.0', $note['followed']['ref']);
    }

    public function testTheCachedNoteBelongsToTheSourceItWasMadeFor(): void
    {
        // Review M1: two workspaces can sit at the same revision with different sources; a note
        // cached for one is never shown for the other. Here: the same revision, another follow.
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));
        self::assertSame('v1.0.0', self::data($controller->show())['note']['followed']['ref']);

        $row = $this->stateRow();
        $source = json_decode((string) $row['source'], true);
        $source['follow'] = 'branch';
        $source['branch'] = 'develop';
        $this->setState(['source' => json_encode($source)]);

        $note = self::data($controller->show())['note'];
        self::assertNotSame('v1.0.0', $note['followed']['ref'] ?? null);
    }

    public function testTheNoteSurvivesGithubBeingDown(): void
    {
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));
        $this->githubDown = true;
        $this->container()->get(CacheStore::class)->clear();

        $response = $controller->show();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('unreachable', self::data($response)['note']['state']);
    }

    public function testAFailedNoteIsCachedBriefly(): void
    {
        // Review M2: while GitHub is down, the card's 2-second polling does not ask it every time.
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));
        $this->githubDown = true;
        $this->container()->get(CacheStore::class)->clear();

        $controller->show();
        $asked = $this->githubRequests;
        $note = self::data($controller->show())['note'];

        self::assertSame('unreachable', $note['state']);
        self::assertSame($asked, $this->githubRequests, 'the second poll did not ask GitHub');
    }

    public function testRemovingTheSource(): void
    {
        $controller = $this->controller();
        $controller->saveSource(self::json('PUT', self::source()));

        self::assertSame(200, $controller->deleteSource()->getStatusCode());
        self::assertNull($this->container()->get(DocsSyncStore::class)->state()['source']);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function routes(): iterable
    {
        $write = ['content_permission:content.manage', 'import_export_permission:import_export.run_import'];
        $read = ['import_export_permission:import_export.view'];
        yield 'show' => ['GET', '/v1/admin/docs-sync', $read];
        yield 'run pages' => ['GET', '/v1/admin/docs-sync/runs/{run}', $read];
        yield 'save source' => ['PUT', '/v1/admin/docs-sync/source', $write];
        yield 'remove source' => ['DELETE', '/v1/admin/docs-sync/source', $write];
        yield 'start' => ['POST', '/v1/admin/docs-sync/run', $write];
        yield 'cancel' => ['POST', '/v1/admin/docs-sync/runs/{run}/cancel', $write];
        yield 'release' => ['POST', '/v1/admin/docs-sync/runs/{run}/release', $write];
    }

    /**
     * @dataProvider routes
     * @param list<string> $needs
     */
    public function testEachRouteNeedsItsPermissions(string $method, string $path, array $needs): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} is registered");
        $middleware = (array) ($route['middleware'] ?? []);
        foreach ($needs as $need) {
            self::assertContains($need, $middleware, "{$method} {$path} needs {$need}");
        }
    }
}
