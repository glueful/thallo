<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Events\EventService;
use Glueful\Validation\RequestDataHydrator;
use Thallo\Contracts\Content\RegionUpdated;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Preview\RegionPreviewStore;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\Controllers\RegionPreviewController;
use Thallo\Core\Http\DTOs\ApplyRegionsData;
use Thallo\Core\Http\DTOs\RegionSessionData;
use Thallo\Core\Http\DTOs\SaveRegionsData;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * One batch save for the header and footer (regions-stage spec §4.5): both regions' versions are
 * checked and the complete candidate validated under the region lock, every posted region is
 * written or none is, and the session's baseline advances while its working copy clears only on
 * an exact pair.
 */
final class RegionSaveApiTest extends AppTestCase
{
    private int $purges = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        $this->container()->get(EventService::class)->addListener(RegionUpdated::class, function (): void {
            $this->purges++;
        });
    }

    private function repo(): RegionRepository
    {
        return new RegionRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function note(string $id, string $text): array
    {
        return [['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$text}</p>"]]];
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function saveAll(array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(SaveRegionsData::class, $body);
        $resp = $this->container()->get(RegionAdminController::class)->saveAll($dto);
        $decoded = json_decode((string) $resp->getContent(), true) ?? [];
        return ['status' => $resp->getStatusCode(), 'body' => $decoded];
    }

    /** @return array{header: ?int, footer: ?int} */
    private function versions(): array
    {
        return [
            'header' => $this->repo()->find('header')['lock_version'] ?? null,
            'footer' => $this->repo()->find('footer')['lock_version'] ?? null,
        ];
    }

    private function seed(): void
    {
        $this->repo()->save('header', $this->note('hdr000000001', 'header'), [], null);
        $this->repo()->save('footer', $this->note('ftr000000001', 'footer'), [], null);
    }

    public function testAFooterOnlySaveWritesTheFooterAndLeavesTheHeader(): void
    {
        $this->seed();
        $before = $this->versions();
        $this->purges = 0;
        $result = $this->saveAll([
            'regions' => ['footer' => ['blocks' => $this->note('ftr000000001', 'new footer'), 'settings' => []]],
            'expected' => $before,
        ]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame($before['footer'] + 1, $this->versions()['footer']);
        self::assertSame($before['header'], $this->versions()['header']);
        self::assertStringContainsString('new footer', json_encode($this->repo()->find('footer')['blocks']));
        self::assertSame(1, $this->purges, 'the region cache was purged more or less than once');
        $out = $result['body']['data']['regions'];
        self::assertSame($before['footer'] + 1, $out['footer']['lock_version']);
        self::assertSame($before['header'], $out['header']['lock_version']);
    }

    public function testTheCompleteCandidateIsValidated(): void
    {
        $this->seed();
        // A block moved from header to footer in one save: judged on the final document.
        $moved = $this->saveAll([
            'regions' => [
                'header' => ['blocks' => [], 'settings' => []],
                'footer' => ['blocks' => $this->note('hdr000000001', 'moved'), 'settings' => []],
            ],
            'expected' => $this->versions(),
        ]);
        self::assertSame(200, $moved['status'], json_encode($moved['body']));

        // The same id posted in the footer while the stored header still has it.
        $this->repo()->save('header', $this->note('dup000000001', 'header'), [], null);
        $dup = $this->saveAll([
            'regions' => ['footer' => ['blocks' => $this->note('dup000000001', 'footer'), 'settings' => []]],
            'expected' => $this->versions(),
        ]);
        self::assertSame(422, $dup['status']);
    }

    public function testAStaleVersionOfTheUnchangedRegionConflicts(): void
    {
        $this->seed();
        $loaded = $this->versions();
        $this->repo()->save('header', $this->note('hdr000000001', 'someone else'), [], null);
        $result = $this->saveAll([
            'regions' => ['footer' => ['blocks' => $this->note('ftr000000001', 'mine'), 'settings' => []]],
            'expected' => $loaded,
        ]);
        self::assertSame(409, $result['status']);
        self::assertSame('REGION_VERSION_CONFLICT', $result['body']['error']['details']['code'] ?? null);
        self::assertSame(['header'], $result['body']['error']['details']['moved'] ?? null);
        self::assertStringNotContainsString('mine', json_encode($this->repo()->find('footer')['blocks']));
    }

    public function testAnAbsentRowIsCreatedOnceWithNull(): void
    {
        $this->repo()->save('header', $this->note('hdr000000001', 'header'), [], null);
        $first = $this->saveAll([
            'regions' => ['footer' => ['blocks' => $this->note('ftr000000001', 'first'), 'settings' => []]],
            'expected' => ['header' => 0, 'footer' => null],
        ]);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        $second = $this->saveAll([
            'regions' => ['footer' => ['blocks' => $this->note('ftr000000001', 'second'), 'settings' => []]],
            'expected' => ['header' => 0, 'footer' => null],
        ]);
        self::assertSame(409, $second['status']);
        self::assertSame(['footer'], $second['body']['error']['details']['moved'] ?? null);
    }

    public function testASaveAdvancesTheSessionBaselineAndClearsOnlyOnTheExactPair(): void
    {
        $this->seed();
        $previews = $this->container()->get(RegionPreviewController::class);
        $mint = function () use ($previews): array {
            $dto = (new RequestDataHydrator())->hydrate(RegionSessionData::class, []);
            return json_decode((string) $previews->session($dto)->getContent(), true)['data'];
        };
        $apply = function (string $token, ?string $epoch, ?int $rev, string $text) use ($previews): array {
            $dto = (new RequestDataHydrator())->hydrate(ApplyRegionsData::class, [
                'token' => $token,
                'epoch' => $epoch,
                'base_revision' => $rev,
                'regions' => [
                    'header' => ['blocks' => $this->note('hdr000000001', $text), 'settings' => []],
                    'footer' => ['blocks' => $this->note('ftr000000001', 'footer'), 'settings' => []],
                ],
            ]);
            return json_decode((string) $previews->apply($dto)->getContent(), true)['data'];
        };
        $store = $this->container()->get(RegionPreviewStore::class);

        // A stale pair: the working copy is at revision 2, the save names revision 1.
        $session = $mint();
        $s = json_decode((string) base64_decode(strtr(explode('.', $session['token'])[0], '-_', '+/')), true)['s'];
        $one = $apply($session['token'], null, null, 'one');
        $apply($session['token'], $one['epoch'], 1, 'two');
        $stale = $this->saveAll([
            'token' => $session['token'],
            'regions' => ['header' => ['blocks' => $this->note('hdr000000001', 'two'), 'settings' => []]],
            'expected' => $this->versions(),
            'preview_revision' => ['epoch' => $one['epoch'], 'revision' => 1],
        ]);
        self::assertSame(200, $stale['status'], json_encode($stale['body']));
        self::assertSame(2, $store->current($s)['revision'], 'a stale pair cleared the working copy');
        self::assertSame($this->versions(), [
            'header' => $store->baseline($s)['header']['lock_version'],
            'footer' => $store->baseline($s)['footer']['lock_version'],
        ], 'the baseline did not advance to the committed versions');

        // The exact pair clears the copy; the stage then shows the committed baseline.
        $exact = $this->saveAll([
            'token' => $session['token'],
            'regions' => ['header' => ['blocks' => $this->note('hdr000000001', 'two'), 'settings' => []]],
            'expected' => $this->versions(),
            'preview_revision' => ['epoch' => $one['epoch'], 'revision' => 2],
        ]);
        self::assertSame(200, $exact['status']);
        self::assertTrue($exact['body']['data']['preview_cleared']);
        self::assertSame('baseline', $store->snapshot($s)['source']);
        self::assertSame($this->versions()['header'], $store->snapshot($s)['regions']['header']['lock_version']);

        // A null pair clears nothing.
        $apply($session['token'], null, null, 'three');
        $this->saveAll([
            'token' => $session['token'],
            'regions' => ['header' => ['blocks' => $this->note('hdr000000001', 'three'), 'settings' => []]],
            'expected' => $this->versions(),
            'preview_revision' => null,
        ]);
        self::assertNotNull($store->current($s));
    }

    public function testThePerRegionSaveIsHeldToTheSameChecks(): void
    {
        $this->seed();
        $loaded = $this->versions();
        $this->repo()->save('header', $this->note('hdr000000001', 'someone else'), [], null);
        $dto = (new RequestDataHydrator())->hydrate(UpdateRegionData::class, [
            'blocks' => $this->note('ftr000000001', 'mine'),
            'settings' => [],
            'expected' => $loaded,
        ]);
        $resp = $this->container()->get(RegionAdminController::class)->update($dto, 'footer');
        self::assertSame(409, $resp->getStatusCode());

        $missing = (new RequestDataHydrator())->hydrate(UpdateRegionData::class, [
            'blocks' => $this->note('ftr000000001', 'mine'),
            'settings' => [],
        ]);
        $refused = $this->container()->get(RegionAdminController::class)->update($missing, 'footer');
        self::assertSame(422, $refused->getStatusCode());
    }

    public function testTheRouteCarriesAuthAndContentManage(): void
    {
        $route = $this->findRoute('PUT', '/v1/admin/regions');
        self::assertNotNull($route);
        self::assertContains('auth', $route['middleware']);
        self::assertContains('content_permission:content.manage', $route['middleware']);
    }
}
