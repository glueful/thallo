<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\CustomBlockStyle;
use Thallo\Core\Content\Blocks\StarterBlockTypes;

/**
 * Which blocks can be animated, and where each motion setting lands. An entrance is for any block
 * a visitor SEES arrive — so every block's outermost element, except the few that are not seen
 * arriving; stagger is for the block that arranges children; Ken Burns for a picture in a frame.
 */
final class StarterBlockMotionTest extends TestCase
{
    /** @return array<string, array<string,mixed>> */
    private function bySlug(): array
    {
        return array_column(StarterBlockTypes::definitions(), null, 'slug');
    }

    private function targets(array $definition): StyleTargets
    {
        return StyleTargets::fromDeclaration($definition['style_targets']);
    }

    public function testEveryBlockEntersOnItsOutermostElementExceptTheOnesNobodySeesArrive(): void
    {
        // A tab's or an accordion item's panel is hidden until opened; a spacer is nothing to
        // see; animated text has an animation of its own.
        $without = ['tab', 'accordion_item', 'spacer', 'animated_text'];
        self::assertSame($without, StarterBlockTypes::NO_ENTRANCE);

        foreach ($this->bySlug() as $slug => $definition) {
            $declares = in_array('motion', $definition['style_capabilities'] ?? [], true);
            self::assertSame(!in_array($slug, $without, true), $declares, $slug);
            if (!$declares) {
                continue;
            }
            $targets = $this->targets($definition);
            foreach (['motion.entrance', 'motion.duration', 'motion.delay', 'motion.repeat'] as $path) {
                self::assertSame('root', $targets->targetFor($path), "{$slug}: {$path}");
            }
            // Still a valid declaration, item rule included (the item target is styled first).
            $caps = StyleCapabilities::fromDeclaration($definition['style_capabilities']);
            self::assertSame([], $targets->validateAgainst($caps), $slug);
        }
    }

    public function testStaggerIsTheContainersOnItsContentAreaWhereTheChildrenAre(): void
    {
        foreach ($this->bySlug() as $slug => $definition) {
            $declares = in_array('motion.children', $definition['style_capabilities'] ?? [], true);
            self::assertSame($slug === 'container', $declares, $slug);
        }
        self::assertSame('inner', $this->targets($this->bySlug()['container'])->targetFor('motion.stagger'));
    }

    public function testKenBurnsLandsOnTheFrameOfTheBlocksWhosePictureFillsOne(): void
    {
        // The frame's DIRECT child is what drifts: the container's background image is a direct
        // child of its root, the hero's picture of its media box. The Image block is NOT a frame:
        // its figure also holds the gutters and the caption, which a drifting picture would cover.
        $frames = ['container' => 'root', 'hero' => 'media'];
        foreach ($this->bySlug() as $slug => $definition) {
            $declares = in_array('motion.media', $definition['style_capabilities'] ?? [], true);
            self::assertSame(isset($frames[$slug]), $declares, $slug);
            if ($declares) {
                self::assertSame($frames[$slug], $this->targets($definition)->targetFor('motion.ken_burns'), $slug);
            }
        }
    }

    public function testABlockMadeInTheAdminCanEnterToo(): void
    {
        self::assertContains('motion', CustomBlockStyle::GROUPS);
        // Not stagger (its one target is not what arranges children) nor Ken Burns (its template's
        // structure is not known to have a picture as the root's direct child).
        self::assertNotContains('motion.children', CustomBlockStyle::GROUPS);
        self::assertNotContains('motion.media', CustomBlockStyle::GROUPS);
    }
}
