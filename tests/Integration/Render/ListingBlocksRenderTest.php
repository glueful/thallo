<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The listing page's own blocks (type layouts plan B, B2): the listing title, the term's
 * description and the page navigation, each reading the page the frame hands it (`layout_context`),
 * rendering nothing where there is nothing on the site and a named placeholder on the stage.
 */
final class ListingBlocksRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    protected function tearDown(): void
    {
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
            new ThemeLocator('default', $base . '/themes', null),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $page */
    private function render(string $type, array $data, array $page, string $scope = 'none'): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->env()->createTemplate('{{ layout_blocks(layout) }}')->render([
            'layout' => [['id' => 'listblock001', 'type' => $type, 'data' => $data, 'settings' => []]],
            'layout_context' => $page,
        ]);
    }

    private const LISTING = ['type' => 'post', 'type_name' => 'Posts', 'term' => null, 'field' => null];

    /** @param array<string,mixed> $fields */
    private static function archive(array $fields, ?string $format = 'rich'): array
    {
        return ['type' => 'post', 'type_name' => 'Posts', 'field' => 'categories',
            'term' => ['uuid' => 'termuuid0001', 'fields' => $fields], 'term_description_format' => $format];
    }

    public function testTheListingTitleNamesTheTypeOrTheTerm(): void
    {
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-listing_title[^"]*">Posts</h1>~',
            $this->render('listing_title', [], self::LISTING),
        );
        self::assertMatchesRegularExpression(
            '~<h2 class="thallo-block thallo-block-listing_title[^"]*">Pottery</h2>~',
            $this->render(
                'listing_title',
                ['level' => 'h2'],
                self::archive(['title' => 'Pottery', 'slug' => 'pottery']),
            ),
        );
        $slugOnly = $this->render('listing_title', [], self::archive(['slug' => 'pottery']));
        self::assertStringContainsString('>pottery</h1>', $slugOnly);
        self::assertStringContainsString('>termuuid0001</h1>', $this->render('listing_title', [], self::archive([])));
    }

    public function testTheTermDescriptionShowsRichOrPlainTextAndNothingWithoutOne(): void
    {
        $rich = $this->render(
            'term_description',
            [],
            self::archive(['description' => '<p>Wheel <em>thrown</em>.</p>']),
        );
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block thallo-block-term_description[^"]*"[^>]*><p>Wheel <em>thrown</em>.</p></div>~',
            $rich,
        );
        $plain = $this->render('term_description', [], self::archive(['description' => 'Fish & <chips>'], 'plain'));
        self::assertStringContainsString('Fish &amp; &lt;chips&gt;', $plain);
        foreach ([self::archive([]), self::LISTING] as $page) {
            self::assertStringNotContainsString(
                'thallo-block-term_description',
                $this->render('term_description', [], $page),
            );
        }
        self::assertStringContainsString(
            'thallo-field-empty">Description — this term has none',
            $this->render('term_description', [], self::archive([]), 'layout'),
        );
    }

    public function testThePageNavigationIsTodaysWithItsLabels(): void
    {
        $page2 = self::LISTING + ['pagination' => [
            'page' => 2, 'per_page' => 2, 'total' => 5, 'total_pages' => 3,
            'prev_path' => '/post', 'next_path' => '/post/page/3',
        ]];
        $html = $this->render('pagination', [], $page2);
        self::assertMatchesRegularExpression(
            '~<nav class="thallo-block thallo-block-pagination pagination[^"]*"[^>]*>\s*'
                . '<a href="/post" rel="prev">Newer</a>\s*<span>Page 2 of 3</span>\s*'
                . '<a href="/post/page/3" rel="next">Older</a>\s*</nav>~',
            $html,
        );
        $labelled = $this->render(
            'pagination',
            ['previous_label' => 'Back', 'next_label' => 'More', 'count' => false],
            $page2,
        );
        self::assertStringContainsString('rel="prev">Back</a>', $labelled);
        self::assertStringContainsString('rel="next">More</a>', $labelled);
        self::assertStringNotContainsString('Page 2 of 3', $labelled);

        $one = self::LISTING + ['pagination' => [
            'page' => 1, 'per_page' => 2, 'total' => 2, 'total_pages' => 1, 'prev_path' => null, 'next_path' => null,
        ]];
        self::assertStringNotContainsString('thallo-block-pagination', $this->render('pagination', [], $one));
        self::assertStringContainsString(
            'thallo-field-empty">Page navigation — one page',
            $this->render('pagination', [], $one, 'layout'),
        );
    }
}
