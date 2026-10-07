<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Hover state spec §2.2.1: every block type the admin reads carries `style_paths` — what the block
 * and each part offer, expanded once by the server, in schema order — derived on read, never stored.
 */
final class BlockTypeStylePathsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const FIXTURES = __DIR__ . '/../../../packages/thallo-contracts/style-capability-fixtures/v1';
    private const STARTERS = ['button', 'links', 'social_link', 'social_links', 'file'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function api(): BlockTypeController
    {
        return $this->container()->get(BlockTypeController::class);
    }

    /** @return array<string,mixed> */
    private function data(\Glueful\Http\Response $response): array
    {
        return (array) (json_decode((string) $response->getContent(), true)['data'] ?? []);
    }

    /** @return array<string,mixed> */
    private function shown(string $slug): array
    {
        return (array) $this->data($this->api()->show(Request::create('/x'), $slug))['block_type'];
    }

    /** @param list<string> $capabilities @param array<string,mixed> $targets */
    private function create(string $slug, array $capabilities, array $targets): void
    {
        $this->container()->get(BlockTypeRepository::class)->create([
            'slug' => $slug, 'label' => $slug, 'schema' => [],
            'style_capabilities' => $capabilities, 'style_targets' => $targets,
        ]);
    }

    public function testEveryExpansionFixtureMatchesThePayload(): void
    {
        $json = (string) file_get_contents(self::FIXTURES . '/expansion.json');
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $n = 0;
        foreach ($doc['cases'] as $case) {
            if (isset($case['error'])) {
                continue;
            }
            $slug = 'fixture' . ++$n;
            $this->create($slug, $case['declaration']['style_capabilities'], $case['declaration']['style_targets']);
            // assertSame: the order is part of the contract.
            self::assertSame($case['expect'], $this->shown($slug)['style_paths'], $case['name']);
        }
        self::assertGreaterThan(0, $n);
    }

    public function testAPayloadIsInSchemaOrderWhateverTheDeclarationOrder(): void
    {
        $map = ['hover' => 'root', 'colors' => 'root', 'opacity' => 'root'];
        $this->create('orderone', ['hover', 'colors', 'opacity'], [
            'targets' => ['root' => ['kind' => 'box']],
            'map' => $map,
        ]);
        $this->create('ordertwo', ['opacity', 'colors', 'hover'], [
            'targets' => ['root' => ['kind' => 'box']],
            'map' => array_reverse($map, true),
        ]);
        $one = $this->shown('orderone')['style_paths']['block'];
        self::assertSame($one, $this->shown('ordertwo')['style_paths']['block']);
        self::assertSame(StyleSchema::ordered($one), $one);
        self::assertContains('hover.opacity', $one);
    }

    public function testNoPartsIsAnEmptyMapInJson(): void
    {
        $this->create('noparts', ['spacing'], [
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['spacing' => 'root'],
        ]);
        $json = (string) $this->api()->show(Request::create('/x'), 'noparts')->getContent();
        self::assertStringContainsString('"parts":{}', $json);
    }

    public function testAStoredDeclarationThatNoLongerParsesDoesNotFailTheList(): void
    {
        $this->create('brokenlater', ['spacing'], [
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['spacing' => 'root'],
        ]);
        // Written around the repository's checks: a declaration a later contract no longer accepts.
        $this->connection()->table('block_types')->where('slug', 'brokenlater')->update([
            'style_targets' => json_encode(['targets' => ['root' => ['kind' => 'nonsense']], 'map' => []]),
        ]);
        $response = $this->api()->index(Request::create('/x'));
        self::assertSame(200, $response->getStatusCode());
        $rows = array_column($this->data($response)['block_types'], null, 'slug');
        // That type offers nothing it cannot prove (an empty list, not null: null would send the admin
        // back to expanding the raw declaration); every other type keeps its paths.
        self::assertSame(['block' => [], 'parts' => []], $rows['brokenlater']['style_paths']);
        self::assertIsArray($rows['button']['style_paths']);
    }

    public function testTheListCarriesStylePaths(): void
    {
        $rows = $this->data($this->api()->index(Request::create('/x')))['block_types'];
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row['style_paths'] ?? null, (string) $row['slug']);
            self::assertArrayHasKey('block', $row['style_paths']);
            self::assertArrayHasKey('parts', $row['style_paths']);
        }
    }

    public function testTheStartersSnapshotIsCurrent(): void
    {
        // Decoded as objects, so an empty `parts` stays `{}` as the admin receives it.
        $body = json_decode((string) $this->api()->index(Request::create('/x'))->getContent());
        $paths = [];
        foreach ($body->data->block_types as $row) {
            if (in_array($row->slug, self::STARTERS, true)) {
                $paths[$row->slug] = $row->style_paths;
            }
        }
        ksort($paths);
        $json = json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $file = self::FIXTURES . '/starters.json';
        if (getenv('THALLO_RECORD_STYLE_PATHS') === '1') {
            file_put_contents($file, $json);
        }
        self::assertSame(
            $json,
            (string) @file_get_contents($file),
            'the starters\' style_paths changed: re-record with THALLO_RECORD_STYLE_PATHS=1',
        );
    }
}
