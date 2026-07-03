<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

final class TemplatesAdminApiTest extends LemmaTestCase
{
    private function api(): TemplatesAdminController
    {
        return $this->container()->get(TemplatesAdminController::class);
    }

    /** @param array<string,mixed> $query */
    private function putReq(string $source, array $query = []): Request
    {
        // Query params go in the URI: Request::create() routes the $parameters arg
        // to the BODY for non-GET methods, so ['theme' => …] would silently vanish
        // from $request->query.
        $uri = '/x' . ($query === [] ? '' : '?' . http_build_query($query));
        $req = Request::create(
            $uri,
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['source' => $source]),
        );
        $req->attributes->set('user', ['uuid' => 'user00000001']);
        return $req;
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    public function testListMergesFilesystemAndDbWithOrigins(): void
    {
        $list = $this->json($this->api()->index(Request::create('/x', 'GET')))['data']['templates'];
        $byPath = array_column($list, null, 'path');
        self::assertSame('default', $byPath['entry.twig']['origin']); // pack default file
        self::assertFalse($byPath['entry.twig']['overridden']);

        $this->api()->save($this->putReq('DB:{{ entry.fields.title }}'), 'entry.twig');
        $this->api()->save($this->putReq('NEW'), 'entry/interview.twig'); // DB-only path

        $list = $this->json($this->api()->index(Request::create('/x', 'GET')))['data']['templates'];
        $byPath = array_column($list, null, 'path');
        self::assertSame('db', $byPath['entry.twig']['origin']);
        self::assertTrue($byPath['entry.twig']['overridden']);
        self::assertSame('db', $byPath['entry/interview.twig']['origin']); // created, not 404'd
    }

    public function testShowReadsDbThenFilesystemAnd404sWhenNeitherExists(): void
    {
        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'entry.twig'));
        self::assertSame('default', $show['data']['origin']); // fs source, read-only start
        self::assertNotSame('', $show['data']['source']);

        $this->api()->save($this->putReq('DBSRC'), 'entry.twig');
        $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'entry.twig'));
        self::assertSame('db', $show['data']['origin']);
        self::assertSame('DBSRC', $show['data']['source']);

        self::assertSame(
            404,
            $this->api()->show(Request::create('/x', 'GET'), 'entry/nope.twig')->getStatusCode(),
        );
    }

    public function testSaveValidatesPathThemeAndPolicy(): void
    {
        // Policy violation → 422 with line-numbered errors.
        $res = $this->api()->save($this->putReq("ok\n{{ x|raw }}"), 'entry.twig');
        self::assertSame(422, $res->getStatusCode());
        $errors = $this->json($res)['errors'];
        self::assertSame(2, $errors[0]['line']);

        // Path traversal / non-.twig / URL-significant characters / empty segments →
        // 422 (the segment grammar keeps raw slash-preserving admin URLs deterministic).
        foreach (
            [
                '../evil.twig',
                'notes.txt',
                'entry/foo?bar.twig',
                'entry/foo#bar.twig',
                'entry/foo bar.twig',
                'entry//foo.twig',
                '/entry.twig',
                'entry/./foo.twig',
            ] as $badPath
        ) {
            self::assertSame(
                422,
                $this->api()->save($this->putReq('x'), $badPath)->getStatusCode(),
                "expected 422 for path: {$badPath}",
            );
        }
        // Unknown theme → 404.
        self::assertSame(
            404,
            $this->api()->save($this->putReq('x', ['theme' => 'ghost']), 'entry.twig')->getStatusCode(),
        );
        // Missing/non-string source → 422.
        $bad = Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(422, $this->api()->save($bad, 'entry.twig')->getStatusCode());
    }

    public function testDeleteVersionsRestoreLifecycle(): void
    {
        self::assertSame(
            404,
            $this->api()->delete(Request::create('/x', 'DELETE'), 'entry.twig')->getStatusCode(),
        );

        $this->api()->save($this->putReq('one'), 'entry.twig');
        $this->api()->save($this->putReq('two'), 'entry.twig');
        self::assertSame(
            200,
            $this->api()->delete(Request::create('/x', 'DELETE'), 'entry.twig')->getStatusCode(),
        );

        // History survives the delete and restore REACTIVATES.
        $versions = $this->json(
            $this->api()->versions(Request::create('/x', 'GET'), 'entry.twig'),
        )['data']['versions'];
        self::assertCount(2, $versions);
        $old = $versions[1]['uuid'];
        $one = $this->json($this->api()->showVersion(Request::create('/x', 'GET'), 'entry.twig', $old));
        self::assertSame('one', $one['data']['source']);

        $res = $this->api()->restore($this->putReq(''), 'entry.twig', $old);
        self::assertSame(200, $res->getStatusCode());
        $repo = new TemplateRepository($this->connection());
        self::assertSame('one', $repo->findCurrentSource('default', 'entry.twig')['source']);
        self::assertCount(3, $repo->versions('default', 'entry.twig')); // restore = append

        self::assertSame(
            404,
            $this->api()->versions(Request::create('/x', 'GET'), 'never-saved.twig')->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api()->restore($this->putReq(''), 'entry.twig', 'nope00000000')->getStatusCode(),
        );
    }

    public function testRestoreRelintsAgainstTheCurrentPolicy(): void
    {
        $this->api()->save($this->putReq('one'), 'entry.twig');
        $this->api()->save($this->putReq('two'), 'entry.twig');
        $repo = new TemplateRepository($this->connection());
        $versions = $repo->versions('default', 'entry.twig');
        $old = $versions[1]['uuid'];

        // Mutate the OLD version around the API (stands in for a version predating a
        // policy tightening): restore must 422 and change nothing.
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $old)
            ->update(['source' => "{{ constant('X') }}"]);

        $res = $this->api()->restore($this->putReq(''), 'entry.twig', $old);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(1, $this->json($res)['errors'][0]['line']);
        self::assertSame('two', $repo->findCurrentSource('default', 'entry.twig')['source']); // unchanged
        self::assertCount(2, $repo->versions('default', 'entry.twig'));                        // no append
    }

    public function testRouteGrammarAndPermissions(): void
    {
        // Triple gate: every route carries the permission middleware.
        foreach (
            [
                ['GET', '/v1/admin/render/templates'],
                ['GET', '/v1/admin/render/templates/{path}'],
                ['PUT', '/v1/admin/render/templates/{path}'],
                ['DELETE', '/v1/admin/render/templates/{path}'],
                ['GET', '/v1/admin/render/templates/{path}/versions'],
                ['GET', '/v1/admin/render/templates/{path}/versions/{uuid}'],
                ['POST', '/v1/admin/render/templates/{path}/versions/{uuid}/restore'],
            ] as [$method, $path]
        ) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "missing route {$method} {$path}");
            self::assertContains(
                'lemma_permission:templates.manage',
                (array) ($route['middleware'] ?? []),
                "permission missing on {$method} {$path}",
            );
        }

        // THE characterization (spec §6): …/entry/blog.twig/versions matches the
        // HISTORY route, not the generic show with path="entry/blog.twig/versions".
        $router = $this->container()->get(Router::class);
        $match = $router->match(
            Request::create('/v1/admin/render/templates/entry/blog.twig/versions', 'GET'),
        );
        self::assertNotNull($match);
        self::assertSame('entry/blog.twig', (string) ($match['params']['path'] ?? ''));
    }
}
