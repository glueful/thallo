<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\DTOs\RestoreDraftData;
use Thallo\Core\Content\Http\DTOs\SaveDraftData;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Retention\VersionPruner;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Restore to draft on the server, with a persisted basis (custom palette spec §4.5; plan Task 12):
 * the version is loaded by id; its brand colours are trusted by block for this and later saves —
 * undo, redo, retention pruning the version — and never widen to a new block or a client-supplied
 * payload. Load, save and restore responses carry the palette generation and the bounded record batch.
 */
final class DraftRestoreTest extends AppTestCase
{
    use PaletteFixtures;
    use SyncsBlockStyleDeclarations;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfrest', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'blocks']],
        ]);
    }

    private function repo(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function controller(): EntryController
    {
        return $this->container()->get(EntryController::class);
    }

    /** @return array{0: string, 1: int} */
    private function entry(): array
    {
        $uuid = $this->repo()->createEntry($this->type, 'en', 1, 'user00000001');
        return [$uuid, $this->lockOf($uuid)];
    }

    private function lockOf(string $uuid): int
    {
        return (int) ($this->repo()->findDraft($uuid, 'en')['lock_version'] ?? 0);
    }

    /** @param list<array<string,mixed>> $blocks */
    private function publishWithBlocks(string $uuid, array $blocks): string
    {
        $this->repo()->saveDraft($uuid, 'en', ['body' => $blocks], 1, $this->lockOf($uuid), 'user00000001');
        return $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
    }

    private function publishWith(string $uuid, string $token): string
    {
        return $this->publishWithBlocks($uuid, [self::heading($token)]);
    }

    /**
     * A version holding these blocks that a completed Replace did not rewrite: a newer version is
     * pinned before Brand 1 is replaced by Accent and cleared, so the old one is history.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private function historical(string $uuid, array $blocks): string
    {
        $old = $this->publishWithBlocks($uuid, $blocks);
        $this->publishWith($uuid, 'color.accent');
        $this->replaceAndClear(1, 'color.accent');
        return $old;
    }

    private function restore(string $uuid, string $version, ?int $through = null): \Glueful\Http\Response
    {
        $dto = (new RequestDataHydrator())->hydrate(RestoreDraftData::class, [
            'version_uuid' => $version, 'lock_version' => $this->lockOf($uuid), 'palette_through' => $through,
        ]);
        return $this->controller()->restoreDraft($dto, Request::create('/'), $uuid, 'en');
    }

    /** @param array<string,mixed> $fields */
    private function putDraft(string $uuid, array $fields, ?int $through = null): \Glueful\Http\Response
    {
        $dto = (new RequestDataHydrator())->hydrate(SaveDraftData::class, [
            'fields' => $fields, 'lock_version' => $this->lockOf($uuid), 'palette_through' => $through,
        ]);
        return $this->controller()->saveDraft($dto, Request::create('/'), $uuid, 'en');
    }

    /** @return array<string,mixed> */
    private static function data(\Glueful\Http\Response $res): array
    {
        return json_decode((string) $res->getContent(), true)['data'] ?? [];
    }

    /** @return array<string,mixed> */
    private function draftFields(string $uuid): array
    {
        return (array) ($this->repo()->findDraft($uuid, 'en')['fields'] ?? []);
    }

    private function draftToken(string $uuid): ?string
    {
        return $this->draftFields($uuid)['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
    }

    private function blockToken(string $uuid, string $id): ?string
    {
        foreach ((array) ($this->draftFields($uuid)['body'] ?? []) as $block) {
            if (($block['id'] ?? null) === $id) {
                return $block['settings']['style']['colors']['text']['value'] ?? null;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $fields */
    private function save(string $uuid, array $fields): void
    {
        $this->repo()->saveDraft($uuid, 'en', $fields, 1, $this->lockOf($uuid), 'user00000001');
    }

    public function testRestoringBringsBackAnUnavailableReferenceAndSavingItAgainKeepsIt(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [self::heading('color.brand-1')]);
        $res = $this->restore($uuid, $old);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('color.brand-1', $this->draftToken($uuid), 'restored although the draft held Accent there');
        $edited = $this->draftFields($uuid);
        $edited['title'] = 'Changed';
        $this->save($uuid, $edited);
        self::assertSame('color.brand-1', $this->draftToken($uuid));
    }

    public function testRestoreThenUndoThenRedoIsAccepted(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [self::heading('color.brand-1')]);
        $before = $this->draftFields($uuid);
        $this->restore($uuid, $old);
        $restored = $this->draftFields($uuid);
        $this->save($uuid, $before);   // undo
        $this->save($uuid, $restored); // redo
        self::assertSame('color.brand-1', $this->draftToken($uuid));
    }

    public function testRedoStillSavesAfterRetentionPrunedTheRestoredVersion(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [self::heading('color.brand-1')]);
        $before = $this->draftFields($uuid);
        $this->restore($uuid, $old);
        $restored = $this->draftFields($uuid);
        $this->save($uuid, $before);
        self::assertSame(1, $this->container()->get(VersionPruner::class)->deleteGuarded([$old]));
        $this->save($uuid, $restored);
        self::assertSame('color.brand-1', $this->draftToken($uuid), 'the persisted basis does not need the version');
    }

    public function testAVersionPrunedBeforeTheRestoreReadsItIs404(): void
    {
        [$uuid] = $this->entry();
        $old = $this->publishWith($uuid, 'color.accent');
        $this->publishWith($uuid, 'color.muted');
        $this->container()->get(VersionPruner::class)->deleteGuarded([$old]);
        self::assertSame(404, $this->restore($uuid, $old)->getStatusCode());
    }

    public function testAVersionPrunedAfterTheRestoreReadItStillRestoresWithItsBasis(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [self::heading('color.brand-1')]);
        $pruner = $this->container()->get(VersionPruner::class);
        $this->state()->afterNextSnapshot(fn () => $pruner->deleteGuarded([$old]));
        self::assertSame(200, $this->restore($uuid, $old)->getStatusCode());
        $this->save($uuid, $this->draftFields($uuid));
        self::assertSame('color.brand-1', $this->draftToken($uuid));
    }

    public function testTheBasisFollowsTheRestoredBlockButNotANewOne(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [
            self::heading('color.brand-1', 'head0000000a'),
            self::heading('color.accent', 'head0000000b'),
        ]);
        $this->restore($uuid, $old);
        $moved = ['body' => [
            self::heading('color.accent', 'head0000000b'),
            self::heading('color.brand-1', 'head0000000a'),
        ]];
        $this->save($uuid, $moved);
        self::assertSame('color.brand-1', $this->blockToken($uuid, 'head0000000a'));
        $added = ['body' => [...$moved['body'], self::heading('color.brand-1', 'head0000000c')]];
        $this->expectException(PaletteRefusal::class);
        $this->save($uuid, $added);
    }

    public function testAPlainSaveCannotSmuggleAHistoricalReference(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->publishWith($uuid, 'color.brand-1');
        $this->replaceAndClear(1, 'color.accent');
        self::assertSame(422, $this->putDraft($uuid, ['body' => [self::heading('color.brand-1')]])->getStatusCode());
    }

    public function testPublishForgetsTheBasis(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->historical($uuid, [self::heading('color.brand-1')]);
        $this->restore($uuid, $old);
        self::assertNotSame([], $this->repo()->restoreBasis($uuid, 'en'));
        $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
        self::assertSame([], $this->repo()->restoreBasis($uuid, 'en'));
    }

    public function testAnotherEntrysVersionIs404(): void
    {
        [$a] = $this->entry();
        [$b] = $this->entry();
        self::assertSame(404, $this->restore($a, $this->publishWith($b, 'color.accent'))->getStatusCode());
    }

    public function testRestoreAndSaveResponsesCarryACompleteBoundedBatch(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->publishWith($uuid, 'color.brand-1');
        $getDraft = $this->controller()->getDraft(Request::create('/'), $uuid, 'en');
        $loaded = self::data($getDraft)['palette_generation'];
        $this->replaceAndClear(1, 'color.accent');
        $data = self::data($this->restore($uuid, $old, $loaded));
        $batch = $data['palette_replacements'];
        self::assertSame([$loaded, $data['palette_generation']], [$batch['after'], $batch['through']]);
        self::assertCount(1, $batch['records']);
        self::assertSame(
            ['color.brand-1' => 'color.accent', 'color.brand-1-contrast' => 'color.accent-contrast'],
            $batch['records'][0]['map'],
        );
        $next = self::data($this->putDraft($uuid, $this->draftFields($uuid), $data['palette_generation']));
        self::assertSame([], $next['palette_replacements']['records'], 'nothing newer than the client boundary');
    }

    public function testABatchNeverIncludesACompletionNewerThanTheResponsesGeneration(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $old = $this->publishWith($uuid, 'color.accent');
        $loaded = self::data($this->controller()->getDraft(Request::create('/'), $uuid, 'en'))['palette_generation'];
        $job = $this->startJob(2, 'color.accent', 'color.accent-contrast');
        // the job completes AFTER the restore's write committed but BEFORE its response is built: the
        // after-commit hook is registered from inside the restore's own fenced transaction
        $this->state()->afterNextSnapshot(fn () => $this->connection()->afterCommit(fn () => $this->completeJob($job)));
        $data = self::data($this->restore($uuid, $old, $loaded));
        $completed = $this->container()->get(PaletteJobRepository::class)->find($job)?->completedGeneration;
        self::assertNotNull($completed, 'the job did complete');
        self::assertSame([], $data['palette_replacements']['records'], 'a newer completion waits for a later batch');
        self::assertLessThan($completed, $data['palette_replacements']['through']);
    }

    public function testALoadReadsItsDocumentAndGenerationConsistently(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->save($uuid, ['body' => [self::heading('color.brand-1')]]);
        // interleaving 1: a replacement completes between the first generation read and the document read
        $this->state()->afterNextSnapshot(fn () => $this->replaceAndClear(1, 'color.accent'));
        $data = self::data($this->controller()->getDraft(Request::create('/'), $uuid, 'en'));
        $body = $data['draft']['fields']['body'];
        self::assertSame('color.accent', $body[0]['settings']['style']['colors']['text']['value']);
        self::assertSame($this->state()->generationNow(), $data['palette_generation']);
    }

    public function testALoadWhoseDocumentPredatesACompletionIsReadAgain(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->save($uuid, ['body' => [self::heading('color.brand-1')]]);
        $reads = 0;
        [$doc, $generation] = $this->state()->consistentRead(function () use ($uuid, &$reads): array {
            $doc = $this->draftFields($uuid);
            if ($reads++ === 0) {
                $this->replaceAndClear(1, 'color.accent'); // between the document and the second generation read
            }
            return $doc;
        });
        self::assertSame(2, $reads, 'retried');
        self::assertSame('color.accent', $doc['body'][0]['settings']['style']['colors']['text']['value']);
        self::assertSame($this->state()->generationNow(), $generation);
    }

    public function testRestoringDuringAReplacementMapsAndReportsTheRewrite(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->publishWith($uuid, 'color.brand-1');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $data = self::data($this->restore($uuid, $old));
        self::assertSame('color.accent', $this->draftToken($uuid));
        self::assertSame(
            [[
                'location' => 'head00000001:settings.style.colors.text',
                'from' => 'color.brand-1',
                'to' => 'color.accent',
            ]],
            $data['palette_rewrites'],
        );
        self::assertSame([], $data['palette_replacements']['records'], 'a running job is not a record');
    }

    public function testRestoringIsFencedAgainstAMutationAfterItsRead(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $old = $this->publishWith($uuid, 'color.brand-1');
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $this->restore($uuid, $old);
        self::assertSame('color.accent', $this->draftToken($uuid));
    }
}
