<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Cache\CacheStore;
use Glueful\Validation\RequestDataHydrator;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Save and Remove for listing and archive layouts (type layouts plan B, B5): Release A's contract at
 * a type's listing and at one of its archives — versions, conflicts, tombstones, a retired session —
 * with the Entry list required, the card's blocks inside its card only, no page-level block inside
 * the card, and no size that loses the Entry list.
 */
final class ListingLayoutSaveTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
    }

    protected function tearDown(): void
    {
        foreach ([['listing', 'post'], ['archive', 'post:categories']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        yield 'listing' => ['listing', 'post'];
        yield 'archive' => ['archive', 'post:categories'];
    }

    /** @return array<string,mixed> */
    private function session(string $surface, string $target): array
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
    private function save(string $surface, string $target, string $token, array $blocks, int $expected): array
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
    private function remove(string $surface, string $target, string $token, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $token, 'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'DELETE'),
            $surface,
            $target,
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function layout(string $marker): array
    {
        return [
            ['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'saveloop0001', 'type' => 'entry_loop', 'data' => ['card' => [
                ['id' => 'savetitle001', 'type' => 'entry_title', 'data' => ['level' => 'h2'], 'settings' => []],
            ]], 'settings' => []],
        ];
    }

    #[DataProvider('targets')]
    public function testSaveAndRemoveKeepTheContract(string $surface, string $target): void
    {
        $a = $this->session($surface, $target);
        self::assertTrue($a['starter']);
        self::assertSame(0, $a['layout']['lock_version']);
        $first = $this->save($surface, $target, $a['token'], self::layout('ONE'), 0);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame(1, $first['body']['data']['layout']['lock_version']);

        $stale = $this->save($surface, $target, $this->session($surface, $target)['token'], self::layout('STALE'), 0);
        self::assertSame(409, $stale['status']);
        self::assertSame('LAYOUT_VERSION_CONFLICT', $stale['body']['error']['details']['code'] ?? null);
        self::assertSame(
            'ONE',
            $this->container()->get(LayoutResolver::class)->for($surface, $target)['blocks'][0]['data']['text'],
            'nothing written',
        );

        $removed = $this->remove($surface, $target, $a['token'], 1);
        self::assertSame(200, $removed['status'], json_encode($removed['body']));
        self::assertSame(2, $removed['body']['data']['lock_version']);
        self::assertNull($this->container()->get(LayoutResolver::class)->for($surface, $target), 'tombstoned');
        self::assertSame(410, $this->apply($a['token'], self::layout('AFTER'))['status'], 'the session is retired');

        $fresh = $this->session($surface, $target);
        self::assertTrue($fresh['starter']);
        self::assertSame(2, $fresh['layout']['lock_version'], 'the tombstone keeps the version');
        $again = $this->save($surface, $target, $fresh['token'], self::layout('AGAIN'), 2);
        self::assertSame(3, $again['body']['data']['layout']['lock_version']);
    }

    #[DataProvider('targets')]
    public function testALayoutWithoutTheEntryListIsRefused(string $surface, string $target): void
    {
        $session = $this->session($surface, $target);
        $none = [['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => 'No list'], 'settings' => []]];
        $answers = [
            $this->save($surface, $target, $session['token'], $none, 0),
            $this->apply($session['token'], $none),
        ];
        foreach ($answers as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'the layout must show the Entry list block',
                $answer['body']['error']['details']['blocks'] ?? null,
            );
        }
    }

    public function testACardBlockAtTheRootIsRefused(): void
    {
        $session = $this->session('listing', 'post');
        $loose = [
            ...self::layout('Loose'),
            ['id' => 'saveloose001', 'type' => 'entry_title', 'data' => [], 'settings' => []],
        ];
        $answer = $this->save('listing', 'post', $session['token'], $loose, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'entry_title' goes inside the Entry list's card",
            $answer['body']['error']['details']['blocks.2.type'] ?? null,
        );
    }

    public function testPaginationInsideTheCardIsRefused(): void
    {
        $session = $this->session('archive', 'post:categories');
        $inside = self::layout('Inside');
        $inside[1]['data']['card'][] = ['id' => 'savepages001', 'type' => 'pagination', 'data' => [], 'settings' => []];
        $answer = $this->save('archive', 'post:categories', $session['token'], $inside, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'pagination' cannot go inside a card",
            $answer['body']['error']['details']['blocks.1.data.card.1.type'] ?? null,
        );
    }

    /** No size loses the Entry list: a container around it cannot be hidden either. */
    public function testAContainerHoldingTheEntryListCannotBeHidden(): void
    {
        $session = $this->session('listing', 'post');
        $tucked = [[
            'id' => 'savebox00001', 'type' => 'container',
            'data' => ['content' => [self::layout('Tucked')[1]]],
            'settings' => ['style' => ['visibility' => ['md' => ['type' => 'choice', 'value' => 'hidden']]]],
        ]];
        $answers = [
            $this->save('listing', 'post', $session['token'], $tucked, 0),
            $this->apply($session['token'], $tucked),
        ];
        foreach ($answers as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'this block holds the Entry list block, which every page shows: it cannot be hidden',
                $answer['body']['error']['details']['blocks.0.settings.style.visibility'] ?? null,
            );
        }
    }
}
