<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\CascadeResolver;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Http\Controllers\StyleClassController;
use Thallo\Core\Content\Http\DTOs\StyleClassJobRequestData;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Classes\StyleClassJobRepository;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;
use Thallo\Core\Content\Style\Classes\StyleClassJobService;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\SiteStyleGeneration;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Visual builder spec §4.5: detach-everywhere and remove-everywhere as idempotent, pass-based
 * jobs pinned to a class version, holding the class locked until completion; document rewrites
 * never move the style generation, the lock and unlock do.
 */
final class StyleClassJobTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private string $typeUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->typeUuid = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'page', 'name' => 'Page', 'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    private function classes(): StyleClassRepository
    {
        return $this->container()->get(StyleClassRepository::class);
    }

    private function jobs(): StyleClassJobRepository
    {
        return $this->container()->get(StyleClassJobRepository::class);
    }

    private function runner(): StyleClassJobRunner
    {
        return $this->container()->get(StyleClassJobRunner::class);
    }

    /** @return array<string,mixed> */
    private static function heading(array $classes, array $style = []): array
    {
        $settings = [];
        if ($classes !== []) {
            $settings['classes'] = $classes;
        }
        if ($style !== []) {
            $settings['style'] = $style;
        }
        return ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => $settings];
    }

    /** @return array<string,mixed> */
    private function band(): array
    {
        return $this->classes()->create(['name' => 'Band', 'style' => [
            'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]],
            'radius' => ['type' => 'token', 'value' => 'radius.lg'],
        ]]);
    }

    /** One draft, one published entry, one retained older version and one region, all referencing. */
    private function seedEverywhere(string $classId): string
    {
        $entries = $this->container()->get(EntryRepository::class);
        $publish = $this->container()->get(PublishService::class);
        $uuid = $entries->createEntry($this->typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft(
            $uuid,
            'en',
            ['title' => 'One',
            'body' => [self::heading([$classId])]],
            1,
            0,
            'user00000001',
        );
        $publish->publish($uuid, 'en', 'user00000001');
        $entries->saveDraft(
            $uuid,
            'en',
            ['title' => 'Two',
            'body' => [self::heading([$classId])]],
            1,
            1,
            'user00000001',
        );
        $publish->publish($uuid, 'en', 'user00000001');
        $entries->saveDraft(
            $uuid,
            'en',
            ['title' => 'Three',
            'body' => [self::heading([$classId])]],
            1,
            2,
            'user00000001',
        );
        $this->container()->get(RegionRepository::class)
            ->save('footer', [self::heading([$classId])], [], 'user00000001');
        return $uuid;
    }

    /** @return array<string, array<string,mixed>> every stored document's first block by source and id */
    private function firstBlocks(): array
    {
        $out = [];
        $this->container()->get(BlockDocumentSources::class)->each(
            static function (BlockDocumentSource $source, DocumentRef $ref) use (&$out): void {
                $out[$ref->sourceType . ':' . $ref->sourceId] = $ref->fields['body'][0] ?? $ref->fields['blocks'][0];
            },
        );
        return $out;
    }

    /** The resolver's effective outcome per property the block can take, per breakpoint. */
    private function outcomes(array $block, array $classRefs): array
    {
        $resolver = new CascadeResolver();
        $caps = $this->container()->get(\Thallo\Contracts\Style\BlockStyleRegistry::class)
            ->capabilitiesFor((string) $block['type']);
        $out = [];
        foreach (StyleSchema::properties() as $path => $def) {
            if (!$caps->allows($path)) {
                continue; // dormant on this block: detach never writes it
            }
            foreach ($resolver->resolve($path, $classRefs, $block['settings']['style'] ?? [], $def) as $bp => $r) {
                $out["{$path}@{$bp}"] = $r->state === 'reset' ? 'reset' : json_encode($r->value);
            }
        }
        return $out;
    }

    public function testDetachEverywhereRewritesEveryDocumentPreservesOutcomesAndRerunsAsANoOp(): void
    {
        $band = $this->band();
        $this->seedEverywhere($band['id']);
        $refs = [['id' => $band['id'], 'style' => $band['style']]];
        $before = array_map(fn (array $b): array => $this->outcomes($b, $refs), $this->firstBlocks());
        // The versions source lists every retained version, the current publication included.
        self::assertCount(5, $before, 'draft, published, two retained versions, region');
        $expected = reset($before);
        self::assertSame(array_fill(0, 5, $expected), array_values($before), 'every seed resolves alike');
        $generation = $this->container()->get(SiteStyleGeneration::class);
        $start = $generation->current();

        $jobId = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'detach');
        self::assertSame($start + 1, $generation->current(), 'the lock is a class write');
        self::assertNotNull($this->classes()->find($band['id'])['locked_by_job']);

        $result = $this->runner()->run($jobId);
        self::assertSame('completed', $result['status'], json_encode($this->jobs()->find($jobId)));
        self::assertSame(5, $result['done']);
        self::assertSame($start + 2, $generation->current(), 'only the unlock moved the generation');
        self::assertNull($this->classes()->find($band['id'])['locked_by_job']);

        // The published document persists by appending a version and re-pinning, so one more
        // retained version exists now; every document resolves exactly as before.
        $after = $this->firstBlocks();
        self::assertCount(6, $after);
        foreach ($after as $key => $block) {
            self::assertArrayNotHasKey('classes', $block['settings'], $key);
            self::assertSame($expected, $this->outcomes($block, []), "{$key} keeps every effective outcome");
            self::assertSame('spacing.lg', $block['settings']['style']['spacing']['padding']['top']['md']['value']);
            self::assertArrayNotHasKey('radius', $block['settings']['style'], 'dormant on a heading: not written');
        }

        $again = $this->runner()->run($jobId);
        self::assertSame('completed', $again['status'], 'a rerun after completion touches nothing');
        self::assertSame($start + 2, $generation->current());
    }

    public function testRemoveEverywhereDropsTheReferenceAndChangesTheOutcome(): void
    {
        $band = $this->band();
        $this->seedEverywhere($band['id']);
        $jobId = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'remove');
        self::assertSame('completed', $this->runner()->run($jobId)['status']);
        foreach ($this->firstBlocks() as $key => $block) {
            self::assertSame([], $block['settings'], "{$key} carries nothing");
        }
    }

    public function testAClassEditedAfterQueueingFailsTheJobAndUnlocksAndAnEditDuringTheJobIs409(): void
    {
        $band = $this->band();
        $this->seedEverywhere($band['id']);
        $jobId = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'detach');
        try {
            $version = $this->classes()->find($band['id'])['version'];
            $this->classes()->update($band['id'], $version, ['name' => 'Renamed']);
            self::fail('a locked class refuses edits');
        } catch (StyleClassLocked) {
            // expected
        }
        $api = $this->container()->get(StyleClassController::class);
        $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $second = $api->queueJob(
            $this->container()->get(RequestDataHydrator::class)
                ->hydrate(StyleClassJobRequestData::class, ['kind' => 'remove']),
            $request,
            $band['id'],
        );
        self::assertSame(409, $second->getStatusCode(), 'one job at a time');

        // The class row moves under the job (a forced unlock and edit, as an operator might):
        $this->classes()->unlock($band['id']);
        $this->classes()->update($band['id'], $this->classes()->find($band['id'])['version'], ['name' => 'Renamed']);
        $result = $this->runner()->run($jobId);
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('changed since', json_encode($this->jobs()->find($jobId)['failure_report']));
        self::assertNull($this->classes()->find($band['id'])['locked_by_job']);
    }

    public function testARefusedWriteIsRetriedOnTheNextPassAndALateReferenceIsFoundByTheCompletionPass(): void
    {
        $band = $this->band();
        $this->seedEverywhere($band['id']);
        $jobId = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'detach');

        // A region edited between the job's read and its write: the first persist is refused,
        // the second pass detaches the fresh row. Simulated with a source whose first persist
        // saves the region again before writing.
        $regions = $this->container()->get(RegionRepository::class);
        $flaky = new class (new RegionsSource($this->connection()), $regions) implements BlockDocumentSource {
            public int $persists = 0;

            public function __construct(private RegionsSource $inner, private RegionRepository $regions)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }

            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                if (++$this->persists === 1) {
                    $blocks = $this->regions->find('footer')['blocks'];
                    $blocks[0]['data']['text'] = 'edited meanwhile';
                    $this->regions->save('footer', $blocks, [], 'user00000001');
                }
                return $this->inner->persist($ref, $fields, $actor);
            }
        };
        $sources = $this->container()->get(BlockDocumentSources::class);
        $all = [];
        foreach ($sources->all() as $source) {
            $all[] = $source->id() === RegionsSource::ID ? $flaky : $source;
        }
        $runner = new StyleClassJobRunner(
            $this->jobs(),
            $this->classes(),
            $this->container()->get(\Thallo\Contracts\Style\StyleClassProvider::class),
            new BlockDocumentSources(...$all),
            $this->container()->get(\Thallo\Contracts\Style\BlockStyleRegistry::class),
        );
        $result = $runner->run($jobId);
        self::assertSame('completed', $result['status'], json_encode($this->jobs()->find($jobId)));
        self::assertSame(2, $flaky->persists, 'refused once, detached on the second pass');
        self::assertGreaterThanOrEqual(2, $this->jobs()->find($jobId)['passes']);
        $footer = $regions->find('footer');
        self::assertSame('edited meanwhile', $footer['blocks'][0]['data']['text'], 'the edit survived');
        self::assertArrayNotHasKey('classes', $footer['blocks'][0]['settings']);
    }

    public function testADocumentThatKeepsRefusingFailsTheJobAndKeepsTheClassLocked(): void
    {
        $band = $this->band();
        $this->seedEverywhere($band['id']);
        $jobId = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'detach');
        $stubborn = new class (new RegionsSource($this->connection())) implements BlockDocumentSource {
            public function __construct(private RegionsSource $inner)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }

            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                return false;
            }
        };
        $all = [];
        foreach ($this->container()->get(BlockDocumentSources::class)->all() as $source) {
            $all[] = $source->id() === RegionsSource::ID ? $stubborn : $source;
        }
        $runner = new StyleClassJobRunner(
            $this->jobs(),
            $this->classes(),
            $this->container()->get(\Thallo\Contracts\Style\StyleClassProvider::class),
            new BlockDocumentSources(...$all),
            $this->container()->get(\Thallo\Contracts\Style\BlockStyleRegistry::class),
        );
        $result = $runner->run($jobId);
        self::assertSame('failed', $result['status']);
        self::assertSame(StyleClassJobRunner::MAX_PASSES, $this->jobs()->find($jobId)['passes']);
        $report = json_encode($this->jobs()->find($jobId)['failure_report']);
        self::assertStringContainsString('footer', $report);
        self::assertStringContainsString('still references', $report);
        self::assertNull($this->classes()->find($band['id'])['locked_by_job'], 'unlocked so an operator can re-queue');
    }

    public function testTheEndpointsQueueAndReportAJob(): void
    {
        $band = $this->band();
        $api = $this->container()->get(StyleClassController::class);
        $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $queued = $api->queueJob(
            $this->container()->get(RequestDataHydrator::class)
                ->hydrate(StyleClassJobRequestData::class, ['kind' => 'detach']),
            $request,
            $band['id'],
        );
        self::assertSame(202, $queued->getStatusCode(), (string) $queued->getContent());
        $job = json_decode((string) $queued->getContent(), true)['data']['job'];
        self::assertSame('running', $job['status']);
        $shown = $api->showJob($request, $band['id'], $job['id']);
        self::assertSame(200, $shown->getStatusCode());
        self::assertSame(404, $api->showJob($request, 'other0000000', $job['id'])->getStatusCode());
        foreach (
            [
                ['POST', '/v1/admin/style-classes/{id}/jobs', 'styles.manage'],
                ['GET', '/v1/admin/style-classes/{id}/jobs/{job}', 'content.view'],
            ] as [$method, $path, $permission]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path}");
            self::assertContains("content_permission:{$permission}", $route['middleware']);
        }
    }
}
