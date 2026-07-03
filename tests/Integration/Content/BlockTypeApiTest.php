<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\Controllers\BlockTypeController;
use App\Content\Http\DTOs\BlockTypeData;
use App\Content\Http\DTOs\FieldDefinitionData;
use App\Content\Http\DTOs\UpdateBlockTypeData;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BlockTypeApiTest extends LemmaTestCase
{
    private function api(): BlockTypeController
    {
        return $this->container()->get(BlockTypeController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    public function testCrudLifecycle(): void
    {
        $store = $this->api()->store(new BlockTypeData(
            slug: 'hero',
            label: 'Hero',
            icon: 'i-lucide-star',
            description: null,
            schema: [new FieldDefinitionData(name: 'heading', type: 'string')],
        ), $this->req());
        self::assertSame(201, $store->getStatusCode());

        // Duplicate slug → 422.
        $dup = $this->api()->store(new BlockTypeData(
            slug: 'hero',
            label: 'Again',
            icon: null,
            description: null,
            schema: [],
        ), $this->req());
        self::assertSame(422, $dup->getStatusCode());

        // §2 schema rules surface as 422 (localized — nested `blocks` fields are
        // ALLOWED since the nesting amendment; see BlockTypeRepositoryTest).
        $bad = $this->api()->store(new BlockTypeData(
            slug: 'bad',
            label: 'Bad',
            icon: null,
            description: null,
            schema: [new FieldDefinitionData(name: 's', type: 'string', localized: true)],
        ), $this->req());
        self::assertSame(422, $bad->getStatusCode());

        $list = $this->json($this->api()->index(Request::create('/x', 'GET')));
        self::assertCount(1, $list['data']['block_types']);

        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'hero'));
        self::assertSame('Hero', $show['data']['block_type']['label']);

        $update = $this->api()->update(new UpdateBlockTypeData(
            label: 'Hero v2',
            icon: null,
            description: 'Big banner',
            schema: [new FieldDefinitionData(name: 'heading', type: 'string')],
        ), Request::create('/x', 'PATCH'), 'hero');
        self::assertSame(200, $update->getStatusCode());

        self::assertSame(200, $this->api()->deactivate('hero')->getStatusCode());
        $repo = new BlockTypeRepository($this->connection());
        self::assertSame(0, $repo->findBySlug('hero')['active']);
        self::assertSame(200, $this->api()->activate('hero')->getStatusCode());
        self::assertSame(1, $repo->findBySlug('hero')['active']);

        self::assertSame(404, $this->api()->show(Request::create('/x', 'GET'), 'ghost')->getStatusCode());
    }

    public function testRoutesCarryTheContentPermissions(): void
    {
        foreach (
            [
                ['GET', '/v1/admin/block-types', 'content.view'],
                ['POST', '/v1/admin/block-types', 'content.manage'],
                ['GET', '/v1/admin/block-types/{slug}', 'content.view'],
                ['PATCH', '/v1/admin/block-types/{slug}', 'content.manage'],
                ['POST', '/v1/admin/block-types/{slug}/activate', 'content.manage'],
                ['POST', '/v1/admin/block-types/{slug}/deactivate', 'content.manage'],
            ] as [$method, $path, $permission]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "missing route {$method} {$path}");
            self::assertContains(
                "lemma_permission:{$permission}",
                (array) ($route['middleware'] ?? []),
                "wrong permission on {$method} {$path}",
            );
        }
    }
}
