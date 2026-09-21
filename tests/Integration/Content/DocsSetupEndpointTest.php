<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Http\Controllers\DocsSetupController;
use Thallo\Core\Content\Http\DTOs\SetupDocsData;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * "Set up documentation" as a button (Settings › Import / Export): what `thallo:docs:setup` does,
 * for a site whose owner has the admin and not a shell.
 */
final class DocsSetupEndpointTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function call(SetupDocsData $input): array
    {
        $response = $this->container()->get(DocsSetupController::class)->store($input);
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function testOneCallMakesTheDocsSectionAndSayingItAgainChangesNothing(): void
    {
        $first = $this->call(new SetupDocsData());
        self::assertSame(201, $first['status']);
        self::assertSame(
            ['type' => 'docs', 'created' => true, 'missing' => [], 'listed' => true, 'url' => '/docs'],
            $first['body']['data'],
        );
        $type = (new ContentTypeRepository($this->connection()))->findBySlug('docs');
        self::assertNotNull($type);
        self::assertContains('docs', $this->container()->get(GeneralSettings::class)->listingTypes());

        $again = $this->call(new SetupDocsData());
        self::assertSame(200, $again['status']);
        self::assertFalse($again['body']['data']['created']);
        self::assertFalse($again['body']['data']['listed']);
    }

    public function testItTakesItsOwnNameAndSections(): void
    {
        $result = $this->call(new SetupDocsData(type: 'handbook', sections: ['start', 'policies']));
        self::assertSame('/handbook', $result['body']['data']['url']);
        $type = (new ContentTypeRepository($this->connection()))->findBySlug('handbook');
        $fields = array_column((array) $type['schema'], null, 'name');
        self::assertSame(['start', 'policies'], $fields['section']['enum']);
    }

    public function testATypeThatCannotHoldDocsIsReportedNotRewritten(): void
    {
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'docs',
            'name' => 'Docs',
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $result = $this->call(new SetupDocsData());
        self::assertSame(200, $result['status']);
        self::assertSame(['body', 'section', 'order'], $result['body']['data']['missing']);
        self::assertFalse($result['body']['data']['listed']);
    }

    public function testANameThatCannotBeAUrlIsRefused(): void
    {
        self::assertSame(422, $this->call(new SetupDocsData(type: 'My Docs'))['status']);
        self::assertSame(422, $this->call(new SetupDocsData(sections: ['Not OK']))['status']);
    }
}
