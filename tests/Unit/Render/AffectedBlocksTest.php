<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Fragments\AffectedBlocks;
use Thallo\Render\Fragments\DocumentIndex;

/** The affected blocks an apply's operations name, validated against both documents (spec §3.5). */
final class AffectedBlocksTest extends TestCase
{
    private const REGIONS = ['container' => ['content'], 'tabs' => ['items'], 'tab' => ['content']];

    /** @param array<string,mixed> $fields */
    private static function index(array $fields): DocumentIndex
    {
        return DocumentIndex::of($fields, ['body'], static fn (string $t): array => self::REGIONS[$t] ?? []);
    }

    /** @param list<array<string,mixed>> $content */
    private static function container(string $id, array $content): array
    {
        return ['id' => $id, 'type' => 'container', 'data' => ['content' => $content]];
    }

    private static function text(string $id, string $body = 'x'): array
    {
        return ['id' => $id, 'type' => 'rich_text', 'data' => ['body' => $body]];
    }

    public function testAValueChangeYieldsTheBlockItself(): void
    {
        $before = self::index(['body' => [self::container('c', [self::text('t')])]]);
        $after = self::index(['body' => [self::container('c', [self::text('t', 'edited')])]]);
        $ops = [['type' => 'SetField', 'block' => 't', 'field' => 'body']];
        self::assertSame(['t'], AffectedBlocks::derive($ops, $before, $after));

        $setting = [['type' => 'SetSetting', 'block' => 'c', 'path' => 'spacing.padding.top', 'breakpoint' => 'base']];
        self::assertSame(['c'], AffectedBlocks::derive($setting, $before, $after));
    }

    public function testADeletionYieldsTheFormerParent(): void
    {
        $before = self::index(['body' => [self::container('c', [self::text('t'), self::text('u')])]]);
        $after = self::index(['body' => [self::container('c', [self::text('u')])]]);
        $ops = [[
            'type' => 'RemoveBlock',
            'position' => ['parent' => 'c', 'slot' => 'content', 'index' => 0],
            'block' => self::text('t'),
        ]];
        self::assertSame(['c'], AffectedBlocks::derive($ops, $before, $after));
    }

    public function testACrossContainerMoveYieldsBothParents(): void
    {
        $before = self::index(['body' => [self::container('a', [self::text('t')]), self::container('b', [])]]);
        $after = self::index(['body' => [self::container('a', []), self::container('b', [self::text('t')])]]);
        $ops = [[
            'type' => 'MoveBlock',
            'block' => 't',
            'from' => ['parent' => 'a', 'slot' => 'content', 'index' => 0],
            'to' => ['parent' => 'b', 'slot' => 'content', 'index' => 0],
        ]];
        self::assertSame(['a', 'b'], AffectedBlocks::derive($ops, $before, $after), 'document order');

        // A move inside one container names that container once.
        $within = self::index(['body' => [self::container('a', [self::text('u'), self::text('t')])]]);
        $reordered = self::index(['body' => [self::container('a', [self::text('t'), self::text('u')])]]);
        $reorder = [[
            'type' => 'MoveBlock',
            'block' => 't',
            'from' => ['parent' => 'a', 'slot' => 'content', 'index' => 1],
            'to' => ['parent' => 'a', 'slot' => 'content', 'index' => 0],
        ]];
        self::assertSame(['a'], AffectedBlocks::derive($reorder, $within, $reordered));
    }

    public function testAnInsertYieldsTheParentAndTheWholePageAtTheRoot(): void
    {
        $before = self::index(['body' => [self::container('c', [])]]);
        $after = self::index(['body' => [self::container('c', [self::text('n')])]]);
        $insert = [[
            'type' => 'InsertBlock',
            'position' => ['parent' => 'c', 'slot' => 'content', 'index' => 0],
            'block' => self::text('n'),
        ]];
        self::assertSame(['c'], AffectedBlocks::derive($insert, $before, $after));

        $root = self::index(['body' => [self::container('c', []), self::text('r')]]);
        $atRoot = [[
            'type' => 'InsertBlock',
            'position' => ['parent' => null, 'slot' => 'body', 'index' => 1],
            'block' => self::text('r'),
        ]];
        self::assertNull(AffectedBlocks::derive($atRoot, $before, $root), 'a root-level structural change');
    }

    public function testOmittedOperationsPageSettingsAndAnUnknownOperationAreTheWholePage(): void
    {
        $doc = self::index(['body' => [self::container('c', [self::text('t')])]]);
        self::assertNull(AffectedBlocks::derive([], $doc, $doc));
        $edit = [['type' => 'SetField', 'block' => 't']];
        self::assertNull(AffectedBlocks::derive($edit, null, $doc), 'no accepted-before');
        self::assertNull(AffectedBlocks::derive([['type' => 'SetPageSettings', 'field' => 'title']], $doc, $doc));
        self::assertNull(AffectedBlocks::derive([['type' => 'Teleport', 'block' => 't']], $doc, $doc));
        // One whole-page operation among block ones takes the whole page.
        $mixed = [['type' => 'SetField', 'block' => 't'], ['type' => 'SetPageSettings', 'field' => 'title']];
        self::assertNull(AffectedBlocks::derive($mixed, $doc, $doc));
    }

    public function testAnOperationTheDocumentsDisagreeWithIsTheWholePage(): void
    {
        $before = self::index(['body' => [self::container('c', [self::text('t')])]]);
        $after = self::index(['body' => [self::container('c', [self::text('t')])]]);
        // A field change on a block that is not in the document.
        self::assertNull(AffectedBlocks::derive([['type' => 'SetField', 'block' => 'ghost']], $before, $after));
        // A removal whose block is still there.
        $remove = [[
            'type' => 'RemoveBlock',
            'position' => ['parent' => 'c', 'slot' => 'content', 'index' => 0],
            'block' => self::text('t'),
        ]];
        self::assertNull(AffectedBlocks::derive($remove, $before, $after));
        // An insert whose block did not land under the named parent.
        $insert = [[
            'type' => 'InsertBlock',
            'position' => ['parent' => 'c', 'slot' => 'content', 'index' => 1],
            'block' => self::text('n'),
        ]];
        self::assertNull(AffectedBlocks::derive($insert, $before, $after));
    }

    public function testTheIndexKnowsParentsAncestorsAndSubtrees(): void
    {
        $doc = self::index(['body' => [
            ['id' => 'tabs', 'type' => 'tabs', 'data' => ['items' => [
                ['id' => 'tab1', 'type' => 'tab', 'data' => ['label' => 'A', 'content' => [self::text('t1')]]],
            ]]],
            self::text('after'),
        ]]);
        self::assertSame(['tabs', 'tab1', 't1', 'after'], $doc->ids());
        self::assertSame('tab1', $doc->parentOf('t1'));
        self::assertSame(['tab1', 'tabs'], $doc->ancestors('t1'));
        self::assertSame(['tabs', 'tab1', 't1'], $doc->subtree('tabs'));
        self::assertSame('content', $doc->slotOf('t1'));
        self::assertNull($doc->parentOf('tabs'));
        self::assertSame(['tabs', 'tab', 'rich_text'], $doc->types());
    }
}
