<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;

final class RegionValidatorTest extends AppTestCase
{
    private function validator(): RegionValidator
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        return new RegionValidator(new FieldValidator($this->connection(), $this->appContext(), $repo));
    }

    public function testValidHeaderSaves(): void
    {
        $clean = $this->validator()->validate('header', [
            ['id' => 'regionblock1', 'type' => 'logo', 'data' => ['size' => 'medium', 'link_home' => true]],
            ['id' => 'regionblock2', 'type' => 'button', 'data' => ['label' => 'Contact', 'url' => '/contact']],
        ], ['sticky' => true, 'width' => 'full']);
        self::assertCount(2, $clean['blocks']);
        self::assertSame('logo', $clean['blocks'][0]['type']);
        self::assertTrue($clean['settings']['sticky']);
        self::assertSame('full', $clean['settings']['width']);
    }

    public function testARegionsStyleIsValidatedByTheStyleContract(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $style = [
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.sm'), 'lg' => $token('spacing.lg')]]],
            'colors' => ['surface' => $token('color.background'), 'surface_opacity' => $choice('80')],
            'backdrop' => ['blur' => $choice('md')],
            'border' => ['width' => $choice('thin'), 'style' => $choice('solid'), 'sides' => $choice('bottom')],
            'radius' => $token('radius.lg'),
            'shadow' => ['base' => $token('shadow.sm')],
        ];
        foreach (['header', 'footer'] as $slug) {
            $clean = $this->validator()->validate($slug, [], ['style' => $style]);
            self::assertSame($style, $clean['settings']['style'], $slug);
        }

        // An empty style is no style: nothing is stored for it.
        self::assertArrayNotHasKey('style', $this->validator()->validate('header', [], ['style' => []])['settings']);
    }

    public function testARegionsStyleRefusesWhatARegionCannotBeStyledWith(): void
    {
        $bad = [
            // Not a region capability: a hidden header is the page's presentation setting.
            ['visibility' => ['base' => ['type' => 'choice', 'value' => 'hidden']]],
            // Not in the vocabulary.
            ['radius' => ['type' => 'token', 'value' => 'radius.enormous']],
            // Not responsive.
            ['colors' => ['surface_opacity' => ['md' => ['type' => 'choice', 'value' => '80']]]],
        ];
        foreach ($bad as $style) {
            try {
                $this->validator()->validate('header', [], ['style' => $style]);
                self::fail('expected ValidationException for ' . json_encode($style));
            } catch (ValidationException $e) {
                $keys = array_keys($e->errors());
                self::assertNotSame([], $keys);
                self::assertStringStartsWith('settings.style', $keys[0]);
            }
        }
        // Style classes and the Advanced fields are a block's: a region stores a style, only.
        try {
            $this->validator()->validate('header', [], ['classes' => ['cls000000001']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.classes', $e->errors());
        }
    }

    public function testOutOfPaletteBlockIsADotPath422(): void
    {
        try {
            $this->validator()->validate('header', [
                ['id' => 'regionblock1', 'type' => 'gallery', 'data' => ['images' => []]],
            ], []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.0.type', $e->errors());
        }
    }

    public function testUnknownSettingsKeyAndWrongTypesFailLoudly(): void
    {
        // sticky is a header-only key.
        try {
            $this->validator()->validate('footer', [], ['sticky' => true]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.sticky', $e->errors());
        }
        try {
            $this->validator()->validate('header', [], ['sticky' => 'yes']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.sticky', $e->errors());
        }
        try {
            $this->validator()->validate('header', [], ['width' => 'huge']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.width', $e->errors());
        }
    }

    public function testUnknownRegionSlugRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator()->validate('sidebar', [], []);
    }

    public function testEmptyListIsALegalSave(): void
    {
        $clean = $this->validator()->validate('footer', [], []);
        self::assertSame([], $clean['blocks']);
        self::assertSame([], $clean['settings']);
    }
}
