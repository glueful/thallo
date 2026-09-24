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
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Style\StyleClassSnapshot;

/**
 * Visual builder plan A1.2: block settings are validated against the style contract and the
 * block's capabilities; managed style is
 * refused without a capability.
 */
final class BlockSettingsValidationTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        // The starter library is the fixture: the shipped block types with their declarations,
        // brought up to date so a stale row in the test database cannot stand in for one of them.
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string, array{caps?: list<string>}> $types */
    private function validator(array $types, ?StyleClassProvider $classes = null): FieldValidator
    {
        return new FieldValidator(
            $this->connection(),
            $this->appContext(),
            null,
            null,
            $this->registry($types),
            styleClasses: $classes,
        );
    }

    /** @param array<string, array{caps?: list<string>}> $types */
    private function registry(array $types): BlockStyleRegistry
    {
        return new class ($types) implements BlockStyleRegistry {
            /** @param array<string, array{caps?: list<string>}> $types */
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
                return [];
            }
            public function regionsFor(string $type): array
            {
                return [];
            }
        };
    }

    /** @param list<string> $owned ids the site owns (`archived` ones prefixed with `~`) */
    private function validatorOwning(array $owned): FieldValidator
    {
        $classes = [];
        foreach ($owned as $id) {
            $archived = str_starts_with($id, '~');
            $id = ltrim($id, '~');
            $classes[$id] = ['id' => $id, 'name' => ucfirst($id), 'style' => [], 'archived' => $archived];
        }
        $provider = new class (new StyleClassSnapshot(3, $classes)) implements StyleClassProvider {
            public function __construct(private StyleClassSnapshot $snapshot)
            {
            }

            public function snapshot(): StyleClassSnapshot
            {
                return $this->snapshot;
            }

            public function refresh(): void
            {
            }
        };
        return $this->validator(['heading' => ['caps' => ['spacing']]], $provider);
    }

    public function testClassReferencesAreValidatedForOwnershipNotExistence(): void
    {
        $v = $this->validatorOwning(['band', '~old']);
        $ok = $v->validate($this->schema(), ['body' => [$this->heading(['classes' => ['old', 'band']])]]);
        self::assertSame(['old', 'band'], $ok['body'][0]['settings']['classes'], 'an archived owned class is valid');

        $cases = [
            [['classes' => ['band', 'foreign']], 'body.0.settings.classes.1', 'unknown style class'],
            [['classes' => ['band', 'band']], 'body.0.settings.classes.1', 'listed twice'],
        ];
        foreach ($cases as [$settings, $path, $message]) {
            try {
                $v->validate($this->schema(), ['body' => [$this->heading($settings)]]);
                self::fail("expected {$path}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($path, $e->errors(), json_encode($e->errors()));
                self::assertStringContainsString($message, $e->errors()[$path]);
            }
        }
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

    public function testStyleIsRefusedWithoutCapabilitiesAndAdvancedNeedsNone(): void
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

        $clean = $this->validator(['heading' => []])
            ->validate($this->schema(), ['body' => [$this->heading(['advanced' => ['anchor' => 'top']])]]);
        self::assertSame(['advanced' => ['anchor' => 'top']], $clean['body'][0]['settings']);
    }

    public function testARetiredPropertyIsDroppedOnSaveNotRefused(): void
    {
        // `aside.padding` was one value for all four sides in 1.0.0-beta.56 and 57; it became four
        // side paths. A page that stored the old one still saves: the value is dropped, not refused.
        $lg = ['type' => 'token', 'value' => 'spacing.lg'];
        $style = ['style' => ['aside' => ['padding' => ['lg' => ['type' => 'token', 'value' => 'spacing.none']]]]];
        $clean = $this->validator(['heading' => ['caps' => ['aside']]])
            ->validate($this->schema(), ['body' => [$this->heading($style)]]);
        self::assertSame([], $clean['body'][0]['settings']);

        // The sides take its place, and are kept.
        $sides = ['style' => ['aside' => ['padding' => ['top' => ['md' => $lg], 'left' => ['md' => $lg]]]]];
        $clean = $this->validator(['heading' => ['caps' => ['aside']]])
            ->validate($this->schema(), ['body' => [$this->heading($sides)]]);
        self::assertSame($sides, $clean['body'][0]['settings']);
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

    public function testLayoutSettingsFollowTheContractAndTheDeclaredCapabilities(): void
    {
        // Container-layout plan, Task 1.1: layout is validated like every other managed style.
        $v = $this->validator(['heading' => ['caps' => ['layout.display', 'layout.columns', 'layout.overflow']]]);

        $ok = $v->validate($this->schema(), ['body' => [$this->heading(['style' => [
            'layout' => [
                'display' => [
                    'base' => ['type' => 'choice', 'value' => 'flex'],
                    'md' => ['type' => 'choice', 'value' => 'grid'],
                ],
                'columns' => ['md' => ['type' => 'choice', 'value' => '1-2']],
                'overflow' => ['type' => 'choice', 'value' => 'hidden'],
            ],
        ]])]]);
        $style = $ok['body'][0]['settings']['style']['layout'];
        self::assertSame('grid', $style['display']['md']['value']);
        self::assertSame('1-2', $style['columns']['md']['value']);
        self::assertSame('hidden', $style['overflow']['value']);

        $cases = [
            [
                ['layout' => ['display' => ['base' => ['type' => 'choice', 'value' => 'table']]]],
                'body.0.settings.style.layout.display.base',
                'must be one of flex, grid',
            ],
            [
                // Flex and Grid only (spec §11.1): the block display one release offered is not
                // a value, at any breakpoint, and nothing maps it to one.
                ['layout' => ['display' => ['md' => ['type' => 'choice', 'value' => 'block']]]],
                'body.0.settings.style.layout.display.md',
                'must be one of flex, grid',
            ],
            [
                // Not responsive: an overflow that changed with the viewport would hide content
                // at one width and not another.
                ['layout' => ['overflow' => ['md' => ['type' => 'choice', 'value' => 'hidden']]]],
                'body.0.settings.style.layout.overflow',
                'is not responsive',
            ],
            [
                // A property the block type does not declare is refused, layout included.
                ['layout' => ['gap' => ['column' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]]],
                'body.0.settings.style.layout.gap.column',
                'not styleable on this block',
            ],
        ];
        foreach ($cases as [$style, $path, $message]) {
            try {
                $v->validate($this->schema(), ['body' => [$this->heading(['style' => $style])]]);
                self::fail('expected ValidationException for ' . $path);
            } catch (ValidationException $e) {
                self::assertArrayHasKey($path, $e->errors(), $path);
                self::assertStringContainsString($message, $e->errors()[$path], $path);
            }
        }
    }

    public function testAContainerTakesLayoutSettingsOnTheTargetThatOwnsThem(): void
    {
        // Container-layout spec §4: after the cutover the band's own box keeps min height and
        // overflow, while everything that arranges the children belongs to the content area. The
        // real seeded declaration is the fixture — a hand-written registry would prove nothing.
        $registry = $this->container()->get(BlockStyleRegistry::class);
        $targets = $registry->targetsFor('container');
        self::assertNotNull($targets);
        self::assertSame(['root', 'inner'], $targets->names());
        self::assertSame('inner', $targets->targetFor('layout.display'));
        self::assertSame('inner', $targets->targetFor('alignment.content'));
        self::assertSame('root', $targets->targetFor('layout.min_height'));
        self::assertSame('root', $targets->targetFor('layout.span'));

        $v = new FieldValidator(
            $this->connection(),
            $this->appContext(),
            null,
            null,
            $registry,
        );
        $container = static fn (array $style): array => [
            'id' => 'cont00000001',
            'type' => 'container',
            'data' => ['content' => []],
            'settings' => ['style' => $style],
        ];
        $ok = $v->validate($this->schema(), ['body' => [$container([
            'layout' => [
                'display' => ['base' => ['type' => 'choice', 'value' => 'grid']],
                'columns' => ['md' => ['type' => 'choice', 'value' => '1-2']],
                'min_height' => ['base' => ['type' => 'choice', 'value' => 'half']],
            ],
        ])]]);
        $style = $ok['body'][0]['settings']['style']['layout'];
        self::assertSame('grid', $style['display']['base']['value']);
        self::assertSame('1-2', $style['columns']['md']['value']);
        self::assertSame('half', $style['min_height']['base']['value']);

        // A property no target owns is still refused: the container declares no typography.
        try {
            $v->validate($this->schema(), ['body' => [$container([
                'typography' => ['size' => ['base' => ['type' => 'token', 'value' => 'font.lg']]],
            ])]]);
            self::fail('a container accepted a property it does not declare');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.settings.style.typography.size', $e->errors());
            self::assertStringContainsString(
                'not styleable on this block',
                $e->errors()['body.0.settings.style.typography.size'],
            );
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
        $v = $this->validator(['container' => ['caps' => ['spacing']], 'heading' => []]);
        $section = [
            'id' => 'sect00000001',
            'type' => 'container',
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
