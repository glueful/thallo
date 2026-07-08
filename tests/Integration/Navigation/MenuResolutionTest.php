<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Navigation\MenuReader;
use Thallo\Navigation\MenuRepository;

final class MenuResolutionTest extends AppTestCase
{
    use SeedsPublishedContent;

    private function menus(): MenuRepository
    {
        return $this->container()->get(MenuRepository::class);
    }

    private function reader(): MenuReader
    {
        return $this->container()->get(MenuReader::class);
    }

    /** @param array<string,mixed> $overrides */
    private function item(array $overrides): array
    {
        return $overrides + [
            'uuid' => Utils::generateNanoID(),
            'parent_uuid' => null,
            'position' => 0,
            'kind' => 'url',
            'entry_uuid' => null,
            'url' => '/x',
            'labels' => json_encode(['en' => 'Item']),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function testLabelFallbackChain(): void
    {
        $menu = $this->menus()->createMenu('main', 'Main');
        $this->menus()->replaceTree((string) $menu['uuid'], 0, [
            $this->item(['labels' => json_encode(['fr' => 'À propos'])]),
        ]);

        // en requested, en label absent, default (en) absent → any available label.
        $tree = $this->reader()->menu('main', 'en');
        self::assertNotNull($tree);
        self::assertSame('À propos', $tree[0]['label']);
    }

    public function testDescriptionResolvesWithLocaleFallbackAndDefaultsEmpty(): void
    {
        $menu = $this->menus()->createMenu('main', 'Main');
        $this->menus()->replaceTree((string) $menu['uuid'], 0, [
            // Has a description (fr only) — resolves via the fallback chain.
            $this->item(['position' => 0, 'url' => '/a', 'labels' => json_encode(['en' => 'A']),
                'descriptions' => json_encode(['fr' => 'Depuis fr'])]),
            // No description column value at all — resolves to ''.
            $this->item(['position' => 1, 'url' => '/b', 'labels' => json_encode(['en' => 'B'])]),
        ]);

        $tree = $this->reader()->menu('main', 'en');
        self::assertNotNull($tree);
        self::assertSame('Depuis fr', $tree[0]['description']); // en absent → any available
        self::assertSame('', $tree[1]['description']);          // null column → empty string
    }

    public function testUrlItemsAlwaysServeAndEntryItemsResolvePaths(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $menu = $this->menus()->createMenu('main', 'Main');
        $this->menus()->replaceTree((string) $menu['uuid'], 0, [
            $this->item(['position' => 0, 'url' => 'https://example.test/ext',
                'labels' => json_encode(['en' => 'External'])]),
            $this->item(['position' => 1, 'kind' => 'entry', 'entry_uuid' => $entry, 'url' => null,
                'labels' => json_encode(['en' => 'Hello'])]),
        ]);

        $tree = $this->reader()->menu('main', 'en');
        self::assertNotNull($tree);
        self::assertCount(2, $tree);
        self::assertSame('https://example.test/ext', $tree[0]['url']);
        self::assertNull($tree[0]['entry']);
        // The default locale collapses (no /en/ prefix) — the CanonicalProjector
        // rule; a prefixed default here would render off-canonical nav links.
        // (phpunit.xml sets PUBLIC_URL_BASE=https://site.test.)
        self::assertSame('https://site.test/blog/hello', $tree[1]['url']);
        self::assertSame($entry, $tree[1]['entry']);

        // A non-default locale keeps its prefix.
        $fr = $this->reader()->menu('main', 'fr');
        self::assertNotNull($fr);
        self::assertSame('https://site.test/fr/blog/bonjour', $fr[1]['url']);
    }

    public function testEmptyLabelInheritsThePublishedPageTitle(): void
    {
        // nav-entry-items design: empty labels = "follow the page title"
        // (published version's localized title); typed label = override;
        // clearing the override re-inherits.
        $entry = $this->seedBilingualPublishedEntry(); // published 'Hello' (en) / 'Bonjour' (fr)
        $menu = $this->menus()->createMenu('main', 'Main');
        $inherit = $this->item(['kind' => 'entry', 'entry_uuid' => $entry, 'url' => null,
            'labels' => json_encode([])]);
        $this->menus()->replaceTree((string) $menu['uuid'], 0, [$inherit]);

        $tree = $this->reader()->menu('main', 'en');
        self::assertSame('Hello', $tree[0]['label']);      // inherited (en)
        $fr = $this->reader()->menu('main', 'fr');
        self::assertSame('Bonjour', $fr[0]['label']);      // localized inheritance

        // Override wins…
        $this->menus()->replaceTree((string) $menu['uuid'], 1, [
            $this->item(['uuid' => $inherit['uuid'], 'kind' => 'entry', 'entry_uuid' => $entry,
                'url' => null, 'labels' => json_encode(['en' => 'Custom'])]),
        ]);
        self::assertSame('Custom', $this->reader()->menu('main', 'en')[0]['label']);

        // …and clearing it re-inherits.
        $this->menus()->replaceTree((string) $menu['uuid'], 2, [
            $this->item(['uuid' => $inherit['uuid'], 'kind' => 'entry', 'entry_uuid' => $entry,
                'url' => null, 'labels' => json_encode([])]),
        ]);
        self::assertSame('Hello', $this->reader()->menu('main', 'en')[0]['label']);
    }

    public function testNonPublishedSubtreeIsDropped(): void
    {
        $this->seedBilingualPublishedEntry();
        $entries = $this->container()->get(EntryRepository::class);
        $types = $this->container()->get(ContentTypeRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        $menu = $this->menus()->createMenu('main', 'Main');
        $parentUuid = Utils::generateNanoID();
        $this->menus()->replaceTree((string) $menu['uuid'], 0, [
            $this->item(['uuid' => $parentUuid, 'position' => 0, 'kind' => 'entry',
                'entry_uuid' => $draft, 'url' => null, 'labels' => json_encode(['en' => 'Draft'])]),
            $this->item(['parent_uuid' => $parentUuid, 'position' => 0,
                'url' => '/child', 'labels' => json_encode(['en' => 'Child'])]),
            $this->item(['position' => 1, 'url' => '/sibling',
                'labels' => json_encode(['en' => 'Sibling'])]),
        ]);

        $tree = $this->reader()->menu('main', 'en');
        self::assertNotNull($tree);
        // Draft parent AND its url child are gone; the sibling survives.
        self::assertCount(1, $tree);
        self::assertSame('Sibling', $tree[0]['label']);
    }

    public function testUnknownMenuIsNull(): void
    {
        self::assertNull($this->reader()->menu('nope', 'en'));
    }

    public function testStaleLockVersionIsRejected(): void
    {
        $menu = $this->menus()->createMenu('main', 'Main');
        self::assertTrue($this->menus()->replaceTree((string) $menu['uuid'], 0, [$this->item([])]));
        // Same version again → stale (the first replace bumped it to 1).
        self::assertFalse($this->menus()->replaceTree((string) $menu['uuid'], 0, [$this->item([])]));
        self::assertTrue($this->menus()->replaceTree((string) $menu['uuid'], 1, [$this->item([])]));
    }
}
