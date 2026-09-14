<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Visual builder plan A1.3: block types persist style capabilities, targets, flags and starter
 * content, validated on write, hydrated on read, and reaching the admin API verbatim.
 */
final class BlockTypeStyleKeysTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The starter library is the fixture: the shipped block types with their declarations.
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
    }

    private function repo(): BlockTypeRepository
    {
        return new BlockTypeRepository($this->connection());
    }

    public function testTheFourKeysRoundTripThroughTheRepository(): void
    {
        $repo = $this->repo();
        $uuid = $repo->create([
            'slug' => 'stylekeys_a',
            'label' => 'Style keys A',
            'schema' => [['name' => 'text', 'type' => 'string']],
            'style_capabilities' => ['spacing', 'colors.text'],
            'style_targets' => [
                'targets' => ['root' => ['kind' => 'text']],
                'map' => ['spacing' => 'root', 'colors.text' => 'root'],
            ],
            'flags' => ['legacy_presentation' => false, 'renders_children_inline' => true],
            'starter_content' => ['text' => 'Hello'],
        ]);

        $row = $repo->findByUuid($uuid);
        self::assertSame(['spacing', 'colors.text'], $row['style_capabilities']);
        self::assertSame('text', $row['style_targets']['targets']['root']['kind']);
        self::assertTrue($row['flags']['renders_children_inline']);
        self::assertSame(['text' => 'Hello'], $row['starter_content']);

        $byRegistry = $this->container()->get(BlockStyleRegistry::class);
        self::assertTrue($byRegistry->capabilitiesFor('stylekeys_a')->allows('spacing.padding.top'));
        self::assertFalse($byRegistry->capabilitiesFor('stylekeys_a')->allows('radius'));
        self::assertSame('root', $byRegistry->targetsFor('stylekeys_a')?->targetFor('colors.text'));
        self::assertFalse($byRegistry->flagsFor('stylekeys_a')['legacy_presentation']);

        $repo->deleteBySlug('stylekeys_a');
    }

    public function testUndeclaredKeysHydrateAsNullAndMeanNone(): void
    {
        $repo = $this->repo();
        $uuid = $repo->create(['slug' => 'stylekeys_b', 'label' => 'B', 'schema' => []]);
        $row = $repo->findByUuid($uuid);

        self::assertNull($row['style_capabilities']);
        self::assertNull($row['style_targets']);
        self::assertNull($row['flags']);
        self::assertNull($row['starter_content']);

        $registry = $this->container()->get(BlockStyleRegistry::class);
        self::assertSame([], $registry->capabilitiesFor('stylekeys_b')->paths());
        self::assertNull($registry->targetsFor('stylekeys_b'));
        self::assertSame([], $registry->flagsFor('stylekeys_b'));
        self::assertSame([], $registry->capabilitiesFor('no_such_type')->paths(), 'unknown type means none');

        $repo->deleteBySlug('stylekeys_b');
    }

    public function testInvalidDeclarationsAreRejectedOnWrite(): void
    {
        $repo = $this->repo();
        try {
            $repo->create([
                'slug' => 'stylekeys_c', 'label' => 'C', 'schema' => [], 'style_capabilities' => ['colors.glow'],
            ]);
            self::fail('unknown capability accepted');
        } catch (SchemaParseException $e) {
            self::assertStringContainsString('unknown style capability "colors.glow"', $e->getMessage());
        }
        try {
            $repo->create([
                'slug' => 'stylekeys_c',
                'label' => 'C',
                'schema' => [],
                'style_capabilities' => ['alignment.text'],
                'style_targets' => ['targets' => ['root' => ['kind' => 'row']], 'map' => ['alignment.text' => 'root']],
            ]);
            self::fail('kind rule violation accepted');
        } catch (SchemaParseException $e) {
            self::assertStringContainsString('alignment.text requires a text target', $e->getMessage());
        }
        try {
            $repo->create(['slug' => 'stylekeys_c', 'label' => 'C', 'schema' => [], 'flags' => ['bogus' => true]]);
            self::fail('unknown flag accepted');
        } catch (SchemaParseException $e) {
            self::assertStringContainsString('unknown block flag "bogus"', $e->getMessage());
        }
        self::assertNull($repo->findBySlug('stylekeys_c'));
    }

    public function testUpdateStyleWritesTheKeysWithoutTouchingTheSchema(): void
    {
        $repo = $this->repo();
        $uuid = $repo->create([
            'slug' => 'stylekeys_d', 'label' => 'D', 'schema' => [['name' => 'x', 'type' => 'string']],
        ]);

        $repo->updateStyle($uuid, ['spacing'], null, ['legacy_presentation' => true], null);

        $row = $repo->findByUuid($uuid);
        self::assertSame(['spacing'], $row['style_capabilities']);
        self::assertTrue($row['flags']['legacy_presentation']);
        self::assertSame('x', $row['schema'][0]['name']);
        $repo->deleteBySlug('stylekeys_d');
    }

    public function testEveryStarterCarriesTheTransitionalFlagAndTheApiReturnsTheKeys(): void
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            self::assertTrue(
                $definition['flags']['legacy_presentation'] ?? false,
                "{$definition['slug']} carries legacy_presentation until its conversion ships",
            );
        }

        $response = $this->container()->get(BlockTypeController::class)
            ->index(Request::create('/v1/admin/block-types'));
        $rows = json_decode((string) $response->getContent(), true)['data']['block_types'];
        $heading = array_values(array_filter($rows, static fn (array $r): bool => $r['slug'] === 'heading'))[0];
        self::assertArrayHasKey('style_capabilities', $heading);
        self::assertArrayHasKey('style_targets', $heading);
        self::assertArrayHasKey('starter_content', $heading);
        self::assertTrue($heading['flags']['legacy_presentation']);
    }
}
