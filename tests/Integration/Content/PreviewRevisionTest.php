<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\DTOs\ApplyPreviewData;
use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Content\Http\DTOs\SaveDraftData;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Style\SiteStyleGeneration;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/**
 * The apply protocol with atomic acceptance (visual builder spec §3.5): three revisions, one
 * epoch per working-copy lifetime, compare-and-set acceptance, and a save that clears only the
 * revision it was submitted from.
 */
final class PreviewRevisionTest extends AppTestCase
{
    private string $entry = '';

    protected function setUp(): void
    {
        parent::setUp();
        if ((new BlockTypeRepository($this->connection()))->findBySlug('card') === null) {
            (new BlockTypeRepository($this->connection()))->create([
                'slug' => 'card',
                'label' => 'Card',
                'schema' => [['name' => 'title', 'type' => 'string']],
            ]);
        }
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $this->entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');
    }

    protected function tearDown(): void
    {
        // A canvas render turns block annotation on for the process-shared extension; later
        // tests render through the same singleton without a controller to reset it.
        $this->container()->get(RenderContextExtension::class)->setBlockAnnotations(false);
        parent::tearDown();
    }

    private function store(): PreviewWorkingCopyStore
    {
        return $this->container()->get(PreviewWorkingCopyStore::class);
    }

    private function token(): string
    {
        return $this->container()->get(PreviewMinter::class)->mint($this->entry, 'en', null);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function apply(?string $epoch, ?int $base, string $title = 'Working', array $ops = []): array
    {
        $response = $this->container()->get(EntryController::class)->applyPreview(
            new ApplyPreviewData(
                token: $this->token(),
                fields: ['title' => $title],
                epoch: $epoch,
                base_revision: $base,
                operations: $ops,
            ),
            $this->req(),
            $this->entry,
            'en',
        );
        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    public function testTheFirstApplyMintsAnEpochAndEveryAcceptedApplyAdvancesTheRevision(): void
    {
        $first = $this->apply(null, null);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        $data = $first['body']['data'];
        self::assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $data['epoch'], 'a ULID');
        self::assertSame(1, $data['revision']);
        self::assertSame(0, $data['baseline']);
        self::assertSame(0, $data['style_generation']);
        self::assertNotEmpty($data['applied_at']);

        $second = $this->apply($data['epoch'], 1, 'Again');
        self::assertSame(200, $second['status']);
        self::assertSame($data['epoch'], $second['body']['data']['epoch']);
        self::assertSame(2, $second['body']['data']['revision']);
        self::assertSame(1, $second['body']['data']['baseline']);
        self::assertSame('Again', $this->store()->fields($this->entry, 'en')['title']);
        self::assertSame(['epoch' => $data['epoch'], 'revision' => 2], $this->store()->current($this->entry, 'en'));
    }

    public function testTwoAppliesFromTheSameBaseOneWinsAndTheOtherIs409WithTheCurrentPair(): void
    {
        $epoch = $this->apply(null, null)['body']['data']['epoch'];
        $winner = $this->apply($epoch, 1, 'A');
        $loser = $this->apply($epoch, 1, 'B');
        self::assertSame(200, $winner['status']);
        self::assertSame(409, $loser['status']);
        self::assertSame('PREVIEW_REVISION_STALE', $loser['body']['error']['details']['code'] ?? null);
        $current = $loser['body']['error']['details']['current'] ?? null;
        self::assertSame(['epoch' => $epoch, 'revision' => 2], $current);
        self::assertSame('A', $this->store()->fields($this->entry, 'en')['title'], 'the loser never wrote');
    }

    public function testANullPairInitialisesOnlyWhenNoRecordExists(): void
    {
        $this->apply(null, null);
        $again = $this->apply(null, null);
        self::assertSame(409, $again['status']);
        $current = $again['body']['error']['details']['current'] ?? null;
        self::assertSame(1, $current['revision']);
    }

    public function testAfterSaveOrExpiryTheNextApplyStartsANewEpoch(): void
    {
        $epoch = $this->apply(null, null)['body']['data']['epoch'];
        $this->apply($epoch, 1); // revision 2
        // Save submitted from revision 2 clears the record.
        $save = $this->container()->get(EntryController::class)->saveDraft(
            new SaveDraftData(fields: ['title' => 'Saved'], lock_version: 1, preview_revision: 2),
            $this->req(),
            $this->entry,
            'en',
        );
        self::assertSame(200, $save->getStatusCode());
        self::assertTrue(json_decode((string) $save->getContent(), true)['data']['preview_cleared']);
        self::assertNull($this->store()->current($this->entry, 'en'));

        // The client still holds (epoch, 2): a stale pair against no record is 409 with a null
        // current pair; a null pair then starts epoch 2 at revision 1.
        $stale = $this->apply($epoch, 2);
        self::assertSame(409, $stale['status']);
        $details = $stale['body']['error']['details'];
        self::assertArrayHasKey('current', $details);
        self::assertNull($details['current'], 'no record: the current pair is null');
        $fresh = $this->apply(null, null);
        self::assertSame(200, $fresh['status']);
        self::assertNotSame($epoch, $fresh['body']['data']['epoch']);
        self::assertSame(1, $fresh['body']['data']['revision']);

        // Expiry looks the same to the client: the record is gone.
        $this->store()->clear($this->entry, 'en');
        self::assertSame(409, $this->apply($fresh['body']['data']['epoch'], 1)['status']);
        self::assertSame(200, $this->apply(null, null)['status']);
    }

    public function testASaveSubmittedFromAnOlderRevisionDoesNotClearANewerAcceptedCopy(): void
    {
        $epoch = $this->apply(null, null)['body']['data']['epoch'];
        $this->apply($epoch, 1, 'Newer'); // revision 2 accepted after the save was submitted from 1
        $save = $this->container()->get(EntryController::class)->saveDraft(
            new SaveDraftData(fields: ['title' => 'Older'], lock_version: 1, preview_revision: 1),
            $this->req(),
            $this->entry,
            'en',
        );
        self::assertSame(200, $save->getStatusCode());
        self::assertFalse(json_decode((string) $save->getContent(), true)['data']['preview_cleared']);
        self::assertSame(['epoch' => $epoch, 'revision' => 2], $this->store()->current($this->entry, 'en'));
        self::assertSame('Newer', $this->store()->fields($this->entry, 'en')['title']);

        // A save without a revision (the form editor) clears unconditionally.
        $plain = $this->container()->get(EntryController::class)->saveDraft(
            new SaveDraftData(fields: ['title' => 'Form'], lock_version: 2),
            $this->req(),
            $this->entry,
            'en',
        );
        self::assertSame(200, $plain->getStatusCode());
        self::assertNull($this->store()->current($this->entry, 'en'));
    }

    public function testASecondEditorMintingLearnsTheAcceptedPairAndTheCanvasPageCarriesIt(): void
    {
        $before = $this->container()->get(PreviewController::class)
            ->mint(new MintPreviewData(), $this->req(), $this->entry, 'en');
        $mint = json_decode((string) $before->getContent(), true)['data'];
        self::assertNull($mint['epoch']);
        self::assertNull($mint['revision']);

        $epoch = $this->apply(null, null)['body']['data']['epoch'];
        $after = $this->container()->get(PreviewController::class)
            ->mint(new MintPreviewData(), $this->req(), $this->entry, 'en');
        $mint = json_decode((string) $after->getContent(), true)['data'];
        self::assertSame($epoch, $mint['epoch']);
        self::assertSame(1, $mint['revision']);

        $canvas = Request::create('/_preview/' . $mint['token'] . '?canvas=1', 'GET');
        $html = (string) $this->handle($canvas)->getContent();
        self::assertStringContainsString('data-thallo-epoch="' . $epoch . '"', $html);
        self::assertStringContainsString('data-thallo-revision="1"', $html);
        self::assertStringContainsString('Working', $html, 'the accepted working copy renders');
    }

    public function testOperationsAreSanitisedAndKeptWithTheRecord(): void
    {
        $ops = [
            ['type' => 'SetField', 'op_id' => 'a', 'block' => 'x', 'field' => 'title'],
            'garbage',
            ['no_type' => true],
        ];
        $this->apply(null, null, 'Working', $ops);
        $record = $this->store()->record($this->entry, 'en');
        $kept = ['type' => 'SetField', 'op_id' => 'a', 'block' => 'x', 'field' => 'title'];
        $expected = [$kept];
        self::assertSame($expected, $record['ops']);
        self::assertSame(1, $record['revision']);
        self::assertNotEmpty($record['accepted_at']);
    }

    public function testTheStyleGenerationIsAnIncrementingSystemFlag(): void
    {
        $generation = $this->container()->get(SiteStyleGeneration::class);
        $start = $generation->current();
        self::assertSame($start + 1, $generation->increment());
        self::assertSame($start + 1, $generation->current());
        self::assertSame($start + 1, $this->apply(null, null)['body']['data']['style_generation']);
    }
}
