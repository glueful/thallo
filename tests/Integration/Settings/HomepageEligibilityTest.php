<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Delivery\HomepageEligibility;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\DTOs\UpdateGeneralSettingsData;
use App\Tests\Support\AppTestCase;
use Glueful\Validation\RequestDataHydrator;

/**
 * "must be a published entry of a publicly delivered content type" collapsed four conditions into
 * one sentence, and a page that shows "published" in the list can still fail the one it never
 * mentions (no route saved). The refusal now names the failing condition.
 */
final class HomepageEligibilityTest extends AppTestCase
{
    private ContentTypeRepository $types;
    private EntryRepository $entries;

    protected function setUp(): void
    {
        parent::setUp();
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'rich_text', 'label' => 'Rich text', 'schema' => [['name' => 'body', 'type' => 'text']],
        ]);
        $this->types = new ContentTypeRepository($this->connection());
        $this->entries = new EntryRepository($this->connection(), $this->appContext(), $this->types);
    }

    private function eligibility(): HomepageEligibility
    {
        return $this->container()->get(HomepageEligibility::class);
    }

    /** @return array{type: array<string,mixed>, entry: string} */
    private function seedEntry(bool $publicDelivery = true, string $slug = 'pages'): array
    {
        $type = $this->types->create([
            'slug' => $slug, 'name' => ucfirst($slug), 'public_delivery' => $publicDelivery, 'mount_at_root' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entry = $this->entries->createEntry($type, 'en', 1, 'user00000001');
        $this->entries->saveDraft($entry, 'en', ['title' => 'T', 'body' => []], 1, 0, 'user00000001');
        return ['type' => $type, 'entry' => $entry];
    }

    private function publish(string $entry): void
    {
        (new PublishService(
            $this->appContext(),
            $this->entries,
            new VersionRepository($this->connection()),
            $this->types,
            new FieldValidator($this->connection(), $this->appContext(), new BlockTypeRepository($this->connection())),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
    }

    public function testUnknownEntry(): void
    {
        self::assertSame('no entry with id "nope00000001"', $this->eligibility()->reasonNotEligible('nope00000001'));
    }

    public function testTypeNotPubliclyDelivered(): void
    {
        ['entry' => $entry] = $this->seedEntry(publicDelivery: false, slug: 'category');
        self::assertSame(
            'content type "category" is not publicly delivered',
            $this->eligibility()->reasonNotEligible($entry),
        );
    }

    public function testDraftIsNotPublished(): void
    {
        ['type' => $type, 'entry' => $entry] = $this->seedEntry();
        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', 'draft');
        self::assertSame('not published in locale "en"', $this->eligibility()->reasonNotEligible($entry));
    }

    public function testPublishedWithoutARouteNamesTheMissingSlug(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $this->publish($entry);
        self::assertSame(
            'published in locale "en" but has no route yet — save a slug in the Publishing panel',
            $this->eligibility()->reasonNotEligible($entry),
        );
    }

    public function testPublishedWithARouteIsEligible(): void
    {
        ['type' => $type, 'entry' => $entry] = $this->seedEntry();
        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', 'home');
        $this->publish($entry);
        self::assertNull($this->eligibility()->reasonNotEligible($entry));
    }

    public function testTheSettingsResponseCarriesTheReason(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $this->publish($entry); // published, routeless — the case the list cannot show

        $dto = (new RequestDataHydrator())->hydrate(UpdateGeneralSettingsData::class, ['homepage_entry' => $entry]);
        $response = $this->container()->get(GeneralSettingsController::class)->update($dto);

        self::assertSame(422, $response->getStatusCode());
        $message = json_decode((string) $response->getContent(), true)['error']['details']['homepage_entry'] ?? '';
        self::assertStringContainsString('has no route yet', $message);
        self::assertStringContainsString('save a slug', $message);
    }
}
