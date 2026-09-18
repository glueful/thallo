<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §1.7: every shipped block type — the starter library and every pack
 * contribution — declares its capabilities and targets, and the declaration is internally
 * consistent (every capability lands on a target of the right kind, advanced paths have one
 * owner, the root target is never optional).
 */
final class BlockStyleDeclarationsTest extends AppTestCase
{
    private const RENDERS_CHILDREN_INLINE = ['accordion', 'tabs', 'stepper', 'gallery', 'pricing_table', 'carousel'];

    public function testEveryShippedBlockTypeDeclaresAConsistentStyleContract(): void
    {
        $definitions = $this->container()->get(BlockTypeKind::class)->definitions();
        self::assertGreaterThanOrEqual(53, count($definitions), 'starters and the two packs');
        foreach ($definitions as $definition) {
            $payload = $definition->payload;
            $slug = (string) $payload['slug'];
            self::assertIsArray($payload['style_capabilities'] ?? null, "{$slug} declares caps");
            self::assertIsArray($payload['style_targets'] ?? null, "{$slug} declares targets");
            $caps = StyleCapabilities::fromDeclaration($payload['style_capabilities']);
            $targets = StyleTargets::fromDeclaration($payload['style_targets']);
            self::assertSame([], $targets->validateAgainst($caps), $slug);
            self::assertContains('root', $targets->names(), "{$slug} has a root target");
            self::assertFalse($targets->optional('root'), "{$slug}: the root is never optional");
            self::assertSame('root', $targets->targetFor('advanced.css_classes'), "{$slug}: classes on root");
            self::assertTrue($caps->allows('visibility'), "{$slug} can be hidden per breakpoint");
            $inline = (bool) ($payload['flags']['renders_children_inline'] ?? false);
            $expected = in_array($slug, self::RENDERS_CHILDREN_INLINE, true);
            self::assertSame($expected, $inline, "{$slug} renders_children_inline");
        }
    }
}
