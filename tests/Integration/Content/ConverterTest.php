<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\Conversion\ConversionOutcome;
use Thallo\Core\Content\Style\Conversion\ConversionRule;
use Thallo\Core\Content\Style\Conversion\ConversionStage;
use Thallo\Core\Content\Style\Conversion\ConversionStages;
use Thallo\Core\Content\Style\Conversion\ConversionTables;
use Thallo\Core\Content\Style\Conversion\Converter;
use Thallo\Core\Content\Style\Conversion\DecisionsFile;
use Thallo\Core\Content\Style\Conversion\DiagnosticsReport;
use Thallo\Core\Tests\Support\AppTestCase;

/** The converter (visual builder spec §7.2–7.3): explicit translations, decisions, collisions, stamps. */
final class ConverterTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
    }

    private function converter(): Converter
    {
        return new Converter(new BlockTypeRepository($this->connection()));
    }

    private function ref(array $fields, string $revision = 'r1'): DocumentRef
    {
        return new DocumentRef(
            'entry_draft',
            'entry0000001',
            'en',
            $revision,
            ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]),
            $fields,
        );
    }

    private function stage(): ConversionStage
    {
        return ConversionTables::presentationGroup1();
    }

    public function testExplicitTranslationsBecomeSettingsAndTheDocumentIsStamped(): void
    {
        $ref = $this->ref(['body' => [
            ['id' => 'h', 'type' => 'heading', 'data' => ['text' => 'Hi', 'align' => 'left']],
            ['id' => 'b', 'type' => 'button', 'data' => ['label' => 'Go', 'shape' => 'pill', 'block' => true]],
            ['id' => 'c', 'type' => 'carousel', 'data' => ['slides' => [], 'transition_duration' => 2.5]],
        ]]);
        $report = new DiagnosticsReport();
        $out = $this->converter()->convert($ref, [$this->stage()], new DecisionsFile(), $report);

        self::assertTrue($out->changed);
        self::assertSame(0, $out->unresolved);
        [$heading, $button, $carousel] = $out->fields['body'];
        self::assertArrayNotHasKey('align', $heading['data']);
        $alignment = $heading['settings']['style']['alignment']['text']['base'];
        self::assertSame(['type' => 'choice', 'value' => 'start'], $alignment);
        self::assertSame(['type' => 'token', 'value' => 'radius.full'], $button['settings']['style']['radius']);
        self::assertTrue($button['data']['block'], 'kept: block semantics stay in data');
        self::assertSame('slow', $carousel['data']['speed']);
        self::assertArrayNotHasKey('transition_duration', $carousel['data']);
        self::assertSame(['settings' => 1, 'conversions' => ['presentation-group-1']], $out->fields['_schema']);
        $statuses = array_column($report->lines(), 'status', 'field');
        self::assertSame(
            ['align' => 'converted', 'shape' => 'converted', 'block' => 'kept', 'transition_duration' => 'converted'],
            $statuses,
        );
    }

    public function testAnUnmappableValueNeedsADecisionAndAStaleDecisionIsRejected(): void
    {
        $ref = $this->ref(['body' => [
            ['id' => 'i', 'type' => 'image', 'data' => ['image' => 'blob00000000', 'width' => 320]],
        ]]);
        $report = new DiagnosticsReport();
        $out = $this->converter()->convert($ref, [$this->stage()], new DecisionsFile(), $report);
        self::assertSame(1, $out->unresolved);
        self::assertSame(320, $out->fields['body'][0]['data']['width'], 'untouched until decided');
        $line = $report->unresolved()[0];
        self::assertSame('unmappable', $line['status']);
        self::assertSame(Converter::hash($ref->fields), $line['document_hash']);

        $key = DecisionsFile::key('entry_draft', 'entry0000001', 'r1', 'i', 'width');
        $decisions = new DecisionsFile([$key => [
            'document_hash' => $line['document_hash'],
            'converter_version' => Converter::VERSION,
            'action' => 'token',
            'value' => 'width.container',
        ]]);
        $decided = $this->converter()->convert($ref, [$this->stage()], $decisions, new DiagnosticsReport());
        self::assertSame(0, $decided->unresolved);
        $width = $decided->fields['body'][0]['settings']['style']['width']['base'];
        self::assertSame(['type' => 'token', 'value' => 'width.container'], $width);
        self::assertArrayNotHasKey('width', $decided->fields['body'][0]['data']);

        // Stale by hash: the document changed since review.
        $changed = $this->ref(['body' => [
            ['id' => 'i', 'type' => 'image', 'data' => ['image' => 'blob00000000', 'width' => 321]],
        ]]);
        $stale = new DiagnosticsReport();
        self::assertSame(1, $this->converter()->convert($changed, [$this->stage()], $decisions, $stale)->unresolved);
        self::assertStringContainsString('stale', (string) $stale->lines()[0]['reason']);
        // Stale by converter version.
        $older = new DecisionsFile([$key => [
            'document_hash' => $line['document_hash'],
            'converter_version' => Converter::VERSION + 1,
            'action' => 'token',
            'value' => 'width.container',
        ]]);
        $byVersion = $this->converter()->convert($ref, [$this->stage()], $older, new DiagnosticsReport());
        self::assertSame(1, $byVersion->unresolved);
        // A discard decision removes the field.
        $discard = new DecisionsFile([$key => [
            'document_hash' => $line['document_hash'],
            'converter_version' => Converter::VERSION,
            'action' => 'discard',
        ]]);
        $dropped = $this->converter()->convert($ref, [$this->stage()], $discard, $r = new DiagnosticsReport());
        self::assertArrayNotHasKey('width', $dropped->fields['body'][0]['data']);
        self::assertSame('discarded', $r->lines()[0]['status']);
    }

    public function testADecidedTokenLandsInDataForAnimatedTextColours(): void
    {
        $ref = $this->ref(['body' => [
            ['id' => 'a', 'type' => 'animated_text', 'data' => ['prefix' => 'x', 'prefix_color' => '#112233']],
        ]]);
        $line = null;
        $report = new DiagnosticsReport();
        $this->converter()->convert($ref, [$this->stage()], new DecisionsFile(), $report);
        $line = $report->lines()[0];
        $key = DecisionsFile::key('entry_draft', 'entry0000001', 'r1', 'a', 'prefix_color');
        $decisions = new DecisionsFile([$key => [
            'document_hash' => $line['document_hash'],
            'converter_version' => Converter::VERSION,
            'action' => 'token',
            'value' => 'color.accent',
        ]]);
        $out = $this->converter()->convert($ref, [$this->stage()], $decisions, new DiagnosticsReport());
        $prefix = $out->fields['body'][0]['data']['prefix_color'];
        self::assertSame(['type' => 'token', 'value' => 'color.accent'], $prefix);
        self::assertArrayNotHasKey('settings', $out->fields['body'][0]);
    }

    public function testAnExplicitSettingsValueWinsAndTheLegacyFieldIsSuperseded(): void
    {
        $ref = $this->ref(['body' => [
            ['id' => 'h', 'type' => 'heading', 'data' => ['text' => 'Hi', 'align' => 'left'], 'settings' => [
                'style' => ['alignment' => ['text' => ['base' => ['type' => 'choice', 'value' => 'center']]]],
            ]],
        ]]);
        $report = new DiagnosticsReport();
        $out = $this->converter()->convert($ref, [$this->stage()], new DecisionsFile(), $report);
        self::assertSame('center', $out->fields['body'][0]['settings']['style']['alignment']['text']['base']['value']);
        self::assertArrayNotHasKey('align', $out->fields['body'][0]['data']);
        self::assertSame('superseded', $report->lines()[0]['status']);
    }

    public function testNestedRegionsConvertAndCompletedStagesArePending(): void
    {
        $ref = $this->ref([
            'body' => [
                ['id' => 's', 'type' => 'section', 'data' => ['content' => [
                    ['id' => 'h', 'type' => 'heading', 'data' => ['text' => 'Deep', 'align' => 'center']],
                ]]],
            ],
            '_schema' => ['settings' => 1, 'conversions' => ['presentation-group-1']],
        ]);
        $stages = new ConversionStages(
            ConversionTables::presentationGroup1(),
            new ConversionStage('later', [
                ConversionRule::convert(
                    'heading',
                    'align',
                    static fn (): ConversionOutcome => ConversionOutcome::discard(),
                ),
            ]),
        );
        $pending = $stages->pending($ref->fields);
        self::assertSame(['later'], array_map(static fn (ConversionStage $s): string => $s->name, $pending));
        $out = $this->converter()->convert($ref, $pending, new DecisionsFile(), new DiagnosticsReport());
        self::assertArrayNotHasKey('align', $out->fields['body'][0]['data']['content'][0]['data']);
        self::assertSame(['presentation-group-1', 'later'], $out->fields['_schema']['conversions']);
        self::assertSame([], $stages->pending($out->fields), 'a rerun with no pending stage is a no-op');
    }

    public function testGroupTwoConvertsTheContainerAndTheStyleBlock(): void
    {
        $stage = ConversionTables::presentationGroup2();
        $ref = $this->ref(['body' => [
            ['id' => 'c', 'type' => 'container', 'data' => [
                'padding_preset' => 'small', 'overlay_opacity' => 40, 'border_style' => 'dotted',
                'border_width' => 2, 'shadow' => '2xl', 'bg_repeat' => 'repeat',
                'padding' => ['top' => 17, 'right' => 17], 'content' => [],
            ]],
            ['id' => 's', 'type' => 'style', 'data' => [
                'padding' => 'medium', 'shadow' => 'md', 'class_hook' => 'promo  wide', 'shadow_opacity' => 40,
                'content' => [],
            ]],
        ]]);
        $report = new DiagnosticsReport();
        $out = $this->converter()->convert($ref, [$stage], new DecisionsFile(), $report);
        self::assertSame(2, $out->unresolved, 'the pixel box and the shadow opacity need decisions');
        self::assertSame([ConversionTables::GROUP_2], array_unique(array_column($report->lines(), 'stage')));
        [$container, $style] = $out->fields['body'];
        $cs = $container['settings']['style'];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertSame('spacing.lg', $cs['spacing']['padding'][$side]['base']['value'], $side);
        }
        self::assertSame('50', $container['data']['overlay_opacity']);
        self::assertSame('dashed', $cs['border']['style']['value']);
        self::assertSame('thick', $cs['border']['width']['value']);
        self::assertSame('shadow.xl', $cs['shadow']['base']['value']);
        self::assertArrayNotHasKey('bg_repeat', $container['data']);
        self::assertSame(['top' => 17, 'right' => 17], $container['data']['padding'], 'undecided: untouched');
        $ss = $style['settings'];
        self::assertSame('spacing.lg', $ss['style']['spacing']['padding']['left']['base']['value']);
        self::assertSame('shadow.md', $ss['style']['shadow']['base']['value']);
        self::assertSame(['promo', 'wide'], $ss['advanced']['css_classes']);
        self::assertArrayNotHasKey('class_hook', $style['data']);

        // A box decision styles every side of the box.
        $hash = Converter::hash($ref->fields);
        $decisions = new DecisionsFile([
            DecisionsFile::key('entry_draft', 'entry0000001', 'r1', 'c', 'padding') => [
                'document_hash' => $hash, 'converter_version' => Converter::VERSION,
                'action' => 'token', 'value' => 'spacing.xs',
            ],
            DecisionsFile::key('entry_draft', 'entry0000001', 'r1', 's', 'shadow_opacity') => [
                'document_hash' => $hash, 'converter_version' => Converter::VERSION, 'action' => 'discard',
            ],
        ]);
        $decided = $this->converter()->convert($ref, [$stage], $decisions, new DiagnosticsReport());
        self::assertSame(0, $decided->unresolved);
        // The box decision is superseded by the preset that already landed on every side.
        $padding = $decided->fields['body'][0]['settings']['style']['spacing']['padding'];
        self::assertSame('spacing.lg', $padding['top']['base']['value']);
        self::assertArrayNotHasKey('padding', $decided->fields['body'][0]['data']);
    }
}
