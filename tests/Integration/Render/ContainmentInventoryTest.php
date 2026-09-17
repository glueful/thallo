<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Page-level containment and its release inside a container (container-layout spec §3.6).
 *
 * A block whose own root rule clamps itself to the page measure carries the page's gutter with it.
 * Inside a container that is a doubled gutter, so the theme releases the clamp — for exactly the
 * blocks that have one. Two facts have to stay true together: the committed inventory must still
 * describe the stylesheet, and the release must name the inventory exactly. A block that gains
 * containment later fails the first test; a release that drifts fails the second.
 */
final class ContainmentInventoryTest extends AppTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath()
                . '/packages/thallo-render/themes/default/assets/blocks.css',
        );
    }

    /** @return list<string> block names, sorted */
    private function inventoried(): array
    {
        $inventory = json_decode(
            (string) file_get_contents(
                $this->appContext()->getBasePath() . '/tests/fixtures/layout/containment-inventory.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $names = [];
        foreach ($inventory['rules'] as $rule) {
            foreach ($rule['blocks'] as $block) {
                $names[$block] = true;
            }
        }
        $names = array_keys($names);
        sort($names);
        return $names;
    }

    public function testTheInventoryStillDescribesTheStylesheet(): void
    {
        // Recomputed here, not read from the build script's output, so a block that gains page-level
        // containment without being added to the release is caught by this test rather than by a
        // stale artifact agreeing with itself. Mirrors scripts/build-containment-inventory.
        $found = [];
        preg_match_all('/(?m)^(?P<sel>[^{\n]+)\{(?P<body>[^}]*)\}/s', $this->css(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $selector = trim($match['sel']);
            $body = $match['body'];
            $contained = str_contains($body, 'max-width: var(--container)')
                || str_contains($body, 'max-width: var(--content)');
            if (!$contained) {
                continue;
            }
            // A BEM child or a layout-mode scoped rule is not the block's own root containment.
            if (str_contains($selector, '__') || str_contains($selector, '.layout--')) {
                continue;
            }
            if (!preg_match_all('/\.thallo-block-([a-z_0-9]+)(?![\w-])/', $selector, $blocks)) {
                continue;
            }
            foreach ($blocks[1] as $block) {
                $found[$block] = true;
            }
        }
        $found = array_keys($found);
        sort($found);

        self::assertSame(
            $this->inventoried(),
            $found,
            'blocks.css and tests/fixtures/layout/containment-inventory.json disagree — '
                . 're-run scripts/build-containment-inventory and extend the containment release',
        );
    }

    public function testEveryInventoriedContainmentIsReleasedInsideAContainerAndNothingElseIs(): void
    {
        // The release names exactly the blocks the inventory found (spec §3.6): more would strip a
        // component's own padding, fewer would leave a doubled gutter inside a cell.
        preg_match(
            '~/\* containment release \(spec §3\.6\) \*/\n(?P<block>.*?)\n/\* end containment release \*/~s',
            $this->css(),
            $m,
        );
        self::assertNotEmpty($m['block'] ?? '', 'the containment release block is missing');
        preg_match_all('~\.thallo-block-([a-z_0-9]+)(?![\w-])~', $m['block'], $found);
        // The release is scoped by the container's inner element, whose class matches the same
        // shape; a BEM child is not a block name.
        $released = array_values(array_unique(array_filter(
            $found[1],
            static fn (string $name): bool => !str_contains($name, '__'),
        )));
        sort($released);
        self::assertSame($this->inventoried(), $released);

        // Only the three containment declarations are released.
        self::assertStringContainsString('max-width: none;', $m['block']);
        self::assertStringContainsString('margin-inline: 0;', $m['block']);
        self::assertStringContainsString('padding-inline: 0;', $m['block']);
        foreach (['background', 'border', 'gap', 'font-size'] as $unrelated) {
            self::assertStringNotContainsString($unrelated, $m['block']);
        }
        // The stage wraps each block root in a display:contents annotation element.
        self::assertStringContainsString('.thallo-preview-block >', $m['block']);
    }
}
