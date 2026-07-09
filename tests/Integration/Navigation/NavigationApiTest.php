<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Navigation\Http\Controllers\MenuController;
use Thallo\Navigation\Http\Controllers\NavigationAdminController;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class NavigationApiTest extends AppTestCase
{
    use SeedsPublishedContent;

    private function admin(): NavigationAdminController
    {
        return $this->container()->get(NavigationAdminController::class);
    }

    /** @param array<string,mixed> $body */
    private function req(array $body = [], array $query = []): Request
    {
        return Request::create(
            '/x',
            'POST',
            $query,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /** @return array<string,mixed> */
    private function data(\Glueful\Http\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }

    public function testCreateListRenameDelete(): void
    {
        $res = $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));
        self::assertSame(201, $res->getStatusCode());
        self::assertSame(0, $this->data($res)['lock_version']);

        // duplicate slug
        self::assertSame(409, $this->admin()->create($this->req(['slug' => 'main', 'name' => 'X']))->getStatusCode());

        // bad slug
        try {
            $this->admin()->create($this->req(['slug' => 'Main Menu!', 'name' => 'X']));
            self::fail('expected ValidationException');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $list = $this->data($this->admin()->index(Request::create('/x', 'GET')));
        self::assertSame('main', $list['menus'][0]['slug']);

        self::assertSame(200, $this->admin()->rename($this->req(['name' => 'Primary']), 'main')->getStatusCode());
        self::assertSame(200, $this->admin()->delete(Request::create('/x', 'DELETE'), 'main')->getStatusCode());
        self::assertSame(404, $this->admin()->show(Request::create('/x', 'GET'), 'main')->getStatusCode());
    }

    public function testTreePutRoundTripAndLockVersion(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));

        $tree = [
            'lock_version' => 0,
            'items' => [
                ['kind' => 'url', 'url' => '/about', 'icon' => 'external-link',
                    'labels' => ['en' => 'About'], 'children' => []],
                ['kind' => 'entry', 'entry_uuid' => $entry, 'labels' => ['en' => 'Hello'], 'children' => [
                    ['kind' => 'url', 'url' => 'https://example.test', 'labels' => ['en' => 'Ext'], 'children' => []],
                ]],
            ],
        ];
        $res = $this->admin()->replaceItems($this->req($tree), 'main');
        self::assertSame(200, $res->getStatusCode());

        $show = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        self::assertSame(1, $show['lock_version']);
        self::assertSame('en', $show['locale']);
        self::assertCount(2, $show['items']);
        // Per-item icon round-trips (nav-v2 spec §5); absent stays null.
        self::assertSame('external-link', $show['items'][0]['icon']);
        self::assertNull($show['items'][1]['icon']);
        self::assertSame('published', $show['items'][1]['target_status']);
        // Exact canonical: the default locale collapses (no /en/ prefix).
        self::assertSame('https://site.test/blog/hello', (string) $show['items'][1]['target_url']);
        // The inherited label (nav-entry-items design): the SPA shows this as
        // the label placeholder — it never guesses titles itself.
        self::assertSame('Hello', $show['items'][1]['target_title']);
        self::assertCount(1, $show['items'][1]['children']);

        // Stale lock_version → 409
        $res = $this->admin()->replaceItems($this->req($tree), 'main');
        self::assertSame(409, $res->getStatusCode());
    }

    public function testLocaleSensitiveBadges(): void
    {
        // Seed a bilingual entry, then unpublish fr: the SAME item must badge published
        // for ?locale=en and unpublished for ?locale=fr.
        $entry = $this->seedBilingualPublishedEntry();
        $this->container()->get(\App\Content\Services\PublishService::class)->unpublish($entry, 'fr');

        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));
        $this->admin()->replaceItems($this->req([
            'lock_version' => 0,
            'items' => [
                ['kind' => 'entry', 'entry_uuid' => $entry, 'labels' => ['en' => 'Hello'], 'children' => []],
            ],
        ]), 'main');

        $en = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        $fr = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'fr']), 'main'));
        self::assertSame('published', $en['items'][0]['target_status']);
        self::assertSame('unpublished', $fr['items'][0]['target_status']);
        self::assertSame('fr', $fr['locale']);
    }

    public function testTreeValidationMatrix(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));

        $bad = [
            'unknown kind' => [['kind' => 'blob', 'labels' => [], 'children' => []]],
            'javascript url' => [['kind' => 'url', 'url' => 'javascript:alert(1)', 'labels' => [], 'children' => []]],
            'missing entry' => [['kind' => 'entry', 'entry_uuid' => 'nope00000000', 'labels' => [], 'children' => []]],
            // Icon grammar (nav-v2 spec §5): lucide-only, lowercase-hyphenated.
            'malformed icon' => [['kind' => 'url', 'url' => '/x', 'icon' => 'Bad Name',
                'labels' => [], 'children' => []]],
            'brand icon' => [['kind' => 'url', 'url' => '/x', 'icon' => 'brand:github',
                'labels' => [], 'children' => []]],
        ];
        // depth > 6
        $deep = ['kind' => 'url', 'url' => '/x', 'labels' => [], 'children' => []];
        for ($i = 0; $i < 7; $i++) {
            $deep = ['kind' => 'url', 'url' => '/x', 'labels' => [], 'children' => [$deep]];
        }
        $bad['too deep'] = [$deep];
        // > 500 items
        $many = [];
        for ($i = 0; $i < 501; $i++) {
            $many[] = ['kind' => 'url', 'url' => '/x', 'labels' => [], 'children' => []];
        }
        $bad['too many'] = $many;
        // deleted entry
        $deleted = $this->seedPublishedEntryInType('gone-type', true, 'en', 'gone', 'Gone');
        $this->container()->get(EntryRepository::class)->softDelete($deleted);
        $bad['deleted entry'] = [['kind' => 'entry', 'entry_uuid' => $deleted, 'labels' => [], 'children' => []]];

        foreach ($bad as $label => $items) {
            try {
                $this->admin()->replaceItems($this->req(['lock_version' => 0, 'items' => $items]), 'main');
                self::fail("{$label}: expected ValidationException");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Unpublished target is ACCEPTED (editors build menus while content is in draft).
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $entries = $this->container()->get(EntryRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');
        $res = $this->admin()->replaceItems($this->req([
            'lock_version' => 0,
            'items' => [['kind' => 'entry', 'entry_uuid' => $draft, 'labels' => ['en' => 'D'], 'children' => []]],
        ]), 'main');
        self::assertSame(200, $res->getStatusCode());
    }

    public function testPublicEndpoint(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));
        $this->admin()->replaceItems($this->req([
            'lock_version' => 0,
            'items' => [
                ['kind' => 'entry', 'entry_uuid' => $entry, 'labels' => ['en' => 'Hello'], 'children' => []],
            ],
        ]), 'main');

        $public = $this->container()->get(MenuController::class);
        $res = $public->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main');
        $data = $this->data($res);
        self::assertSame('main', $data['slug']);
        self::assertCount(1, $data['items']);

        self::assertSame(404, $public->show(Request::create('/x', 'GET'), 'nope')->getStatusCode());
    }

    public function testRoutesAreRegisteredWithPermissions(): void
    {
        $route = $this->findRoute('PUT', '/v1/admin/navigation/menus/{slug}/items');
        self::assertNotNull($route);
        self::assertContains('content_permission:navigation.manage', (array) ($route['middleware'] ?? []));

        $route = $this->findRoute('GET', '/v1/menus/{slug}');
        self::assertNotNull($route);
        self::assertContains('rate_limit', (array) ($route['middleware'] ?? []));
    }

    public function testCreateAppendsPositionAndListOrdersByInsertion(): void
    {
        // Created out of alphabetical order — the list must follow position (insertion),
        // not slug, so the alpha tiebreak never masks a broken position.
        $this->admin()->create($this->req(['slug' => 'zeta', 'name' => 'Zeta']));
        $this->admin()->create($this->req(['slug' => 'alpha', 'name' => 'Alpha']));
        $this->admin()->create($this->req(['slug' => 'mid', 'name' => 'Mid']));

        $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
        self::assertSame(['zeta', 'alpha', 'mid'], array_column($list, 'slug'));
    }

    public function testReorderRewritesDensePositions(): void
    {
        foreach (['a', 'b', 'c'] as $s) {
            $this->admin()->create($this->req(['slug' => $s, 'name' => strtoupper($s)]));
        }
        $res = $this->admin()->reorder($this->req(['slugs' => ['c', 'a', 'b']]));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['c', 'a', 'b'], array_column($this->data($res)['menus'], 'slug'));

        // Persisted, not just echoed.
        $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
        self::assertSame(['c', 'a', 'b'], array_column($list, 'slug'));
    }

    public function testReorderRejectsBadPayloadsWithNoPartialWrite(): void
    {
        foreach (['a', 'b', 'c'] as $s) {
            $this->admin()->create($this->req(['slug' => $s, 'name' => strtoupper($s)]));
        }
        // Establish a known order first.
        $this->admin()->reorder($this->req(['slugs' => ['c', 'a', 'b']]));

        $bad = [
            'duplicate' => ['c', 'a', 'a'],
            'missing'   => ['c', 'a'],           // b omitted
            'unknown'   => ['c', 'a', 'b', 'z'], // z is not a menu
        ];
        foreach ($bad as $label => $slugs) {
            $res = $this->admin()->reorder($this->req(['slugs' => $slugs]));
            self::assertSame(422, $res->getStatusCode(), "{$label} must 422");
            // No partial write: the order is still c, a, b.
            $list = $this->data($this->admin()->index(Request::create('/x', 'GET')))['menus'];
            self::assertSame(['c', 'a', 'b'], array_column($list, 'slug'), "{$label} must not write");
        }
    }

    public function testReorderRouteResolvesNotRenameViaSlug(): void
    {
        $route = $this->findRoute('POST', '/v1/admin/navigation/menus/reorder');
        self::assertNotNull($route);
        self::assertContains('content_permission:navigation.manage', (array) ($route['middleware'] ?? []));
    }

    public function testTreeReNestingRoundTripsThroughTheSavePath(): void
    {
        // The P1 nesting guarantee: whatever a tree drag produces, the menu-save path
        // (PUT /menus/{slug}/items) preserves it. Move a child from parent A to parent B
        // and assert the reload reflects the new nesting.
        $this->admin()->create($this->req(['slug' => 'main', 'name' => 'Main']));
        $childUnderA = [
            'lock_version' => 0,
            'items' => [
                ['kind' => 'url', 'url' => '/a', 'labels' => ['en' => 'A'], 'children' => [
                    ['kind' => 'url', 'url' => '/x', 'labels' => ['en' => 'X'], 'children' => []],
                ]],
                ['kind' => 'url', 'url' => '/b', 'labels' => ['en' => 'B'], 'children' => []],
            ],
        ];
        $this->admin()->replaceItems($this->req($childUnderA), 'main');
        $show = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        self::assertSame('X', $show['items'][0]['children'][0]['labels']['en']); // X under A
        self::assertCount(0, $show['items'][1]['children']);                     // B empty

        $lock = $show['lock_version'];
        $childUnderB = [
            'lock_version' => $lock,
            'items' => [
                ['kind' => 'url', 'url' => '/a', 'labels' => ['en' => 'A'], 'children' => []],
                ['kind' => 'url', 'url' => '/b', 'labels' => ['en' => 'B'], 'children' => [
                    ['kind' => 'url', 'url' => '/x', 'labels' => ['en' => 'X'], 'children' => []],
                ]],
            ],
        ];
        $this->admin()->replaceItems($this->req($childUnderB), 'main');
        $show = $this->data($this->admin()->show(Request::create('/x', 'GET', ['locale' => 'en']), 'main'));
        self::assertCount(0, $show['items'][0]['children']);                     // A empty
        self::assertSame('X', $show['items'][1]['children'][0]['labels']['en']); // X now under B
    }
}
