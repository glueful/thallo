<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Console\ConvertSettingsCommand;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Conversion\Converter;
use Thallo\Core\Content\Style\Conversion\ConversionTables;
use Thallo\Core\Content\Style\Conversion\DecisionsFile;
use Thallo\Core\Content\Style\Conversion\SettingsConversion;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;

/** `thallo:blocks:convert-settings` end to end (visual builder spec §7.3): dry run, decisions, live, idempotence. */
final class ConvertSettingsCommandTest extends AppTestCase
{
    private string $type = '';
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $this->dir = sys_get_temp_dir() . '/thallo-convert-' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function convert(array $options): array
    {
        $tester = new CommandTester(new ConvertSettingsCommand($this->container(), self::$app));
        $exit = $tester->execute($options + ['--report' => $this->dir . '/report.jsonl']);
        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    /** @return list<array<string,mixed>> */
    private function report(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->dir . '/report.jsonl')));
        return array_map(static fn (string $l): array => json_decode($l, true), array_values($lines));
    }

    private function seed(): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user1');
        $legacy = ['title' => 'Legacy', 'body' => [
            ['id' => 'hd', 'type' => 'heading', 'data' => ['text' => 'Hi', 'align' => 'center', 'color' => '#ff0000']],
        ]];
        $entries->saveDraft($uuid, 'en', $legacy, 1, 0, 'user1');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'convert-page');
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection(), $this->appContext(), new BlockTypeRepository($this->connection())),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user1');
        (new RegionRepository($this->connection()))->save('header', [
            ['id' => 'bt', 'type' => 'button', 'data' => ['label' => 'Go', 'url' => '/go', 'shape' => 'square']],
        ], [], 'user1');
        return $uuid;
    }

    public function testDryRunReportsLiveRefusesUntilDecidedThenConvertsEveryDocumentOnce(): void
    {
        $uuid = $this->seed();
        $dry = $this->convert(['--dry-run' => true]);
        self::assertSame(0, $dry['exit'], $dry['display']);
        $report = $this->report();
        $unmappable = array_values(array_filter($report, static fn (array $l): bool => $l['status'] === 'unmappable'));
        // The heading's hex colour is unmappable in the draft AND the published version.
        self::assertCount(2, $unmappable);
        self::assertSame(['entry_draft', 'entry_version'], array_column($unmappable, 'source_type'));
        $draft = $this->entries()->findDraft($uuid, 'en');
        self::assertSame('center', $draft['fields']['body'][0]['data']['align'], 'a dry run writes nothing');

        $live = $this->convert([]);
        self::assertSame(1, $live['exit']);
        self::assertStringContainsString('unresolved', $live['display']);

        $decisions = new DecisionsFile();
        foreach ($unmappable as $line) {
            $decisions->record(
                DecisionsFile::key(
                    $line['source_type'],
                    $line['source_id'],
                    $line['source_revision'],
                    $line['block_id'],
                    $line['field'],
                ),
                [
                    'document_hash' => $line['document_hash'],
                    'converter_version' => Converter::VERSION,
                    'action' => 'token',
                    'value' => 'color.accent',
                ],
            );
        }
        $decisions->write($this->dir . '/decisions.json');
        $converted = $this->convert(['--decisions' => $this->dir . '/decisions.json']);
        self::assertSame(0, $converted['exit'], $converted['display']);

        $draft = $this->entries()->findDraft($uuid, 'en');
        self::assertSame(['settings' => 1, 'conversions' => ['presentation-group-1']], $draft['fields']['_schema']);
        self::assertArrayNotHasKey('align', $draft['fields']['body'][0]['data']);
        $style = $draft['fields']['body'][0]['settings']['style'];
        self::assertSame('center', $style['alignment']['text']['base']['value']);
        self::assertSame(['type' => 'token', 'value' => 'color.accent'], $style['colors']['text']);
        self::assertArrayNotHasKey('color', $draft['fields']['body'][0]['data']);
        $versions = new VersionRepository($this->connection());
        $pinned = $versions->findVersionByUuid((string) $versions->findPublication($uuid, 'en')['version_uuid']);
        $stamp = $pinned['fields']['_schema']['conversions'];
        self::assertSame(['presentation-group-1'], $stamp, 'the version converted in place');
        $header = (new RegionRepository($this->connection()))->find('header');
        self::assertSame('radius.none', $header['blocks'][0]['settings']['style']['radius']['value']);

        $again = $this->convert([]);
        self::assertSame(0, $again['exit']);
        self::assertStringContainsString('Documents pending: 0', $again['display'], 'idempotent: nothing pending');
    }

    public function testAFailureBetweenConvertAndPersistLeavesTheDocumentUnstampedAndUnconverted(): void
    {
        $uuid = $this->seed();
        $drafts = $this->container()->get(EntryDraftsSource::class);
        $crashing = new class ($drafts) implements BlockDocumentSource {
            public function __construct(private readonly EntryDraftsSource $inner)
            {
            }
            public function id(): string
            {
                return 'entry_draft';
            }
            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }
            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                throw new \RuntimeException('disk gone');
            }
        };
        $conversion = new SettingsConversion(
            new BlockDocumentSources($crashing),
            $this->container()->get(Converter::class),
            $this->container()->get(\Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository::class),
            ConversionTables::shipped(),
        );
        $decisions = new DecisionsFile();
        $evaluation = $conversion->evaluate($decisions);
        foreach ($evaluation['report']->unresolved() as $line) {
            $decisions->record(DecisionsFile::key(
                $line['source_type'],
                $line['source_id'],
                $line['source_revision'],
                $line['block_id'],
                $line['field'],
            ), [
                'document_hash' => $line['document_hash'],
                'converter_version' => Converter::VERSION,
                'action' => 'discard',
            ]);
        }
        $evaluation = $conversion->evaluate($decisions);
        self::assertSame(0, $evaluation['unresolved']);
        try {
            $conversion->apply($evaluation);
            self::fail('expected the persist failure to surface');
        } catch (\RuntimeException $e) {
            self::assertSame('disk gone', $e->getMessage());
        }
        $draft = $this->entries()->findDraft($uuid, 'en');
        self::assertArrayNotHasKey('_schema', $draft['fields']);
        self::assertSame('center', $draft['fields']['body'][0]['data']['align']);
    }

    private function entries(): EntryRepository
    {
        $types = new ContentTypeRepository($this->connection());
        return new EntryRepository($this->connection(), $this->appContext(), $types);
    }
}
