<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Http\Response;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\StyleClassController;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Visual builder spec §4.1, §4.3, §4.5: the style classes API behind `styles.manage` — create,
 * read, update with optimistic versions, archive, and usage counted across every document
 * source with the published revision counted once.
 */
final class StyleClassApiTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function api(): StyleClassController
    {
        return $this->container()->get(StyleClassController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array<string,mixed> */
    private function json(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    /** @param array<string,mixed> $body */
    private function create(array $body): StyleClassData
    {
        return $this->container()->get(RequestDataHydrator::class)->hydrate(StyleClassData::class, $body);
    }

    /** @param array<string,mixed> $body */
    private function update(array $body): UpdateStyleClassData
    {
        return $this->container()->get(RequestDataHydrator::class)->hydrate(UpdateStyleClassData::class, $body);
    }

    public function testRoutesCarryTheirPermissions(): void
    {
        foreach (
            [
                ['GET', '/v1/admin/style-classes', 'content.view'],
                ['POST', '/v1/admin/style-classes', 'styles.manage'],
                ['GET', '/v1/admin/style-classes/{id}', 'content.view'],
                ['PATCH', '/v1/admin/style-classes/{id}', 'styles.manage'],
                ['DELETE', '/v1/admin/style-classes/{id}', 'styles.manage'],
                ['GET', '/v1/admin/style-classes/{id}/usage', 'content.view'],
            ] as [$method, $path, $permission]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path}");
            self::assertContains("content_permission:{$permission}", $route['middleware'], "{$method} {$path}");
        }
    }

    public function testCreateReadUpdateAndArchive(): void
    {
        $created = $this->api()->store($this->create([
            'name' => 'Hero band',
            'description' => 'The hero strip',
            'style' => ['spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.xl']]]]],
        ]), $this->req());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $class = $this->json($created)['data']['style_class'];
        self::assertSame(1, $class['version']);
        self::assertSame('Hero band', $class['name']);
        self::assertFalse($class['archived']);

        $dupe = $this->api()->store($this->create(['name' => 'HERO BAND', 'style' => []]), $this->req());
        self::assertSame(422, $dupe->getStatusCode());
        self::assertArrayHasKey('name', $this->json($dupe)['error']['details']);

        $literal = $this->api()->store($this->create([
            'name' => 'Raw', 'style' => ['radius' => ['type' => 'literal', 'value' => '4px']],
        ]), $this->req());
        self::assertSame(422, $literal->getStatusCode());
        self::assertArrayHasKey('style.radius', $this->json($literal)['error']['details']);

        $rename = $this->update(['version' => 1, 'name' => 'Hero strip']);
        $updated = $this->api()->update($rename, $this->req(), $class['id']);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame(2, $this->json($updated)['data']['style_class']['version']);

        $behind = $this->update(['version' => 1, 'description' => 'x']);
        $stale = $this->api()->update($behind, $this->req(), $class['id']);
        self::assertSame(409, $stale->getStatusCode());
        $error = $this->json($stale)['error'];
        self::assertSame('STYLE_CLASS_VERSION_CONFLICT', $error['details']['code']);
        self::assertSame(2, $error['details']['current_version']);

        $list = $this->json($this->api()->index($this->req()))['data'];
        self::assertSame(
            $this->container()->get(\Thallo\Core\Content\Style\SiteStyleGeneration::class)->current(),
            $list['generation'],
            'the list names the generation of the classes it lists',
        );
        self::assertSame(['Hero strip'], array_column($list['style_classes'], 'name'));

        $archived = $this->api()->destroy($this->req(), $class['id']);
        self::assertSame(200, $archived->getStatusCode());
        $shown = $this->json($this->api()->show($this->req(), $class['id']))['data']['style_class'];
        self::assertTrue($shown['archived']);
        self::assertSame(404, $this->api()->show($this->req(), 'nope00000000')->getStatusCode());
    }

    public function testAnUnreferencedClassCanBeDeletedOutrightAndAReferencedOneCannot(): void
    {
        $this->syncBlockStyleDeclarations();
        $repository = $this->container()->get(StyleClassRepository::class);
        $orphan = $repository->create(['name' => 'Orphan', 'style' => []]);
        $used = $repository->create(['name' => 'Used', 'style' => []]);
        (new \Thallo\Core\Content\Regions\RegionRepository($this->connection()))->save('footer', [[
            'id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['classes' => [$used['id']]],
        ]], [], 'user00000001');

        $request = Request::create('/x?unreferenced=1', 'DELETE');
        $deleted = $this->api()->destroy($request, $orphan['id']);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getContent());
        self::assertNull($repository->find($orphan['id']), 'deleted outright, not archived');

        $refused = $this->api()->destroy($request, $used['id']);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('STYLE_CLASS_REFERENCED', $this->json($refused)['error']['details']['code']);
        self::assertNotNull($repository->find($used['id']));
    }

    public function testUsageCountsOneReferencePerStoredDocumentWithThePublishedRevisionOnce(): void
    {
        $this->syncBlockStyleDeclarations();
        $band = $this->container()->get(StyleClassRepository::class)->create(['name' => 'Band', 'style' => [
            'spacing' => ['padding' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]],
            'radius' => ['type' => 'token', 'value' => 'radius.lg'],
        ]]);
        $types = $this->container()->get(ContentTypeRepository::class);
        $typeUuid = $types->create(['slug' => 'page', 'name' => 'Page', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'blocks'],
        ]]);
        $heading = ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => [
            'classes' => [$band['id']],
        ]];
        $entries = $this->container()->get(EntryRepository::class);
        $publish = $this->container()->get(PublishService::class);
        $entryUuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entryUuid, 'en', ['title' => 'One', 'body' => [$heading]], 1, 0, 'user00000001');
        // Published once (a retained version that is also the current publication), then a
        // newer publication behind an updated draft, leaving one older retained version.
        $publish->publish($entryUuid, 'en', 'user00000001');
        $entries->saveDraft($entryUuid, 'en', ['title' => 'Two', 'body' => [$heading]], 1, 1, 'user00000001');
        $publish->publish($entryUuid, 'en', 'user00000001');
        (new \Thallo\Core\Content\Regions\RegionRepository($this->connection()))
            ->save('footer', [$heading], [], 'user00000001');

        $usage = $this->json($this->api()->usage($this->req(), $band['id']))['data']['usage'];
        self::assertSame(
            ['entry_drafts' => 1, 'entry_published' => 1, 'entry_versions' => 1, 'regions' => 1],
            $usage['by_source'],
        );
        self::assertSame(4, $usage['references']);
        self::assertSame(4, $usage['properties']['spacing.padding.top']['active']);
        self::assertSame(0, $usage['properties']['spacing.padding.top']['dormant']);
        self::assertSame(4, $usage['properties']['radius']['dormant'], 'a heading has no radius capability');
        self::assertSame(4, $usage['active']);
        self::assertSame(4, $usage['dormant']);
    }
}
