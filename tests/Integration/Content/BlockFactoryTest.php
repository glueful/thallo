<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockFactory;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The server block factory (visual builder Phase B, B6.4): the server is authoritative for a
 * fresh block's canonical structure and defaults, the block type row for its starter content,
 * and the editor for its ids — the factory returns none.
 */
final class BlockFactoryTest extends AppTestCase
{
    private function seed(string $slug, ?array $starter, bool $active = true): void
    {
        $repo = $this->container()->get(BlockTypeRepository::class);
        $uuid = $repo->create([
            'slug' => $slug,
            'label' => ucfirst($slug),
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ['name' => 'variant', 'type' => 'enum', 'enum' => ['solid', 'outline']],
                ['name' => 'reverse', 'type' => 'boolean'],
                ['name' => 'image', 'type' => 'asset'],
                ['name' => 'items', 'type' => 'blocks'],
                ['name' => 'aside', 'type' => 'blocks', 'block_types' => ['button']],
            ],
            'starter_content' => $starter,
        ]);
        if (!$active) {
            $repo->setActive($uuid, false);
        }
    }

    public function testDefaultsPerFieldTypeWithTheStarterKeptSeparate(): void
    {
        $this->seed('card', ['title' => 'Card', 'items' => [['type' => 'heading', 'data' => ['text' => 'Hi']]]]);
        $made = $this->container()->get(BlockFactory::class)->make('card');

        self::assertNotNull($made);
        // Every blocks field is an empty list, every enum its first option, everything else
        // absent — and settings start empty. No id: the editor mints those.
        self::assertSame(
            ['type' => 'card', 'data' => ['variant' => 'solid', 'items' => [], 'aside' => []], 'settings' => []],
            $made['block'],
        );
        self::assertArrayNotHasKey('id', $made['block']);
        // JSONB keeps its own key order: compare by content.
        self::assertEquals(
            ['title' => 'Card', 'items' => [['type' => 'heading', 'data' => ['text' => 'Hi']]]],
            $made['starter'],
        );
    }

    public function testNoStarterContentIsAnEmptyStarter(): void
    {
        $this->seed('plain', null);
        $made = $this->container()->get(BlockFactory::class)->make('plain');
        self::assertSame([], $made['starter']);
        self::assertSame(['variant' => 'solid', 'items' => [], 'aside' => []], $made['block']['data']);
    }

    public function testUnknownSlugMakesNothing(): void
    {
        self::assertNull($this->container()->get(BlockFactory::class)->make('nope'));
    }

    public function testTheEndpointReturnsTheInstanceAndRefusesUnknownOrInactiveTypes(): void
    {
        $this->seed('card', ['title' => 'Card']);
        $this->seed('retired', null, active: false);
        $api = $this->container()->get(BlockTypeController::class);
        $req = Request::create('/x', 'POST');

        $ok = $api->instance($req, 'card');
        self::assertSame(200, $ok->getStatusCode());
        $data = json_decode((string) $ok->getContent(), true)['data'];
        self::assertSame('card', $data['block']['type']);
        self::assertSame(['variant' => 'solid', 'items' => [], 'aside' => []], $data['block']['data']);
        self::assertSame([], $data['block']['settings']);
        self::assertSame(['title' => 'Card'], $data['starter']);

        self::assertSame(404, $api->instance($req, 'nope')->getStatusCode());
        $inactive = $api->instance($req, 'retired');
        self::assertSame(422, $inactive->getStatusCode());
        self::assertSame(
            'BLOCK_TYPE_INACTIVE',
            json_decode((string) $inactive->getContent(), true)['error']['details']['code'] ?? null,
        );
    }

    public function testTheRouteRequiresContentEdit(): void
    {
        $route = $this->findRoute('POST', '/v1/admin/block-types/{slug}/instance');
        self::assertNotNull($route);
        self::assertContains('content_permission:content.edit', (array) ($route['middleware'] ?? []));
    }
}
