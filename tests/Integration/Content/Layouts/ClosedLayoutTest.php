<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Cache\CacheStore;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Http\Controllers\MigrationController;
use Thallo\Core\Content\Http\DTOs\MigrationData;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutTargets;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A kept layout whose pages are off the site stays in reach (final review, deferred minor 1): a
 * listing layout of a type taken off the listing types, an archive layout of a field no longer
 * filterable. Site › Layouts lists it, turned off, with the reason; it opens only to be removed —
 * Save and apply stay refused — and once removed, the field it showed can be deleted. A closed target
 * with no layout still opens nothing.
 */
final class ClosedLayoutTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    /** @var array{post_type: string, category_type: string, pottery: string, posts: array<string,string>} */
    private array $seeded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->seeded = (new ListingPageSeed($this->container(), $this->appContext()))->seed();
    }

    protected function tearDown(): void
    {
        foreach ([['listing', 'post'], ['archive', 'post:categories'], ['entry', 'post']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function saveStarter(string $surface, string $target): void
    {
        $blocks = self::withIds($this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter($target));
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            $surface,
            $target,
            fn (): int => $repo->saveExpected($surface, $target, $blocks, [], $repo->version($surface, $target), null),
        );
        $this->container()->get(LayoutResolver::class)->forget($surface, $target);
    }

    /** @return array<string,mixed>|null the row the Layouts page shows */
    private function row(string $surface, string $target): ?array
    {
        $index = $this->container()->get(LayoutAdminController::class)->index(Request::create('/x'));
        foreach (json_decode((string) $index->getContent(), true)['data']['layouts'] as $row) {
            if ($row['surface'] === $surface && $row['target'] === $target) {
                return $row;
            }
        }
        return null;
    }

    private function session(string $surface, string $target): Response
    {
        return $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => $target],
            ),
        );
    }

    /** @return array<string,mixed> */
    private static function data(Response $response): array
    {
        return json_decode((string) $response->getContent(), true)['data'] ?? [];
    }

    private function deleteField(string $field): Response
    {
        $dto = (new RequestDataHydrator())->hydrate(
            MigrationData::class,
            ['ops' => [['op' => 'delete', 'name' => $field]]],
        );
        return $this->container()->get(MigrationController::class)->store($dto, Request::create('/x', 'POST'), 'post');
    }

    /** The whole path: listed, opened only to remove, removed, and then the field is free. */
    private function assertKeptAndRemovable(string $surface, string $target, string $reason, string $field): void
    {
        $row = $this->row($surface, $target);
        self::assertNotNull($row, 'the kept layout is listed');
        self::assertSame(
            ['custom', false, $reason, true],
            [$row['state'], $row['enabled'], $row['reason'], $row['removable']],
        );

        self::assertSame(422, $this->deleteField($field)->getStatusCode(), 'the layout still shows the field');

        $opened = $this->session($surface, $target);
        self::assertSame(200, $opened->getStatusCode(), (string) $opened->getContent());
        $session = self::data($opened);
        self::assertSame($reason, $session['closed']);
        self::assertTrue($session['placeholder']);
        self::assertNull($session['sample']);

        $apply = $this->container()->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $session['token'], 'layout' => $session['layout'],
            ]),
        );
        self::assertSame(422, $apply->getStatusCode(), 'nothing is applied to a closed target');
        $save = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $session['token'], 'layout' => $session['layout'],
                'expected_lock_version' => $session['layout']['lock_version'],
            ]),
            Request::create('/x', 'PUT'),
            $surface,
            $target,
        );
        self::assertSame(422, $save->getStatusCode(), 'nothing is saved to a closed target');

        $removed = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $session['token'], 'expected_lock_version' => $session['layout']['lock_version'],
            ]),
            Request::create('/x', 'DELETE'),
            $surface,
            $target,
        );
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        self::assertSame(201, $this->deleteField($field)->getStatusCode(), 'removed: the field can be deleted');
    }

    public function testATypeWithoutListingPagesListsNoListingOrArchiveRow(): void
    {
        // Listing pages on: the type's listing and archive layouts are listed.
        self::assertNotNull($this->row('listing', 'post'));
        self::assertNotNull($this->row('archive', 'post:categories'));
        // Off, with nothing saved there: neither is listed — Settings › General turns them on.
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);
        self::assertNull($this->row('listing', 'post'));
        self::assertNull($this->row('archive', 'post:categories'));
        // Its single entry layout still is, named by the type alone.
        self::assertSame('Posts', $this->row('entry', 'post')['label'] ?? null);
    }

    public function testAnUnlistedTypesListingLayoutCanBeRemoved(): void
    {
        $this->saveStarter('listing', 'post');
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);
        // The starter's card shows the cover.
        $this->assertKeptAndRemovable('listing', 'post', 'Listing pages are off for Posts.', 'cover');
    }

    public function testANoLongerFilterableFieldsArchiveLayoutCanBeRemoved(): void
    {
        $this->saveStarter('archive', 'post:categories');
        $types = $this->container()->get(ContentTypeRepository::class);
        $schema = $types->findByUuid($this->seeded['post_type'])['schema'];
        foreach ($schema as $i => $field) {
            if ($field['name'] === 'categories') {
                $schema[$i]['filterable'] = false;
            }
        }
        $types->updateSchema($this->seeded['post_type'], $schema);
        $this->assertKeptAndRemovable('archive', 'post:categories', LayoutTargets::KEPT, 'categories');
    }

    /** A type taken off the site keeps its single-entry layout too: listed, and removable. */
    public function testANoLongerPublicTypesEntryLayoutCanBeRemoved(): void
    {
        $this->saveStarter('entry', 'post');
        $this->container()->get(ContentTypeRepository::class)
            ->updateMeta($this->seeded['post_type'], ['public_delivery' => false]);
        $this->assertKeptAndRemovable('entry', 'post', LayoutTargets::KEPT, 'excerpt');
    }

    /**
     * Review of aa2801ec: a row closed only because its blocks are not installed yet is still live —
     * the site renders its layout — so it is not removable, and it opens no session, as before.
     */
    public function testARowClosedForMissingBlocksIsLiveAndNotRemovable(): void
    {
        $this->saveStarter('listing', 'post');
        $this->connection()->table('block_types')->where('slug', '=', 'pagination')->delete();
        $row = $this->row('listing', 'post');
        self::assertNotNull($row);
        self::assertSame(
            ['custom', false, LayoutTargets::NOT_PROVISIONED, false],
            [$row['state'], $row['enabled'], $row['reason'], $row['removable']],
        );
        self::assertSame(422, $this->session('listing', 'post')->getStatusCode());
        self::assertTrue($this->row('entry', 'post')['enabled'], 'another surface is open');
        self::assertFalse($this->row('entry', 'post')['removable'], 'an open row is not removable');
    }

    public function testAClosedTargetWithNoLayoutStillOpensNothing(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);
        self::assertSame(422, $this->session('listing', 'post')->getStatusCode());
        self::assertSame(422, $this->session('archive', 'post:nothing')->getStatusCode());
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'k'): array
    {
        foreach ($tree as $i => $block) {
            $tree[$i]['id'] = str_pad($prefix . $i, 12, '0');
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $tree[$i]['data'][$key] = self::withIds($value, $prefix . $i . 'n');
                }
            }
        }
        return $tree;
    }
}
