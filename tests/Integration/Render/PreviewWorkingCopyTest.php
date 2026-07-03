<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewWorkingCopyStore;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Loop C spec §3: /_preview/{token} overlays the stashed working copy over the
 * DB draft for DRAFT-MODE tokens only; saveDraft clears the stash; pinned
 * versions are never overlaid.
 */
final class PreviewWorkingCopyTest extends LemmaTestCase
{
    private string $type;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Glueful\Lemma\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    /** @return array{entry:string,version:string} */
    private function seedBlockPage(string $slug): array
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'quote',
            'label' => 'Quote',
            'schema' => [['name' => 'text', 'type' => 'text']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Draft only']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', $slug);
        $version = (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        return ['entry' => $entry, 'version' => $version];
    }

    private function renderPreview(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        );
        return (string) $response->getContent();
    }

    public function testWorkingCopyOverlaysDraftAndSaveClearsIt(): void
    {
        ['entry' => $entry] = $this->seedBlockPage('wc-page');
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        // No stash: the draft renders.
        self::assertStringContainsString('Draft only', $this->renderPreview($token));

        // Stash a working copy with a block that exists ONLY there.
        $store->put($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Draft only']],
            ['id' => 'workingb0001', 'type' => 'quote', 'data' => ['text' => 'Applied only']],
        ]], 60);
        $html = $this->renderPreview($token);
        self::assertStringContainsString('Applied only', $html);
        // The working-only block is ANNOTATED like any rendered instance.
        self::assertStringContainsString('data-lemma-block="workingb0001"', $html);

        // saveDraft SUCCESS clears the stash (clear-on-save pin): the next render
        // shows the (updated) draft, not a stale working copy. Read the CURRENT
        // lock (seed + publish each bump it) instead of guessing a literal.
        $entriesRepo = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $lock = (int) ($entriesRepo->findDraft($entry, 'en')['lock_version'] ?? 0);
        $save = $this->container()->get(EntryController::class)->saveDraft(
            new SaveDraftData(fields: ['title' => 'S', 'body' => [
                ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Saved now']],
            ]], lock_version: $lock),
            Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'),
            $entry,
            'en',
        );
        self::assertSame(200, $save->getStatusCode());
        self::assertNull($store->get($entry, 'en'));
        $after = $this->renderPreview($token);
        self::assertStringContainsString('Saved now', $after);
        self::assertStringNotContainsString('Applied only', $after);
    }

    public function testVersionPinnedTokensAreNeverOverlaid(): void
    {
        ['entry' => $entry, 'version' => $version] = $this->seedBlockPage('wc-pinned');
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $store->put($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'workingb0002', 'type' => 'quote', 'data' => ['text' => 'Applied only']],
        ]], 60);

        $pinned = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', $version);
        $html = $this->renderPreview($pinned);
        // Hard requirement (spec §3): the pinned version renders, the stash never shows.
        self::assertStringContainsString('Draft only', $html);
        self::assertStringNotContainsString('Applied only', $html);
    }
}
