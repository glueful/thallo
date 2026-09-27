<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationService;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;
use Thallo\Core\Content\Style\Classes\StyleClassJobService;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassUsage;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Saved sections are stored block documents like drafts, versions and regions, so every walker
 * over stored blocks reaches them: a block type's migration rewrites them, a style class's
 * usage counts them, and detach- or remove-everywhere cleans them. Each write is conditional on
 * the version the walker read, and a save cannot apply a class that is archived or held by a job.
 */
final class SavedSectionDocumentsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function repo(): SavedSectionRepository
    {
        return $this->container()->get(SavedSectionRepository::class);
    }

    /** @param list<string> $classes @return array<string,mixed> */
    private static function heading(array $classes = []): array
    {
        return ['type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => $classes === [] ? [] : ['classes' => $classes]];
    }

    /** @return list<DocumentRef> the saved sections as the registry enumerates them */
    private function documents(): array
    {
        $out = [];
        $this->container()->get(BlockDocumentSources::class)
            ->only(SavedSectionsSource::ID)
            ->each(static function ($source, DocumentRef $ref) use (&$out): void {
                $out[] = $ref;
            });
        return $out;
    }

    public function testASavedSectionIsADocumentWrittenOnlyAtTheVersionItWasRead(): void
    {
        $id = $this->repo()->create('Hello', 'Saved', null, self::heading(), null, null);
        [$ref] = $this->documents();
        self::assertSame($id, $ref->sourceId);
        self::assertSame('0', $ref->revision);
        self::assertSame('heading', $ref->fields['blocks'][0]['type']);

        $source = $this->container()->get(SavedSectionsSource::class);
        $changed = ['blocks' => [['type' => 'heading', 'data' => ['text' => 'Hello'], 'settings' => []]]];
        self::assertTrue($source->persist($ref, $changed));
        self::assertSame('Hello', $this->repo()->all()[0]['block']['data']['text']);
        self::assertSame('1', $this->documents()[0]->revision);
        self::assertFalse($source->persist($ref, $changed), 'a stale read writes nothing');

        // A rename is a write too: a walker holding the old version is refused.
        [$fresh] = $this->documents();
        $this->repo()->update($id, ['name' => 'Renamed']);
        self::assertFalse($source->persist($fresh, $changed));
    }

    public function testABlockTypesMigrationRewritesSavedSections(): void
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'promo_tile', 'label' => 'Promo tile', 'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $tile = ['type' => 'promo_tile', 'data' => ['title' => 'Kept'], 'settings' => []];
        $this->repo()->create('Card', 'Saved', null, $tile, null, null);
        $card = (string) (new BlockTypeRepository($this->connection()))->findBySlug('promo_tile')['uuid'];
        $migration = $this->container()->get(BlockMigrationService::class)
            ->migrate($card, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], 'user00000001');

        $result = $this->container()->get(BlockBackfillRunner::class)->run($migration);
        self::assertSame(0, $result['failed']);
        $block = $this->repo()->all()[0]['block'];
        self::assertSame(['heading' => 'Kept'], $block['data']);
    }

    public function testAStyleClassCountsAndRemovesItsUseInSavedSections(): void
    {
        $band = $this->container()->get(StyleClassRepository::class)->create(['name' => 'Band', 'style' => [
            'radius' => ['type' => 'token', 'value' => 'radius.lg'],
        ]]);
        $this->repo()->create('Banded', 'Saved', null, self::heading([$band['id']]), null, null);

        $usage = $this->container()->get(StyleClassUsage::class)->of($band['id'], $band['style']);
        self::assertSame(1, $usage['by_source']['saved_sections']);
        self::assertSame(1, $usage['references']);

        $job = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'remove');
        self::assertSame('completed', $this->container()->get(StyleClassJobRunner::class)->run($job)['status']);
        self::assertSame([], $this->repo()->all()[0]['block']['settings'], 'the reference is gone');
    }

    public function testASaveCannotApplyAnArchivedClass(): void
    {
        $classes = $this->container()->get(StyleClassRepository::class);
        $band = $classes->create(['name' => 'Band', 'style' => [
            'radius' => ['type' => 'token', 'value' => 'radius.lg'],
        ]]);
        $this->connection()->table('style_classes')->where('id', '=', $band['id'])
            ->update(['archived_at' => gmdate('Y-m-d H:i:s')]);

        $block = self::heading([$band['id']]) + ['id' => 'head00000001'];
        $dto = (new RequestDataHydrator())->hydrate(SaveSectionData::class, ['name' => 'Banded', 'block' => $block]);
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        $resp = $this->container()->get(SavedSectionController::class)->store($dto, $request);

        self::assertSame(422, $resp->getStatusCode(), (string) $resp->getContent());
        self::assertSame([], $this->repo()->all());
    }
}
