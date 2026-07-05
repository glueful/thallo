<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Regions\RegionValidator;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class RegionValidatorTest extends LemmaTestCase
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
