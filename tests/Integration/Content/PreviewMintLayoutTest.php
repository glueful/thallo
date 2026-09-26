<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\DTOs\ApplyPreviewData;
use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The entry's Design view learns whether a layout applies (type layouts spec §6.3): the mint and
 * every accepted apply answer the effective layout — the type has one and the accepted document
 * does not opt out — so the strip and the controls follow the document, not the first load.
 */
final class PreviewMintLayoutTest extends AppTestCase
{
    protected function tearDown(): void
    {
        foreach (['post', 'lpage'] as $slug) {
            $this->container()->get(LayoutResolver::class)->forget('entry', $slug);
        }
        parent::tearDown();
    }

    /** @param array<string,mixed>|null $presentation */
    private function entry(string $type, ?array $presentation = null): string
    {
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = $types->findBySlug($type)['uuid'] ?? $types->create([
            'slug' => $type,
            'name' => $type === 'post' ? 'Posts' : 'Pages',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry((string) $typeUuid, 'en', 1, 'user00000001');
        $fields = ['title' => 'Draft'];
        if ($presentation !== null) {
            $fields['_presentation'] = $presentation;
        }
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    private function saveLayout(string $type): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = [
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ];
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            $type,
            fn (): int => $repo->saveExpected('entry', $type, $blocks, [], 0, null),
        );
        $this->container()->get(LayoutResolver::class)->forget('entry', $type);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array<string,mixed> */
    private function mint(string $entry): array
    {
        $response = $this->container()->get(PreviewController::class)
            ->mint(new MintPreviewData(), $this->req(), $entry, 'en');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function apply(string $entry, string $token, array $fields, ?string $epoch, ?int $base): array
    {
        $response = $this->container()->get(EntryController::class)->applyPreview(
            new ApplyPreviewData(token: $token, fields: $fields, epoch: $epoch, base_revision: $base),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    public function testTheMintNamesTheEffectiveLayout(): void
    {
        $this->saveLayout('post');
        $post = $this->entry('post');
        self::assertSame(
            ['surface' => 'entry', 'target' => 'post', 'label' => 'Posts — single post'],
            $this->mint($post)['layout'],
        );

        $page = $this->entry('lpage');
        self::assertNull($this->mint($page)['layout']);

        $optedOut = $this->entry('post', ['use_layout' => false]);
        self::assertNull($this->mint($optedOut)['layout']);
    }

    public function testEachAcceptedApplyAnswersTheLayoutItsDocumentGets(): void
    {
        $this->saveLayout('post');
        $post = $this->entry('post');
        $token = $this->mint($post)['token'];

        $optOut = ['title' => 'Draft', '_presentation' => ['use_layout' => false]];
        $optIn = ['title' => 'Draft', '_presentation' => ['use_layout' => true]];
        $off = $this->apply($post, $token, $optOut, null, null);
        self::assertNull($off['layout']);

        $on = $this->apply($post, $token, $optIn, $off['epoch'], $off['revision']);
        self::assertSame('post', $on['layout']['target']);

        // The mint after an accepted opt-out follows the working copy, not the stored draft.
        $this->apply($post, $token, $optOut, $on['epoch'], $on['revision']);
        self::assertNull($this->mint($post)['layout']);
    }
}
