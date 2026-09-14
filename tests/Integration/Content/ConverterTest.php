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

    /** A stage over the shipped block types: every outcome kind, every decision target shape. */
    private function stage(): ConversionStage
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        return new ConversionStage('test-stage', [
            ConversionRule::convert(
                'heading',
                'align',
                static fn (mixed $v): ConversionOutcome => match ($v) {
                    'left' => ConversionOutcome::setting('alignment.text', $choice('start')),
                    'center' => ConversionOutcome::setting('alignment.text', $choice('center')),
                    default => ConversionOutcome::discard(),
                },
            ),
            ConversionRule::unmappable('heading', 'color', 'a raw hex colour', 'color', 'setting:colors.text'),
            ConversionRule::convert(
                'button',
                'shape',
                static fn (mixed $v): ConversionOutcome => $v === 'pill'
                    ? ConversionOutcome::setting('radius', $token('radius.full'))
                    : ConversionOutcome::discard(),
            ),
            ConversionRule::keep('button', 'block'),
            ConversionRule::convert(
                'carousel',
                'transition_duration',
                static fn (mixed $v): ConversionOutcome => ConversionOutcome::data(
                    'speed',
                    is_numeric($v) && (float) $v >= 2 ? 'slow' : 'normal',
                ),
            ),
            ConversionRule::unmappable('image', 'width', 'a pixel width', 'width', 'setting:width'),
            ConversionRule::unmappable('animated_text', 'prefix_color', 'a hex colour', 'color', 'data:prefix_color'),
            ConversionRule::convert(
                'container',
                'padding_preset',
                static fn (mixed $v): ConversionOutcome => $v === 'small'
                    ? ConversionOutcome::setting('spacing.padding', $token('spacing.lg'))
                    : ConversionOutcome::discard(),
            ),
            ConversionRule::unmappable('container', 'padding', 'a pixel box', 'spacing', 'setting:spacing.padding'),
            ConversionRule::convert('style', 'class_hook', static function (mixed $v): ConversionOutcome {
                $names = is_string($v) ? array_values(array_filter(preg_split('/\\s+/', trim($v)) ?: [])) : [];
                return $names === []
                    ? ConversionOutcome::discard()
                    : ConversionOutcome::advanced('css_classes', $names);
            }),
            ConversionRule::unmappable('style', 'shadow_opacity', 'not a managed property'),
        ], [
            'heading' => ['align', 'color'],
            'button' => ['shape'],
            'carousel' => ['transition_duration'],
            'image' => ['width'],
            'container' => ['padding_preset', 'padding'],
            'style' => ['class_hook', 'shadow_opacity'],
        ]);
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
        self::assertSame(['settings' => 1, 'conversions' => ['test-stage']], $out->fields['_schema']);
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
            '_schema' => ['settings' => 1, 'conversions' => ['test-stage']],
        ]);
        $stages = new ConversionStages(
            $this->stage(),
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
        self::assertSame(['test-stage', 'later'], $out->fields['_schema']['conversions']);
        self::assertSame([], $stages->pending($out->fields), 'a rerun with no pending stage is a no-op');
    }

    public function testABoxDecisionStylesEverySideAndAHookBecomesCssClasses(): void
    {
        $ref = $this->ref(['body' => [
            ['id' => 'c', 'type' => 'container', 'data' => [
                'padding_preset' => 'small', 'padding' => ['top' => 17, 'right' => 17], 'content' => [],
            ]],
            ['id' => 's', 'type' => 'style', 'data' => [
                'class_hook' => 'promo  wide', 'shadow_opacity' => 40, 'content' => [],
            ]],
        ]]);
        $report = new DiagnosticsReport();
        $out = $this->converter()->convert($ref, [$this->stage()], new DecisionsFile(), $report);
        self::assertSame(2, $out->unresolved, 'the pixel box and the shadow opacity need decisions');
        self::assertSame(['test-stage'], array_unique(array_column($report->lines(), 'stage')));
        [$container, $style] = $out->fields['body'];
        $cs = $container['settings']['style'];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertSame('spacing.lg', $cs['spacing']['padding'][$side]['base']['value'], $side);
        }
        self::assertSame(['top' => 17, 'right' => 17], $container['data']['padding'], 'undecided: untouched');
        self::assertSame(['promo', 'wide'], $style['settings']['advanced']['css_classes']);
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
        $decided = $this->converter()->convert($ref, [$this->stage()], $decisions, new DiagnosticsReport());
        self::assertSame(0, $decided->unresolved);
        // The box decision is superseded by the preset that already landed on every side.
        $padding = $decided->fields['body'][0]['settings']['style']['spacing']['padding'];
        self::assertSame('spacing.lg', $padding['top']['base']['value']);
        self::assertArrayNotHasKey('padding', $decided->fields['body'][0]['data']);
        self::assertArrayNotHasKey('shadow_opacity', $decided->fields['body'][1]['data']);
    }
}
