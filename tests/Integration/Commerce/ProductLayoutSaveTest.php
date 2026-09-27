<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Save and Remove for the product layout (type layouts plan C1, P5): Release A's contract at the
 * site-wide target — versions, conflicts, tombstones, a retired session — with Add to cart required
 * exactly once, and each surface's field blocks refused on the other. (One product layout per
 * workspace, under real tenancy enforcement, is ProductLayoutTenancyTest's.)
 */
final class ProductLayoutSaveTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ProductPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ProductPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        $this->container()->get(LayoutResolver::class)->forget('product', '@site');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function session(): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function save(string $token, array $blocks, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []],
                'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'PUT'),
            'product',
            '@site',
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function apply(string $token, array $blocks): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []],
            ]),
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function remove(string $token, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $token, 'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'DELETE'),
            'product',
            '@site',
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    private static function layout(string $marker): array
    {
        return [
            ['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'savebuy00001', 'type' => 'product_buy', 'data' => [], 'settings' => []],
        ];
    }

    public function testSaveAndRemoveKeepTheContract(): void
    {
        $a = $this->session();
        self::assertTrue($a['starter']);
        self::assertSame(0, $a['layout']['lock_version']);
        $first = $this->save($a['token'], self::layout('ONE'), 0);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame(1, $first['body']['data']['layout']['lock_version']);

        $stale = $this->save($this->session()['token'], self::layout('STALE'), 0);
        self::assertSame(409, $stale['status']);
        self::assertSame('LAYOUT_VERSION_CONFLICT', $stale['body']['error']['details']['code'] ?? null);
        self::assertSame(
            'ONE',
            $this->container()->get(LayoutResolver::class)->for('product', '@site')['blocks'][0]['data']['text'],
        );

        $removed = $this->remove($a['token'], 1);
        self::assertSame(200, $removed['status'], json_encode($removed['body']));
        self::assertSame(2, $removed['body']['data']['lock_version']);
        self::assertSame(410, $this->apply($a['token'], self::layout('AFTER'))['status'], 'the session is retired');

        $fresh = $this->session();
        self::assertTrue($fresh['starter']);
        self::assertSame(2, $fresh['layout']['lock_version'], 'the tombstone keeps the version');
        self::assertSame(
            3,
            $this->save($fresh['token'], self::layout('AGAIN'), 2)['body']['data']['layout']['lock_version'],
        );
    }

    public function testALayoutWithoutTheBuyBlockIsRefused(): void
    {
        $session = $this->session();
        $none = [['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => 'No buy'], 'settings' => []]];
        foreach ([$this->save($session['token'], $none, 0), $this->apply($session['token'], $none)] as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'the layout must show the Add to cart block',
                $answer['body']['error']['details']['blocks'] ?? null,
            );
        }
        $twice = [
            ...self::layout('Twice'),
            ['id' => 'savebuy00002', 'type' => 'product_buy', 'data' => [], 'settings' => []],
        ];
        $answer = $this->save($session['token'], $twice, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'product_buy' can appear only once in a layout",
            $answer['body']['error']['details']['blocks.2.type'] ?? null,
        );
    }

    public function testEachSurfacesFieldBlocksAreRefusedOnTheOther(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        try {
            $validator->validate('product', '@site', [
                ...self::layout('Entry title here'),
                ['id' => 'entrytitle01', 'type' => 'entry_title', 'data' => [], 'settings' => []],
            ], []);
            self::fail('an entry field block is refused on the product surface');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.2.type', $e->errors());
        }

        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        try {
            $validator->validate('entry', 'post', [
                ['id' => 'postbody0001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
                ['id' => 'productname1', 'type' => 'product_name', 'data' => [], 'settings' => []],
            ], []);
            self::fail('a product field block is refused on the entry surface');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.1.type', $e->errors());
        }
    }
}
