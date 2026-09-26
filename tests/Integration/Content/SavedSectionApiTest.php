<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\UpdateSavedSectionData;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Saved sections: a block an editor saves from the stage, offered in the Blocks tab's library
 * beside the shipped sections and inserted as a copy. What is saved is a document a save accepts,
 * stored without ids (every insert mints its own), and it leaves the library when its block type
 * can no longer be used — as a shipped section does.
 */
final class SavedSectionApiTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function api(): SavedSectionController
    {
        return $this->container()->get(SavedSectionController::class);
    }

    private function request(): Request
    {
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        return $request;
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function save(array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(SaveSectionData::class, $body);
        $resp = $this->api()->store($dto, $this->request());
        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    }

    /** @return array<string,mixed> */
    private function card(string $id, string $title = 'Card title'): array
    {
        return [
            'id' => $id,
            'type' => 'container',
            'data' => ['content' => [
                ['id' => $id . 'h', 'type' => 'heading', 'data' => ['text' => $title], 'settings' => []],
            ]],
            'settings' => ['style' => ['spacing' => ['padding' => ['top' => [
                'base' => ['type' => 'token', 'value' => 'spacing.lg'],
            ]]]]],
        ];
    }

    /** @return list<array<string,mixed>> the library's saved sections */
    private function saved(): array
    {
        return array_values(array_filter(
            $this->container()->get(PatternLibrary::class)->all(),
            static fn (array $p): bool => ($p['saved'] ?? false) === true,
        ));
    }

    public function testASavedBlockJoinsTheLibraryWithoutIdsAndAfterTheShippedSections(): void
    {
        $result = $this->save([
            'name' => 'Promo card',
            'description' => 'The spring promo, boxed.',
            'block' => $this->card('blockaaa0001'),
        ]);
        self::assertSame(201, $result['status'], json_encode($result['body']));
        $section = $result['body']['data']['section'];
        self::assertSame('Promo card', $section['label']);
        self::assertSame('Saved', $section['category']);
        self::assertSame('section', $section['kind']);
        self::assertTrue($section['saved']);
        self::assertSame('saved-' . $section['id'], $section['slug']);

        $library = $this->container()->get(PatternLibrary::class)->all();
        $last = end($library);
        self::assertSame($section['slug'], $last['slug'], 'saved sections come after the shipped ones');
        $block = $last['blocks'][0];
        self::assertArrayNotHasKey('id', $block);
        self::assertArrayNotHasKey('id', $block['data']['content'][0]);
        self::assertSame('Card title', $block['data']['content'][0]['data']['text']);
        self::assertSame('spacing.lg', $block['settings']['style']['spacing']['padding']['top']['base']['value']);
    }

    public function testWhatASaveWouldRefuseCannotBeSavedAndANameIsRequired(): void
    {
        $bad = $this->card('blockbbb0001');
        $bad['data']['content'][0]['type'] = 'no_such_block_type';
        self::assertSame(422, $this->save(['name' => 'Broken', 'block' => $bad])['status']);
        self::assertSame(422, $this->save(['name' => '  ', 'block' => $this->card('blockccc0001')])['status']);
        $long = $this->save(['name' => str_repeat('x', 121), 'block' => $this->card('blockddd0001')]);
        self::assertSame(422, $long['status']);
        self::assertSame([], $this->saved());
    }

    public function testASavedSectionIsRenamedRecategorisedAndDeleted(): void
    {
        $saved = $this->save(['name' => 'Promo card', 'block' => $this->card('blockeee0001')]);
        $id = $saved['body']['data']['section']['id'];
        $dto = (new RequestDataHydrator())->hydrate(UpdateSavedSectionData::class, [
            'name' => 'Spring promo',
            'category' => 'Promotions',
        ]);
        $renamed = $this->api()->update($dto, $id);
        self::assertSame(200, $renamed->getStatusCode());
        self::assertSame(['Spring promo', 'Promotions'], [$this->saved()[0]['label'], $this->saved()[0]['category']]);

        self::assertSame(200, $this->api()->destroy($id)->getStatusCode());
        self::assertSame([], $this->saved());
        self::assertSame(404, $this->api()->destroy($id)->getStatusCode());
        self::assertSame(404, $this->api()->update($dto, 'nosuchsectn1')->getStatusCode());
    }

    public function testASectionWhoseBlockTypeIsSwitchedOffLeavesTheLibraryUntilItIsBack(): void
    {
        $this->save(['name' => 'Promo card', 'block' => $this->card('blockfff0001')]);
        $types = new BlockTypeRepository($this->connection());
        $heading = $types->findBySlug('heading');
        self::assertNotNull($heading);
        $types->setActive((string) $heading['uuid'], false);
        try {
            self::assertSame([], $this->saved());
        } finally {
            $types->setActive((string) $heading['uuid'], true);
        }
        self::assertCount(1, $this->saved());
    }

    public function testASectionSavedFromTheHeaderOrFooterBelongsThereAndItsRegionMustTakeIt(): void
    {
        $page = $this->save(['name' => 'Promo card', 'block' => $this->card('blockggg0001')]);
        $section = $page['body']['data']['section'];
        self::assertSame(['page', null], [$section['scope'], $section['region']]);

        $footer = $this->save([
            'name' => 'Footer card',
            'scope' => 'region',
            'region' => 'footer',
            'block' => $this->card('blockhhh0001'),
        ]);
        self::assertSame(201, $footer['status'], json_encode($footer['body']));
        $listed = array_values(array_filter(
            $this->saved(),
            static fn (array $p): bool => $p['label'] === 'Footer card',
        ))[0];
        self::assertSame(['region', 'footer'], [$listed['scope'], $listed['region']]);

        // A heading is no block the header takes at its root.
        $heading = ['id' => 'blockiii0001', 'type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => []];
        $refused = $this->save(['name' => 'Big hello', 'scope' => 'region', 'region' => 'header', 'block' => $heading]);
        self::assertSame(422, $refused['status']);
        self::assertStringContainsString('block.type', json_encode($refused['body'], JSON_THROW_ON_ERROR));

        // A region section names its region, one of the two; the scope is one of the two.
        $bad = [
            ['scope' => 'region'],
            ['scope' => 'region', 'region' => 'sidebar'],
            ['scope' => 'everywhere'],
        ];
        foreach ($bad as $n => $place) {
            $result = $this->save(['name' => 'X', 'block' => $this->card("blockzz{$n}0001")] + $place);
            self::assertSame(422, $result['status'], json_encode($place));
        }
        self::assertCount(2, $this->saved());
    }

    public function testSavingAndChangingNeedContentManage(): void
    {
        $routes = [
            ['POST', '/v1/admin/saved-sections'],
            ['PATCH', '/v1/admin/saved-sections/{id}'],
            ['DELETE', '/v1/admin/saved-sections/{id}'],
        ];
        foreach ($routes as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path}");
            self::assertContains('content_permission:content.manage', $route['middleware']);
        }
    }
}
