<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\ApplyPreviewData;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewToken;
use App\Content\Preview\PreviewWorkingCopyStore;
use App\Content\Preview\ResolvesPreviewKey;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Loop C spec §2: the apply endpoint runs the EXACT saveDraft guard order
 * (token verify -> route binding -> version-pin 409 -> locale -> cap ->
 * migration gate -> validator -> stash), fails closed on every path, and
 * stashes only the validator's CLEANED output.
 */
final class PreviewApplyTest extends AppTestCase
{
    use ResolvesPreviewKey;

    private string $type = '';

    private function store(): PreviewWorkingCopyStore
    {
        return $this->container()->get(PreviewWorkingCopyStore::class);
    }

    /** @return array{entry:string} */
    private function seedEntry(): array
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'card',
            'label' => 'Card',
            'schema' => [['name' => 'title', 'type' => 'string']],
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
        $entries->saveDraft($entry, 'en', ['title' => 'D'], 1, 0, 'user00000001');
        return ['entry' => $entry];
    }

    private function controller(): EntryController
    {
        return $this->container()->get(EntryController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    private function mint(string $entry, ?string $version = null, string $locale = 'en'): string
    {
        return $this->container()->get(PreviewMinter::class)->mint($entry, $locale, $version);
    }

    public function testWorkingCopyStoreRoundTripAndClear(): void
    {
        $store = $this->store();
        self::assertNull($store->get('entry0000001', 'en'));

        $store->put('entry0000001', 'en', ['title' => 'W'], 60);
        self::assertSame(['title' => 'W'], $store->get('entry0000001', 'en'));
        // Keyed per entry+locale: neighbours are isolated.
        self::assertNull($store->get('entry0000001', 'fr'));
        self::assertNull($store->get('entry0000002', 'en'));

        // Overwrite (last writer wins), then clear.
        $store->put('entry0000001', 'en', ['title' => 'W2'], 60);
        self::assertSame(['title' => 'W2'], $store->get('entry0000001', 'en'));
        $store->clear('entry0000001', 'en');
        self::assertNull($store->get('entry0000001', 'en'));
    }

    public function testApplyTokenFailuresAreFailClosed(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // Invalid token -> 403.
        $bad = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token . 'x', fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(403, $bad->getStatusCode());

        // Wrong entry for a VALID token -> 403 (binding), stash untouched.
        $other = (new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        ))->createEntry($this->type, 'en', 1, 'user00000001');
        $rebound = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W']),
            $this->req(),
            $other,
            'en',
        );
        self::assertSame(403, $rebound->getStatusCode());
        self::assertNull($this->store()->get($other, 'en'));

        // Expired token -> 410 (the SPA's re-mint-and-retry path keys off this),
        // no stash. Minted directly with an in-the-past expiry via the same key
        // derivation both sides use (ResolvesPreviewKey on the test class).
        $expired = PreviewToken::mint($entry, 'en', null, time() - 1, $this->previewKey($this->appContext()));
        $gone = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $expired, fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(410, $gone->getStatusCode());
        self::assertNull($this->store()->get($entry, 'en'));
    }

    public function testApplyRejectsVersionPinnedTokensWith409(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        // Any version uuid works: the pin check runs BEFORE existence checks.
        $pinned = $this->mint($entry, 'version00001');
        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $pinned, fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('PREVIEW_VERSION_PINNED', (string) $resp->getContent());
        self::assertNull($this->store()->get($entry, 'en'));
    }

    public function testApplyValidates422AndCaps413(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // Duplicate block ids across the entry -> the saveDraft 422 mirror.
        $dup = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W', 'body' => [
                ['id' => 'sameid000001', 'type' => 'card', 'data' => ['title' => 'a']],
                ['id' => 'sameid000001', 'type' => 'card', 'data' => ['title' => 'b']],
            ]]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(422, $dup->getStatusCode());
        self::assertNull($this->store()->get($entry, 'en'));

        // 1 MB cap -> 413.
        $big = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => str_repeat('x', 1100000)]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(413, $big->getStatusCode());

        // Unknown locale -> 422 (a token minted FOR that locale passes binding
        // and dies at the locale check, mirroring mint/saveDraft).
        $xx = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $this->mint($entry, null, 'xx'), fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'xx',
        );
        self::assertSame(422, $xx->getStatusCode());
    }

    public function testApplyIsGatedByBlockMigrations(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);
        $blockType = (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'];
        $this->container()->get(BlockMigrationService::class)
            ->migrate($blockType, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);

        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W', 'body' => [
                ['id' => 'blockone0001', 'type' => 'card', 'data' => ['title' => 'x']],
            ]]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('BLOCK_MIGRATION_IN_PROGRESS', (string) $resp->getContent());
        self::assertNull($this->store()->get($entry, 'en')); // no stash on 409
    }

    public function testApplyStashesTheCleanedFields(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // An unknown field rides along: the validator's CLEANED output drops it —
        // proving the stash never stores the raw payload.
        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: [
                'title' => 'Working',
                'not_in_schema' => 'dropped',
            ]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
        $stashed = $this->store()->get($entry, 'en');
        self::assertNotNull($stashed);
        self::assertSame('Working', $stashed['title']);
        self::assertArrayNotHasKey('not_in_schema', $stashed);
    }
}
