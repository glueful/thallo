<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Index\ResilientContentReindexer;
use Symfony\Component\HttpFoundation\Request;

/**
 * thallo-search is an always-loaded module whose behaviour is OPT-IN via the `thallo.search`
 * capability (disabled in config/thallo.php by default — a lean install resolves the no-op
 * reindexer and does not register /v1/search; see CapabilityGatingTest +
 * SearchEndpointTest::testRouteAbsentByDefaultBecauseCapabilityIsOff). This proves the other
 * direction: once the capability is switched on, the boot gate registers /v1/search and the
 * reindexer seam resolves to the real (resilient) implementation. Dedicated enabled boot via a
 * temp config/testing/thallo.php override (array_replace_recursive keeps every other thallo
 * key); the committed config/testing/extensions.php tenancy-off shield applies automatically.
 */
final class SearchEnablementTest extends AppTestCase
{
    private static ?ApplicationContext $enabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$enabledApp !== null) {
            return;
        }

        self::$enabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.search' => true],
        ]);
    }

    public function testRouteRegisteredWhenEnabled(): void
    {
        $status = (new Application(self::$enabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertNotSame(404, $status, 'enabling the thallo.search capability must register /v1/search');
    }

    public function testWithNoMeilisearchConfiguredTheSitesOwnDatabaseAnswers(): void
    {
        // The whole path, with nothing installed: the engine resolves to PostgreSQL, a publish
        // reindexes the page through the same seam Meilisearch uses, and the public endpoint finds
        // it — 200, not the 503 this boot answered while Meilisearch was the only engine.
        $c = self::$enabledApp->getContainer();
        self::assertInstanceOf(
            \Thallo\Search\Engine\PostgresFtsBackend::class,
            $c->get(\Thallo\Search\Engine\SearchBackend::class),
        );

        $db = $c->get(\Glueful\Database\Connection::class);
        $types = new \Thallo\Core\Content\Repositories\ContentTypeRepository($db);
        $entries = new \Thallo\Core\Content\Repositories\EntryRepository($db, self::$enabledApp, $types);
        $type = $types->create([
            'slug' => 'searchdocs',
            'name' => 'Docs',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => 'plain'],
            ],
        ]);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Provisioning',
            'body' => "## Run it\n\nRun `php glueful thallo:provision` after every deploy.\n",
        ], 1, 0, 'user00000001');
        (new \Thallo\Core\Content\Repositories\RouteRepository($db))->assign($entry, $type, 'en', 'provisioning');
        (new \Thallo\Core\Content\Services\PublishService(
            self::$enabledApp,
            $entries,
            new \Thallo\Core\Content\Repositories\VersionRepository($db),
            $types,
            new \Thallo\Core\Content\Validation\FieldValidator($db),
            new \Thallo\Core\Content\Repositories\ReferenceProjectionRepository($db),
        ))->publish($entry, 'en', 'user00000001');
        $c->get(ContentReindexer::class)->reindexEntry($entry, 'en');

        $res = (new Application(self::$enabledApp))->handle(Request::create(
            '/v1/search?q=deplo&locale=en&type=searchdocs',
            'GET',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $data = ((array) json_decode((string) $res->getContent(), true))['data'];
        self::assertSame(1, $data['total']);
        $hit = $data['hits'][0];
        self::assertSame($entry, $hit['uuid']);
        self::assertSame('Provisioning', $hit['title']);
        self::assertStringEndsWith('/searchdocs/provisioning', $hit['href']);
        self::assertStringContainsString('<mark>deploy</mark>', $hit['snippet']);
        self::assertStringNotContainsString('`', $hit['snippet'], 'Markdown syntax is not part of a snippet');
    }

    public function testAThemeIsToldSearchIsOnSoItCanOfferASearchBox(): void
    {
        $on = self::$enabledApp->getContainer()->get(\Thallo\Render\RenderContextExtension::class);
        self::assertTrue($on->searchEnabled());
        // And off in the ordinary boot, where the capability is off: no dead search box.
        self::assertFalse($this->container()->get(\Thallo\Render\RenderContextExtension::class)->searchEnabled());
        self::assertContains('search_enabled', \Thallo\Render\Templates\TemplatePolicy::FUNCTIONS);
    }

    public function testReindexerBoundToResilientWhenEnabled(): void
    {
        $reindexer = self::$enabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(ResilientContentReindexer::class, $reindexer);
    }
}
