<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Content\Enums\ScheduleAction;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ScheduleRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Scheduling\ScheduleRunner;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Custom palette spec §4.3, §4.5: every entry writer normalises under the palette fence, and both
 * orderings of a save against a palette change are safe — a save committed first is seen by the
 * change; a change committed first is caught by the save's fence, which normalises again from the
 * submitted payload.
 */
final class EntryWritersFenceTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfpage',
            'name' => 'Page',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /** @return array{type: string, value: string} */
    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    /** @return array<string,mixed> */
    private static function heading(string $token, string $id = 'head00000001'): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'],
            'settings' => ['style' => ['colors' => ['text' => self::tok($token)]]]];
    }

    private function repo(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function state(): PaletteState
    {
        return $this->container()->get(PaletteState::class);
    }

    private function publisher(): PublishService
    {
        return $this->container()->get(PublishService::class);
    }

    /** @return array{0: string, 1: int} the entry and its draft's lock version */
    private function entry(): array
    {
        $uuid = $this->repo()->createEntry($this->type, 'en', 1, 'user00000001');
        return [$uuid, $this->lockOf($uuid)];
    }

    private function lockOf(string $uuid, string $locale = 'en'): int
    {
        return (int) ($this->repo()->findDraft($uuid, $locale)['lock_version'] ?? 0);
    }

    private function configure(int $slot, string $name, string $hex): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_' . $slot => json_encode(['name' => $name, 'hex' => $hex]),
        ]);
    }

    private function clear(int $slot): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($slot): void {
            $this->state()->lock();
            $this->state()->bump();
            $this->container()->get(GeneralSettings::class)->save(['theme_brand_' . $slot => '']);
        });
    }

    private function startJob(int $slot, string $to, ?string $contrastTo): string
    {
        return $this->container()->get(PaletteFence::class)->within(function () use ($slot, $to, $contrastTo): string {
            $this->state()->lock();
            $this->state()->bump();
            return $this->container()->get(PaletteJobRepository::class)
                ->start($slot, $to, $contrastTo, 'user00000001', null);
        });
    }

    private function cancelJob(string $id): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($id): void {
            $this->state()->lock();
            $this->container()->get(PaletteJobRepository::class)->transition($id, 'cancelled');
            $this->state()->bump();
        });
    }

    private function saveBrandOne(string $uuid, int $lock): \Thallo\Core\Content\Palette\PaletteOutcome
    {
        $fields = ['body' => [self::heading('color.brand-1')]];
        return $this->repo()->saveDraft($uuid, 'en', $fields, 1, $lock, 'user00000001');
    }

    /** @param array<string,mixed> $fields */
    private static function tokenIn(array $fields): ?string
    {
        return $fields['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
    }

    private function draftToken(string $uuid, string $locale = 'en'): ?string
    {
        return self::tokenIn((array) ($this->repo()->findDraft($uuid, $locale)['fields'] ?? []));
    }

    private function publishedToken(string $uuid): ?string
    {
        $versions = $this->container()->get(VersionRepository::class);
        $pin = $versions->findPublication($uuid, 'en');
        $version = $pin === null ? null : $versions->findVersionByUuid((string) $pin['version_uuid']);
        return self::tokenIn((array) ($version['fields'] ?? []));
    }

    private function versionCount(string $uuid): int
    {
        return $this->connection()->table('entry_versions')->where('entry_uuid', '=', $uuid)->count();
    }

    public function testSaveFirstThenTheJobStarts(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->saveBrandOne($uuid, $lock);
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        self::assertSame('color.brand-1', $this->draftToken($uuid), 'committed: the job finds it');
    }

    public function testJobStartsWhileTheSaveIsPausedAfterNormalising(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $rewrites = $this->saveBrandOne($uuid, $lock)->rewrites;
        self::assertSame('color.accent', $this->draftToken($uuid), 'the fence caught the change and normalised again');
        self::assertSame(
            [[
                'location' => 'head00000001:settings.style.colors.text',
                'from' => 'color.brand-1',
                'to' => 'color.accent',
            ]],
            $rewrites,
        );
    }

    public function testAStaleMappingIsNotUsedAfterCancelAndANewReplacement(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $first = $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $this->state()->afterNextSnapshot(function () use ($first): void {
            $this->cancelJob($first);
            $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
        });
        $this->saveBrandOne($uuid, $lock);
        self::assertSame('color.brand-2', $this->draftToken($uuid), 'from Brand 1 as sent, not the obsolete Accent');
    }

    public function testOrdinaryClearFirstRefusesAFreshReferenceButKeepsAStoredOne(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->state()->afterNextSnapshot(fn () => $this->clear(1));
        try {
            $this->saveBrandOne($uuid, $lock);
            self::fail('a fresh reference to a just-cleared slot is refused');
        } catch (PaletteRefusal $e) {
            self::assertSame(
                ['head00000001:settings.style.colors.text' => 'Brand 1 is no longer in the palette'],
                $e->errors,
            );
        }
        // a draft that already stores the reference saves unchanged, with an unrelated edit
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $uuid)->update([
            'fields' => json_encode(['title' => 'a', 'body' => [self::heading('color.brand-1')]]),
        ]);
        $this->repo()->saveDraft(
            $uuid,
            'en',
            ['title' => 'b', 'body' => [self::heading('color.brand-1')]],
            1,
            $this->lockOf($uuid),
            'user00000001',
        );
        self::assertSame('color.brand-1', $this->draftToken($uuid));
    }

    public function testASavePausedAcrossAClearCannotCommitTheOldToken(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $this->state()->afterNextSnapshot(function () use ($job): void {
            $this->cancelJob($job);
            $this->clear(1);
        });
        $this->expectException(PaletteRefusal::class);
        $this->saveBrandOne($uuid, $lock);
    }

    public function testASaveNamingNoBrandTokenTakesNoFence(): void
    {
        [$uuid, $lock] = $this->entry();
        $before = $this->state()->snapshot()->generation;
        $this->state()->afterNextSnapshot(fn () => $this->clear(2));
        $this->repo()->saveDraft($uuid, 'en', ['body' => [self::heading('color.accent')]], 1, $lock, 'user00000001');
        self::assertSame('color.accent', $this->draftToken($uuid));
        self::assertSame($before + 1, $this->state()->snapshot()->generation, 'only the clear moved it');
    }

    public function testPublishLocaleCopyAndRollbackAreFenced(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->saveBrandOne($uuid, $lock);
        $old = $this->publisher()->publish($uuid, 'en', 'user00000001');      // a version naming Brand 1
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $this->publisher()->publish($uuid, 'en', 'user00000001');
        self::assertSame('color.accent', $this->publishedToken($uuid), 'publish normalised the draft it read');

        $this->repo()->createLocaleDraft($uuid, 'fr', 1, 'user00000001', 'en');
        self::assertSame('color.accent', $this->draftToken($uuid, 'fr'), 'the copy was normalised');

        $before = $this->versionCount($uuid);
        $result = $this->publisher()->rollback($uuid, 'en', $old, 'user00000001');
        self::assertNotSame($old, $result['version_uuid'], 'a changed rollback appends a version');
        self::assertSame($before + 1, $this->versionCount($uuid));
        self::assertSame('color.accent', $this->publishedToken($uuid));
    }

    public function testScheduledPublishGoesThroughTheSameFence(): void
    {
        [$uuid, $lock] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->saveBrandOne($uuid, $lock);
        $this->container()->get(ScheduleRepository::class)
            ->schedule($uuid, 'en', ScheduleAction::Publish, '2020-01-01T00:00:00Z', 'user00000001');
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        self::assertSame(1, $this->container()->get(ScheduleRunner::class)->run());
        self::assertSame('color.accent', $this->publishedToken($uuid));
    }

    public function testTheAuthoringEngineIsFencedThroughSaveDraft(): void
    {
        [$uuid] = $this->entry();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $this->container()->get(\Thallo\Contracts\Authoring\ContentUpserter::class)
            ->updateDraft($uuid, 'en', ['body' => [self::heading('color.brand-1')]], 'user00000001');
        self::assertSame('color.accent', $this->draftToken($uuid));
    }
}
