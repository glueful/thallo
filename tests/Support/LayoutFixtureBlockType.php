<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\BlockTypeRepository;

/**
 * The test-only `layout_fixture` block type (container-layout plan, Task 1.2): the container's
 * shape — a band `root` and a content-area `inner` — declaring every layout capability.
 *
 * The production container declares none of them until its cutover (Task 2.1), so this type is
 * what proves the compiled contract in a browser first: dormancy, span clamping, the gutter rules,
 * min height, item participation. It is registered only in the test database, and the PHP render
 * test and `scripts/build-layout-proof-fixtures` share this declaration so the fixtures they build
 * are the same fixtures the browser measures.
 */
final class LayoutFixtureBlockType
{
    public const SLUG = 'layout_fixture';

    /** Capabilities on the band root: the block's own box, plus how it sits in its parent. */
    private const ROOT = [
        'spacing', 'width', 'visibility', 'colors', 'radius', 'border', 'shadow', 'backdrop', 'motion',
        'layout.min_height', 'layout.overflow', 'layout.item',
    ];

    /** Capabilities on the content area: everything that arranges the children. */
    private const INNER = [
        'layout.display', 'layout.direction', 'layout.wrap', 'layout.align_items', 'layout.columns',
        'layout.gap.column', 'layout.gap.row', 'layout.content_width', 'layout.gutter',
        'alignment.content', 'motion.children',
    ];

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_merge(self::ROOT, self::INNER);
    }

    /** @return array{targets: array<string, array<string,mixed>>, map: array<string,string>} */
    public static function targets(): array
    {
        $map = [];
        foreach (self::INNER as $capability) {
            $map[$capability] = 'inner';
        }
        return StyleTargets::root('box', self::ROOT, [
            'targets' => ['inner' => ['kind' => 'stack']],
            'map' => $map,
        ]);
    }

    /** Create the type, or bring an existing row up to this declaration. */
    public static function register(BlockTypeRepository $blockTypes): void
    {
        $existing = $blockTypes->findBySlug(self::SLUG);
        if ($existing !== null) {
            $blockTypes->updateStyle(
                (string) $existing['uuid'],
                self::capabilities(),
                self::targets(),
                null,
                null,
            );
            return;
        }
        $blockTypes->create([
            'slug' => self::SLUG,
            'label' => 'Layout fixture',
            'category' => 'Layout',
            'description' => 'Test-only: the container shape with every layout capability.',
            'schema' => [['name' => 'content', 'type' => 'blocks']],
            'style_capabilities' => self::capabilities(),
            'style_targets' => self::targets(),
        ]);
    }

    /** The directory contributed to the theme so `blocks/layout_fixture.twig` resolves. */
    public static function templateDir(string $basePath): string
    {
        return $basePath . '/tests/fixtures/layout/templates';
    }

    /**
     * Every proof case, by name.
     *
     * @return array<string, array{case: string, note: string, tree: array<string,mixed>}>
     */
    public static function cases(string $basePath): array
    {
        $cases = [];
        foreach (glob($basePath . '/tests/fixtures/layout/cases/*.json') ?: [] as $file) {
            $case = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $cases[(string) $case['case']] = $case;
        }
        ksort($cases);
        return $cases;
    }
}
