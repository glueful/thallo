<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Palette;

use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/** Custom palette spec §4.1, §4.5: every colour-token location, named once, by block identity. */
final class ColorTokenWalkerTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array{type: string, value: string} */
    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    private function walker(): ColorTokenWalker
    {
        return $this->container()->get(ColorTokenWalker::class);
    }

    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
    }

    public function testItFindsStylePartsHoverDataNestedAndPageLocations(): void
    {
        $doc = [
            'body' => [[
                'id' => 'cont00000001',
                'type' => 'container',
                'settings' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]],
                'data' => ['content' => [[
                    'id' => 'link00000001',
                    'type' => 'links',
                    'settings' => ['parts' => ['link' => [
                        'hover' => ['colors' => ['surface' => self::tok('color.brand-2')]],
                    ]]],
                    'data' => ['links' => []],
                ], [
                    'id' => 'anim00000001',
                    'type' => 'animated_text',
                    'data' => [
                        'prefix_color' => self::tok('color.brand-3'),
                        'suffix_color' => self::tok('color.accent'),
                        'prefix' => 'color.brand-1',
                    ],
                ]]],
            ]],
            '_presentation' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1-contrast')]]],
        ];
        self::assertSame([
            'cont00000001:settings.style.colors.surface' => 'color.brand-1',
            'link00000001:settings.parts.link.hover.colors.surface' => 'color.brand-2',
            'anim00000001:data.prefix_color' => 'color.brand-3',
            'anim00000001:data.suffix_color' => 'color.accent',
            '_presentation.style.colors.surface' => 'color.brand-1-contrast',
        ], $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, $doc, $this->schema()), 'a plain string: no token');
    }

    public function testALocationFollowsItsBlockThroughMovesAndIndexShifts(): void
    {
        $a = ['id' => 'head0000000a', 'type' => 'heading', 'data' => ['text' => 'A'],
            'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]];
        $b = ['id' => 'head0000000b', 'type' => 'heading', 'data' => ['text' => 'B'],
            'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]];
        $before = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$a, $b]], $this->schema());
        $reordered = $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$b, $a]], $this->schema());
        self::assertEquals($before, $reordered, 'same blocks, same colours, same locations');
        self::assertSame(
            ['head0000000b:settings.style.colors.text' => 'color.brand-1'],
            $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, ['body' => [$b]], $this->schema()),
        );
    }

    public function testABlockWithoutAnIdFallsBackToItsIndexPath(): void
    {
        $doc = ['body' => [['type' => 'heading', 'data' => ['text' => 'x'],
            'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]]]]];
        self::assertSame(
            ['body.0.settings.style.colors.text' => 'color.brand-1'],
            $this->walker()->tokens(ColorTokenWalker::KIND_ENTRY, $doc, $this->schema()),
        );
    }

    public function testMapRewritesOnlyWhatTheCallbackReplaces(): void
    {
        $doc = ['settings' => ['style' => ['colors' => [
            'text' => self::tok('color.brand-1'), 'surface' => self::tok('color.surface'),
        ]]], 'blocks' => []];
        $out = $this->walker()->map(
            ColorTokenWalker::KIND_REGION,
            $doc,
            static fn (string $loc, string $t): ?string => $t === 'color.brand-1' ? 'color.accent' : null,
        );
        self::assertSame('color.accent', $out['settings']['style']['colors']['text']['value']);
        self::assertSame('color.surface', $out['settings']['style']['colors']['surface']['value']);
    }

    public function testStyleClassesAndSavedSections(): void
    {
        $class = ['style' => ['hover' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        self::assertSame(
            ['style.hover.colors.text' => 'color.brand-2'],
            $this->walker()->tokens(ColorTokenWalker::KIND_CLASS, $class),
        );
        $section = ['blocks' => [['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'x'],
            'settings' => ['style' => ['colors' => ['text' => self::tok('color.brand-3')]]]]]];
        self::assertTrue($this->walker()->hasBrand(ColorTokenWalker::KIND_SECTION, $section));
        self::assertFalse($this->walker()->hasBrand(ColorTokenWalker::KIND_CLASS, ['style' => []]));
    }
}
