<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\FixtureLayoutSurface;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A pack's surface (type layouts spec §2.1, §3): registered into core's registry, it runs the whole
 * Release A lifecycle — the Layouts row, a session on its starter, an apply, a Save — for a target
 * that is not a content type (`@site`, §5.1), addressed through the router with its `@` encoded.
 */
final class LayoutSurfaceRegistrationTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function registry(): LayoutSurfaceRegistry
    {
        return $this->container()->get(LayoutSurfaceRegistry::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->registry()->register(new FixtureLayoutSurface());
    }

    protected function tearDown(): void
    {
        FixtureLayoutSurface::unregister($this->registry());
        $this->container()->get(LayoutResolver::class)->forget(FixtureLayoutSurface::KEY, FixtureLayoutSurface::TARGET);
        parent::tearDown();
    }

    public function testARegisteredSurfaceIsFoundAndRegisteringItAgainReplacesIt(): void
    {
        self::assertInstanceOf(FixtureLayoutSurface::class, $this->registry()->get('fixture'));
        $this->registry()->register(new FixtureLayoutSurface());
        $keys = array_map(static fn ($s): string => $s->key(), $this->registry()->all());
        self::assertSame(1, count(array_keys($keys, 'fixture', true)));
    }

    public function testTheSurfaceRunsTheWholeLifecycleAtASiteWideTarget(): void
    {
        $admin = $this->container()->get(LayoutAdminController::class);
        $index = $admin->index(Request::create('/v1/layouts'));
        $rows = json_decode((string) $index->getContent(), true)['data']['layouts'];
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['surface'] === 'fixture'))[0] ?? null;
        self::assertNotNull($row);
        self::assertSame('@site', $row['target']);
        self::assertSame('theme', $row['state']);

        $minted = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'fixture', 'target' => '@site'],
            ),
        );
        self::assertSame(200, $minted->getStatusCode(), (string) $minted->getContent());
        $session = json_decode((string) $minted->getContent(), true)['data'];
        self::assertTrue($session['starter']);
        self::assertSame(['heading', 'button'], array_column($session['layout']['blocks'], 'type'));

        $layout = ['blocks' => $session['layout']['blocks'], 'settings' => []];
        $applied = $this->container()->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $session['token'], 'layout' => $layout, 'epoch' => null, 'base_revision' => null,
            ]),
        );
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getContent());

        // Through the router, as the admin sends it: the `@` travels percent-encoded and arrives decoded.
        $path = api_prefix($this->appContext()) . '/admin/layouts/fixture/%40site';
        $match = $this->router()->match(Request::create($path, 'PUT'));
        self::assertNotNull($match['route'] ?? null, "PUT {$path} matches no route");
        self::assertSame(['surface' => 'fixture', 'target' => '@site'], $match['params']);
        $saved = $admin->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $session['token'], 'layout' => $layout, 'expected_lock_version' => 0,
                'preview_revision' => null,
            ]),
            Request::create($path, 'PUT'),
            $match['params']['surface'],
            $match['params']['target'],
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertSame(1, json_decode((string) $saved->getContent(), true)['data']['layout']['lock_version']);
        self::assertSame(
            'Fixture',
            $this->container()->get(LayoutResolver::class)->for('fixture', '@site')['blocks'][0]['data']['text'],
        );
    }
}
