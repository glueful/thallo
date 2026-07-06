<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Content\Console\PruneVersionsCommand;
use App\Content\Repositories\VersionRepository;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneVersionsCommandTest extends AppTestCase
{
    private VersionRepository $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = new VersionRepository($this->connection());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PruneVersionsCommand($this->container(), $this->appContext()));
    }

    /** @return list<string> */
    private function buildLineage(string $entry, int $count): array
    {
        $uuids = [];
        for ($version = 1; $version <= $count; $version++) {
            $uuids[] = $this->versions->appendVersion(
                $entry,
                'en',
                $version,
                ['title' => "v{$version}"],
                1,
                'user00000001',
            );
        }
        return $uuids;
    }

    public function testKeepOverridePrunesAndReportsCounts(): void
    {
        $entry = 'f1aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 5);
        $this->versions->pin($entry, 'en', $versions[4], 'user00000001');

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '2']);

        self::assertSame(0, $exit);
        self::assertCount(2, $this->versions->versionsFor($entry, 'en'));
        self::assertStringContainsString('versions_deleted', $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testDryRunDeletesNothing(): void
    {
        $entry = 'f2aaaaaaaaaa';
        $versions = $this->buildLineage($entry, 5);
        $this->versions->pin($entry, 'en', $versions[4], 'user00000001');

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '2', '--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertCount(5, $this->versions->versionsFor($entry, 'en'));
        self::assertStringContainsString('dry-run', strtolower($tester->getDisplay()));
    }

    public function testDisabledPolicyIsANoOp(): void
    {
        $entry = 'f3aaaaaaaaaa';
        $this->buildLineage($entry, 4);

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertCount(4, $this->versions->versionsFor($entry, 'en'));
    }

    public function testInvalidOverrideFailsLoudWithoutDeleting(): void
    {
        $entry = 'f4aaaaaaaaaa';
        $this->buildLineage($entry, 4);

        $tester = $this->tester();
        $exit = $tester->execute(['--keep' => '0']);

        self::assertSame(1, $exit);
        self::assertCount(4, $this->versions->versionsFor($entry, 'en'));
        self::assertStringContainsString('keep', strtolower($tester->getDisplay()));
    }

    public function testMaxAgeOverrideRejectsNonNumeric(): void
    {
        $entry = 'f5aaaaaaaaaa';
        $this->buildLineage($entry, 3);

        $tester = $this->tester();
        $exit = $tester->execute(['--max-age-days' => 'soon']);

        self::assertSame(1, $exit);
        self::assertCount(3, $this->versions->versionsFor($entry, 'en'));
    }

    public function testCommandIsNamedForCliRegistration(): void
    {
        self::assertSame(
            'lemma:versions:prune',
            (new PruneVersionsCommand($this->container(), $this->appContext()))->getName(),
        );
    }
}
