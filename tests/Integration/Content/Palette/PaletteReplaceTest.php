<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Core\Content\Palette\PaletteConflict;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Services\DraftRestore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Palette\PaletteReplaceFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Replace with… (custom palette spec §4.2, §4.4): a job that rewrites every current document naming
 * a brand colour — drafts, current publications (appended and repinned), regions and their frames,
 * layouts and their frames, saved sections, style classes, colour content fields — never history,
 * then clears the slot; destinations checked, contrast references mapped or required.
 */
final class PaletteReplaceTest extends AppTestCase
{
    use PaletteFixtures;
    use PaletteReplaceFixtures;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceWorld();
    }

    public function testReplaceRewritesEveryBlockingDocumentAppendsPublicationVersionsAndClears(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->publishedEntryNaming('color.brand-1');
        $old = $this->retainedVersionNaming($uuid, 'color.brand-1');
        $this->regionStyleNaming('header', 'color.brand-1-contrast');
        $this->layoutFrameNaming('entry', 'pfrep', 'color.brand-1');
        $this->savedSectionNaming('Hero', 'color.brand-1');
        $class = $this->styleClassNaming('Card', 'color.brand-1');
        $this->animatedTextDraftNaming('color.brand-1');
        $versionsBefore = $this->versionCount($uuid);
        $pinnedBefore = $this->publishedVersionUuid($uuid);

        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $result = $this->runner()->run($job);

        self::assertSame('completed', $result['status'], json_encode($this->jobs()->find($job)?->failureReport));
        self::assertSame('color.accent', $this->draftToken($uuid));
        self::assertSame('color.accent', $this->publishedToken($uuid));
        self::assertSame($versionsBefore + 1, $this->versionCount($uuid), 'append-and-repin');
        self::assertNotSame($pinnedBefore, $this->publishedVersionUuid($uuid));
        self::assertSame('color.brand-1', $this->versionToken($old), 'history untouched');
        self::assertSame('color.accent-contrast', $this->regionStyleToken('header'));
        self::assertSame('color.accent', $this->layoutFrameToken('entry', 'pfrep'));
        self::assertSame('color.accent', $this->sectionToken('Hero'));
        self::assertSame('color.accent', $this->classToken($class, 'colors.text'));
        self::assertSame('color.accent', $this->animatedTextToken());
        self::assertNull($this->palette()->brand(1), 'cleared');
        self::assertSame(['action' => 'palette.brand.replaced', 'label' => 'Gold'], $this->replacedAudit($job));
        $context = $this->replacedAuditContext($job);
        $counts = [
            'entry_draft' => 2, 'entry_published' => 1, 'region_settings' => 1,
            'layout_settings' => 1, 'saved_section' => 1, 'style_class' => 1,
        ];
        self::assertEquals($counts, array_intersect_key($context['counts'] ?? [], $counts));
        self::assertSame(1, $context['historical'] ?? null, 'the older version still naming it');
        self::assertSame('completed', $this->jobs()->find($job)?->status);
        self::assertNotNull($this->jobs()->find($job)?->completedGeneration);
    }

    public function testThePreviousPublicationVersionIsUnchangedAndTheNewOneAuthoredByTheStarter(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->publishedEntryNaming('color.brand-1');
        $pinnedBefore = $this->publishedVersionUuid($uuid);
        $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000002'));
        self::assertSame('color.brand-1', $this->versionToken($pinnedBefore), 'the old publication version is kept');
        $new = $this->connection()->table('entry_versions')
            ->where('uuid', '=', $this->publishedVersionUuid($uuid))->first();
        self::assertSame('user00000002', $new['created_by']);
        self::assertSame('Replaced Gold with Accent', $new['note'], 'authored as the palette operation');
        $pinned = $this->connection()->table('entry_versions')->where('uuid', '=', $pinnedBefore)->first();
        self::assertNull($pinned['note']);
    }

    public function testRestoringAnOlderVersionAfterReplaceBringsTheUnavailableColourBack(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->publishedEntryNaming('color.brand-1');
        $old = $this->publishedVersionUuid($uuid);
        $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000001'));
        $this->container()->get(DraftRestore::class)->restore($uuid, 'en', $old, $this->lockOf($uuid), 'user00000001');
        // rendered with no colour: PagePaletteAvailabilityTest
        self::assertSame('color.brand-1', $this->draftToken($uuid));
    }

    public function testDestinationRules(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $bad = ['color.brand-1', 'color.brand-1-contrast', 'color.brand-3', 'color.accent-contrast',
            'color.brand-2-contrast', 'color.nope', 'spacing.md'];
        foreach ($bad as $to) {
            try {
                $this->service()->start(1, $to, null, 'user00000001');
                self::fail($to);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->jobs()->active(), $to);
            }
        }
        try {
            $this->service()->start(1, 'color.accent', 'color.brand-3', 'user00000001');
            self::fail('an unconfigured contrast destination');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->jobs()->active());
        }
    }

    public function testAnUnconfiguredSourceIsAConflict(): void
    {
        $this->expectException(PaletteConflict::class);
        $this->service()->start(3, 'color.accent', null, 'user00000001');
    }

    public function testContrastReferencesWithoutAPairRequireAnExplicitDestination(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftNaming('color.brand-1-contrast');
        try {
            $this->service()->start(1, 'color.surface', null, 'user00000001');
            self::fail('contrast_to is required');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('contrast_to is required', $e->getMessage());
        }
        self::assertSame([], $this->jobs()->active(), 'nothing recorded');
    }

    public function testWithoutContrastReferencesAPairlessDestinationRecordsNoContrastMapping(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftNaming('color.brand-1');
        $job = $this->service()->start(1, 'color.surface', null, 'user00000001');
        self::assertNull($this->jobs()->find($job)?->contrastTo);
        self::assertSame('completed', $this->runner()->run($job)['status']);
        self::assertSame('color.surface', $this->draftToken());
    }

    public function testAnExplicitContrastDestinationIsUsedAndReserved(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(3, 'Ink', '#111111');
        $this->draftNaming('color.brand-1-contrast');
        $job = $this->service()->start(1, 'color.surface', 'color.brand-3', 'user00000001');
        self::assertSame([3], $this->state()->snapshot()->reservedSlots());
        $this->runner()->run($job);
        self::assertSame('color.brand-3', $this->draftToken());
    }

    public function testAStaleDraftSavedWhileTheJobRunsStoresTheDestination(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->entry();
        $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->saveBody($uuid, [self::heading('color.brand-1')]);
        self::assertSame('color.accent', $this->draftToken($uuid));
    }

    public function testRenameRecolourClearAndReplaceOfTheSourceAre409(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->service()->start(1, 'color.accent', null, 'user00000001');
        $attempts = [
            fn () => $this->mutations()->save(
                ['theme_brand_colors' => $this->brandList([[1, 'Old gold', '#8a6a2a']])],
                'user00000001',
            ),
            fn () => $this->mutations()->save(
                ['theme_brand_colors' => $this->brandList([[1, 'Gold', '#99772e']])],
                'user00000001',
            ),
            fn () => $this->mutations()->clear(1, 'user00000001'),
            fn () => $this->service()->start(1, 'color.surface', null, 'user00000001'),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt {$i}");
            } catch (PaletteConflict) {
                self::assertSame('Gold', $this->palette()->brand(1)?->name);
            }
        }
    }

    public function testOverlappingReplacements(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $first = $this->service()->start(1, 'color.brand-2', null, 'user00000001');
        $reserved = [
            fn () => $this->service()->start(2, 'color.accent', null, 'user00000001'),
            fn () => $this->mutations()->clear(2, 'user00000001'),
        ];
        foreach ($reserved as $attempt) {
            try {
                $attempt();
                self::fail('reserved');
            } catch (PaletteConflict) {
                self::assertTrue(true);
            }
        }
        $blush = ['theme_brand_colors' => $this->brandList([[1, 'Gold', '#8a6a2a'], [2, 'Blush', '#c98a8a']])];
        self::assertTrue($this->mutations()->save($blush, 'user00000001')->changed, 'rename allowed');
        $this->service()->cancel($first);
        $this->service()->start(2, 'color.accent', null, 'user00000001');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->start(1, 'color.brand-2', null, 'user00000001'); // Brand 2 is now a source
    }

    public function testTwoRacingStartsForConflictingSlots(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        // start(1 → brand-2) commits while start(2 → accent) is paused after its unlocked read
        $this->state()->afterNextSnapshot(fn () => $this->service()->start(1, 'color.brand-2', null, 'user00000001'));
        $this->expectException(PaletteConflict::class);
        $this->service()->start(2, 'color.accent', null, 'user00000001');
    }

    public function testThemeAppearanceChangedFiresAfterTheClearingCommit(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $seen = new \ArrayObject();
        $this->container()->get(EventService::class)->addListener(
            ThemeAppearanceChanged::class,
            function () use ($seen): void {
                $seen->append($this->container()->get(Connection::class)->withinTransaction());
            },
        );
        $this->runner()->run($this->service()->start(1, 'color.accent', null, 'user00000001'));
        self::assertSame([false], $seen->getArrayCopy(), 'dispatched once, after the clearing transaction committed');
        self::assertNull($this->palette()->brand(1));
    }

    public function testAContrastReferenceArrivingBeforeStartTakesTheLockRefusesAMappinglessStart(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        // start's unlocked read sees no contrast references; one is saved before start locks
        $this->state()->afterNextSnapshot(fn () => $this->draftNaming('color.brand-1-contrast'));
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->start(1, 'color.surface', null, 'user00000001');
    }

    public function testAStaleEditorIntroducingAContrastReferenceDuringAMappinglessJobIsRefused(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->entry();
        $job = $this->service()->start(1, 'color.surface', null, 'user00000001');
        self::assertNull($this->jobs()->find($job)?->contrastTo);
        $this->expectException(PaletteRefusal::class);
        $this->saveBody($uuid, [self::heading('color.brand-1-contrast')]);
    }

    public function testAContrastReferenceThatSlipsInIsAFailureNeverAFillColour(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.surface', null, 'user00000001');
        $uuid = $this->storeDraftRawNaming('color.brand-1-contrast');
        $result = $this->runner()->run($job);
        self::assertSame('failed', $result['status']);
        self::assertSame('color.brand-1-contrast', $this->draftToken($uuid), 'never rewritten to the fill');
        $report = (string) json_encode($this->jobs()->find($job)?->failureReport);
        self::assertStringContainsString('arrived after the replacement started', $report);
        self::assertNotNull($this->palette()->brand(1));
    }

    public function testResumeEligibilityIsDecidedUnderTheLock(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->runner()->run($job);
        $this->expectException(PaletteConflict::class);
        $this->service()->resume($job);
    }

    public function testCancellingATerminalJobIsAConflict(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->service()->cancel($job);
        $this->expectException(PaletteConflict::class);
        $this->service()->cancel($job);
    }
}
