<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder plan A1.2: block settings are validated against the style contract and the
 * block's capabilities; while a block type carries `legacy_presentation`, managed style is
 * refused so a setting can never compete with a legacy field.
 */
final class BlockSettingsValidationTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The starter library is the fixture: the shipped block types with their declarations.
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
    }

    /** @param array<string, array{caps?: list<string>, legacy?: bool}> $types */
    private function validator(array $types): FieldValidator
    {
        $registry = new class ($types) implements BlockStyleRegistry {
            /** @param array<string, array{caps?: list<string>, legacy?: bool}> $types */
            public function __construct(private array $types)
            {
            }
            public function capabilitiesFor(string $type): StyleCapabilities
            {
                return StyleCapabilities::fromDeclaration($this->types[$type]['caps'] ?? null);
            }
            public function targetsFor(string $type): ?StyleTargets
            {
                return null;
            }
            public function flagsFor(string $type): array
            {
                return ['legacy_presentation' => (bool) ($this->types[$type]['legacy'] ?? false)];
            }
        };
        return new FieldValidator($this->connection(), $this->appContext(), null, null, $registry);
    }

    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
    }

    /** @return array<string,mixed> */
    private function heading(?array $settings = null): array
    {
        $block = ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi']];
        if ($settings !== null) {
            $block['settings'] = $settings;
        }
        return $block;
    }

    public function testOmittedSettingsNormaliseToAnEmptyObjectOnEveryBlock(): void
    {
        $clean = $this->validator(['heading' => []])->validate($this->schema(), ['body' => [$this->heading()]]);

        self::assertSame(['id', 'type', 'data', 'settings'], array_keys($clean['body'][0]));
        self::assertSame([], $clean['body'][0]['settings']);
    }

    public function testStyleIsRefusedWithoutCapabilitiesAndWhileLegacyPresentationHolds(): void
    {
        $style = ['style' => [
            'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]],
        ]];

        try {
            $this->validator(['heading' => []])->validate($this->schema(), ['body' => [$this->heading($style)]]);
            self::fail('accepted without capabilities');
        } catch (ValidationException $e) {
            self::assertSame(
                ['body.0.settings.style.spacing.padding.top' => 'not styleable on this block'],
                $e->errors(),
            );
        }

        try {
            $this->validator(['heading' => ['caps' => ['spacing'], 'legacy' => true]])
                ->validate($this->schema(), ['body' => [$this->heading($style)]]);
            self::fail('accepted while legacy presentation holds');
        } catch (ValidationException $e) {
            self::assertSame(
                ['body.0.settings.style' => 'styling for this block arrives with its conversion'],
                $e->errors(),
            );
        }

        // Advanced is fine even while legacy presentation holds.
        $clean = $this->validator(['heading' => ['caps' => ['spacing'], 'legacy' => true]])
            ->validate($this->schema(), ['body' => [$this->heading(['advanced' => ['anchor' => 'top']])]]);
        self::assertSame(['advanced' => ['anchor' => 'top']], $clean['body'][0]['settings']);
    }

    public function testKindsResponsivenessAndResetFollowTheContract(): void
    {
        $v = $this->validator(['heading' => ['caps' => ['spacing', 'visibility', 'radius', 'colors.text']]]);

        $ok = $v->validate($this->schema(), ['body' => [$this->heading(['style' => [
            'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]],
            'visibility' => ['base' => ['type' => 'choice', 'value' => 'hidden'], 'lg' => ['type' => 'reset']],
            'radius' => ['type' => 'token', 'value' => 'radius.md'],
            'colors' => ['text' => ['type' => 'reset']],
        ]])]]);
        self::assertSame('spacing.lg', $ok['body'][0]['settings']['style']['spacing']['padding']['top']['md']['value']);
        self::assertSame('reset', $ok['body'][0]['settings']['style']['visibility']['lg']['type']);
        self::assertSame('reset', $ok['body'][0]['settings']['style']['colors']['text']['type']);

        $cases = [
            [
                ['visibility' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]],
                'body.0.settings.style.visibility.base',
                'expects a choice',
            ],
            [
                ['visibility' => ['base' => ['type' => 'choice', 'value' => 'gone']]],
                'body.0.settings.style.visibility.base',
                'must be one of visible, hidden',
            ],
            [
                ['radius' => ['md' => ['type' => 'token', 'value' => 'radius.md']]],
                'body.0.settings.style.radius',
                'is not responsive',
            ],
            [
                ['radius' => ['type' => 'literal', 'value' => '37px']],
                'body.0.settings.style.radius',
                'reserved value kind',
            ],
            [
                ['radius' => ['type' => 'token', 'value' => 'spacing.lg']],
                'body.0.settings.style.radius',
                'expects a radius token',
            ],
            [
                ['radius' => ['type' => 'token', 'value' => 'radius.huge']],
                'body.0.settings.style.radius',
                'unknown token',
            ],
            [
                ['spacing' => ['padding' => ['top' => ['xl' => ['type' => 'token', 'value' => 'spacing.lg']]]]],
                'body.0.settings.style.spacing.padding.top',
                'unknown breakpoint "xl"',
            ],
            [
                ['spacing' => ['gap' => ['type' => 'token', 'value' => 'spacing.lg']]],
                'body.0.settings.style.spacing.gap',
                'unknown style property',
            ],
            [
                ['shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.md']]],
                'body.0.settings.style.shadow',
                'not styleable on this block',
            ],
        ];
        foreach ($cases as [$style, $path, $message]) {
            try {
                $v->validate($this->schema(), ['body' => [$this->heading(['style' => $style])]]);
                self::fail("accepted: {$path}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($path, $e->errors(), json_encode($e->errors()));
                self::assertStringContainsString($message, $e->errors()[$path]);
            }
        }
    }

    public function testAdvancedAndClassesAreValidated(): void
    {
        $v = $this->validator(['heading' => ['caps' => ['spacing']]]);

        $ok = $v->validate($this->schema(), ['body' => [$this->heading([
            'classes' => ['zeta', 'alpha'],
            'advanced' => [
                'anchor' => 'pricing',
                'css_classes' => ['hero', 'is-dark'],
                'attributes' => ['data-track' => 'hero-cta'],
                'accessibility' => ['label' => 'Pricing'],
            ],
        ])]]);
        self::assertSame(['zeta', 'alpha'], $ok['body'][0]['settings']['classes'], 'order preserved');
        self::assertSame('hero-cta', $ok['body'][0]['settings']['advanced']['attributes']['data-track']);

        $cases = [
            [['advanced' => ['anchor' => 'Pricing Table']], 'body.0.settings.advanced.anchor', 'must be a slug'],
            [
                ['advanced' => ['css_classes' => ['a b!']]],
                'body.0.settings.advanced.css_classes.0',
                'must be a class name',
            ],
            [
                ['advanced' => ['attributes' => ['data-thallo-x' => '1']]],
                'body.0.settings.advanced.attributes.data-thallo-x',
                'reserved prefix',
            ],
            [
                ['advanced' => ['attributes' => ['onclick' => '1']]],
                'body.0.settings.advanced.attributes.onclick',
                'must be a data-* name',
            ],
            [['classes' => 'card'], 'body.0.settings.classes', 'must be a list of style class ids'],
            [['classes' => ['card', 7]], 'body.0.settings.classes.1', 'must be a style class id'],
            [['nope' => []], 'body.0.settings.nope', 'unknown settings key'],
        ];
        foreach ($cases as [$settings, $path, $message]) {
            try {
                $v->validate($this->schema(), ['body' => [$this->heading($settings)]]);
                self::fail("accepted: {$path}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($path, $e->errors(), json_encode($e->errors()));
                self::assertStringContainsString($message, $e->errors()[$path]);
            }
        }
    }

    public function testNestedBlocksAreValidatedToo(): void
    {
        $v = $this->validator(['section' => ['caps' => ['spacing']], 'heading' => []]);
        $section = [
            'id' => 'sect00000001',
            'type' => 'section',
            'data' => ['content' => [
                $this->heading(['style' => ['radius' => ['type' => 'token', 'value' => 'radius.md']]]),
            ]],
        ];
        try {
            $v->validate($this->schema(), ['body' => [$section]]);
            self::fail('nested style accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.content.0.settings.style.radius', $e->errors());
        }
    }
}
