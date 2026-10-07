<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Style\SettingsValidator;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Hover state spec §2.2: a hover path exists only where its target owns the resting path. The
 * registry answers the effective set, so saving a hover value a target cannot have is refused, while
 * a style class — not bound to a target — may carry one.
 */
final class HoverCapabilityTest extends AppTestCase
{
    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->container()->get(BlockTypeRepository::class);
        // Colours on two targets, the hover group on the root: only the root's surface has a hover.
        $repository->create([
            'slug' => 'hovercard', 'label' => 'Hover card', 'schema' => [],
            'style_capabilities' => ['colors.text', 'colors.surface', 'hover'],
            'style_targets' => [
                'targets' => ['root' => ['kind' => 'box'], 'title' => ['kind' => 'text']],
                'map' => ['colors.text' => 'title', 'colors.surface' => 'root', 'hover' => 'root'],
            ],
        ]);
        // A part with a text colour and the hover group: a hover text colour, nothing else.
        $repository->create([
            'slug' => 'hoverlinks', 'label' => 'Hover links', 'schema' => [],
            'style_capabilities' => ['spacing'],
            'style_targets' => [
                'targets' => ['root' => ['kind' => 'box']],
                'map' => ['spacing' => 'root'],
                'parts' => ['link' => ['label' => 'Link', 'capabilities' => ['hover', 'colors.text']]],
            ],
        ]);
        $this->container()->get(BlockStyleRegistry::class)->reset();
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> the stored settings */
    private function save(string $type, array $settings): array
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        $clean = $this->container()->get(FieldValidator::class)->validate($schema, ['body' => [[
            'id' => 'hoverblock01', 'type' => $type, 'data' => [], 'settings' => $settings,
        ]]], true);
        return $clean['body'][0]['settings'] ?? [];
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    private function errors(string $type, array $settings): array
    {
        try {
            $this->save($type, $settings);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('expected the settings to be refused');
    }

    public function testTheRegistryOffersOnlyTheHoverPathsATargetOwns(): void
    {
        $caps = $this->container()->get(BlockStyleRegistry::class)->capabilitiesFor('hovercard');
        self::assertTrue($caps->allows('hover.colors.surface'));
        self::assertFalse($caps->allows('hover.colors.text'), 'the text colour is the title\'s; hover is the root\'s');
    }

    public function testSavingAHoverPathTheTargetLacksIsRefused(): void
    {
        $errors = $this->errors('hovercard', ['style' => ['hover' => ['colors' => [
            'text' => self::token('color.accent'),
        ]]]]);
        self::assertArrayHasKey('body.0.settings.style.hover.colors.text', $errors);
        // The one it owns is kept.
        $kept = ['hover' => ['colors' => ['surface' => self::token('color.accent')]]];
        self::assertSame($kept, $this->save('hovercard', ['style' => $kept])['style']);
    }

    public function testAPartHoverPathWithoutItsRestingPathIsRefused(): void
    {
        $errors = $this->errors('hoverlinks', ['parts' => ['link' => ['hover' => ['colors' => [
            'surface' => self::token('color.accent'),
        ]]]]]);
        self::assertArrayHasKey('body.0.settings.parts.link.hover.colors.surface', $errors);
        $kept = ['link' => ['hover' => ['colors' => ['text' => self::token('color.accent')]]]];
        self::assertSame($kept, $this->save('hoverlinks', ['parts' => $kept])['parts']);
    }

    public function testAWrongTargetHoverDeclarationIsRefusedOnSave(): void
    {
        $this->expectException(SchemaParseException::class);
        $this->expectExceptionMessage('but colors.text is on "title"');
        $this->container()->get(BlockTypeRepository::class)->create([
            'slug' => 'hoverwrong', 'label' => 'Wrong', 'schema' => [],
            'style_capabilities' => ['colors.text', 'hover.colors.text'],
            'style_targets' => [
                'targets' => ['root' => ['kind' => 'box'], 'title' => ['kind' => 'text']],
                'map' => ['colors.text' => 'title', 'hover.colors.text' => 'root'],
            ],
        ]);
    }

    public function testAStyleClassMayCarryHoverValues(): void
    {
        $style = ['hover' => ['colors' => ['surface' => self::token('color.accent')]]];
        // As StyleClassController validates a class: every path, no target.
        [$clean, $errors] = (new SettingsValidator())->validate(['style' => $style], StyleCapabilities::all());
        self::assertSame([], $errors);
        self::assertSame($style, $clean['style']);
    }

    public function testAClassHoverValueIsActiveOnlyWhereTheTargetHasIt(): void
    {
        // What StyleClassUsage and the class job runner ask (capabilitiesFor): effective per type.
        $registry = $this->container()->get(BlockStyleRegistry::class);
        self::assertTrue($registry->capabilitiesFor('hovercard')->allows('hover.colors.surface'));
        self::assertFalse($registry->capabilitiesFor('hoverlinks')->allows('hover.colors.surface'));
        self::assertFalse($registry->capabilitiesFor('heading')->allows('hover.colors.surface'));
    }
}
