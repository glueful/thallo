<?php

declare(strict_types=1);

namespace App\Tests\Integration\Routing;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\AssignRouteData;
use App\Content\Http\DTOs\CreateContentTypeData;
use App\Content\Http\DTOs\UpdateContentTypeData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Routing\RootMountGuard;
use App\Tests\Support\LemmaTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The global root URL namespace (root-mounted-types spec §3): every way a
 * root slug could collide, checked at the three write paths — route
 * assignment, flipping mount_at_root ON, and content-type creation.
 */
final class RootMountGuardTest extends LemmaTestCase
{
    private function guard(): RootMountGuard
    {
        return $this->container()->get(RootMountGuard::class);
    }

    /**
     * @param class-string<RequestData> $dtoClass
     * @param array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    /** @return array{type:string, entry:string} */
    private function seedRootType(string $typeSlug = 'pages', string $entrySlug = 'about'): array
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $type = $types->create([
            'slug' => $typeSlug,
            'name' => ucfirst($typeSlug),
            'public_delivery' => true,
            'mount_at_root' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'About'], 1, 0, 'user00000001');
        $this->container()->get(RouteRepository::class)->assign($entry, $type, 'en', $entrySlug);
        return ['type' => $type, 'entry' => $entry];
    }

    public function testCollisionMatrix(): void
    {
        $seeded = $this->seedRootType();

        // Another root-mounted type competing for the namespace.
        $types = $this->container()->get(ContentTypeRepository::class);
        $types->create([
            'slug' => 'docs', 'name' => 'Docs',
            'public_delivery' => true, 'mount_at_root' => true, 'schema' => [],
        ]);

        $guard = $this->guard();

        self::assertNotSame([], $guard->conflictsForSlug('en', 'docs'), 'type slug');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'v1'), 'reserved prefix');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'sitemap.xml'), 'reserved exact');
        self::assertNotSame([], $guard->conflictsForSlug('en', '_preview'), 'app prefix');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'page'), 'reserved segment');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'terms'), 'reserved segment');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'en'), 'active locale code');
        self::assertNotSame([], $guard->conflictsForSlug('en', 'about'), 'other root page');

        // The SAME slug in a different locale is fine (the namespace is per locale).
        self::assertSame([], $guard->conflictsForSlug('fr', 'about'));
        // A fresh slug is fine.
        self::assertSame([], $guard->conflictsForSlug('en', 'contact'));
        // Self-reclaim: the owning entry may keep/re-claim its own slug.
        self::assertSame([], $guard->conflictsForSlug('en', 'about', $seeded['entry']));
    }

    public function testRedirectSourcesJoinTheNamespaceWithSelfReclaim(): void
    {
        $seeded = $this->seedRootType();
        $routes = $this->container()->get(RouteRepository::class);

        // Rename about -> team: an auto-redirect claims 'about' as a source.
        $routes->assign($seeded['entry'], $seeded['type'], 'en', 'team');

        $guard = $this->guard();
        // A DIFFERENT entry cannot take the redirect source...
        self::assertNotSame([], $guard->conflictsForSlug('en', 'about'));
        // ...but the redirect's own target entry can rename back (self-reclaim).
        self::assertSame([], $guard->conflictsForSlug('en', 'about', $seeded['entry']));
    }

    public function testAssignRouteReturns409WithConflicts(): void
    {
        $this->seedRootType();

        // A second entry in the same root type tries to take the taken slug.
        $entries = $this->container()->get(EntryRepository::class);
        $types = $this->container()->get(ContentTypeRepository::class);
        $typeUuid = (string) $types->findBySlug('pages')['uuid'];
        $other = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($other, 'en', ['title' => 'Other'], 1, 0, 'user00000001');

        $resp = $this->container()->get(EntryController::class)->assignRoute(
            $this->hydrate(AssignRouteData::class, ['slug' => 'v1']),
            new Request(),
            $other,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('ROOT_SLUG_TAKEN', (string) $resp->getContent());
        self::assertStringContainsString('reserved', (string) $resp->getContent());
    }

    public function testFlipOnValidatesEveryRouteAndRedirectSource(): void
    {
        // A PREFIXED type whose existing route collides with a reserved path.
        $types = $this->container()->get(ContentTypeRepository::class);
        $type = $types->create([
            'slug' => 'guides', 'name' => 'Guides',
            'public_delivery' => true, 'mount_at_root' => false,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
        $this->container()->get(RouteRepository::class)->assign($entry, $type, 'en', 'v1');

        $resp = $this->container()->get(ContentTypeController::class)->update(
            $this->hydrate(UpdateContentTypeData::class, ['mount_at_root' => true]),
            new Request(),
            'guides',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('v1', (string) $resp->getContent());
        // The flag never flips partially.
        self::assertFalse((bool) $types->findBySlug('guides')['mount_at_root']);

        // Fix the collision -> the flip succeeds.
        $this->container()->get(RouteRepository::class)->assign($entry, $type, 'en', 'getting-started');
        // (the rename leaves a 'v1' redirect source — reserved conflicts apply to it too)
        $resp = $this->container()->get(ContentTypeController::class)->update(
            $this->hydrate(UpdateContentTypeData::class, ['mount_at_root' => true]),
            new Request(),
            'guides',
        );
        self::assertSame(409, $resp->getStatusCode(), 'redirect source v1 still blocks the flip');
    }

    public function testTypeCreationCannotShadowARootPage(): void
    {
        $this->seedRootType(entrySlug: 'about');

        $resp = $this->container()->get(ContentTypeController::class)->store(
            $this->hydrate(CreateContentTypeData::class, ['slug' => 'about', 'name' => 'About', 'schema' => []]),
            new Request(),
        );
        self::assertSame(422, $resp->getStatusCode());
        self::assertStringContainsString('shadow', (string) $resp->getContent());
    }
}
