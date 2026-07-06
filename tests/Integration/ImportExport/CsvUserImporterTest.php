<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use Thallo\Importers\CsvUserImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

final class CsvUserImporterTest extends AppTestCase
{
    /** @var array<string,mixed> */
    private const OPTIONS = [
        'mapping' => [
            'username' => 'Username',
            'email' => 'Email',
            'first_name' => 'First',
            'last_name' => 'Last',
        ],
    ];

    private const CSV = "Username,Email,First,Last\njane,jane@example.com,Jane,Doe\njohn,john@example.com,John,Smith\n";

    protected function setUp(): void
    {
        parent::setUp();
        // uuid-keyed users table; CASCADE also clears profiles/user_roles.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles CASCADE');
    }

    public function testSupportsAndPlansAUserCsv(): void
    {
        $path = $this->writeCsv(self::CSV);
        $source = new ImportSource('storage', $path, 'text/csv');

        self::assertTrue($this->importer()->supports($source));

        $plan = $this->importer()->plan($source, new ImportOptions(options: self::OPTIONS));
        self::assertSame(2, $plan->totalRecords);
    }

    public function testPlanRequiresUsernameAndEmailMapped(): void
    {
        $path = $this->writeCsv(self::CSV);
        $this->expectExceptionMessageMatches('/email/');
        $this->importer()->plan(
            new ImportSource('storage', $path, 'text/csv'),
            new ImportOptions(options: ['mapping' => ['username' => 'Username']]),
        );
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $this->seedJob($this->writeCsv(self::CSV));

        $result = $this->importer()->process(
            new ImportBatch('batchusr0001', 'jobusr00001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobusr00001', 'dry_run', null, self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors));
        self::assertSame(0, $this->connection()->table('users')->count());
    }

    public function testCommitCreatesUsersWithProfiles(): void
    {
        $this->seedJob($this->writeCsv(self::CSV));

        $result = $this->importer()->process(
            new ImportBatch('batchusr0001', 'jobusr00001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobusr00001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(2, $this->connection()->table('users')->count());

        $jane = $this->connection()->table('users')->where('email', '=', 'jane@example.com')->first();
        self::assertNotNull($jane);
        self::assertSame('jane', $jane['username']);
        self::assertNotSame('', (string) $jane['password']); // hashed, non-empty

        $profile = $this->connection()->table('profiles')->where('user_uuid', '=', $jane['uuid'])->first();
        self::assertSame('Jane', $profile['first_name']);
        self::assertSame('Doe', $profile['last_name']);
    }

    public function testDuplicateEmailIsReportedAndOtherRowsStillImport(): void
    {
        $this->connection()->table('users')->insert([
            'uuid' => 'existing0001',
            'username' => 'jane',
            'email' => 'jane@example.com',
            'password' => 'x',
            'status' => 'active',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $this->seedJob($this->writeCsv(self::CSV));

        $result = $this->importer()->process(
            new ImportBatch('batchusr0001', 'jobusr00001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobusr00001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(1, $result->processedRecords); // john
        self::assertSame(1, $result->failedRecords); // jane (duplicate)
        self::assertSame(1, $result->errors[0]['record_number']);
        self::assertSame(2, $this->connection()->table('users')->count()); // existing jane + new john
    }

    public function testRowWithUnknownRoleFailsAtomicallyLeavingNoOrphanAccount(): void
    {
        // Map a `roles` column pointing at a role slug that does not exist. assignRole() fails after
        // the account+profile were written; the row must roll back so no orphaned active account is
        // left (which the uniqueness pre-check would otherwise make permanently un-retryable).
        $csv = "Username,Email,Roles\nzoe,zoe@example.com,does-not-exist\n";
        $this->seedJob($this->writeCsv($csv));

        $result = $this->importer()->process(
            new ImportBatch('batchusr0001', 'jobusr00001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobusr00001', 'commit', null, [
                'mapping' => ['username' => 'Username', 'email' => 'Email', 'roles' => 'Roles'],
            ]),
        );

        self::assertSame(0, $result->processedRecords);
        self::assertSame(1, $result->failedRecords);
        self::assertMatchesRegularExpression('/role/i', (string) $result->errors[0]['message']);
        // The account was rolled back — no orphan, and a corrected re-import can recreate it.
        self::assertSame(0, $this->connection()->table('users')->where('email', '=', 'zoe@example.com')->count());
    }

    private function importer(): CsvUserImporter
    {
        return $this->container()->get(CsvUserImporter::class);
    }

    private function writeCsv(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/lemma-user-import-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/users-' . bin2hex(random_bytes(6)) . '.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function seedJob(string $absolutePath): void
    {
        $this->connection()->table('import_export_jobs')->insert([
            'uuid' => 'jobusr00001',
            'type' => 'import',
            'adapter' => 'csv.users',
            'status' => 'queued',
            'mode' => 'commit',
            'source_disk' => 'storage',
            'source_path' => $absolutePath,
            'total_records' => 2,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'fileusr00001',
            'job_uuid' => 'jobusr00001',
            'role' => 'source',
            'disk' => 'storage',
            'path' => $absolutePath,
            'mime_type' => 'text/csv',
            'size_bytes' => filesize($absolutePath) ?: 0,
            'created_at' => '2026-06-27 00:00:00',
        ]);
    }
}
