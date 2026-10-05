<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontBlobCheck;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\FontLibraryUpgrade;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Settings\AppearanceLock;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Core\Tests\Support\Fonts\SqlLockHolder;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Custom's uploads become library families once per workspace (block typeface spec §2.7; plan
 * Task 7): repeatable, untouched-only — a new assignment is written only where none is stored, and a
 * deliberately cleared one (stored as '') is left — in one transaction with an atomic marker, under
 * the same appearance lock a settings save takes.
 */
final class FontLibraryUpgradeTest extends AppTestCase
{
    private const FONTS = __DIR__ . '/../../fixtures/fonts';

    private FixtureFontBlobFiles $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new FixtureFontBlobFiles([]);
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DROP TRIGGER IF EXISTS thallo_test_fail_marker ON settings');
        $this->connection()->getPDO()->exec('DROP FUNCTION IF EXISTS thallo_test_fail_marker()');
        parent::tearDown();
    }

    private function upgrade(): FontLibraryUpgrade
    {
        $flags = $this->container()->get(SystemFlags::class);
        $reader = new Woff2FaceReader(BrotliDecoders::best());
        $library = new FontLibrary(
            $this->connection(),
            $this->files,
            $reader,
            $flags,
            $this->container()->get(MediaUrlBatchResolver::class),
        );
        return new FontLibraryUpgrade(
            $this->connection(),
            $library,
            $this->files,
            $reader,
            new FontBlobCheck($this->connection(), $flags),
            $this->settings(),
            new AppearanceLock($this->connection()),
        );
    }

    private function settings(): GeneralSettings
    {
        $this->container()->get(SettingsStore::class)->clearCache();
        return $this->container()->get(GeneralSettings::class);
    }

    private function blob(string $uuid, string $fixture): string
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => $fixture, 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->files->map($uuid, self::FONTS . '/' . $fixture);
        return $uuid;
    }

    /** @param array<string, string> $pairs */
    private function store(array $pairs): void
    {
        $this->container()->get(SettingsStore::class)->putMany($pairs);
    }

    /** @return array{0: ?string, 1: ?string} the stored text and headings assignments */
    private function assignments(): array
    {
        $settings = $this->settings();
        return [$settings->storedValue('theme_font_text_family'), $settings->storedValue('theme_font_headings_family')];
    }

    /** @return list<array<string,mixed>> */
    private function families(): array
    {
        return $this->connection()->table('font_families')->get();
    }

    public function testTextOnlyBecomesSiteTextAndHeadingsStayUnassigned(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        $result = $this->upgrade()->run();

        self::assertSame(1, $result['created']);
        [$family] = $this->families();
        self::assertSame(['Site text', 'system-ui'], [$family['name'], $family['fallback']]);
        self::assertSame([$family['id'], null], $this->assignments());
        self::assertSame(['text' => true, 'headings' => false], $result['assigned']);
        self::assertSame('custom', $this->settings()->themeFont(), 'the pairing is never changed');
    }

    public function testHeadingsOnlyBecomesSiteHeadings(): void
    {
        $display = $this->blob('fonthead0001', 'static-700.woff2');
        $this->store(['theme_font' => 'custom', 'theme_font_display' => $display]);
        $this->upgrade()->run();
        [$family] = $this->families();
        self::assertSame('Site headings', $family['name']);
        self::assertSame([null, $family['id']], $this->assignments());
    }

    public function testTwoFilesBecomeTwoFamilies(): void
    {
        $this->store([
            'theme_font' => 'custom',
            'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2'),
            'theme_font_display' => $this->blob('fonthead0001', 'static-400-italic.woff2'),
        ]);
        self::assertSame(2, $this->upgrade()->run()['created']);
        $byName = array_column($this->families(), 'id', 'name');
        self::assertSame([$byName['Site text'], $byName['Site headings']], $this->assignments());
    }

    public function testASharedFileIsOneFamily(): void
    {
        $shared = $this->blob('fontshared01', 'variable.woff2');
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $shared, 'theme_font_display' => $shared]);
        self::assertSame(1, $this->upgrade()->run()['created']);
        [$family] = $this->families();
        self::assertSame('Site font', $family['name']);
        self::assertSame([$family['id'], $family['id']], $this->assignments());
    }

    public function testUnselectedCustomStillMovesItsUploadsAndChangesNothingVisible(): void
    {
        $this->store(['theme_font' => 'serif', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        $this->upgrade()->run();
        self::assertCount(1, $this->families());
        self::assertSame('serif', $this->settings()->themeFont());
        self::assertNotNull($this->assignments()[0]);
    }

    public function testItRunsOnce(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        $this->upgrade()->run();
        $again = $this->upgrade()->run();
        self::assertSame(0, $again['created']);
        self::assertCount(1, $this->families());
        self::assertSame('1', $this->settings()->storedValue(FontLibraryUpgrade::MARKER));
    }

    public function testAnExplicitClearIsLeftAlone(): void
    {
        $this->store([
            'theme_font' => 'custom',
            'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2'),
            'theme_font_text_family' => '',
        ]);
        $this->upgrade()->run();
        self::assertSame('', $this->assignments()[0], 'someone cleared it: never overwritten');
    }

    /**
     * The Appearance page sends every key it shows, Custom's families included, as '' when unset. A
     * save of something else before provision runs is not a clear: the upgrade still assigns.
     */
    public function testAnAppearanceSaveBeforeTheUpgradeIsNotAClear(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        $res = $this->container()->get(GeneralSettingsController::class)->update(new UpdateGeneralSettingsData(
            theme_accent: 'rose',
            theme_font_text_family: '',
            theme_font_headings_family: '',
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->upgrade()->run();
        [$family] = $this->families();
        self::assertSame([$family['id'], null], $this->assignments());
    }

    /** Clearing a family that is stored is a clear, and the upgrade leaves it. */
    public function testClearingAStoredFamilyFromTheAppearancePageIsAClear(): void
    {
        $this->store([
            'theme_font' => 'custom',
            'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2'),
            'theme_font_text_family' => 'serif',
        ]);
        $this->container()->get(GeneralSettingsController::class)
            ->update(new UpdateGeneralSettingsData(theme_font_text_family: ''));
        $this->upgrade()->run();
        self::assertSame('', $this->assignments()[0]);
    }

    public function testAnAssignmentChangedAfterTheMarkerIsNeverRestored(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        $this->upgrade()->run();
        $this->store(['theme_font_text_family' => 'serif']);
        $this->upgrade()->run();
        self::assertSame('serif', $this->assignments()[0]);
    }

    public function testAnUnreadableFileBecomesAnUnknownFace(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbad00001', 'not-a-font.woff2')]);
        $this->upgrade()->run();
        $face = $this->connection()->table('font_faces')->first();
        self::assertIsArray($face);
        $declared = [(int) $face['weight_min'], (int) $face['weight_max'], (bool) $face['unknown']];
        self::assertSame([100, 900, true], $declared);
    }

    public function testAFileTheLibraryNoLongerHasIsSkipped(): void
    {
        $this->blob('fontgone0001', 'static-700.woff2');
        $this->connection()->table('blobs')->where('uuid', '=', 'fontgone0001')->update(['status' => 'deleted']);
        $this->store(['theme_font' => 'custom', 'theme_font_body' => 'fontgone0001']);
        $result = $this->upgrade()->run();
        self::assertSame(0, $result['created']);
        self::assertSame([null, null], $this->assignments());
        self::assertSame('1', $this->settings()->storedValue(FontLibraryUpgrade::MARKER));
    }

    /** A save holding the appearance lock wins: the upgrade waits, then sees the stored value. */
    public function testAConcurrentSaveIsNeverOverwritten(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        (new AppearanceLock($this->connection()))->ensure();
        $holder = SqlLockHolder::hold([
            ["UPDATE settings SET updated_at = updated_at WHERE key = ?", [AppearanceLock::KEY]],
            ["INSERT INTO settings (key, value, updated_at) VALUES ('theme_font_text_family', 'serif', now())", []],
        ], 1.0);
        $start = microtime(true);
        $this->upgrade()->run();
        self::assertGreaterThan(0.5, microtime(true) - $start, 'the upgrade waited for the lock');
        $holder->finish();
        self::assertSame('serif', $this->assignments()[0]);
        self::assertCount(1, $this->families(), 'the family is still made, only not assigned');
    }

    public function testAnInterruptedRunLeavesNothingAndTheRetryCompletes(): void
    {
        $this->store(['theme_font' => 'custom', 'theme_font_body' => $this->blob('fontbody0001', 'static-700.woff2')]);
        // The marker write fails: after the family was created, inside the same transaction.
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "CREATE FUNCTION thallo_test_fail_marker() RETURNS trigger AS \$\$ BEGIN "
            . "IF NEW.key = '" . FontLibraryUpgrade::MARKER . "' THEN RAISE EXCEPTION 'interrupted'; END IF; "
            . 'RETURN NEW; END; $$ LANGUAGE plpgsql',
        );
        $pdo->exec('CREATE TRIGGER thallo_test_fail_marker BEFORE INSERT OR UPDATE ON settings '
            . 'FOR EACH ROW EXECUTE FUNCTION thallo_test_fail_marker()');
        try {
            $this->upgrade()->run();
            self::fail('the run was not interrupted');
        } catch (\Throwable $e) {
            self::assertStringContainsString('interrupted', $e->getMessage());
        }
        self::assertSame([], $this->families());
        self::assertSame([null, null], $this->assignments());
        self::assertNull($this->settings()->storedValue(FontLibraryUpgrade::MARKER));

        $pdo->exec('DROP TRIGGER thallo_test_fail_marker ON settings');
        self::assertSame(1, $this->upgrade()->run()['created']);
        self::assertNotNull($this->assignments()[0]);
    }

    public function testNoUploadsIsJustTheMarker(): void
    {
        $result = $this->upgrade()->run();
        self::assertSame(['created' => 0, 'assigned' => ['text' => false, 'headings' => false]], $result);
        self::assertSame('1', $this->settings()->storedValue(FontLibraryUpgrade::MARKER));
    }
}
