<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Fragments\DocumentIndex;
use Thallo\Render\Fragments\RenderScopeResolver;
use Thallo\Render\Fragments\TemplateDependencies;
use Thallo\Render\TwigFactory;

/**
 * The render-scope table (visual builder spec §3.5) over the shipped block types and templates:
 * lifting to inline-rendering parents, ancestors absorbing descendants, and every escalation
 * to the whole page.
 */
final class RenderScopeResolverTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private TemplateDependencies $dependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->dependencies = new TemplateDependencies($this->container()->get(TwigFactory::class)->environment());
    }

    private function registry(): BlockStyleRegistry
    {
        return $this->container()->get(BlockStyleRegistry::class);
    }

    private function resolver(): RenderScopeResolver
    {
        return new RenderScopeResolver($this->registry(), $this->dependencies);
    }

    /** @param list<array<string,mixed>> $body */
    private function index(array $body): DocumentIndex
    {
        $registry = $this->registry();
        $regionsOf = static fn (string $t): array => $registry->regionsFor($t);
        return DocumentIndex::of(['body' => $body], ['body'], $regionsOf);
    }

    private static function text(string $id): array
    {
        return ['id' => $id, 'type' => 'rich_text', 'data' => ['body' => '<p>x</p>']];
    }

    /** @param list<array<string,mixed>> $content */
    private static function box(string $id, array $content): array
    {
        return ['id' => $id, 'type' => 'container', 'data' => ['content' => $content]];
    }

    public function testTheRegistryKnowsRegionsAndTheTemplatesTheirDependencies(): void
    {
        self::assertSame(['content'], $this->registry()->regionsFor('container'));
        self::assertSame(['items'], $this->registry()->regionsFor('tabs'));
        self::assertSame([], $this->registry()->regionsFor('heading'));
        self::assertSame([], $this->registry()->regionsFor('no_such_type'));

        self::assertTrue($this->dependencies->pageOrderDependent('image'), 'claims the priority image');
        self::assertTrue($this->dependencies->pageOrderDependent('hero'));
        self::assertFalse($this->dependencies->pageOrderDependent('rich_text'));
        self::assertTrue($this->dependencies->pageDependent('blog_posts'), 'entries()');
        self::assertSame(['image'], $this->dependencies->claimFields('image'), 'the claim is guarded by data.image');
        self::assertSame(['background_image'], $this->dependencies->claimFields('container'));
        self::assertSame([], $this->dependencies->claimFields('rich_text'), 'never claims');
        self::assertNull($this->dependencies->claimFields('shortcode'), 'unknowable: counts as reachable');
        self::assertTrue($this->dependencies->pageDependent('navigation'), 'reads current_path');
        self::assertTrue($this->dependencies->pageOrderDependent('shortcode'), 'a computed include is unknowable');
        self::assertSame(['blocks/rich_text.twig'], $this->dependencies->templatesFor('rich_text'));
        self::assertNotNull($this->dependencies->templateHash('blocks/rich_text.twig'));
        self::assertNull($this->dependencies->templateHash('blocks/no_such_type.twig'));
        self::assertSame([], $this->dependencies->templatesFor('no_such_type'));
    }

    public function testAChildOfAnInlineRenderingParentLiftsToThatParent(): void
    {
        $doc = $this->index([
            ['id' => 'tabs', 'type' => 'tabs', 'data' => ['items' => [
                ['id' => 'tab1', 'type' => 'tab', 'data' => ['label' => 'A', 'content' => [self::text('t1')]]],
                ['id' => 'tab2', 'type' => 'tab', 'data' => ['label' => 'B', 'content' => []]],
            ]]],
            self::text('after'),
        ]);
        self::assertSame(['tabs'], $this->resolver()->resolve(['tab1'], $doc, $doc), 'a tab label edit');
        self::assertSame(['t1'], $this->resolver()->resolve(['t1'], $doc, $doc), 'a tab wraps its own content');
        self::assertSame(['tabs'], $this->resolver()->resolve(['tabs'], $doc, $doc));
        $both = $this->resolver()->resolve(['after', 'tab2'], $doc, $doc);
        self::assertSame(['tabs', 'after'], $both, 'document order');
    }

    public function testAncestorsAbsorbDescendantsAndAContainerIsItsOwnRoot(): void
    {
        $doc = $this->index([self::box('c', [self::text('t'), self::box('inner', [self::text('u')])])]);
        self::assertSame(['t'], $this->resolver()->resolve(['t'], $doc, $doc));
        self::assertSame(['c'], $this->resolver()->resolve(['c', 't', 'u'], $doc, $doc));
        self::assertSame(['inner'], $this->resolver()->resolve(['u', 'inner'], $doc, $doc));
    }

    public function testAPaddingChangeOnAContainerHoldingAnImageEscalatesToTheWholePage(): void
    {
        // An earlier priority image elsewhere on the page: the inner image's claim depends on
        // page order, so the container cannot render in isolation.
        $doc = $this->index([
            ['id' => 'lead', 'type' => 'image', 'data' => ['image' => 'blob00000000', 'alt' => 'lead']],
            self::box('c', [['id' => 'pic', 'type' => 'image', 'data' => ['image' => 'blob00000001', 'alt' => 'x']]]),
            self::text('t'),
        ]);
        self::assertNull($this->resolver()->resolve(['c'], $doc, $doc));
        self::assertNull($this->resolver()->resolve(['pic'], $doc, $doc));
        self::assertSame(['t'], $this->resolver()->resolve(['t'], $doc, $doc), 'a sibling without images is fine');

        // A container whose image block has no image (and no background of its own) cannot claim.
        $empty = $this->index([
            self::box('c', [['id' => 'pic', 'type' => 'image', 'data' => ['alt' => 'x']]]),
        ]);
        self::assertSame(['c'], $this->resolver()->resolve(['c'], $empty, $empty));
        // The claim being removed by this edit escalates too: the displayed render claimed it.
        self::assertNull($this->resolver()->resolve(['c'], $empty, $doc), 'before: the image could claim');
        $withBackground = $this->index([self::box('c', [])]);
        $withBackground2 = $this->index([
            ['id' => 'c', 'type' => 'container', 'data' => ['background_image' => 'blob00000002', 'content' => []]],
        ]);
        self::assertNull($this->resolver()->resolve(['c'], $withBackground2, $withBackground), 'a background claims');
    }

    public function testAPageDependencyAnywhereOnThePageIsTheWholePage(): void
    {
        $doc = $this->index([
            self::text('t'),
            ['id' => 'posts', 'type' => 'blog_posts', 'data' => ['limit' => 3]],
        ]);
        self::assertNull($this->resolver()->resolve(['t'], $doc, $doc));
    }

    public function testABlockTypeNewToThePageThatNeedsRuntimeAssetsEscalates(): void
    {
        $needing = null;
        foreach (['carousel', 'tabs', 'accordion', 'gallery', 'animated_text', 'code', 'color_mode'] as $type) {
            if ($this->dependencies->needsAssets($type)) {
                $needing = $type;
                break;
            }
        }
        self::assertNotNull($needing, 'some shipped block loads a runtime module');
        $before = $this->index([self::box('c', [self::text('t')])]);
        $after = $this->index([self::box('c', [self::text('t'), ['id' => 'n', 'type' => $needing, 'data' => []]])]);
        self::assertNull($this->resolver()->resolve(['c'], $after, $before), 'its assets may not be loaded');
        self::assertSame(['c'], $this->resolver()->resolve(['c'], $after, $after), 'once it was on the page');
        self::assertNull($this->resolver()->resolve([], $after, $before), 'nothing affected: nothing to render');
    }
}
