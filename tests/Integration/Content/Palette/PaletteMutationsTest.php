<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Events\EventService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Palette\BrandColorInUse;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\PaletteConflict;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\PaletteSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Every palette change under the palette row (custom palette spec §4, §4.3): Clear refuses a colour
 * a draft, publication, region, layout, saved section or class still names, and rolls its bump back;
 * a slot being replaced cannot be renamed, re-coloured or cleared; a reserved one can be edited but
 * not cleared; effects only after commit.
 */
final class PaletteMutationsTest extends AppTestCase
{
    use PaletteFixtures;
    use SyncsBlockStyleDeclarations;

    /** @var list<AuditEntry> */
    private array $audits = [];
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfmut', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'blocks']],
        ]);
    }

    private function mutations(): PaletteMutations
    {
        $audits = &$this->audits;
        $recorder = new class ($audits) implements AuditRecorderInterface {
            /** @param list<AuditEntry> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function record(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        return new PaletteMutations(
            $this->connection(),
            $this->container()->get(PaletteFence::class),
            $this->state(),
            $this->container()->get(GeneralSettings::class),
            $this->container()->get(BrandColorUsage::class),
            $this->container()->get(EventService::class),
            $recorder,
        );
    }

    private function palette(): Palette
    {
        return $this->container()->get(PaletteSettings::class)->palette();
    }

    private function repo(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    /** @return array{0: string, 1: int} */
    private function entry(): array
    {
        $uuid = $this->repo()->createEntry($this->type, 'en', 1, 'user00000001');
        return [$uuid, (int) ($this->repo()->findDraft($uuid, 'en')['lock_version'] ?? 0)];
    }

    private function draftNaming(string $token): string
    {
        [$uuid, $lock] = $this->entry();
        $this->repo()->saveDraft($uuid, 'en', ['body' => [self::heading($token)]], 1, $lock, 'user00000001');
        return $uuid;
    }

    /** A version naming the token that is neither the draft nor the current publication. */
    private function retainedVersionNaming(string $token): void
    {
        $uuid = $this->draftNaming($token);
        $publisher = $this->container()->get(PublishService::class);
        $publisher->publish($uuid, 'en', 'user00000001');
        $lock = (int) ($this->repo()->findDraft($uuid, 'en')['lock_version'] ?? 0);
        $this->repo()->saveDraft($uuid, 'en', ['body' => [self::heading('color.accent')]], 1, $lock, 'user00000001');
        $publisher->publish($uuid, 'en', 'user00000001');
    }

    public function testClearWithNoUsageClearsBumpsAndFiresAfterCommit(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $fired = new \ArrayObject();
        $this->container()->get(EventService::class)->addListener(
            ThemeAppearanceChanged::class,
            static function (object $e) use ($fired): void {
                $fired->append($e);
            },
        );
        $g = $this->state()->snapshot()->generation;
        $this->mutations()->clear(1, 'user00000001');
        self::assertNull($this->palette()->brand(1));
        self::assertTrue($this->palette()->isRemoved(1), 'moved to removed, keeping its name');
        self::assertSame('Gold', $this->palette()->labelOf(1));
        self::assertSame($g + 1, $this->state()->snapshot()->generation);
        self::assertCount(1, $fired);
        self::assertSame('palette.brand.cleared', $this->audits[array_key_last($this->audits)]->action);
        self::assertSame('Gold', $this->audits[array_key_last($this->audits)]->targetLabel);
    }

    public function testClearingAnUnsetSlotDoesNothing(): void
    {
        $g = $this->state()->snapshot()->generation;
        $this->mutations()->clear(2, 'user00000001');
        self::assertSame($g, $this->state()->snapshot()->generation);
        self::assertSame([], $this->audits);
    }

    public function testClearWithBlockingUsageIsRefusedAndChangesNothing(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftNaming('color.brand-1');
        $g = $this->state()->snapshot()->generation;
        try {
            $this->mutations()->clear(1, 'user00000001');
            self::fail('in use');
        } catch (BrandColorInUse $e) {
            self::assertSame(1, $e->usage['blocking']['total']);
        }
        self::assertNotNull($this->palette()->brand(1));
        self::assertSame($g, $this->state()->snapshot()->generation, 'rolled back with the refusal');
        self::assertSame([], $this->audits);
    }

    public function testClearWithOnlyHistoricalUsageClears(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->retainedVersionNaming('color.brand-1');
        $this->mutations()->clear(1, 'user00000001');
        self::assertNull($this->palette()->brand(1));
    }

    public function testASaveCommittedBeforeTheClearRefusesIt(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid, $lock] = $this->entry();
        // the save commits first: the clear's in-transaction scan sees it
        $this->repo()->saveDraft($uuid, 'en', ['body' => [self::heading('color.brand-1')]], 1, $lock, 'user00000001');
        $this->expectException(BrandColorInUse::class);
        $this->mutations()->clear(1, 'user00000001');
    }

    public function testRenamingTheSourceOfAJobIsAConflictButRenamingAReservedSlotIsNot(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
        $blush = ['theme_brand_colors' => $this->brandList([[1, 'Gold', '#8a6a2a'], [2, 'Blush', '#c98a8a']])];
        self::assertTrue($this->mutations()->save($blush, 'user00000001')->changed);
        self::assertSame('Blush', $this->palette()->brand(2)?->name);
        $this->expectException(PaletteConflict::class);
        $this->mutations()->save(
            ['theme_brand_colors' => $this->brandList([[1, 'Old gold', '#8a6a2a'], [2, 'Blush', '#c98a8a']])],
            'user00000001',
        );
    }

    public function testSavingTheSourceUnchangedAndTheNeutralsIsNeverBlocked(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        self::assertTrue($this->mutations()->save([
            'theme_brand_colors' => $this->brandList([[1, 'Gold', '#8a6a2a']]),
            'theme_dark_base' => 'stone',
        ], 'user00000001')->changed);
        self::assertFalse(
            $this->mutations()->save(['theme_dark_base' => 'stone'], 'user00000001')->changed,
            'no change',
        );
    }

    public function testClearingAReservedSlotOrAJobsSourceIsAConflict(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->startJob(1, 'color.brand-2', 'color.brand-2-contrast');
        foreach ([1, 2] as $slot) {
            try {
                $this->mutations()->clear($slot, 'user00000001');
                self::fail("slot {$slot}");
            } catch (PaletteConflict) {
                self::assertNotNull($this->palette()->brand($slot));
            }
        }
    }

    public function testANewColourNeverTakesAClearedId(): void
    {
        $this->configure(3, 'Teal', '#0f766e');
        $this->container()->get(PaletteMutations::class)->clear(3, null);
        $palette = $this->container()->get(PaletteSettings::class)->palette();
        self::assertFalse($palette->isConfigured(3));
        self::assertTrue($palette->isRemoved(3));
        self::assertSame('Teal', $palette->labelOf(3));
        self::assertSame(3, $palette->highestIssued());
    }
}
