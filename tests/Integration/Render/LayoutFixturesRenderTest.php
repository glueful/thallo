<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\LayoutFixtureBlockType;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The layout proof cases render as the contract says they should (container-layout plan, Task 1.2).
 *
 * The browser measures what these classes DO (`tools/runtime-browser/tests/layout.spec.js`); this
 * test pins what is emitted, so a cascade or emission regression is caught in the PHP suite rather
 * than only in the browser gate. Two mechanical facts cover every case: a layout value resolves to
 * a class at EVERY breakpoint, not only the one it was authored at (resolved emission — the span
 * and spacing rules key off the state at each width), and the annotated rendering carries exactly
 * the classes the public one does, so the canvas is never styled differently from the page.
 */
final class LayoutFixturesRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const BREAKPOINTS = ['base', 'md', 'lg'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        LayoutFixtureBlockType::register($this->container()->get(BlockTypeRepository::class));
        $this->container()->get(BlockStyleRegistry::class)->reset();
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function casesProvider(): iterable
    {
        foreach (LayoutFixtureBlockType::cases(dirname(__DIR__, 3)) as $name => $case) {
            yield $name => [$case];
        }
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes', null, [LayoutFixtureBlockType::templateDir($base)]),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $tree */
    private function render(array $tree, bool $annotations = false): string
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope($annotations ? 'entry' : 'none');
        try {
            return $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => [$tree]]);
        } finally {
            $extension->setAnnotationScope('none');
        }
    }

    /**
     * Every managed path a node authors, as path => breakpoint => value name. Walks the stored
     * `settings.style` tree down to the breakpoint maps, so nested paths (`layout.gap.column`)
     * need no special handling here.
     *
     * @param array<string,mixed> $style
     * @return array<string, array<string,string>>
     */
    private function authored(array $style, string $prefix = ''): array
    {
        $found = [];
        foreach ($style as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (isset($value['type']) || isset($value['base']) || isset($value['md']) || isset($value['lg'])) {
                $states = [];
                foreach (self::BREAKPOINTS as $breakpoint) {
                    $state = $value[$breakpoint] ?? null;
                    if (!is_array($state)) {
                        continue;
                    }
                    $states[$breakpoint] = ($state['type'] ?? '') === 'reset'
                        ? 'reset'
                        : ClassNames::valueName((string) ($state['value'] ?? ''));
                }
                if ($states !== []) {
                    $found[$path] = $states;
                }
                continue;
            }
            $found += $this->authored($value, $path);
        }
        return $found;
    }

    /** Every block in a case tree, depth first. @return list<array<string,mixed>> */
    private function nodes(array $tree): array
    {
        $nodes = [$tree];
        foreach ($tree['data'] ?? [] as $value) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $child) {
                if (is_array($child) && isset($child['type'], $child['id'])) {
                    $nodes = array_merge($nodes, $this->nodes($child));
                }
            }
        }
        return $nodes;
    }

    /** Mobile-first inheritance: a breakpoint with no state of its own carries the one below. */
    private function resolve(array $states): array
    {
        $resolved = [];
        $current = null;
        foreach (self::BREAKPOINTS as $breakpoint) {
            $current = $states[$breakpoint] ?? $current;
            if ($current !== null) {
                $resolved[$breakpoint] = $current;
            }
        }
        return $resolved;
    }

    #[DataProvider('casesProvider')]
    public function testEveryAuthoredLayoutValueResolvesToAClassAtEveryBreakpoint(array $case): void
    {
        $html = $this->render($case['tree']);
        /** @var array<string,int> $expectedPerStem */
        $expectedPerStem = [];

        foreach ($this->nodes($case['tree']) as $node) {
            $style = $node['settings']['style'] ?? [];
            foreach ($this->authored(is_array($style) ? $style : []) as $path => $states) {
                $property = StyleSchema::property($path);
                if ($property === null || !isset(ClassNames::STEMS[$path])) {
                    continue;
                }
                if (!$property->responsive) {
                    // One value for every width: emitted once, at base (spec §3.2).
                    self::assertStringContainsString(
                        ClassNames::for($path, $states['base'], 'base'),
                        $html,
                        $path,
                    );
                    $expectedPerStem[ClassNames::STEMS[$path]] ??= 0;
                    $expectedPerStem[ClassNames::STEMS[$path]]++;
                    continue;
                }
                // Layout and alignment resolve at every width; everything else is emitted only
                // where it was authored (exact emission, plan "Emission").
                $resolved = in_array($property->group, ['layout', 'layout.item'], true)
                    || $path === 'alignment.content'
                    ? $this->resolve($states)
                    : $states;
                foreach ($resolved as $breakpoint => $value) {
                    self::assertStringContainsString(
                        ClassNames::for($path, $value, $breakpoint),
                        $html,
                        "{$path} at {$breakpoint}",
                    );
                    $expectedPerStem[ClassNames::STEMS[$path]] ??= 0;
                    $expectedPerStem[ClassNames::STEMS[$path]]++;
                }
            }
            // A block that can arrange children always states its track count, authored or not:
            // the span rules pair a parent's track state with a child's span (spec §3.7), and the
            // default state, one track, has to be named to be paired.
            if (($node['type'] ?? '') === LayoutFixtureBlockType::SLUG) {
                $expectedPerStem['cols'] ??= 0;
                $tracks = $this->resolve($this->authored(
                    is_array($style) ? $style : [],
                )['layout.columns'] ?? []);
                foreach (self::BREAKPOINTS as $breakpoint) {
                    if (!isset($tracks[$breakpoint])) {
                        self::assertStringContainsString(
                            ClassNames::for('layout.columns', 'auto', $breakpoint),
                            $html,
                            "default track state at {$breakpoint}",
                        );
                        $expectedPerStem['cols']++;
                    }
                }
            }
        }

        // No stray classes: what the settings resolve to is exactly what the block carries.
        foreach ($expectedPerStem as $stem => $expected) {
            self::assertSame(
                $expected,
                preg_match_all('~(?<![\w:-])(?:md:|lg:)?t-' . preg_quote($stem, '~') . '-[\w-]+~', $html),
                "occurrences of t-{$stem}-*",
            );
        }
    }

    #[DataProvider('casesProvider')]
    public function testTheAnnotatedRenderingCarriesTheSameStyleClassesAsThePublicOne(array $case): void
    {
        $classes = static function (string $html): array {
            preg_match_all('~class="([^"]*\bt-[^"]*)"~', $html, $m);
            return $m[1];
        };
        $public = $classes($this->render($case['tree']));
        $canvas = $classes($this->render($case['tree'], true));
        self::assertNotSame([], $public, 'the case emits no managed classes at all');
        self::assertSame($public, $canvas);
    }

    public function testACaseUnderTheStageIsWrappedWithoutChangingTheBlockElements(): void
    {
        // The stage wraps each block root in a display:contents annotation element, which is why
        // the compiled span and spacing rules carry a wrapper form of every selector (spec §3.7).
        $case = LayoutFixtureBlockType::cases(
            $this->container()->get(ApplicationContext::class)->getBasePath(),
        )['span-asymmetric'];
        $canvas = $this->render($case['tree'], true);
        self::assertStringContainsString(
            '<div class="thallo-preview-block" data-thallo-block="lf0000000001">',
            $canvas,
        );
        self::assertStringContainsString('data-thallo-slot="content"', $canvas);
        self::assertMatchesRegularExpression(
            '~<div class="thallo-preview-block"[^>]*><h2 class="thallo-block thallo-block-heading[^"]*t-span-2~',
            $canvas,
        );
    }

    public function testTheContainerAndTheFixtureDeclareTheSameLayoutVocabulary(): void
    {
        // The fixture type existed to prove the contract before any block depended on it. The
        // container has cut over now (Task 2.1), so it declares the same parent and item
        // properties — and the proofs keep measuring the fixture, which is not the production
        // block and so cannot drift into standing in for it.
        $registry = $this->container()->get(BlockStyleRegistry::class);
        $container = $registry->capabilitiesFor('container')->paths();
        $fixture = $registry->capabilitiesFor(LayoutFixtureBlockType::SLUG)->paths();
        $layout = static fn (array $paths): array => array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_starts_with($path, 'layout')
                || $path === 'alignment.content',
        ));
        sort($container);
        sort($fixture);
        self::assertSame($layout($fixture), $layout($container));
    }
}
