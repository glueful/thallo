<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Glueful\Application;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * One listing layout per type per workspace, under real tenancy enforcement (type layouts plan B,
 * B5): a retrofitted schema, the tenancy provider bound, two provisioned workspaces. Each lists its
 * own `post` type, saves its own listing layout at version 1 — the two never conflict — and its
 * listing renders its own.
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class ListingLayoutTenancyTest extends RetrofittedTenantTestCase
{
    private function tenant(string $name): string
    {
        return $name === 'a' ? self::$tenantAUuid : self::$tenantBUuid;
    }

    /** The post listing, served through the kernel in the workspace's context. */
    private function page(string $name): string
    {
        return (string) $this->runAsTenant(
            $this->tenant($name),
            fn () => (new Application($this->appContext()))->handle(Request::create('/post', 'GET'))->getContent(),
        );
    }

    /** The workspace's post type, listed; block types seeded as provisioning does. */
    private function listPosts(string $name): void
    {
        $this->runAsTenant($this->tenant($name), function (): void {
            // The harness truncates block_types between tests.
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            $types = $this->container()->get(ContentTypeRepository::class);
            if ($types->findBySlug('post') === null) {
                $types->create(['slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                ]]);
            }
            $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post']]);
        });
    }

    /** Saves the workspace's listing layout from version 0; returns the version it saved at. */
    private function save(string $name, string $marker): int
    {
        return (int) $this->runAsTenant($this->tenant($name), function () use ($marker): int {
            $session = $this->container()->get(LayoutPreviewController::class)->session(
                (new RequestDataHydrator())->hydrate(
                    LayoutSessionData::class,
                    ['surface' => 'listing', 'target' => 'post'],
                ),
            );
            self::assertSame(200, $session->getStatusCode(), (string) $session->getContent());
            $saved = $this->container()->get(LayoutAdminController::class)->save(
                (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                    'token' => json_decode((string) $session->getContent(), true)['data']['token'],
                    'layout' => ['blocks' => [
                        ['id' => 'tenantmark01', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
                        ['id' => 'tenantloop01', 'type' => 'entry_loop', 'data' => ['card' => [
                            ['id' => 'tenanttitle1', 'type' => 'entry_title', 'data' => [], 'settings' => []],
                        ]], 'settings' => []],
                    ], 'settings' => []],
                    'expected_lock_version' => 0,
                ]),
                Request::create('/x', 'PUT'),
                'listing',
                'post',
            );
            self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
            return json_decode((string) $saved->getContent(), true)['data']['layout']['lock_version'];
        });
    }

    public function testEachWorkspaceKeepsItsOwnListingLayout(): void
    {
        $this->listPosts('a');
        $this->listPosts('b');

        self::assertSame(1, $this->save('a', 'LISTING-A'), 'A saves its own at 1');
        self::assertSame(1, $this->save('b', 'LISTING-B'), 'B saves its own at 1');

        $a = $this->page('a');
        self::assertStringContainsString('LISTING-A', $a);
        self::assertStringNotContainsString('LISTING-B', $a, 'A renders its own');
        $b = $this->page('b');
        self::assertStringContainsString('LISTING-B', $b);
        self::assertStringNotContainsString('LISTING-A', $b, 'B renders its own');
    }

    /**
     * Every layout of a type is the workspace's own (final review, Important 1): a workspace's archive
     * layouts never reach another's — the type's field bindings, rename moves and tombstones read
     * them, so another workspace's would refuse a field deletion there, naming a layout it has not.
     */
    public function testATypesLayoutsAreTheWorkspacesOwn(): void
    {
        $this->listPosts('a');
        $this->listPosts('b');
        $this->runAsTenant($this->tenant('b'), function (): void {
            $repo = $this->container()->get(LayoutRepository::class);
            $this->container()->get(LayoutWriteLock::class)->within(
                'archive',
                'post:categories',
                fn (): int => $repo->saveExpected('archive', 'post:categories', [
                    ['id' => 'tenantloopb1', 'type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []],
                ], [], 0, null),
            );
        });
        self::assertSame(1, $this->save('a', 'LISTING-A'), 'A has its listing layout');

        $a = $this->runAsTenant($this->tenant('a'), fn (): array => array_map(
            static fn (array $row): string => $row['surface'] . ':' . $row['target'],
            $this->container()->get(LayoutRepository::class)->forType('post'),
        ));
        self::assertSame(['listing:post'], $a, "A's layouts of post: its own listing, not B's archive");
        $b = $this->runAsTenant($this->tenant('b'), fn (): array => array_map(
            static fn (array $row): string => $row['surface'] . ':' . $row['target'],
            $this->container()->get(LayoutRepository::class)->forType('post'),
        ));
        self::assertSame(['archive:post:categories'], $b);
    }
}
