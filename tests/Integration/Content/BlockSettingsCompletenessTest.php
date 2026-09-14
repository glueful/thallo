<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockInstanceWalker;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Thallo\Core\Content\Schema\Migration\RenameField;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Visual builder spec §1.2: settings survive every path that reconstructs a block. One fixture
 * document with nested blocks carrying ordered class ids, a sparse breakpoint map, a reset and
 * advanced settings, pushed through each path and read back byte-identical.
 */
final class BlockSettingsCompletenessTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        // The shared boot memoises block schemas and style declarations per process; a test
        // that seeds must drop those memos the way a new request would.
        $shared = $this->container()->get(BlockTypeRepository::class);
        (new \ReflectionProperty($shared, 'schemas'))->setValue($shared, null);
        $this->container()->get(\Thallo\Contracts\Style\BlockStyleRegistry::class)->reset();
        $blocks = new BlockTypeRepository($this->connection());
        // A block type past its conversion: managed style is accepted.
        if ($blocks->findBySlug('probe') === null) {
            $blocks->create([
                'slug' => 'probe',
                'label' => 'Probe',
                'schema' => [['name' => 'text', 'type' => 'string'], ['name' => 'inner', 'type' => 'blocks']],
                'style_capabilities' => ['spacing', 'radius'],
                'style_targets' => [
                    'targets' => ['root' => ['kind' => 'box']],
                    'map' => ['spacing' => 'root', 'radius' => 'root'],
                ],
                'flags' => [],
            ]);
        }
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /** @return array<string,mixed> the settings under test, in the order the validator emits them */
    private function settings(): array
    {
        return [
            'style' => [
                'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]],
                'radius' => ['type' => 'reset'],
            ],
            'classes' => ['zeta', 'alpha', 'mid'],
            'advanced' => [
                'anchor' => 'pricing', 'css_classes' => ['hero'], 'attributes' => ['data-track' => 'x'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function body(): array
    {
        return [[
            'id' => 'outer0000001',
            'type' => 'probe',
            'data' => [
                'text' => 'outer',
                'inner' => [[
                    'id' => 'inner0000001',
                    'type' => 'probe',
                    'data' => ['text' => 'inner', 'inner' => []],
                    'settings' => $this->settings(),
                ]],
            ],
            'settings' => $this->settings(),
        ]];
    }

    private function validator(): FieldValidator
    {
        return new FieldValidator(
            $this->connection(),
            $this->appContext(),
            new BlockTypeRepository($this->connection()),
        );
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    /**
     * Object key order is not preserved by JSONB storage; list order is. Compare canonically:
     * associative keys sorted, lists (e.g. `classes`) kept in order.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map(self::canonical(...), $value);
        if (!array_is_list($out)) {
            ksort($out);
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $body
     * @param array<string,mixed>|null $expected
     */
    private function assertSettingsSurvive(
        array $body,
        string $path,
        ?array $expected = null,
        bool $nested = true,
    ): void {
        $expected ??= $this->settings();
        self::assertSame(
            self::canonical($expected),
            self::canonical($body[0]['settings'] ?? null),
            "outer block through {$path}",
        );
        if ($nested) {
            self::assertSame(
                self::canonical($expected),
                self::canonical($body[0]['data']['inner'][0]['settings'] ?? null),
                "nested block through {$path}",
            );
        }
    }

    public function testDraftSaveAndReadThroughTheApi(): void
    {
        $uuid = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');
        $clean = $this->validator()->validate(
            ContentTypeSchema::fromArray([
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ]),
            ['title' => 'T', 'body' => $this->body()],
        );
        $this->entries()->saveDraft($uuid, 'en', $clean, 1, 0, 'user00000001');

        $this->assertSettingsSurvive($this->entries()->findDraft($uuid, 'en')['fields']['body'], 'draft');

        $request = Request::create("/v1/admin/entries/{$uuid}/draft/en", 'GET');
        $request->attributes->set('user', ['uuid' => 'user00000001']);
        $response = $this->container()->get(EntryController::class)->getDraft($request, $uuid, 'en');
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSettingsSurvive($payload['data']['draft']['fields']['body'], 'GET /entries/{uuid}/draft/{locale}');
    }

    public function testPublishWritesAVersionThatKeepsSettings(): void
    {
        $uuid = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');
        $clean = $this->validator()->validate(
            ContentTypeSchema::fromArray([
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ]),
            ['title' => 'T', 'body' => $this->body()],
        );
        $this->entries()->saveDraft($uuid, 'en', $clean, 1, 0, 'user00000001');
        $versions = new VersionRepository($this->connection());
        (new PublishService(
            $this->appContext(),
            $this->entries(),
            $versions,
            new ContentTypeRepository($this->connection()),
            $this->validator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');

        $publication = $versions->findPublication($uuid, 'en');
        $version = $versions->findVersionByUuid((string) $publication['version_uuid']);
        $this->assertSettingsSurvive($version['fields']['body'], 'published version');
    }

    public function testRegionsSaveAndReadSettings(): void
    {
        // Region allowlists admit starters only; the region document carries classes and advanced
        // settings here (style is covered by the entry paths above).
        $settings = [
            'classes' => ['zeta', 'alpha'],
            'advanced' => ['anchor' => 'foot', 'css_classes' => ['x']],
        ];
        $blocks = [[
            'id' => 'cont00000001',
            'type' => 'container',
            'data' => ['content' => [[
                'id' => 'head00000001',
                'type' => 'rich_text',
                'data' => ['content' => '<p>hi</p>'],
                'settings' => $settings,
            ]]],
            'settings' => $settings,
        ]];
        $dto = (new RequestDataHydrator())->hydrate(UpdateRegionData::class, ['blocks' => $blocks, 'settings' => []]);
        try {
            $response = $this->container()->get(RegionAdminController::class)->update($dto, 'footer');
        } catch (\Thallo\Core\Content\Validation\ValidationException $e) {
            self::fail('region validation: ' . json_encode($e->errors()));
        }
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true)['data'];
        $saved = $payload['blocks'] ?? $payload['region']['blocks'];
        self::assertSame(self::canonical($settings), self::canonical($saved[0]['settings']), 'region update response');
        self::assertSame(
            self::canonical($settings),
            self::canonical($saved[0]['data']['content'][0]['settings']),
            'nested through region update',
        );
        $read = (new \Thallo\Core\Content\Regions\RegionRepository($this->connection()))->find('footer');
        self::assertSame(self::canonical($settings), self::canonical($read['blocks'][0]['settings']), 'region read');
        self::assertSame(
            self::canonical($settings),
            self::canonical($read['blocks'][0]['data']['content'][0]['settings']),
            'nested through region read',
        );
    }

    public function testThePreviewWorkingCopyKeepsSettings(): void
    {
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $clean = $this->validator()->validate(
            ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]),
            ['body' => $this->body()],
        );
        $store->clear('entry0000001', 'en');
        $store->accept('entry0000001', 'en', null, null, $clean, [], 60);
        try {
            $this->assertSettingsSurvive($store->fields('entry0000001', 'en')['body'], 'preview working copy');
        } finally {
            $store->clear('entry0000001', 'en'); // the cache store is process-shared across tests
        }
    }

    public function testBlockMigrationRewriteKeepsSettings(): void
    {
        $walker = new BlockInstanceWalker(new BlockTypeRepository($this->connection()));
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        [$out, $changed] = $walker->rewrite(
            ['body' => $this->body()],
            $schema,
            'probe',
            new MigrationOpSet([new RenameField('text', 'label')]),
        );
        self::assertTrue($changed);
        self::assertSame('outer', $out['body'][0]['data']['label']);
        $this->assertSettingsSurvive($out['body'], 'block migration rewrite');
    }

    public function testTheRendererSeesSettingsOnEveryBlock(): void
    {
        $base = $this->appContext()->getBasePath();
        $themes = sys_get_temp_dir() . '/thallo-probe-theme-' . uniqid('', true);
        mkdir($themes . '/probe/templates/blocks', 0755, true);
        ThemeFixture::write($themes . '/probe', 'probe');
        file_put_contents(
            $themes . '/probe/templates/blocks/probe.twig',
            '<p data-anchor="{{ block.settings.advanced.anchor }}"'
            . ' data-classes="{{ block.settings.classes|join(",") }}">'
            . '{{ data.text }}</p>{{ blocks(data.inner) }}',
        );
        try {
            $env = (new TwigFactory(
                new ThemeLocator('probe', $themes),
                $this->container()->get(RenderContextExtension::class),
                $base . '/storage/cache/twig',
            ))->environment();
            $html = $env->createTemplate('{{ blocks(list) }}')->render(['list' => $this->body()]);
        } finally {
            exec('rm -rf ' . escapeshellarg($themes));
        }

        self::assertSame(2, substr_count($html, 'data-anchor="pricing"'), 'outer and nested block see their settings');
        self::assertStringContainsString('data-classes="zeta,alpha,mid"', $html);
    }
}
