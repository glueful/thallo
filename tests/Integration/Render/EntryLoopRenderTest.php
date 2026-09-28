<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The Entry list and its card (type layouts spec §5.4, plan B, B2): the card's blocks render once
 * per item, reading it as `entry` and `item`; on the stage the first card is the one selectable —
 * the drop destination for the loop's card — and every later card renders the same blocks for its
 * item without annotation, so no id appears twice.
 */
final class EntryLoopRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    /** @var list<array<string,mixed>> the seeded posts as the listing shapes them, newest first */
    private array $items = [];

    /** @var list<array<string,mixed>>|null */
    private ?array $headerBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $resolver = $this->container()->get(PublicRouteResolver::class);
        $this->items = [
            ...$resolver->resolvePath('/post')['listing']['items'],
            ...$resolver->resolvePath('/post/page/2')['listing']['items'],
        ];
        $regions = $this->container()->get(RegionRepository::class);
        $this->headerBefore = $regions->find('header')['blocks'] ?? [];
    }

    protected function tearDown(): void
    {
        $this->container()->get(RegionRepository::class)->save('header', $this->headerBefore ?? [], [], null);
        $this->extension()->setAnnotationScope('none');
        parent::tearDown();
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes', null, [$base . '/tests/fixtures/render/context-probe']),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $layout @param array<string,mixed> $context */
    private function render(array $layout, string $scope, array $context = [], ?string $template = null): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->env()->createTemplate($template ?? '{{ layout_blocks(layout) }}')->render($context + [
            'layout' => $layout,
            'layout_context' => ['items' => $this->items],
            'is_canvas' => $scope !== 'none',
        ]);
    }

    /** @param list<array<string,mixed>> $card */
    private static function loop(array $card, string $id = 'loopblock001'): array
    {
        return ['id' => $id, 'type' => 'entry_loop', 'data' => ['card' => $card], 'settings' => []];
    }

    /** @return list<array<string,mixed>> the plan's card: a linked title, the date, the excerpt */
    private static function card(): array
    {
        return [
            ['id' => 'cardtitle001', 'type' => 'entry_title', 'data' => ['level' => 'h2', 'link' => true],
                'settings' => []],
            ['id' => 'carddate0001', 'type' => 'entry_date', 'data' => ['format' => 'long'], 'settings' => []],
            ['id' => 'cardexcerpt1', 'type' => 'entry_excerpt', 'data' => ['field' => 'excerpt'], 'settings' => []],
        ];
    }

    public function testTheCardRendersEveryItemInOrder(): void
    {
        $html = $this->render([self::loop(self::card())], 'none');
        self::assertSame(3, substr_count($html, '<li class="thallo-loop-card listing-row">'));
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block thallo-block-entry_loop[^"]*"[^>]*>\s*'
                . '<ul class="thallo-block-entry_loop__cards listing-rows~',
            $html,
        );
        $titles = ['The kiln at dawn', 'Glazing by hand', 'First firing'];
        $at = array_map(static fn (string $t): int|false => strpos($html, $t), $titles);
        self::assertNotContains(false, $at);
        self::assertSame($at, array_values(array_unique($at)));
        $sorted = $at;
        sort($sorted);
        self::assertSame($sorted, $at, 'newest first, as the page lists them');
        foreach ($this->items as $item) {
            self::assertStringContainsString('href="' . $item['href'] . '"', $html);
        }
        self::assertStringContainsString('Opening the kiln after a long firing is the best part of the week.', $html);
        self::assertStringContainsString('datetime="2026-08-01"', $html);
        foreach (['data-thallo-block', 'data-thallo-slot', 'data-thallo-card-copy'] as $attribute) {
            self::assertStringNotContainsString($attribute, $html);
        }
    }

    /** Review Focus 5: the first card, and only the first, is the stage's; every id once. */
    public function testOnTheStageOnlyTheFirstCardIsSelectable(): void
    {
        $card = [
            ...self::card(),
            ['id' => 'cardbox00001', 'type' => 'container', 'data' => ['element' => 'div', 'content' => [
                ['id' => 'cardboxhead1', 'type' => 'heading', 'data' => ['text' => 'Inside'], 'settings' => []],
                ['id' => 'cardboxtext1', 'type' => 'heading', 'data' => ['text' => 'Also inside'], 'settings' => []],
            ]], 'settings' => []],
        ];
        $html = $this->render([self::loop($card)], 'layout');
        preg_match_all('~<li class="thallo-loop-card listing-row"([^>]*)>~', $html, $cards);
        self::assertCount(3, $cards[1]);
        self::assertSame(' data-thallo-slot="card"', $cards[1][0], 'the first card is the drop destination');
        self::assertSame([' data-thallo-card-copy', ' data-thallo-card-copy'], array_slice($cards[1], 1));
        // The card sits inside the loop's own selectable wrapper: a drop in it names the loop and `card`.
        self::assertMatchesRegularExpression(
            '~data-thallo-block="loopblock001">\s*<div class="thallo-block thallo-block-entry_loop[^>]*>\s*'
                . '<ul[^>]*>\s*<li class="thallo-loop-card listing-row" data-thallo-slot="card">~',
            $html,
        );
        $ids = ['loopblock001', 'cardtitle001', 'carddate0001', 'cardexcerpt1', 'cardbox00001', 'cardboxhead1',
            'cardboxtext1'];
        foreach ($ids as $id) {
            self::assertSame(1, substr_count($html, 'data-thallo-block="' . $id . '"'), "{$id} is selectable once");
        }
        preg_match_all('~data-thallo-block="([^"]+)"~', $html, $ids);
        self::assertSame(count($ids[1]), count(array_unique($ids[1])), 'no id appears twice');
        self::assertSame(3, substr_count($html, 'Also inside'), 'every card renders the container');
    }

    /**
     * Review Focus 5 in full: a card holding a container with nested blocks, on a page of ten items.
     * The stage annotates exactly the first card's blocks, the container and its children; every id
     * appears once; and each of the other nine cards is the site's card, byte for byte — no
     * annotation, no slot, nothing the stage adds.
     */
    public function testTenCardsOnTheStageAreTheFirstAnnotatedAndNineSiteCards(): void
    {
        $card = [
            ['id' => 'tenbox000001', 'type' => 'container', 'data' => ['element' => 'div', 'content' => [
                ['id' => 'tenhead00001', 'type' => 'heading', 'data' => ['text' => 'Inside'], 'settings' => []],
                ['id' => 'tentitle0001', 'type' => 'entry_title', 'data' => ['level' => 'h3', 'link' => true],
                    'settings' => []],
                ['id' => 'tendate00001', 'type' => 'entry_date', 'data' => ['format' => 'long'], 'settings' => []],
            ]], 'settings' => []],
        ];
        // Ten items that render alike, so every card's markup can be compared with one site card.
        $this->items = array_fill(0, 10, $this->items[0]);
        $cardsOf = static function (string $html): array {
            preg_match_all('~<li class="thallo-loop-card listing-row"([^>]*)>(.*?)</li>~s', $html, $m);
            return ['attrs' => $m[1], 'inner' => $m[2]];
        };

        $site = $cardsOf($this->render([self::loop($card)], 'none'));
        self::assertCount(10, $site['inner']);
        self::assertSame(array_fill(0, 10, ''), $site['attrs'], 'the site marks no card');
        self::assertCount(1, array_unique($site['inner']), 'the site renders ten alike cards');

        $html = $this->render([self::loop($card)], 'layout');
        $stage = $cardsOf($html);
        self::assertCount(10, $stage['inner']);
        self::assertSame(' data-thallo-slot="card"', $stage['attrs'][0]);
        foreach (['tenbox000001', 'tenhead00001', 'tentitle0001', 'tendate00001'] as $id) {
            self::assertSame(1, substr_count($html, 'data-thallo-block="' . $id . '"'), "{$id} is selectable once");
            $first = $stage['inner'][0];
            self::assertStringContainsString('data-thallo-block="' . $id . '"', $first, 'in the first card');
        }
        preg_match_all('~data-thallo-block="([^"]+)"~', $html, $ids);
        self::assertSame(count($ids[1]), count(array_unique($ids[1])), 'no id appears twice');
        for ($i = 1; $i < 10; $i++) {
            self::assertSame(' data-thallo-card-copy', $stage['attrs'][$i], "card {$i} is a copy");
            self::assertSame($site['inner'][0], $stage['inner'][$i], "card {$i} is the site's card, unannotated");
        }
    }

    /**
     * An anchor set on a card's block names one element on the page (final review): the first card
     * keeps it — a link to `#studio` lands there — and the other cards, which repeat the design for
     * other entries, carry no id, on the site and on the stage alike. Its other attributes repeat.
     */
    public function testACardBlocksAnchorIsTheFirstCardsOnly(): void
    {
        $card = [['id' => 'anchorhead01', 'type' => 'heading', 'data' => ['text' => 'Card'], 'settings' => [
            'advanced' => ['anchor' => 'studio', 'attributes' => ['data-track' => 'card']],
        ]]];
        foreach (['none', 'layout'] as $scope) {
            $html = $this->render([self::loop($card)], $scope);
            self::assertSame(1, substr_count($html, 'id="studio"'), "{$scope}: one element named studio");
            preg_match('~<li class="thallo-loop-card[^>]*>(.*?)</li>~s', $html, $first);
            self::assertStringContainsString('id="studio"', $first[1], "{$scope}: in the first card");
            self::assertSame(3, substr_count($html, 'data-track="card"'), "{$scope}: other attributes repeat");
        }
    }

    /**
     * Review of aa2801ec: a card's tabs and accordion are the card's own. Their radios and exclusive
     * details are grouped by name, so each card's group has its own name and ids — a tab chosen in one
     * card never switches another — on the site and on the stage.
     */
    public function testEachCardsTabsAndAccordionAreItsOwn(): void
    {
        $card = [
            ['id' => 'cardtabs0001', 'type' => 'tabs', 'data' => ['items' => [
                ['id' => 'cardtab00001', 'type' => 'tab', 'data' => ['label' => 'One', 'content' => []],
                    'settings' => []],
                ['id' => 'cardtab00002', 'type' => 'tab', 'data' => ['label' => 'Two', 'content' => []],
                    'settings' => []],
            ]], 'settings' => []],
            ['id' => 'cardacc00001', 'type' => 'accordion', 'data' => ['multiple' => false, 'items' => [
                ['id' => 'cardaccit001', 'type' => 'accordion_item', 'data' => ['title' => 'Q', 'content' => []],
                    'settings' => []],
            ]], 'settings' => []],
        ];
        foreach (['none', 'layout'] as $scope) {
            $html = $this->render([self::loop($card)], $scope);
            preg_match_all(
                '~<input class="thallo-block-tabs__radio" type="radio" name="([^"]+)" id="([^"]+)"~',
                $html,
                $radios,
            );
            self::assertCount(6, $radios[1], "{$scope}: two tabs in each of three cards");
            self::assertCount(3, array_unique($radios[1]), "{$scope}: one radio group per card");
            self::assertCount(6, array_unique($radios[2]), "{$scope}: every radio id once");
            preg_match_all('~<details class="thallo-block-accordion__item" name="([^"]+)"~', $html, $groups);
            self::assertCount(3, array_unique($groups[1]), "{$scope}: one accordion group per card");
        }
    }

    /** Review of aa2801ec: a loop inside a card's copy is a copy too — the anchor stays single. */
    public function testALoopNestedInACardCopiesKeepsOneAnchor(): void
    {
        $inner = ['id' => 'innerloop001', 'type' => 'entry_loop', 'data' => ['card' => [
            ['id' => 'innerhead001', 'type' => 'heading', 'data' => ['text' => 'Deep'], 'settings' => [
                'advanced' => ['anchor' => 'deep'],
            ]],
        ]], 'settings' => []];
        $html = $this->render([self::loop([$inner])], 'none');
        self::assertSame(1, substr_count($html, 'id="deep"'), 'one element named deep');
    }

    public function testAnEmptyPageShowsItsTextAndTheStageOnePlaceholderCard(): void
    {
        $empty = ['layout_context' => ['items' => [], 'placeholder_item' => [
            'uuid' => null, 'fields' => ['title' => 'Sample post'], 'published_at' => '2026-09-28T00:00:00+00:00',
        ]]];
        $site = $this->render([self::loop(self::card())], 'none', $empty);
        self::assertStringContainsString('<p class="listing-empty">Nothing here yet.</p>', $site);
        self::assertStringNotContainsString('thallo-loop-card', $site);
        $custom = $this->render([['empty_text' => 'No posts yet.'] + self::loop(self::card())], 'none', $empty);
        self::assertStringContainsString(
            '<p class="listing-empty">Nothing here yet.</p>',
            $custom,
            'data, not a top-level key',
        );
        $withText = $this->render([[
            'id' => 'loopblock001', 'type' => 'entry_loop',
            'data' => ['card' => self::card(), 'empty_text' => 'No posts yet.'], 'settings' => [],
        ]], 'none', $empty);
        self::assertStringContainsString('<p class="listing-empty">No posts yet.</p>', $withText);

        $stage = $this->render([self::loop(self::card())], 'layout', $empty);
        self::assertSame(1, substr_count($stage, '<li class="thallo-loop-card listing-row" data-thallo-slot="card">'));
        self::assertStringContainsString('Sample post', $stage);
        self::assertSame(1, substr_count($stage, 'data-thallo-block="cardtitle001"'));
    }

    public function testAnEmptyCardKeepsItsSlotOnTheFirstCard(): void
    {
        $html = $this->render([self::loop([])], 'layout');
        preg_match_all('~<li class="thallo-loop-card listing-row"([^>]*)>(.*?)</li>~s', $html, $cards);
        self::assertSame([' data-thallo-slot="card"', ' data-thallo-card-copy', ' data-thallo-card-copy'], $cards[1]);
        self::assertSame(['', '', ''], array_map('trim', $cards[2]), 'no blocks yet: empty cards');
    }

    public function testTheItemReachesANestedBlockAndNeverTheChrome(): void
    {
        $this->container()->get(RegionRepository::class)->save('header', [
            ['id' => 'headerprobe1', 'type' => 'item_probe', 'data' => [], 'settings' => []],
        ], [], null);
        $card = [['id' => 'cardbox00001', 'type' => 'container', 'data' => ['element' => 'div', 'content' => [
            ['id' => 'cardprobe001', 'type' => 'item_probe', 'data' => [], 'settings' => []],
        ]], 'settings' => []]];
        $html = $this->render(
            [self::loop($card)],
            'none',
            [],
            '{{ layout_blocks(layout) }}<header>{{ region_blocks("header") }}</header>',
        );
        foreach ($this->items as $item) {
            self::assertStringContainsString("[{$item['uuid']}|{$item['uuid']}|none]", $html, 'two deep in the card');
        }
        self::assertMatchesRegularExpression(
            '~<header><p class="thallo-block item-probe">\[none\|none\|none\]</p>\s*</header>~',
            $html,
        );
    }

    /** Review Focus 4: only the first card's cover is the priority image, as today's first row. */
    public function testOnlyTheFirstCardsCoverIsThePriorityImage(): void
    {
        $html = $this->render([self::loop([
            ['id' => 'cardcover001', 'type' => 'entry_cover', 'data' => ['field' => 'cover'], 'settings' => []],
        ])], 'none');
        preg_match_all('~<img class="thallo-block-entry_cover__image[^>]*>~', $html, $images);
        self::assertCount(2, $images[0], 'the two posts with a cover');
        self::assertStringContainsString('fetchpriority="high"', $images[0][0]);
        self::assertStringNotContainsString('loading="lazy"', $images[0][0]);
        self::assertStringContainsString('loading="lazy"', $images[0][1]);
        self::assertStringNotContainsString('fetchpriority', $images[0][1]);
    }

    /**
     * A card's cover is today's row thumbnail (final review, Important 2): drawn 160px wide (96px on
     * phones) by the theme, it asks for today's candidates and sizes, not the full-width set a page's
     * cover asks for. Given a width of its own, it asks as a page's cover does.
     */
    public function testACardsCoverAsksForThumbnailSizes(): void
    {
        $cover = static fn (array $settings = []): array => [
            'id' => 'cardcover001', 'type' => 'entry_cover', 'data' => ['field' => 'cover'], 'settings' => $settings,
        ];
        $img = function (string $html): string {
            preg_match('~<img class="thallo-block-entry_cover__image[^>]*>~', $html, $m);
            self::assertNotEmpty($m, 'a cover rendered');
            return $m[0];
        };

        $thumb = $img($this->render([self::loop([$cover()])], 'none'));
        self::assertStringContainsString('sizes="(max-width: 48rem) 96px, 160px"', $thumb);
        self::assertStringContainsString(' 160w', $thumb);
        self::assertStringContainsString(' 320w', $thumb);
        self::assertStringNotContainsString(' 1920w', $thumb);

        $wide = $img($this->render([self::loop([$cover(['style' => [
            'width' => ['base' => ['type' => 'token', 'value' => 'width.full']],
        ]])])], 'none'));
        self::assertStringContainsString('sizes="(max-width: 72rem) 100vw, 72rem"', $wide, 'its own width');

        $page = $img($this->render([$cover()], 'none', [
            'entry' => $this->items[0],
        ]));
        self::assertStringContainsString('sizes="(max-width: 72rem) 100vw, 72rem"', $page, 'a page\'s cover');
    }

    /** The card is the layout's own tree: its depth counts from the layout's root, and the cap holds. */
    public function testACardDeepInTheLayoutCountsFromTheRoot(): void
    {
        $box = static fn (string $id, array $content): array => [
            'id' => $id, 'type' => 'container', 'data' => ['element' => 'div', 'content' => $content], 'settings' => [],
        ];
        $probe = static fn (string $id): array => ['id' => $id, 'type' => 'item_probe', 'data' => [], 'settings' => []];
        // Depth 1 box, 2 box, 3 the loop, 4 the card's box, 5 its probe — the deepest a tree goes;
        // 6 a box's probe inside that — beyond it.
        $layout = [$box('depthbox0001', [$box('depthbox0002', [self::loop([
            $box('depthbox0004', [$probe('depthprobe05'), $box('depthbox0005', [$probe('depthprobe06')])]),
        ])])])];
        $html = $this->render($layout, 'layout');
        self::assertSame(1, substr_count($html, 'data-thallo-block="depthprobe05"'), 'depth 5 renders');
        self::assertStringNotContainsString('data-thallo-block="depthprobe06"', $html, 'depth 6 does not');
    }

    /** C2's seam: a loop names what each card's item is, and the card reads it there — not as the entry. */
    public function testLoopCardsExposeTheItemUnderTheNameGiven(): void
    {
        $card = [['id' => 'cardprobe001', 'type' => 'item_probe', 'data' => [], 'settings' => []]];
        $html = $this->render([], 'none', ['card' => $card], "{{ loop_cards(card, layout_context.items, 'product') }}");
        foreach ($this->items as $item) {
            self::assertStringContainsString("[{$item['uuid']}|none|{$item['uuid']}]", $html);
        }
    }
}
