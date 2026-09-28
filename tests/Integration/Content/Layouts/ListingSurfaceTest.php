<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Layouts\ListingSurface;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The listing surface (type layouts spec §1, §3; plan B, B3): one layout for every page of a listed
 * type's listing (`/{type}[/page/n]`) — its targets from the delivered types, closed while a type is
 * not listed, its sample the listing's first page, its placeholder an empty page with one sample
 * entry, and its starter today's listing page in blocks.
 */
final class ListingSurfaceTest extends AppTestCase
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
        $this->container()->get(LayoutResolver::class)->forget('listing', 'post');
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function surface(): ListingSurface
    {
        $surface = $this->container()->get(LayoutSurfaceRegistry::class)->get('listing');
        self::assertInstanceOf(ListingSurface::class, $surface);
        return $surface;
    }

    private function unlist(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);
    }

    public function testTargetsAreTheDeliveredTypesOpenWhileListed(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'page', 'name' => 'Pages', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
        ]);
        $rows = array_column($this->surface()->targets(), null, 'target');
        self::assertSame(
            [
                'target' => 'post', 'label' => 'Posts — listing pages', 'enabled' => true, 'reason' => null,
                'link' => null,
            ],
            $rows['post'],
        );
        self::assertFalse($rows['page']['enabled']);
        self::assertSame('Listing pages are off for Pages.', $rows['page']['reason']);
        self::assertSame('/settings/general', $rows['page']['link']);
        self::assertSame('Posts — listing pages', $this->surface()->label('post'));
        self::assertSame('Applies to every page of the post listing', $this->surface()->reach('post'));
    }

    public function testTheSampleIsTheListingsFirstPage(): void
    {
        self::assertSame([['id' => '1', 'label' => 'Page 1']], $this->surface()->samples('post', null));
        self::assertSame('1', $this->surface()->defaultSample('post'));

        $vars = $this->surface()->sampleContext('post', '1');
        self::assertNotNull($vars);
        $page = $vars['layout_context'];
        self::assertSame(['The kiln at dawn', 'Glazing by hand'], array_map(
            static fn (array $item): string => $item['fields']['title'],
            $page['items'],
        ));
        self::assertSame(1, $page['pagination']['page']);
        self::assertSame(2, $page['pagination']['total_pages']);
        self::assertSame('/post/page/2', $page['pagination']['next_path']);
        self::assertNull($page['pagination']['prev_path']);
        self::assertSame('post', $page['type']);
        self::assertSame('Posts', $page['type_name']);
        self::assertNull($page['term']);
        self::assertSame('/post', $vars['type_listing']['path']);
        self::assertArrayHasKey('categories', $vars['type_listing']['archives']);
        self::assertSame($page['items'], $vars['items'], 'the frame reads the same page');

        $this->unlist();
        self::assertNull($this->surface()->sampleContext('post', '1'), 'unlisted: no page to sample');
        self::assertSame([], $this->surface()->samples('post', null));
    }

    public function testThePlaceholderIsAnEmptyPageWithOneSampleEntry(): void
    {
        $vars = $this->surface()->placeholder('post');
        $page = $vars['layout_context'];
        self::assertSame([], $page['items']);
        self::assertSame(1, $page['pagination']['total_pages']);
        self::assertSame('Sample post', $page['placeholder_item']['fields']['title']);
        self::assertSame('Posts', $page['type_name']);
    }

    public function testTheSurfaceDeclaresItsBlocksLoopsAndTags(): void
    {
        $surface = $this->surface();
        $items = ['entry_title', 'entry_date', 'entry_cover', 'entry_excerpt', 'entry_terms', 'entry_field'];
        self::assertSame(['entry_loop', 'pagination', 'listing_title', ...$items], $surface->palette());
        self::assertSame([['type' => 'entry_loop']], $surface->required('post'));
        self::assertSame([['type' => 'entry_loop', 'card' => 'card', 'items' => $items]], $surface->loops('post'));
        self::assertSame('asset', $surface->bindable('post')['cover']);
        self::assertSame('reference', $surface->bindable('post')['categories']);
        self::assertSame(['thallo:layout:listing:post'], $surface->pageTags('post'));
        self::assertSame('layouts/listing.twig', $surface->frame());
    }

    public function testTheStarterIsTodaysListingPage(): void
    {
        $starter = $this->surface()->starter('post');
        self::assertSame([
            ['type' => 'listing_title', 'data' => ['level' => 'h1'], 'settings' => []],
            ['type' => 'entry_loop', 'data' => ['card' => [
                ['type' => 'entry_cover', 'data' => ['field' => 'cover', 'link' => true], 'settings' => []],
                ['type' => 'entry_title', 'data' => ['level' => 'h2', 'link' => true], 'settings' => []],
                ['type' => 'entry_date', 'data' => ['format' => 'long'], 'settings' => []],
                ['type' => 'entry_excerpt', 'data' => ['field' => 'excerpt'], 'settings' => []],
            ]], 'settings' => []],
            ['type' => 'pagination', 'data' => [], 'settings' => []],
        ], $starter);
        $withIds = self::withIds($starter);
        $clean = $this->container()->get(LayoutValidator::class)->validate('listing', 'post', $withIds, []);
        self::assertEquals($withIds, $clean['blocks'], 'it passes validation unchanged');

        // A type with neither a cover nor an excerpt: the card holds the title and the date.
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'note', 'name' => 'Notes', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
        ]);
        self::assertSame(
            ['entry_title', 'entry_date'],
            array_column($this->surface()->starter('note')[1]['data']['card'], 'type'),
        );
    }

    /** Review Focus 2: unlisting keeps the layout and closes its row; relisting serves it again. */
    public function testUnlistingKeepsTheLayoutAndRelistingServesIt(): void
    {
        $blocks = self::withIds($this->surface()->starter('post'));
        $this->container()->get(LayoutWriteLock::class)->within(
            'listing',
            'post',
            fn (): int => $this->container()->get(LayoutRepository::class)
                ->saveExpected('listing', 'post', $blocks, [], 0, null),
        );

        $this->unlist();
        self::assertSame(404, $this->handle(Request::create('/post', 'GET'))->getStatusCode(), 'as today');
        $index = $this->container()->get(LayoutAdminController::class)->index(Request::create('/x'));
        self::assertSame(200, $index->getStatusCode());
        $row = array_values(array_filter(
            json_decode((string) $index->getContent(), true)['data']['layouts'],
            static fn (array $r): bool => $r['surface'] === 'listing' && $r['target'] === 'post',
        ))[0];
        self::assertFalse($row['enabled']);
        self::assertSame('/settings/general', $row['link']);
        self::assertSame('custom', $row['state'], 'kept');
        $walked = [];
        $this->container()->get(LayoutsSource::class)->each(static function ($ref) use (&$walked): void {
            $walked[] = $ref->sourceId;
        });
        self::assertContains('listing:post', $walked, 'the block-document walkers still reach it');

        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post']]);
        $this->container()->get(LayoutResolver::class)->forget('listing', 'post');
        $row = array_values(array_filter(
            json_decode(
                (string) $this->container()->get(LayoutAdminController::class)
                    ->index(Request::create('/x'))->getContent(),
                true,
            )['data']['layouts'],
            static fn (array $r): bool => $r['surface'] === 'listing' && $r['target'] === 'post',
        ))[0];
        self::assertTrue($row['enabled']);
        self::assertSame('custom', $row['state']);
        self::assertEquals($blocks, $this->container()->get(LayoutReader::class)->for('listing', 'post')['blocks']);
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'l'): array
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
