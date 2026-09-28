<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Field blocks belong to layouts (type layouts spec §5.6): an entry, a region and a saved section
 * refuse them anywhere in their trees — the server's rule, not the palette's — and only a
 * validator built for layouts accepts them.
 */
final class LayoutOnlyBlocksTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array<string,mixed> a container holding a field block */
    private static function nested(string $type = 'entry_title'): array
    {
        return ['id' => 'contain00001', 'type' => 'container', 'settings' => [], 'data' => [
            'element' => 'div',
            'content' => [
                ['id' => 'fieldblock01', 'type' => $type, 'data' => [], 'settings' => []],
            ],
        ]];
    }

    private function validator(): FieldValidator
    {
        return $this->container()->get(FieldValidator::class);
    }

    public function testAnEntryRefusesAFieldBlock(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        try {
            $this->validator()->validate($schema, ['body' => [self::nested()]], true);
            self::fail('an entry must refuse a field block');
        } catch (ValidationException $e) {
            self::assertStringContainsString("'entry_title' belongs to layouts", json_encode($e->errors()));
            self::assertArrayHasKey('body.0.content.0', $e->errors());
        }
    }

    public function testARegionRefusesAFieldBlock(): void
    {
        try {
            $this->container()->get(RegionValidator::class)->validate('footer', [self::nested()], []);
            self::fail('a region must refuse a field block');
        } catch (ValidationException $e) {
            self::assertStringContainsString('belongs to layouts', json_encode($e->errors()));
        }
    }

    public function testASavedSectionRefusesAFieldBlock(): void
    {
        $dto = (new RequestDataHydrator())->hydrate(
            SaveSectionData::class,
            ['name' => 'Sneaky', 'block' => self::nested()],
        );
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        $resp = $this->container()->get(SavedSectionController::class)->store($dto, $request);
        self::assertSame(422, $resp->getStatusCode());
        self::assertStringContainsString('belongs to layouts', (string) $resp->getContent());
    }

    /**
     * The product page's field blocks (type layouts plan C1) belong to layouts too: an entry, a
     * region and a saved section refuse `product_name` at the block's path.
     */
    public function testProductFieldBlocksBelongToLayouts(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        try {
            $this->validator()->validate($schema, ['body' => [self::nested('product_name')]], true);
            self::fail('an entry must refuse a product field block');
        } catch (ValidationException $e) {
            self::assertSame("'product_name' belongs to layouts", $e->errors()['body.0.content.0'] ?? null);
        }
        try {
            $this->container()->get(RegionValidator::class)->validate('footer', [self::nested('product_name')], []);
            self::fail('a region must refuse a product field block');
        } catch (ValidationException $e) {
            self::assertStringContainsString("'product_name' belongs to layouts", json_encode($e->errors()));
        }
        $dto = (new RequestDataHydrator())->hydrate(
            SaveSectionData::class,
            ['name' => 'Sneaky product', 'block' => self::nested('product_name')],
        );
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        $resp = $this->container()->get(SavedSectionController::class)->store($dto, $request);
        self::assertSame(422, $resp->getStatusCode());
        self::assertStringContainsString("'product_name' belongs to layouts", (string) $resp->getContent());
    }

    /**
     * The shop pages' blocks (type layouts plan C2) belong to layouts too: an entry, a region and a
     * saved section refuse `product_loop` and `product_tile` at the block's path.
     */
    public function testShopLayoutBlocksBelongToLayouts(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        foreach (['product_loop', 'product_tile'] as $slug) {
            try {
                $this->validator()->validate($schema, ['body' => [self::nested($slug)]], true);
                self::fail("an entry must refuse {$slug}");
            } catch (ValidationException $e) {
                self::assertSame("'{$slug}' belongs to layouts", $e->errors()['body.0.content.0'] ?? null);
            }
            try {
                $this->container()->get(RegionValidator::class)->validate('footer', [self::nested($slug)], []);
                self::fail("a region must refuse {$slug}");
            } catch (ValidationException $e) {
                self::assertStringContainsString("'{$slug}' belongs to layouts", json_encode($e->errors()));
            }
            $dto = (new RequestDataHydrator())->hydrate(
                SaveSectionData::class,
                ['name' => 'Sneaky ' . $slug, 'block' => self::nested($slug)],
            );
            $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
            $request->attributes->set('user', ['uuid' => 'editor000001']);
            $resp = $this->container()->get(SavedSectionController::class)->store($dto, $request);
            self::assertSame(422, $resp->getStatusCode(), $slug);
            self::assertStringContainsString("'{$slug}' belongs to layouts", (string) $resp->getContent());
        }
    }

    /**
     * The listing page's blocks (type layouts plan B) belong to layouts too: an entry, a region and a
     * saved section refuse `entry_loop` and `pagination` at the block's path.
     */
    public function testListingBlocksBelongToLayouts(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        foreach (['entry_loop', 'pagination'] as $type) {
            try {
                $this->validator()->validate($schema, ['body' => [self::nested($type)]], true);
                self::fail("an entry must refuse {$type}");
            } catch (ValidationException $e) {
                self::assertSame("'{$type}' belongs to layouts", $e->errors()['body.0.content.0'] ?? null);
            }
            try {
                $this->container()->get(RegionValidator::class)->validate('footer', [self::nested($type)], []);
                self::fail("a region must refuse {$type}");
            } catch (ValidationException $e) {
                self::assertStringContainsString("'{$type}' belongs to layouts", json_encode($e->errors()));
            }
            $dto = (new RequestDataHydrator())->hydrate(
                SaveSectionData::class,
                ['name' => 'Sneaky ' . $type, 'block' => self::nested($type)],
            );
            $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
            $request->attributes->set('user', ['uuid' => 'editor000001']);
            $resp = $this->container()->get(SavedSectionController::class)->store($dto, $request);
            self::assertSame(422, $resp->getStatusCode());
            self::assertStringContainsString("'{$type}' belongs to layouts", (string) $resp->getContent());
        }
    }

    public function testAValidatorForLayoutsAcceptsIt(): void
    {
        $schema = ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
        $clean = $this->validator()->forLayouts()->validate($schema, ['blocks' => [self::nested()]], true);
        self::assertSame('entry_title', $clean['blocks'][0]['data']['content'][0]['type']);
    }
}
