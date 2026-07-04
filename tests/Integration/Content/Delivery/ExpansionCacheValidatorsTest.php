<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryShowQuery;
use App\Content\Http\DeliveryEtag;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Http\Response;
use Glueful\Support\FieldSelection\Projector;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spec §4: expansion targets must reach BOTH cache channels — Cache-Tag (purge)
 * and the delivery ETag (revalidation) — for top-level AND block references; and
 * unresolved targets must reach NEITHER (surrogate-header privacy).
 */
final class ExpansionCacheValidatorsTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'related',
            'label' => 'Related',
            'schema' => [['name' => 'post', 'type' => 'reference']],
        ]);
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

    private function controller(): DeliveryController
    {
        $repo = new DeliveryRepository($this->connection());
        $types = new ContentTypeRepository($this->connection());
        $routes = new RouteRepository(
            $this->connection(),
            new RedirectRepository($this->connection()),
        );
        $paths = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');

        return new DeliveryController(
            $this->appContext(),
            $repo,
            $types,
            $this->container()->get(FilterCompiler::class),
            new SortCompiler(),
            new ReferenceResolver($repo, new BlockTypeRepository($this->connection())),
            new Projector(),
            new DeliveryEtag(),
            new FakeLocaleManager(),
            new RouteResolver(
                $repo,
                new RedirectRepository($this->connection()),
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
            ),
            new CanonicalProjector(
                $repo,
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
                'en',
            ),
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

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            // FULL validator — a bare `new FieldValidator()` rejects blocks fields
            // with "block types are unavailable" (Task 4 fixture rule).
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /** @param array<string,mixed> $fields */
    private function publishPage(array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        $this->publishService()->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /** @param array<string,mixed> $fields */
    private function draftOnlyPage(array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    /** @param array<string,mixed> $fields */
    private function republish(string $entryUuid, array $fields): void
    {
        $this->entries()->saveDraft($entryUuid, 'en', $fields, 1, 1, 'user00000001');
        $this->publishService()->publish($entryUuid, 'en', 'user00000001');
    }

    private function deliverShow(string $type, string $slug, ?string $ifNoneMatch = null): Response
    {
        $dto = (new RequestDataHydrator())->hydrate(DeliveryShowQuery::class, [], [], []);
        $req = Request::create('/');
        if ($ifNoneMatch !== null) {
            $req->headers->set('If-None-Match', $ifNoneMatch);
        }
        return $this->controller()->show($req, $dto, $type, $slug);
    }

    private function deliverList(string $type): Response
    {
        $dto = (new RequestDataHydrator())->hydrate(DeliveryListQuery::class, [], [], []);
        return $this->controller()->index(Request::create('/'), $dto, $type);
    }

    public function testShowCarriesTargetTagsAndTargetSensitiveEtag(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target');
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source');

        $first = $this->deliverShow('page', 'source');
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $first->headers->get('Cache-Tag'),
        );
        $etagBefore = (string) $first->headers->get('ETag');

        // Body: expanded in place; NO collector residue anywhere in the JSON.
        $body = (string) $first->getContent();
        self::assertStringContainsString('"title":"T"', $body);
        self::assertStringNotContainsString('expanded_entry_uuids', $body);
        self::assertStringNotContainsString('versionIdentities', $body);

        // Republish the TARGET (source's own version unchanged) → source's ETag
        // MUST change (spec §4 P1: tags purge caches; validators stop false 304s).
        $this->republish($target, ['title' => 'T2']);
        $second = $this->deliverShow('page', 'source');
        self::assertNotSame($etagBefore, (string) $second->headers->get('ETag'));

        // And a conditional request with the OLD etag must NOT 304.
        $conditional = $this->deliverShow('page', 'source', ifNoneMatch: $etagBefore);
        self::assertSame(200, $conditional->getStatusCode());
    }

    public function testListEtagFoldsExpandedIdentities(): void
    {
        $target = $this->publishPage(['title' => 'T', 'body' => []], 'target2');
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 'source2');

        $first = $this->deliverList('page');
        $etagBefore = (string) $first->headers->get('ETag');
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $first->headers->get('Cache-Tag'),
        );

        $this->republish($target, ['title' => 'T2']);
        self::assertNotSame(
            $etagBefore,
            (string) $this->deliverList('page')->headers->get('ETag'),
        );
    }

    public function testUnresolvedTargetLeavesNoTraceInSurrogateHeaders(): void
    {
        $draft = $this->draftOnlyPage(['title' => 'Hidden']);
        $this->publishPage(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $draft]],
        ]], 'source3');

        $resp = $this->deliverShow('page', 'source3');
        self::assertStringNotContainsString($draft, (string) $resp->headers->get('Cache-Tag'));
        self::assertStringNotContainsString($draft, (string) $resp->headers->get('ETag'));
        // The unresolved ref splices to null in the body.
        $body = json_decode((string) $resp->getContent(), true);
        self::assertNull($body['data']['fields']['body'][0]['data']['post']);
    }
}
