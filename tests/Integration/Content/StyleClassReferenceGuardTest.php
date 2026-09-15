<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\DTOs\SaveDraftData;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Classes\StyleClassArchived;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Visual builder spec §4.5: the serialisation between a document write and a job's lock. Inside
 * the write transaction, a reference the write INTRODUCES bumps the class row's guard — a write
 * on the row the lock holds — so a locked class refuses it (409) and an archived one refuses a
 * newly authored reference (422); references already stored are never re-checked, and a
 * restored revision's references are trusted except against a lock.
 */
final class StyleClassReferenceGuardTest extends AppTestCase
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

    /** @param list<string> $classes */
    private static function heading(array $classes, string $text = 'Hi'): array
    {
        $settings = $classes === [] ? [] : ['classes' => $classes];
        return ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => $text], 'settings' => $settings];
    }

    private function classes(): StyleClassRepository
    {
        return $this->container()->get(StyleClassRepository::class);
    }

    /** @return array{0: string, 1: EntryRepository} the entry uuid and the repository */
    private function entryWith(array $body): array
    {
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($this->typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => 'T', 'body' => $body], 1, 0, 'user00000001');
        return [$uuid, $entries];
    }

    public function testASaveIntroducingAReferenceToALockedClassIsRefusedAndAStoredOneIsNot(): void
    {
        $band = $this->classes()->create(['name' => 'Band', 'style' => []]);
        [$uuid, $entries] = $this->entryWith([self::heading([$band['id']])]);
        $this->classes()->lock($band['id'], 'job000000001');

        // The reference is already stored: an edit elsewhere in the document still saves.
        $entries->saveDraft(
            $uuid,
            'en',
            ['title' => 'T2',
            'body' => [self::heading([$band['id']], 'edited')]],
            1,
            1,
            'user00000001',
        );

        [$other] = $this->entryWith([self::heading([])]);
        try {
            $entries->saveDraft(
                $other,
                'en',
                ['title' => 'T',
                'body' => [self::heading([$band['id']])]],
                1,
                1,
                'user00000001',
            );
            self::fail('a newly introduced reference to a locked class must be refused');
        } catch (StyleClassLocked $e) {
            self::assertSame('job000000001', $e->job);
        }
        $draft = $entries->findDraft($other, 'en');
        self::assertSame([], $draft['fields']['body'][0]['settings'], 'nothing written');

        $this->classes()->unlock($band['id']);
        $entries->saveDraft(
            $other,
            'en',
            ['title' => 'T',
            'body' => [self::heading([$band['id']])]],
            1,
            1,
            'user00000001',
        );
        $draft = $entries->findDraft($other, 'en');
        self::assertSame([$band['id']], $draft['fields']['body'][0]['settings']['classes']);
    }

    public function testTheDraftEndpointAnswers409ForALockedClassAnd422ForAnArchivedOne(): void
    {
        $locked = $this->classes()->create(['name' => 'Locked', 'style' => []]);
        $archived = $this->classes()->create(['name' => 'Old', 'style' => []]);
        $this->classes()->lock($locked['id'], 'job000000001');
        $this->classes()->archive($archived['id']);
        [$uuid] = $this->entryWith([self::heading([])]);
        $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $save = fn (array $classes) => $this->container()->get(EntryController::class)->saveDraft(
            $this->container()->get(RequestDataHydrator::class)->hydrate(SaveDraftData::class, [
                'fields' => ['title' => 'T', 'body' => [self::heading($classes)]],
                'lock_version' => 1,
            ]),
            $request,
            $uuid,
            'en',
        );
        $refused = $save([$locked['id']]);
        self::assertSame(409, $refused->getStatusCode(), (string) $refused->getContent());
        $error = json_decode((string) $refused->getContent(), true)['error']['details'];
        self::assertSame('STYLE_CLASS_LOCKED', $error['code']);
        self::assertSame('job000000001', $error['job']);

        $stale = $save([$archived['id']]);
        self::assertSame(422, $stale->getStatusCode(), (string) $stale->getContent());
        $details = json_decode((string) $stale->getContent(), true)['error']['details'];
        self::assertArrayHasKey('settings.classes', $details);
    }

    public function testARegionSaveIntroducingAReferenceToALockedClassIsRefused(): void
    {
        $band = $this->classes()->create(['name' => 'Band', 'style' => []]);
        $regions = $this->container()->get(RegionRepository::class);
        $regions->save('footer', [self::heading([])], [], 'user00000001');
        $this->classes()->lock($band['id'], 'job000000001');
        $this->expectException(StyleClassLocked::class);
        $regions->save('footer', [self::heading([$band['id']])], [], 'user00000001');
    }

    public function testARestoredRevisionMayNameAnArchivedClassButNotALockedOne(): void
    {
        $old = $this->classes()->create(['name' => 'Old', 'style' => []]);
        [$uuid, $entries] = $this->entryWith([self::heading([$old['id']])]);
        $publish = $this->container()->get(PublishService::class);
        $first = $publish->publish($uuid, 'en', 'user00000001');
        // A newer publication without the reference, then the class is archived.
        $entries->saveDraft($uuid, 'en', ['title' => 'T', 'body' => [self::heading([])]], 1, 1, 'user00000001');
        $publish->publish($uuid, 'en', 'user00000001');
        $this->classes()->archive($old['id']);

        $pinned = $publish->rollback($uuid, 'en', $first, 'user00000001');
        self::assertNotEmpty($pinned, 'a retained revision naming an archived class restores');

        // Back to a publication without the reference, then the class is locked: restoring the
        // old revision would introduce the reference against the lock.
        $entries->saveDraft($uuid, 'en', ['title' => 'T3', 'body' => [self::heading([])]], 1, 2, 'user00000001');
        $publish->publish($uuid, 'en', 'user00000001');
        $this->classes()->lock($old['id'], 'job000000001');
        $this->expectException(StyleClassLocked::class);
        $publish->rollback($uuid, 'en', $first, 'user00000001');
    }

    public function testANewlyAuthoredReferenceToAnArchivedClassIsRefusedAtTheWrite(): void
    {
        $old = $this->classes()->create(['name' => 'Old', 'style' => []]);
        $this->classes()->archive($old['id']);
        [$uuid, $entries] = $this->entryWith([self::heading([])]);
        $this->expectException(StyleClassArchived::class);
        $entries->saveDraft(
            $uuid,
            'en',
            ['title' => 'T',
            'body' => [self::heading([$old['id']])]],
            1,
            1,
            'user00000001',
        );
    }
}
