<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutTargets;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\FixtureLayoutSurface;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A surface's rows as the Layouts page lists them (review of aa2801ec): a layout kept at a target no
 * longer offered sits with its own type's rows, not at the end of the list; and a surface whose rows
 * leave out a key the contract requires is named at once, rather than warning on every list.
 */
final class LayoutTargetsTest extends AppTestCase
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
        FixtureLayoutSurface::$targets = null;
        foreach (['post:tags', 'post:categories', 'recipe:topics'] as $target) {
            $this->container()->get(LayoutResolver::class)->forget('archive', $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $fields fields appended to the post type's schema */
    private function extendPosts(array $fields): void
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $schema = $types->findByUuid($this->seeded['post_type'])['schema'];
        $types->updateSchema($this->seeded['post_type'], [...$schema, ...$fields]);
    }

    public function testAKeptArchiveRowSitsWithItsTypesRows(): void
    {
        $tags = ['name' => 'tags', 'type' => 'reference', 'reference_type' => 'category',
            'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true];
        $this->extendPosts([$tags]);
        $types = $this->container()->get(ContentTypeRepository::class);
        $types->create(['slug' => 'recipe', 'name' => 'Recipes', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'topics', 'type' => 'reference', 'reference_type' => 'category',
                'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
        ]]);
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post', 'recipe']]);

        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = [['id' => 'keptloop0001', 'type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []]];
        $this->container()->get(LayoutWriteLock::class)->within(
            'archive',
            'post:tags',
            fn (): int => $repo->saveExpected('archive', 'post:tags', $blocks, [], 0, null),
        );
        // Tags stops filing posts: its archive pages go, its layout is kept.
        $schema = $types->findByUuid($this->seeded['post_type'])['schema'];
        foreach ($schema as $i => $field) {
            if ($field['name'] === 'tags') {
                $schema[$i]['filterable'] = false;
            }
        }
        $types->updateSchema($this->seeded['post_type'], $schema);

        $index = $this->container()->get(LayoutAdminController::class)->index(Request::create('/x'));
        $archive = array_values(array_filter(
            json_decode((string) $index->getContent(), true)['data']['layouts'],
            static fn (array $row): bool => $row['surface'] === 'archive',
        ));
        self::assertSame(
            ['post:categories', 'post:tags', 'recipe:topics'],
            array_column($archive, 'target'),
            "the kept Tags archive sits with the post type's rows",
        );
        self::assertSame([true, false, LayoutTargets::KEPT], [
            $archive[1]['removable'], $archive[1]['enabled'], $archive[1]['reason'],
        ]);
    }

    public function testARowWithoutAKeyTheContractRequiresNamesItsSurface(): void
    {
        FixtureLayoutSurface::$targets = [[
            'target' => '@site', 'label' => 'Fixtures — site page', 'enabled' => true, 'reason' => null,
        ]];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            "layout surface 'fixture': target '@site' has no 'link' (a row carries target, label, "
                . 'enabled, reason and link)',
        );
        $this->container()->get(LayoutTargets::class)->of(new FixtureLayoutSurface());
    }
}
