<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Contracts\Patterns\LayoutSection;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Patterns\LayoutPatterns;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\LayoutTypeShapes;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Core's layout patterns, built for every type shape (sections and templates design §3.2, §6): each
 * template, where it is built, is a layout its surface accepts; each section fits where it is built;
 * the parts follow the type's schema; the templates close to today's are today's starters.
 */
final class LayoutPatternsTest extends AppTestCase
{
    use LayoutTypeShapes;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->createLayoutTypeShapes();
    }

    private function section(string $slug): LayoutSection
    {
        foreach (LayoutPatterns::sections() as $section) {
            if ($section->slug === $slug) {
                return $section;
            }
        }
        self::fail("no section {$slug}");
    }

    private function template(string $slug): LayoutTemplate
    {
        foreach (LayoutPatterns::templates() as $template) {
            if ($template->slug === $slug) {
                return $template;
            }
        }
        self::fail("no template {$slug}");
    }

    public function testEveryTemplatePassesItsSurfaceForEveryTypeShape(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        $built = 0;
        foreach (LayoutPatterns::templates() as $template) {
            foreach ($this->shapeTargets($template->surface) as $target) {
                $tree = ($template->build)($this->layoutTarget($template->surface, $target));
                if ($tree === null) {
                    continue;
                }
                try {
                    $blocks = self::withIds($tree);
                    $validator->validate($template->surface, $target, $blocks, $template->settings, [], false);
                } catch (ValidationException $e) {
                    self::fail("{$template->slug} for {$target}: " . json_encode($e->errors()));
                }
                $built++;
            }
        }
        self::assertGreaterThanOrEqual(30, $built);
    }

    public function testEverySectionFitsWhereItIsBuilt(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        foreach (LayoutPatterns::sections() as $section) {
            foreach ($this->shapeTargets($section->surface) as $target) {
                $block = ($section->build)($this->layoutTarget($section->surface, $target));
                if ($block !== null) {
                    $errors = $validator->fragment($section->surface, $target, [$block])['errors'];
                    self::assertSame([], $errors, "{$section->slug} for {$target}");
                }
            }
        }
    }

    public function testSchemaAwareParts(): void
    {
        $cover = $this->section('entry-cover-band');
        self::assertNotNull(($cover->build)($this->layoutTarget('entry', 'lp_body')));
        self::assertNull(($cover->build)($this->layoutTarget('entry', 'lp_rich')), 'no cover field, no cover band');

        $classic = $this->template('entry-classic');
        $content = self::findBlock(($classic->build)($this->layoutTarget('entry', 'lp_content')), 'entry_content');
        self::assertSame('content', $content['data']['field'] ?? null, 'the primary body is the type’s own');
        $rich = ($classic->build)($this->layoutTarget('entry', 'lp_rich'));
        self::assertNull(self::findBlock($rich, 'entry_content'));
        self::assertSame('text', self::findBlock($rich, 'entry_field')['data']['field'] ?? null);

        self::assertNull(($this->section('entry-article-header')->build)(
            new \Thallo\Contracts\Patterns\LayoutTarget('entry', 'x', [['name' => 'body', 'type' => 'blocks']]),
        ), 'no title field, no article header');
    }

    public function testTheClassicTemplatesAreTodaysStarters(): void
    {
        $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
        foreach ($this->shapeTargets('entry') as $target) {
            self::assertSame(
                $surfaces->get('entry')->starter($target),
                ($this->template('entry-classic')->build)($this->layoutTarget('entry', $target)),
                $target,
            );
            self::assertSame(
                $surfaces->get('listing')->starter($target),
                ($this->template('listing-horizontal')->build)($this->layoutTarget('listing', $target)),
                $target,
            );
        }
        self::assertSame(
            $surfaces->get('archive')->starter('lp_body:categories'),
            ($this->template('archive-horizontal')->build)($this->layoutTarget('archive', 'lp_body:categories')),
        );
    }

    public function testSlugsAreTheShippedOnes(): void
    {
        $patterns = [...LayoutPatterns::sections(), ...LayoutPatterns::templates()];
        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[$pattern->slug] = $pattern->surface;
        }
        self::assertSame($expected, LayoutPatterns::slugs());
        self::assertCount(16, $expected);
        foreach ($expected as $slug => $surface) {
            self::assertStringStartsWith($surface . '-', $slug);
        }
    }
}
