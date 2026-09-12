<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Settings;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Validation\RequestDataHydrator;

/**
 * Settings → General must accept a published page of a publicly delivered type as the homepage
 * through the same path the admin uses: request body → DTO hydration → controller.
 */
final class HomepageSettingAcceptsPublishedPageTest extends AppTestCase
{
    public function testAPublishedPageIsAcceptedAsTheHomepage(): void
    {
        $entry = $this->seedPublishedPage('home');

        /** @var UpdateGeneralSettingsData $dto */
        $dto = (new RequestDataHydrator())->hydrate(UpdateGeneralSettingsData::class, ['homepage_entry' => $entry]);
        $response = $this->container()->get(GeneralSettingsController::class)->update($dto);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $saved = json_decode((string) $response->getContent(), true)['data']['settings']['homepage_entry'] ?? null;
        self::assertSame($entry, $saved);
    }

    private function seedPublishedPage(string $slug): string
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'rich_text',
            'label' => 'Rich text',
            'schema' => [['name' => 'body', 'type' => 'text']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'pages',
            'name' => 'Pages',
            'public_delivery' => true,
            'mount_at_root' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'Home', 'body' => [
            ['id' => 'blockone0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Welcome</p>']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection(), $this->appContext(), new BlockTypeRepository($this->connection())),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        return $entry;
    }
}
