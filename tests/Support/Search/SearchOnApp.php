<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;

/**
 * A boot with search on, publishing real entries and building the index the way the schedule does,
 * then answering real requests — the search surfaces end to end.
 */
final class SearchOnApp
{
    /** @var array<string, string> type slug => uuid */
    private array $types = [];

    public function __construct(public readonly ApplicationContext $app)
    {
    }

    public function type(string $slug, bool $public = true): string
    {
        if (!isset($this->types[$slug])) {
            $this->types[$slug] = $this->contentTypes()->create([
                'slug' => $slug, 'name' => ucfirst($slug), 'public_delivery' => $public,
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'body', 'type' => 'text', 'format' => 'plain'],
                ],
            ]);
        }
        return $this->types[$slug];
    }

    public function publish(
        string $typeSlug,
        string $slug,
        string $title,
        string $body = '',
        bool $public = true,
    ): string {
        $type = $this->type($typeSlug, $public);
        $db = $this->db();
        $entries = new EntryRepository($db, $this->app, $this->contentTypes());
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => $title, 'body' => $body], 1, 0, 'user00000001');
        (new RouteRepository($db))->assign($entry, $type, 'en', $slug);
        (new PublishService(
            $this->app,
            $entries,
            new VersionRepository($db),
            $this->contentTypes(),
            new FieldValidator($db),
            new ReferenceProjectionRepository($db),
        ))->publish($entry, 'en', 'user00000001');
        return $entry;
    }

    /** Build every available kind and cut over, as the scheduled reconcile does. */
    public function reconcile(): void
    {
        $this->app->getContainer()->get(\Thallo\Search\Lifecycle\Reconciler::class)->runAll(false);
    }

    /** @param array<string, mixed> $attributes request attributes (e.g. api_key_scopes) */
    public function get(string $uri, array $attributes = []): Response
    {
        $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }
        return (new \Glueful\Application($this->app))->handle($request);
    }

    /** A browser's request for a page (no JSON Accept header). */
    public function page(string $uri, string $ip = '127.0.0.1'): Response
    {
        $request = Request::create($uri, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        return (new \Glueful\Application($this->app))->handle($request);
    }

    /** @return array<string, mixed> */
    public static function data(Response $response): array
    {
        return (array) (json_decode((string) $response->getContent(), true)['data'] ?? []);
    }

    private function contentTypes(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->db());
    }

    private function db(): \Glueful\Database\Connection
    {
        return $this->app->getContainer()->get(\Glueful\Database\Connection::class);
    }
}
