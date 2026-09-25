<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A block's parts (a links block's links): each part keeps a style record of its own under
 * `settings.parts.<name>`, validated against that part's capabilities — never the block's.
 */
final class BlockPartSettingsTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
    }

    private function validator(): FieldValidator
    {
        $registry = new class implements BlockStyleRegistry {
            public function capabilitiesFor(string $type): StyleCapabilities
            {
                return StyleCapabilities::fromDeclaration(['spacing']);
            }
            public function targetsFor(string $type): ?StyleTargets
            {
                return StyleTargets::fromDeclaration([
                    'targets' => ['root' => ['kind' => 'box']],
                    'map' => ['spacing' => 'root'],
                    'parts' => ['link' => [
                        'label' => 'Link',
                        'capabilities' => ['typography', 'colors.text'],
                    ]],
                ]);
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
        return new FieldValidator($this->connection(), $this->appContext(), null, null, $registry);
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function validate(array $settings): array
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        $clean = $this->validator()->validate($schema, ['body' => [[
            'id' => 'links0000001',
            'type' => 'links',
            'data' => ['title' => 'Product', 'items' => []],
            'settings' => $settings,
        ]]], true);
        return $clean['body'][0]['settings'];
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    private function errors(array $settings): array
    {
        try {
            $this->validate($settings);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('expected the settings to be refused');
    }

    public function testAPartsStyleIsKeptWhenItsCapabilitiesAllowIt(): void
    {
        $record = [
            'typography' => ['size' => ['base' => ['type' => 'token', 'value' => 'typography.size.sm']]],
            'colors' => ['text' => ['type' => 'token', 'value' => 'color.accent']],
        ];
        self::assertSame(['link' => $record], $this->validate(['parts' => ['link' => $record]])['parts']);
        // An emptied part is dropped, not stored as {}.
        self::assertArrayNotHasKey('parts', $this->validate(['parts' => ['link' => []]]));
    }

    public function testWhatThePartDoesNotOfferAndPartsTheBlockDoesNotHaveAreRefused(): void
    {
        $errors = $this->errors(['parts' => [
            'link' => ['spacing' => ['margin' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]]],
        ]]);
        self::assertSame(
            ['body.0.settings.parts.link.spacing.margin.top' => 'not styleable on this part'],
            $errors,
        );
        self::assertArrayHasKey(
            'body.0.settings.parts.title',
            $this->errors(['parts' => ['title' => [
                'colors' => ['text' => ['type' => 'token', 'value' => 'color.accent']],
            ]]]),
        );
        // The block's own capabilities never reach a part: spacing is the block's, not the link's.
        self::assertArrayHasKey(
            'body.0.settings.parts.link.spacing.padding.top',
            $this->errors(['parts' => ['link' => ['spacing' => ['padding' => ['top' => [
                'base' => ['type' => 'token', 'value' => 'spacing.lg'],
            ]]]]]]),
        );
    }
}
