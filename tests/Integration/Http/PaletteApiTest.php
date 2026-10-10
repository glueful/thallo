<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Http\Response;
use Glueful\Validation\RequestDataHydrator;
use Thallo\Core\Content\Palette\Http\PaletteController;
use Thallo\Core\Content\Palette\Http\PalettePreviewData;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Content\Palette\Http\ReplaceBrandData;

/** The palette's admin routes (custom palette spec §4, §5): auth, content.manage, the slot range. */
final class PaletteApiTest extends AppTestCase
{
    use GrantsPermissions;
    use PaletteFixtures;
    use SyncsBlockStyleDeclarations;

    private ?string $madePermission = null;

    protected function tearDown(): void
    {
        $this->scrubGrants();
        if ($this->madePermission !== null) {
            // Hard: a soft-deleted row keeps its unique name, and the next provisioning of the
            // catalog (InstallRoleGrants, the setup tests) would collide with it.
            $this->connection()->table('role_permissions')->where('permission_uuid', '=', $this->madePermission)
                ->forceDelete();
            $this->connection()->table('permissions')->where('uuid', '=', $this->madePermission)->forceDelete();
            \Glueful\Extensions\Aegis\Repositories\PermissionRepository::clearCache();
        }
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            ['GET', '/v1/admin/appearance/palette/brand/{id}/usage', 'content_permission:content.manage'],
            [
                'GET',
                '/v1/admin/appearance/palette/replacements',
                'content_permission:content.edit,content.manage,templates.manage,styles.manage',
            ],
            ['DELETE', '/v1/admin/appearance/palette/brand/{id}', 'content_permission:content.manage'],
            ['POST', '/v1/admin/appearance/palette/preview', 'content_permission:content.manage'],
            ['POST', '/v1/admin/appearance/palette/brand/{id}/replace', 'content_permission:content.manage'],
            ['GET', '/v1/admin/appearance/palette/jobs', 'content_permission:content.manage'],
            ['GET', '/v1/admin/appearance/palette/jobs/{id}', 'content_permission:content.manage'],
            ['POST', '/v1/admin/appearance/palette/jobs/{id}/cancel', 'content_permission:content.manage'],
            ['POST', '/v1/admin/appearance/palette/jobs/{id}/resume', 'content_permission:content.manage'],
        ];
    }

    public function testEveryPaletteRouteCarriesAuthAndItsPermission(): void
    {
        foreach (self::routes() as [$method, $path, $permission]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} is registered");
            self::assertContains('auth', $route['middleware'], $path);
            self::assertContains($permission, $route['middleware'], "{$method} {$path}");
        }
    }

    public function testTheEndpointsTakeAnyIssuedId(): void
    {
        $this->configure(12, 'Teal', '#0f766e');
        $usage = $this->paletteController()->usage(12);
        self::assertSame(200, $usage->getStatusCode());
        self::assertSame(12, self::data($usage)['usage']['slot']);
        self::assertSame(200, $this->paletteController()->clear(12)->getStatusCode());
        $palette = $this->container()->get(\Thallo\Core\Settings\PaletteSettings::class)->palette();
        self::assertTrue($palette->isRemoved(12));
        // never issued, or cleared already (by someone else): nothing to clear — a conflict that
        // says so, never a "cleared" the page would take as its own
        foreach ([7, 12] as $id) {
            $gone = $this->paletteController()->clear($id);
            self::assertSame(409, $gone->getStatusCode(), (string) $id);
            self::assertStringContainsString('already cleared', (string) self::details($gone)['conflict']);
        }
        // the route takes 1–9999 only
        $outside = ['/v1/admin/appearance/palette/brand/0/usage', '/v1/admin/appearance/palette/brand/10000/usage'];
        foreach ($outside as $path) {
            self::assertNotSame(200, $this->handle(Request::create($path, 'GET'))->getStatusCode(), $path);
        }
        self::assertNotNull($this->findRoute('GET', '/v1/admin/appearance/palette/brand/{id}/usage'));
    }

    private function paletteController(): PaletteController
    {
        return $this->container()->get(PaletteController::class);
    }

    /** @return array<string,mixed> */
    private static function data(Response $res): array
    {
        return json_decode((string) $res->getContent(), true)['data'] ?? [];
    }

    /** @return array<string,mixed> an error response's details */
    private static function details(Response $res): array
    {
        return json_decode((string) $res->getContent(), true)['error']['details'] ?? [];
    }

    /** @param array<string,mixed> $body */
    private static function dto(string $class, array $body): object
    {
        return (new RequestDataHydrator())->hydrate($class, $body);
    }

    private function draftNaming(string $token): void
    {
        $this->syncBlockStyleDeclarations();
        $type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfapi', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'blocks']],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $lock = (int) ($entries->findDraft($uuid, 'en')['lock_version'] ?? 0);
        $entries->saveDraft($uuid, 'en', ['body' => [self::heading($token)]], 1, $lock, 'user00000001');
    }

    public function testTheGeneralSettingsSaveRefusesRenamingASlotBeingReplaced(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $res = $this->container()->get(GeneralSettingsController::class)
            ->update(new UpdateGeneralSettingsData(theme_brand_colors: $this->brandList([[1, 'New', '#8a6a2a']])));
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('Gold', $this->container()->get(\Thallo\Core\Settings\PaletteSettings::class)
            ->palette()->brand(1)?->name);
    }

    public function testClearReturnsTheUsageOn409AndThePaletteOn200(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->draftNaming('color.brand-1');
        $res = $this->paletteController()->clear(1);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame(1, self::details($res)['usage']['blocking']['total']);
        $ok = $this->paletteController()->clear(2);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        self::assertSame('removed', self::data($ok)['palette']['slots']['brand-2']['state']);
        // The list this Clear wrote, captured in its transaction (brand colour list plan ruling 13).
        [$colors, $removed] = \Thallo\Core\Settings\BrandColors::parse((string) self::data($ok)['brand_colors']);
        self::assertSame([1], array_keys($colors));
        self::assertSame([2 => 'Rose'], $removed);
    }

    public function testClearingAJobsSourceReturnsTheConflict(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $res = $this->paletteController()->clear(1);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('Gold is part of a running replacement', self::details($res)['conflict'] ?? null);
    }

    public function testThePreviewReturnsRowsForUnsavedValues(): void
    {
        $res = $this->paletteController()->preview(self::dto(PalettePreviewData::class, [
            'theme_accent' => 'blue', 'theme_neutral' => 'custom', 'theme_background' => 'tinted',
            'palette' => [
                'neutral_custom' => [
                    'bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8',
                    'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6',
                ],
                'dark_base' => 'stone',
                'brands' => [['id' => 1, 'name' => 'Gold', 'hex' => '#8a6a2a']],
            ],
        ]));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $data = self::data($res);
        self::assertCount(2 * (6 + 2 + 2), $data['rows']);
        self::assertSame('#ffffff', $data['values']['light']['background'], 'tinted swapped');
        self::assertArrayHasKey('brand-1', $data['values']['dark']);
        $bad = $this->paletteController()
            ->preview(self::dto(PalettePreviewData::class, ['palette' => ['dark_base' => 'purple']]));
        self::assertSame(422, $bad->getStatusCode());
        $badAccent = $this->paletteController()
            ->preview(self::dto(PalettePreviewData::class, ['theme_neutral' => 'plaid']));
        self::assertSame(422, $badAccent->getStatusCode());
    }

    public function testOnlyContentManageUsesClearsAndReplaces(): void
    {
        // `styles.manage` is the catalog's and no migration seeds its row: make it.
        $permissions = new \Glueful\Extensions\Aegis\Repositories\PermissionRepository($this->connection());
        if ($permissions->findPermissionBySlug('styles.manage') === null) {
            $this->madePermission = $permissions->createPermission([
                'slug' => 'styles.manage', 'name' => 'Manage style classes', 'category' => 'Experience',
            ])?->getUuid();
        }
        foreach (['content.edit', 'templates.manage', 'styles.manage'] as $permission) {
            $user = $this->userWith('test_palette_' . str_replace('.', '_', $permission), [$permission]);
            self::assertFalse($this->allows($user, 'content.manage'), $permission);
        }
        self::assertTrue($this->allows($this->userWith('test_palette_manage', ['content.manage']), 'content.manage'));
    }

    private function allows(?string $user, string $permissions): bool
    {
        $reached = false;
        (new RequirePermission($this->appContext()))->handle($this->requestAs($user), function () use (&$reached) {
            $reached = true;
            return Response::success([]);
        }, ...explode(',', $permissions));
        return $reached;
    }

    public function testReplaceStartsAJobAndAnswersConflictsAndRefusals(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $res = $this->paletteController()->replace(self::dto(ReplaceBrandData::class, ['to' => 'color.accent']), 1);
        self::assertSame(202, $res->getStatusCode(), (string) $res->getContent());
        $job = self::data($res)['job'];
        self::assertSame(
            ['slot' => 1, 'to' => 'color.accent', 'contrast_to' => 'color.accent-contrast', 'status' => 'running'],
            array_intersect_key($job, ['slot' => 0, 'to' => 0, 'contrast_to' => 0, 'status' => 0])
        );
        self::assertSame(409, $this->paletteController()
            ->replace(self::dto(ReplaceBrandData::class, ['to' => 'color.surface']), 1)->getStatusCode());
        self::assertSame(422, $this->paletteController()
            ->replace(self::dto(ReplaceBrandData::class, ['to' => 'color.accent-contrast']), 2)->getStatusCode());
        self::assertCount(1, self::data($this->paletteController()->jobs())['jobs']);
        self::assertSame(200, $this->paletteController()->cancel($job['id'])->getStatusCode());
        self::assertSame(409, $this->paletteController()->cancel($job['id'])->getStatusCode());
        self::assertSame(409, $this->paletteController()->resume($job['id'])->getStatusCode());
        self::assertSame(404, $this->paletteController()->job('nope')->getStatusCode());
        self::assertSame('cancelled', self::data($this->paletteController()->job($job['id']))['job']['status']);
    }
}
