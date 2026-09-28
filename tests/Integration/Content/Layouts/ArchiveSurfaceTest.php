<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\ArchiveSurface;
use Thallo\Core\Content\Layouts\LayoutBindings;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The archive surface (type layouts spec §1, §3; plan B, B3): one layout for every archive of an
 * archived field (`/{type}/{field}/{term}`) — a filterable reference field to a delivered type — its
 * samples the terms that have members, its placeholder an empty page for a sample term, and its
 * starter today's archive page in blocks, with the term's description.
 */
final class ArchiveSurfaceTest extends AppTestCase
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

    private function surface(): ArchiveSurface
    {
        $surface = $this->container()->get(LayoutSurfaceRegistry::class)->get('archive');
        self::assertInstanceOf(ArchiveSurface::class, $surface);
        return $surface;
    }

    public function testTargetsAreTheArchivedFields(): void
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $types->create(['slug' => 'secret', 'name' => 'Secrets', 'public_delivery' => false, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]]);
        $types->create(['slug' => 'recipe', 'name' => 'Recipes', 'public_delivery' => true, 'schema' => [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'tags', 'type' => 'reference', 'reference_type' => 'category',
                'reference_slug_field' => 'slug', 'multiple' => true],
            ['name' => 'hidden', 'type' => 'reference', 'reference_type' => 'secret',
                'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
        ]]);
        $rows = array_column($this->surface()->targets(), null, 'target');
        self::assertSame(['post:categories'], array_keys($rows), 'not filterable, and to an undelivered type: none');
        self::assertSame([
            'target' => 'post:categories', 'label' => 'Posts — Categories archive', 'enabled' => true,
            'reason' => null, 'link' => null,
        ], $rows['post:categories']);
        self::assertSame('Applies to every category page of Posts', $this->surface()->reach('post:categories'));

        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);
        $row = $this->surface()->targets()[0];
        self::assertFalse($row['enabled']);
        self::assertSame('Listing pages are off for Posts.', $row['reason']);
        self::assertSame('/settings/general', $row['link']);
    }

    public function testTheSamplesAreTheTermsWithMembers(): void
    {
        $this->publishEmptyCategory();
        self::assertSame(
            [['id' => $this->seeded['pottery'], 'label' => 'Pottery']],
            $this->surface()->samples('post:categories', null),
        );
        self::assertSame([], $this->surface()->samples('post:categories', 'glass'));
        self::assertSame($this->seeded['pottery'], $this->surface()->defaultSample('post:categories'));

        $vars = $this->surface()->sampleContext('post:categories', $this->seeded['pottery']);
        self::assertNotNull($vars);
        $page = $vars['layout_context'];
        self::assertSame('Pottery', $page['term']['fields']['title']);
        self::assertSame('categories', $page['field']);
        self::assertSame('rich', $page['term_description_format']);
        self::assertCount(2, $page['items']);
        self::assertSame('/post/categories/pottery/page/2', $page['pagination']['next_path']);

        $this->connection()->table('entry_publications')->where('entry_uuid', '=', $this->seeded['pottery'])->delete();
        self::assertNull(
            $this->surface()->sampleContext('post:categories', $this->seeded['pottery']),
            'an unpublished term is no sample',
        );
    }

    public function testThePlaceholderIsASampleTermsEmptyPage(): void
    {
        $page = $this->surface()->placeholder('post:categories')['layout_context'];
        self::assertSame('Sample category', $page['term']['fields']['title']);
        self::assertSame([], $page['items']);
        self::assertSame('Sample post', $page['placeholder_item']['fields']['title']);
        self::assertSame('categories', $page['field']);
    }

    public function testTheSurfaceDeclaresItsBlocksAndTheStarter(): void
    {
        $surface = $this->surface();
        self::assertContains('term_description', $surface->palette());
        self::assertSame([['type' => 'entry_loop']], $surface->required('post:categories'));
        self::assertSame(['thallo:layout:archive:post:categories'], $surface->pageTags('post:categories'));
        self::assertSame('layouts/archive.twig', $surface->frame());
        self::assertSame('asset', $surface->bindable('post:categories')['cover']);

        // Today's archive page (the user's B7 ruling): the title, the list and the navigation. The Term
        // description is in the palette, for a layout that wants it.
        $starter = $surface->starter('post:categories');
        self::assertSame(['listing_title', 'entry_loop', 'pagination'], array_column($starter, 'type'));
        $listing = $this->container()->get(LayoutSurfaceRegistry::class)->get('listing');
        self::assertSame($listing->starter('post'), $starter, 'the listing\'s starter');
        $withIds = self::withIds($starter);
        $clean = $this->container()->get(LayoutValidator::class)
            ->validate('archive', 'post:categories', $withIds, []);
        self::assertEquals($withIds, $clean['blocks'], 'it passes validation unchanged');
    }

    /** Deleting the archived field is refused naming the layout as the Layouts page does (plan B, B1). */
    public function testAFieldConflictNamesTheArchiveLayout(): void
    {
        $blocks = self::withIds($this->surface()->starter('post:categories'));
        $this->container()->get(LayoutWriteLock::class)->within(
            'archive',
            'post:categories',
            fn (): int => $this->container()->get(LayoutRepository::class)
                ->saveExpected('archive', 'post:categories', $blocks, [], 0, null),
        );
        self::assertSame(
            ['categories' => ['Posts — Categories archive']],
            $this->container()->get(LayoutBindings::class)->boundTo('post', ['categories']),
        );
    }

    /** A category with no members: never offered as a sample. */
    private function publishEmptyCategory(): void
    {
        $db = $this->connection();
        $at = '2026-09-20 09:00:00';
        $db->table('entries')->insert(['uuid' => 'glasscat0001', 'content_type_uuid' => $this->seeded['category_type'],
            'status' => 'active', 'created_at' => $at, 'updated_at' => $at]);
        $db->table('entry_versions')->insert(['uuid' => 'vglasscat001', 'entry_uuid' => 'glasscat0001',
            'locale' => 'en', 'version' => 1, 'fields' => json_encode(['title' => 'Glass', 'slug' => 'glass']),
            'schema_version' => 1, 'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => 'glasscat0001', 'locale' => 'en',
            'version_uuid' => 'vglasscat001', 'published_at' => $at]);
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'a'): array
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
