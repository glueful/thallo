<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * "Add documentation to my site" is one step. It makes the content type a docs section needs —
 * a title, a Markdown body kept as plain text, a section and an order for the sidebar, a summary,
 * and where the page came from — and lets the site list it, so `/docs` is an index and not a 404.
 * Run twice it changes nothing; run against a type someone made by hand it says what is missing
 * and touches nothing.
 */
final class DocsSetupTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    private function docs(): DocsSetup
    {
        return $this->container()->get(DocsSetup::class);
    }

    public function testItMakesTheDocsTypeAndLetsTheSiteListIt(): void
    {
        $result = $this->docs()->run('docs', ['getting-started', 'guides', 'reference']);
        self::assertTrue($result['created']);
        self::assertSame([], $result['missing']);

        $type = (new ContentTypeRepository($this->connection()))->findBySlug('docs');
        self::assertNotNull($type);
        self::assertTrue((bool) $type['public_delivery']);
        $fields = array_column((array) $type['schema'], null, 'name');
        self::assertSame(
            ['title', 'summary', 'section', 'order', 'body', 'source_path', 'edit_url'],
            array_keys($fields),
        );
        self::assertTrue((bool) $fields['title']['required']);
        self::assertSame(['getting-started', 'guides', 'reference'], $fields['section']['enum']);
        // Markdown is kept as written: a plain text body, which the theme renders with markdown().
        self::assertSame('text', $fields['body']['type']);
        self::assertSame('plain', $fields['body']['format']);

        self::assertContains('docs', $this->container()->get(GeneralSettings::class)->listingTypes());
    }

    public function testRunAgainItChangesNothingAndKeepsWhatTheSiteAlreadyLists(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post']]);
        $this->docs()->run('docs', ['guides']);
        $again = $this->docs()->run('docs', ['other', 'sections']);
        self::assertFalse($again['created']);
        self::assertSame([], $again['missing']);

        $type = (new ContentTypeRepository($this->connection()))->findBySlug('docs');
        $fields = array_column((array) $type['schema'], null, 'name');
        self::assertSame(['guides'], $fields['section']['enum'], 'an existing type is not rewritten');
        self::assertSame(['post', 'docs'], $this->container()->get(GeneralSettings::class)->listingTypes());
    }

    public function testATypeMadeByHandIsReportedNotRewritten(): void
    {
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'manual',
            'name' => 'Manual',
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $result = $this->docs()->run('manual', ['guides']);
        self::assertFalse($result['created']);
        self::assertSame(['body', 'section', 'order'], $result['missing']);
        $type = (new ContentTypeRepository($this->connection()))->findBySlug('manual');
        self::assertCount(1, (array) $type['schema']);
    }

    public function testTheNameHasToBeOneTheUrlGrammarCanCarry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->docs()->run('My Docs', ['guides']);
    }
}
