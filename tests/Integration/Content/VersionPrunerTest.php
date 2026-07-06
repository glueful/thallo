<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Retention\RetentionPolicy;
use App\Content\Retention\VersionPruner;
use App\Tests\Support\AppTestCase;

final class VersionPrunerTest extends AppTestCase
{
    private VersionRepository $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = new VersionRepository($this->connection());
    }

    private function pruner(): VersionPruner
    {
        return new VersionPruner($this->connection());
    }

    /** @return list<string> */
    private function buildLineage(string $entry, string $locale, int $count, int $baseDaysAgo = 0): array
    {
        $uuids = [];
        for ($version = 1; $version <= $count; $version++) {
            $daysAgo = $baseDaysAgo + ($count - $version);
            $uuid = $this->versions->appendVersion(
                $entry,
                $locale,
                $version,
                ['title' => "v{$version}"],
                1,
                'user00000001',
            );
            $stmt = $this->connection()->getPDO()->prepare(
                "UPDATE entry_versions SET created_at = now() - (:days * interval '1 day') WHERE uuid = :uuid"
            );
            $stmt->execute(['days' => $daysAgo, 'uuid' => $uuid]);
            $uuids[] = $uuid;
        }

        return $uuids;
    }

    /** @return list<string> */
    private function survivors(string $entry, string $locale): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $this->versions->versionsFor($entry, $locale),
        );
    }

    public function testKeepNDeletesOldestBeyondN(): void
    {
        $entry = 'e1aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[4], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        self::assertSame([$versions[4], $versions[3]], $this->survivors($entry, 'en'));
        self::assertSame(1, $report->lineagesScanned);
        self::assertSame(3, $report->versionsDeleted);
        self::assertSame(2, $report->versionsRetained);
    }

    public function testKeepNProtectsPinnedVersionBeyondN(): void
    {
        $entry = 'e2aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[2], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        self::assertEqualsCanonicalizing(
            [$versions[4], $versions[3], $versions[2]],
            $this->survivors($entry, 'en'),
        );
        self::assertSame(2, $report->versionsDeleted);
        self::assertSame(1, $report->pinnedSkipped);
    }

    public function testAgeBasedDeletesOldRetainsNewAndAlwaysKeepsPin(): void
    {
        $entry = 'e3aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[0], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues(null, '2'));
        $survivors = $this->survivors($entry, 'en');

        self::assertContains($versions[4], $survivors);
        self::assertContains($versions[3], $survivors);
        self::assertContains($versions[0], $survivors);
        self::assertNotContains($versions[1], $survivors);
        self::assertGreaterThanOrEqual(1, $report->pinnedSkipped);
    }

    public function testCombinedKeepNIsAFloorOverAge(): void
    {
        $entry = 'e4aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 3, baseDaysAgo: 90);
        $this->versions->pin($entry, 'en', $versions[2], 'user00000001');

        $this->pruner()->prune(RetentionPolicy::fromValues('2', '30'));

        self::assertEqualsCanonicalizing([$versions[2], $versions[1]], $this->survivors($entry, 'en'));
    }

    public function testDisabledPolicyDeletesNothing(): void
    {
        $entry = 'e5aaaaaaaaaa';
        $this->buildLineage($entry, 'en', 4);

        $report = $this->pruner()->prune(RetentionPolicy::fromValues(null, null));

        self::assertCount(4, $this->versions->versionsFor($entry, 'en'));
        self::assertSame(0, $report->lineagesScanned);
        self::assertSame(0, $report->versionsDeleted);
    }

    public function testDryRunComputesReportButDeletesNothing(): void
    {
        $entry = 'e6aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[4], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('2', null), dryRun: true);

        self::assertSame(3, $report->versionsDeleted);
        self::assertCount(5, $this->versions->versionsFor($entry, 'en'));
    }

    public function testPerLineageIsolationAcrossEntriesAndLocales(): void
    {
        $a = 'e7aaaaaaaaaa';
        $b = 'e8aaaaaaaaaa';
        $aEn = $this->buildLineage($a, 'en', 4);
        $bEn = $this->buildLineage($b, 'en', 2);
        $aFr = $this->buildLineage($a, 'fr', 3);
        $this->versions->pin($a, 'en', $aEn[3], 'user00000001');
        $this->versions->pin($b, 'en', $bEn[1], 'user00000001');
        $this->versions->pin($a, 'fr', $aFr[2], 'user00000001');

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        self::assertCount(1, $this->versions->versionsFor($a, 'en'));
        self::assertCount(1, $this->versions->versionsFor($b, 'en'));
        self::assertCount(1, $this->versions->versionsFor($a, 'fr'));
        self::assertSame(3, $report->lineagesScanned);
        self::assertSame(6, $report->versionsDeleted);
    }

    public function testNoPublicationLineagePrunesDownToPolicy(): void
    {
        $entry = 'e9aaaaaaaaaa';
        $this->buildLineage($entry, 'en', 4);

        $report = $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        self::assertCount(1, $this->versions->versionsFor($entry, 'en'));
        self::assertSame(0, $report->pinnedSkipped);
        self::assertSame(3, $report->versionsDeleted);
    }

    public function testFkSafetyEveryPublicationStillResolvesAfterPrune(): void
    {
        $entry = 'eaaaaaaaaaaa';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[2], 'user00000001');

        $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        $publication = $this->versions->findPublication($entry, 'en');
        self::assertNotNull($publication);
        self::assertNotNull($this->versions->findVersionByUuid((string) $publication['version_uuid']));
    }

    public function testDeleteTimePinGuardSkipsRowPinnedAfterSelection(): void
    {
        $entry = 'ebbbbbbbbbbb';
        $versions = $this->buildLineage($entry, 'en', 4);
        $this->versions->pin($entry, 'en', $versions[3], 'user00000001');

        $policy = RetentionPolicy::fromValues('1', null);
        $pruner = $this->pruner();
        $selection = $pruner->computeDeletable($entry, 'en', $policy);
        self::assertContains($versions[0], $selection['deletable']);

        $this->versions->pin($entry, 'en', $versions[0], 'user00000001');
        $deleted = $pruner->deleteGuarded($selection['deletable']);

        self::assertNotNull($this->versions->findVersionByUuid($versions[0]));
        self::assertSame(count($selection['deletable']) - 1, $deleted);
        self::assertSame($versions[0], (string) $this->versions->findPublication($entry, 'en')['version_uuid']);
    }

    public function testIdempotencySecondPassDeletesNothing(): void
    {
        $entry = 'eccccccccccc';
        $versions = $this->buildLineage($entry, 'en', 5);
        $this->versions->pin($entry, 'en', $versions[4], 'user00000001');

        $first = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));
        $second = $this->pruner()->prune(RetentionPolicy::fromValues('2', null));

        self::assertSame(3, $first->versionsDeleted);
        self::assertSame(0, $second->versionsDeleted);
    }

    public function testHistoricalPreviewTokenReturns404AfterPruneWhileDraftTokenSurvives(): void
    {
        $entry = 'eddddddddddd';
        $versions = $this->buildLineage($entry, 'en', 3);
        $this->versions->pin($entry, 'en', $versions[2], 'user00000001');
        $this->entryRepository()->createLocaleDraft($entry, 'en', 1, 'user00000001');

        $minter = new PreviewMinter($this->appContext());
        $historicalToken = $minter->mint($entry, 'en', $versions[0]);
        $draftToken = $minter->mint($entry, 'en');

        $this->pruner()->prune(RetentionPolicy::fromValues('1', null));

        $reader = new PreviewReader($this->appContext(), $this->entryRepository(), $this->versions);

        try {
            $reader->read($historicalToken);
            self::fail('Expected PreviewNotFoundException for a pruned historical version.');
        } catch (PreviewNotFoundException) {
            self::assertTrue(true);
        }

        $result = $reader->read($draftToken);
        self::assertSame($entry, $result['entry_uuid']);
        self::assertSame('en', $result['locale']);
        self::assertNull($result['version_uuid']);
    }

    public function testRetentionConfigBlockPassesRawValuesThrough(): void
    {
        self::assertIsArray(
            config($this->appContext(), 'lemma.versions'),
            'config/lemma.php must expose a versions block',
        );

        $keep = config($this->appContext(), 'lemma.versions.retention.keep');
        $maxAge = config($this->appContext(), 'lemma.versions.retention.max_age_days');

        self::assertNull($keep);
        self::assertNull($maxAge);

        $policy = RetentionPolicy::fromValues($keep, $maxAge);
        self::assertFalse($policy->isEnabled());
    }

    private function entryRepository(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }
}
