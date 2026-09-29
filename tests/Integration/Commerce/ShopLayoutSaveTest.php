<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Save and Remove for the shop layouts (type layouts plan C2, S4): Release A's contract at the
 * site-wide target of the shop home and of the category pages — versions, conflicts, tombstones, a
 * retired session — with the Product list required exactly once and never hidden, card blocks only
 * in its card, and each surface's blocks refused on the others.
 */
final class ShopLayoutSaveTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        foreach (['shop_index', 'shop_category', 'product', 'listing'] as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $surface === 'listing' ? 'post' : '@site');
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function session(string $surface, string $target = '@site'): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => $target],
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function save(string $surface, string $token, array $blocks, int $expected, string $target = '@site'): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []],
                'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'PUT'),
            $surface,
            $target,
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
    private function remove(string $surface, string $token, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $token, 'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'DELETE'),
            $surface,
            '@site',
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @param list<array<string,mixed>> $card */
    private static function loop(string $id = 'saveloop0001', array $card = []): array
    {
        return ['id' => $id, 'type' => 'product_loop', 'data' => ['card' => $card], 'settings' => []];
    }

    private static function block(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'data' => $data, 'settings' => []];
    }

    private static function layout(string $marker): array
    {
        return [self::block('savemarker01', 'heading', ['text' => $marker]), self::loop()];
    }

    public function testSaveAndRemoveKeepTheContract(): void
    {
        foreach (['shop_index', 'shop_category'] as $surface) {
            $a = $this->session($surface);
            self::assertTrue($a['starter'], $surface);
            self::assertSame(0, $a['layout']['lock_version']);
            $first = $this->save($surface, $a['token'], self::layout('ONE'), 0);
            self::assertSame(200, $first['status'], json_encode($first['body']));
            self::assertSame(1, $first['body']['data']['layout']['lock_version']);

            $stale = $this->save($surface, $this->session($surface)['token'], self::layout('STALE'), 0);
            self::assertSame(409, $stale['status'], $surface);
            self::assertSame('LAYOUT_VERSION_CONFLICT', $stale['body']['error']['details']['code'] ?? null);
            self::assertSame(
                'ONE',
                $this->container()->get(LayoutResolver::class)->for($surface, '@site')['blocks'][0]['data']['text'],
                "{$surface}: nothing written by the stale save",
            );

            $removed = $this->remove($surface, $a['token'], 1);
            self::assertSame(200, $removed['status'], json_encode($removed['body']));
            self::assertSame(2, $removed['body']['data']['lock_version']);
            self::assertSame(410, $this->apply($a['token'], self::layout('AFTER'))['status'], 'the session is retired');

            $fresh = $this->session($surface);
            self::assertTrue($fresh['starter']);
            self::assertSame(2, $fresh['layout']['lock_version'], 'the tombstone keeps the version');
            self::assertSame(
                3,
                $this->save($surface, $fresh['token'], self::layout('AGAIN'), 2)['body']['data']['layout']
                    ['lock_version'],
            );
        }
    }

    public function testTheProductListIsRequiredExactlyOnce(): void
    {
        $session = $this->session('shop_index');
        $none = [self::block('savemarker01', 'heading', ['text' => 'No list'])];
        $answers = [$this->save('shop_index', $session['token'], $none, 0), $this->apply($session['token'], $none)];
        foreach ($answers as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'the layout must show the Product list block',
                $answer['body']['error']['details']['blocks'] ?? null,
            );
        }
        $twice = [...self::layout('Twice'), self::loop('saveloop0002')];
        $answer = $this->save('shop_index', $session['token'], $twice, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'product_loop' can appear only once in a layout",
            $answer['body']['error']['details']['blocks.2.type'] ?? null,
        );
    }

    public function testCardBlocksGoOnlyInTheCardAndPageBlocksNeverInIt(): void
    {
        $session = $this->session('shop_index');
        $rootName = [...self::layout('Root'), self::block('savename0001', 'product_name')];
        $answer = $this->save('shop_index', $session['token'], $rootName, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'product_name' goes inside the Product list's card",
            $answer['body']['error']['details']['blocks.2.type'] ?? null,
        );

        $inCard = [self::loop('saveloop0001', [self::block('savepage0001', 'pagination')])];
        $answer = $this->save('shop_index', $session['token'], $inCard, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'pagination' cannot go inside a card",
            $answer['body']['error']['details']['blocks.0.data.card.0.type'] ?? null,
        );

        $fine = [self::loop('saveloop0001', [
            self::block('savetile0001', 'product_tile'),
            ['id' => 'savebox00001', 'type' => 'container', 'data' => ['content' => [
                self::block('savename0001', 'product_name', ['link' => true]),
            ]], 'settings' => []],
        ])];
        $saved = $this->save('shop_index', $session['token'], $fine, 0);
        self::assertSame(200, $saved['status'], 'a container in the card');
    }

    /** No size loses the Product list: a container around it cannot be hidden either. */
    public function testAContainerHoldingTheProductListCannotBeHidden(): void
    {
        $session = $this->session('shop_category');
        $tucked = [[
            'id' => 'savebox00001', 'type' => 'container',
            'data' => ['content' => [self::loop()]],
            'settings' => ['style' => ['visibility' => ['md' => ['type' => 'choice', 'value' => 'hidden']]]],
        ]];
        $answers = [
            $this->save('shop_category', $session['token'], $tucked, 0),
            $this->apply($session['token'], $tucked),
        ];
        foreach ($answers as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'this block holds the Product list block, which every page shows: it cannot be hidden',
                $answer['body']['error']['details']['blocks.0.settings.style.visibility'] ?? null,
            );
        }
    }

    /** Each surface's blocks are refused on the others, at their path. */
    public function testEachSurfacesBlocksAreRefusedElsewhere(): void
    {
        $shop = $this->session('shop_index');
        $entryTitle = [...self::layout('Entry'), self::block('saveentry001', 'entry_title')];
        $answer = $this->save('shop_index', $shop['token'], $entryTitle, 0);
        self::assertSame(422, $answer['status']);
        self::assertArrayHasKey('blocks.2.type', $answer['body']['error']['details'], json_encode($answer['body']));

        $buy = [...self::layout('Buy'), self::block('savebuy00001', 'product_buy')];
        $answer = $this->save('shop_index', $shop['token'], $buy, 0);
        self::assertSame(422, $answer['status']);
        self::assertArrayHasKey('blocks.2.type', $answer['body']['error']['details'], json_encode($answer['body']));

        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $listing = $this->session('listing', 'post');
        $answer = $this->save('listing', $listing['token'], [
            ['id' => 'saveloop0009', 'type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []],
            self::loop(),
        ], 0, 'post');
        self::assertSame(422, $answer['status']);
        self::assertArrayHasKey('blocks.1.type', $answer['body']['error']['details'], json_encode($answer['body']));
    }

    /** The three commerce layouts keep their own versions. */
    public function testEachShopLayoutKeepsItsOwnVersion(): void
    {
        $home = $this->session('shop_index');
        self::assertSame(200, $this->save('shop_index', $home['token'], self::layout('HOME'), 0)['status']);
        $product = $this->session('product');
        self::assertSame(0, $product['layout']['lock_version'], 'the product layout is untouched');
        self::assertSame(0, $this->session('shop_category')['layout']['lock_version']);
        $saved = $this->save('product', $product['token'], [
            self::block('savemarker01', 'heading', ['text' => 'Product']),
            self::block('savebuy00001', 'product_buy'),
        ], 0);
        self::assertSame(200, $saved['status'], json_encode($saved['body']));
        self::assertSame(1, $this->session('shop_index')['layout']['lock_version'], 'the home keeps its version');
    }
}
