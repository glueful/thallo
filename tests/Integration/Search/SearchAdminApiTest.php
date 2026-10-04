<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Http\SearchAdminController;
use Thallo\Search\Lifecycle\SearchDemand;
use Thallo\Search\Lifecycle\Workspace;

/**
 * Settings › Search's API (search block spec §3.8): each kind's status, progress and last error
 * (sanitised), whether background processing has picked up a request, and Rebuild — demand
 * committed before the wake-up is queued, reported truthfully when queueing fails, and nothing
 * queued for a request that rolled back.
 */
final class SearchAdminApiTest extends AppTestCase
{
    use GrantsPermissions;

    private LifecycleKit $kit;
    /** @var list<array<string, mixed>> */
    private array $pushes = [];
    /** @var list<int> */
    private array $demandSeenAtPush = [];
    private ?\Throwable $pushFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new LifecycleKit($this->appContext(), $this->connection());
        $this->kit->registry->register(new ArraySource('products', ['thallo.commerce']));
        $this->kit->source->items = ['a' => ['en' => 'Rose']];
    }

    protected function tearDown(): void
    {
        $this->scrubGrants();
        parent::tearDown();
    }

    public function testStatusListsEveryRegisteredKindWithSanitisedErrors(): void
    {
        $this->kit->reconciler()->runWorkspace(false);
        $this->kit->state->markOutOfDate('entries', 'connect failed: http://user:pass@meili:7700/x');
        $this->kit->capabilities['thallo.commerce'] = false;

        $data = $this->json($this->controller()->status());
        self::assertTrue($data['engine']['ready']);
        $kinds = array_column($data['kinds'], null, 'kind');
        self::assertSame('out_of_date', $kinds['entries']['status']);
        self::assertStringContainsString('[redacted]', (string) $kinds['entries']['last_error']);
        self::assertStringNotContainsString('pass', (string) $kinds['entries']['last_error']);
        self::assertFalse($kinds['products']['available']);
        self::assertSame('Requires Commerce', $kinds['products']['reason']);
    }

    public function testRebuildCommitsDemandBeforeDispatch(): void
    {
        $res = $this->controller()->rebuild($this->body(['kind' => 'entries']));
        self::assertSame(202, $res->getStatusCode(), (string) $res->getContent());
        self::assertTrue($this->json($res)['recorded']);
        self::assertCount(1, $this->pushes);
        self::assertSame([1], $this->demandSeenAtPush, 'the demand row existed when the wake-up was queued');
    }

    public function testRebuildWithAFailingQueueIsReportedTruthfully(): void
    {
        $this->pushFailure = new \RuntimeException('queue down');
        $res = $this->controller()->rebuild($this->body([]));
        self::assertSame(202, $res->getStatusCode());
        self::assertTrue($this->json($res)['recorded']);

        $kinds = array_column($this->json($this->controller()->status())['kinds'], null, 'kind');
        self::assertTrue($kinds['entries']['stalled']);
        self::assertStringStartsWith('queue:', (string) $kinds['entries']['last_error']);
    }

    public function testARolledBackRebuildDispatchesNothing(): void
    {
        try {
            $this->connection()->transaction(function (): void {
                $this->controller()->rebuild($this->body(['kind' => 'entries']));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $this->connection()->table('search_index_demand')->count());
        self::assertSame([], $this->pushes);
    }

    public function testAnUnavailableKindCannotBeRebuilt(): void
    {
        $this->kit->capabilities['thallo.commerce'] = false;
        self::assertSame(422, $this->controller()->rebuild($this->body(['kind' => 'products']))->getStatusCode());
        self::assertSame(422, $this->controller()->rebuild($this->body(['kind' => 'reviews']))->getStatusCode());
    }

    public function testAnAuthorWhoCanEditButNotManageCannotRebuild(): void
    {
        $author = $this->userWith('test_author_sa', ['content.edit']);
        $request = $this->requestAs($author, 'POST');
        $reached = false;
        $res = (new RequirePermission($this->appContext()))->handle($request, function () use (&$reached) {
            $reached = true;
            return \Glueful\Http\Response::success([]);
        }, 'content.manage');
        self::assertFalse($reached);
        self::assertSame(403, $res->getStatusCode());

        $options = $this->container()->get(\Thallo\Core\Content\Fields\FieldOptionsController::class)
            ->show($this->requestAs($author), 'thallo-search.scopes');
        self::assertSame(200, $options->getStatusCode(), 'while the same author still loads the scope choices');
    }

    private function controller(): SearchAdminController
    {
        $demand = new SearchDemand(
            $this->kit->state,
            $this->kit->availability(),
            $this->connection(),
            new Workspace($this->appContext()),
            function (array $data): void {
                $this->demandSeenAtPush[] = $this->connection()->table('search_index_demand')->count();
                if ($this->pushFailure !== null) {
                    throw $this->pushFailure;
                }
                $this->pushes[] = $data;
            },
        );
        return new SearchAdminController(
            $demand,
            $this->kit->state,
            $this->kit->demand(),
            $this->kit->availability(),
            $this->kit->registry,
            $this->kit->store,
            600,
        );
    }

    /** @param array<string, mixed> $body */
    private function body(array $body): Request
    {
        $json = json_encode($body) ?: '{}';

        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return (array) (json_decode((string) $response->getContent(), true)['data'] ?? []);
    }
}
