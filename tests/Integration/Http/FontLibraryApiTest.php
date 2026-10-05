<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Storage\StorageManager;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\Http\DTOs\AddFontFaceData;
use Thallo\Core\Content\Fonts\Http\DTOs\CreateFontFamilyData;
use Thallo\Core\Content\Fonts\Http\DTOs\UpdateFontFamilyData;
use Thallo\Core\Content\Fonts\Http\FontLibraryController;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Http\Controllers\MediaAdminController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;

/**
 * The font library API (block typeface spec §2.6, §4.6; plan Task 8): any editor reads the picker —
 * content.edit, content.manage, templates.manage or styles.manage — without usage; usage and every
 * change need content.manage. Files are read from the media library as uploaded; a file the reader
 * refuses is a 422 naming the file and the reason. A library font file cannot be deleted from media.
 */
final class FontLibraryApiTest extends AppTestCase
{
    use GrantsPermissions;

    private const FONTS = __DIR__ . '/../../fixtures/fonts';
    private const PICKER = 'content_permission:content.edit,content.manage,templates.manage,styles.manage';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        $disk = $this->container()->get(StorageManager::class)->disk('uploads');
        foreach ($this->written as $path) {
            $disk->delete($path);
        }
        $this->scrubGrants();
        parent::tearDown();
    }

    private function controller(): FontLibraryController
    {
        return $this->container()->get(FontLibraryController::class);
    }

    /** A font file uploaded to the media library: the stored file and its blob row. */
    private function upload(string $uuid, string $fixture): string
    {
        $path = 'fonts-test/' . $uuid . '.woff2';
        $this->container()->get(StorageManager::class)->disk('uploads')
            ->write($path, (string) file_get_contents(self::FONTS . '/' . $fixture));
        $this->written[] = $path;
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => $fixture, 'mime_type' => 'font/woff2', 'size' => 1000, 'url' => $path,
            'storage_type' => 'uploads', 'visibility' => 'public', 'status' => 'active',
            'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /** @return array<string,mixed> */
    private static function data(\Glueful\Http\Response $res): array
    {
        return json_decode((string) $res->getContent(), true)['data'] ?? [];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed> $body
     * @return T
     */
    private static function dto(string $class, array $body): object
    {
        return (new RequestDataHydrator())->hydrate($class, $body);
    }

    private function create(string $name, array $blobs): \Glueful\Http\Response
    {
        return $this->controller()->store(self::dto(CreateFontFamilyData::class, [
            'name' => $name, 'fallback' => 'serif', 'blob_uuids' => $blobs,
        ]));
    }

    /** The route middleware's check: its parameters arrive split on commas, as the router passes them. */
    private function allows(?string $user, string $permissions): bool
    {
        $reached = false;
        (new RequirePermission($this->appContext()))->handle($this->requestAs($user), function () use (&$reached) {
            $reached = true;
            return \Glueful\Http\Response::success([]);
        }, ...explode(',', $permissions));
        return $reached;
    }

    public function testEveryRouteCarriesAuthAndItsPermission(): void
    {
        $routes = [
            ['GET', '/v1/admin/fonts', self::PICKER],
            ['GET', '/v1/admin/fonts/{id}/usage', 'content_permission:content.manage'],
            ['POST', '/v1/admin/fonts', 'content_permission:content.manage'],
            ['PATCH', '/v1/admin/fonts/{id}', 'content_permission:content.manage'],
            ['POST', '/v1/admin/fonts/{id}/faces', 'content_permission:content.manage'],
            ['DELETE', '/v1/admin/fonts/{id}/faces/{blob_uuid}', 'content_permission:content.manage'],
            ['DELETE', '/v1/admin/fonts/{id}', 'content_permission:content.manage'],
            ['POST', '/v1/admin/fonts/{id}/restore', 'content_permission:content.manage'],
            ['DELETE', '/v1/admin/fonts/{id}/permanent', 'content_permission:content.manage'],
            ['POST', '/v1/admin/fonts/{id}/read-again', 'content_permission:content.manage'],
        ];
        foreach ($routes as [$method, $path, $permission]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} is registered");
            self::assertContains('auth', $route['middleware'], $path);
            self::assertContains($permission, $route['middleware'], "{$method} {$path}");
        }
    }

    public function testAnyEditorReadsThePickerAndOnlyAManagerChangesTheLibrary(): void
    {
        $picker = substr(self::PICKER, strlen('content_permission:'));
        foreach (['content.edit', 'content.manage', 'templates.manage', 'styles.manage'] as $permission) {
            $user = $this->userWith('test_font_' . str_replace('.', '_', $permission), [$permission]);
            self::assertTrue($this->allows($user, $picker), $permission . ' reads the picker');
            self::assertSame($permission === 'content.manage', $this->allows($user, 'content.manage'), $permission);
        }
        $nobody = $this->userWith('test_font_none', ['content.view']);
        self::assertFalse($this->allows($nobody, $picker));
    }

    public function testThePickerListsBuiltInsFirstThenFamiliesWithoutUsage(): void
    {
        $blob = $this->upload('fontstatic01', 'static-700.woff2');
        $id = (string) self::data($this->create('Brand', [$blob]))['family']['id'];
        $manager = $this->userWith('test_font_picker_manager', ['content.manage']);
        $res = $this->controller()->index($this->requestAs($manager));
        self::assertSame(200, $res->getStatusCode());
        $data = self::data($res);
        self::assertSame(['families', 'theme_face', 'can_manage'], array_keys($data), 'no usage in the picker');
        self::assertTrue($data['can_manage']);
        $editor = $this->userWith('test_font_picker_editor', ['content.edit']);
        self::assertFalse(self::data($this->controller()->index($this->requestAs($editor)))['can_manage']);
        $builtIns = ['theme', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system'];
        self::assertSame($builtIns, array_slice(array_column($data['families'], 'id'), 0, 7));
        self::assertSame('builtin', $data['families'][0]['kind']);
        $brand = array_column($data['families'], null, 'id')[$id];
        $described = [$brand['name'], $brand['kind'], $brand['fallback'], $brand['removed']];
        self::assertSame(['Brand', 'uploaded', 'serif', false], $described);
        self::assertSame([700, 700, false, false, false], [
            $brand['faces'][0]['weight_min'], $brand['faces'][0]['weight_max'], $brand['faces'][0]['italic'],
            $brand['faces'][0]['variable'], $brand['faces'][0]['unknown'],
        ]);
        self::assertTrue($data['theme_face']['declared']);
        self::assertSame('Figtree', $data['theme_face']['family']);
        $italicFile = $data['theme_face']['files'][1];
        self::assertSame(['300 900', 'italic'], [$italicFile['weight'], $italicFile['style']]);
        self::assertStringEndsWith('fonts/figtree-roman-latin.woff2', $data['theme_face']['files'][0]['url']);
    }

    public function testTheLifecycleThroughTheApi(): void
    {
        $static = $this->upload('fontstatic01', 'static-700.woff2');
        $italic = $this->upload('fontitalic01', 'static-400-italic.woff2');
        $created = $this->create('Brand', [$static]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $id = (string) self::data($created)['family']['id'];

        $renamed = $this->controller()->update(self::dto(UpdateFontFamilyData::class, ['name' => 'Brand Sans']), $id);
        self::assertSame('Brand Sans', self::data($renamed)['family']['name']);
        $added = $this->controller()->addFace(self::dto(AddFontFaceData::class, ['blob_uuid' => $italic]), $id);
        self::assertSame(200, $added->getStatusCode());
        self::assertSame(200, $this->controller()->removeFace($id, $italic)->getStatusCode());
        self::assertSame(409, $this->controller()->removeFace($id, $static)->getStatusCode(), 'the last face stays');
        self::assertSame(200, $this->controller()->readAgain($id)->getStatusCode());

        self::assertSame(409, $this->controller()->purge($id)->getStatusCode(), 'only a removed family');
        self::assertSame(200, $this->controller()->destroy($id)->getStatusCode());
        $usage = self::data($this->controller()->usage($id));
        self::assertNotSame([], $usage, 'usage still answers for a removed family');
        $listed = array_column(self::data($this->controller()->index())['families'], null, 'id');
        self::assertTrue($listed[$id]['removed']);
        self::assertSame(200, $this->controller()->restore($id)->getStatusCode());
        $this->controller()->destroy($id);
        self::assertSame(200, $this->controller()->purge($id)->getStatusCode());
        self::assertSame(404, $this->controller()->restore($id)->getStatusCode());
    }

    public function testAnUnreadableFileIsA422NamingItAndTheReason(): void
    {
        $bad = $this->upload('notafont0001', 'not-a-font.woff2');
        $res = $this->create('Broken', [$bad]);
        self::assertSame(422, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertStringContainsString('Not a WOFF2 font', (string) $res->getContent());
        self::assertStringContainsString('not-a-font.woff2', (string) $res->getContent());
        self::assertArrayHasKey('blob_uuids', $body['error']['details'] ?? $body['errors'] ?? []);
    }

    public function testADeletedOrUnknownFileIsRefused(): void
    {
        $gone = $this->upload('fontgone0001', 'static-700.woff2');
        $this->connection()->table('blobs')->where('uuid', '=', $gone)->update(['status' => 'deleted']);
        self::assertSame(422, $this->create('Gone', [$gone])->getStatusCode());
        self::assertSame(422, $this->create('Nothing', ['nosuchblob01'])->getStatusCode());
        self::assertSame(422, $this->create('', [$this->upload('fontstatic01', 'static-700.woff2')])->getStatusCode());
    }

    public function testALibraryFontFileCannotBeDeletedFromMediaUntilItsFamilyIsDeletedPermanently(): void
    {
        $static = $this->upload('fontstatic01', 'static-700.woff2');
        $id = (string) self::data($this->create('Brand', [$static]))['family']['id'];
        $media = $this->container()->get(MediaAdminController::class);

        $refused = $media->destroy(Request::create('/x', 'DELETE'), $static);
        self::assertSame(409, $refused->getStatusCode());
        $message = 'This file is a font in the library (Brand); '
            . 'delete the family permanently in Site › Appearance › Typefaces first.';
        self::assertStringContainsString($message, (string) $refused->getContent());
        $usage = self::data($media->usage($static));
        self::assertSame([['id' => $id, 'name' => 'Brand', 'removed' => false]], $usage['font_library']);

        $this->controller()->destroy($id);
        $stillRefused = $media->destroy(Request::create('/x', 'DELETE'), $static);
        self::assertSame(409, $stillRefused->getStatusCode(), 'removed still protects');
        $this->controller()->purge($id);
        self::assertSame(200, $media->destroy(Request::create('/x', 'DELETE'), $static)->getStatusCode());
        self::assertFalse($this->container()->get(FontLibrary::class)->isLibraryBlob($static));
    }
}
