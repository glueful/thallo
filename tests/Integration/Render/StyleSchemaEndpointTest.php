<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Settings\BrandColors;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;
use Thallo\Render\Style\RequestPalette;
use Thallo\Render\Http\Controllers\StyleSchemaController;
use Thallo\Render\ThemeLocator;

/** Visual builder spec §3.4: the inspector's one runtime source of the property table. */
final class StyleSchemaEndpointTest extends AppTestCase
{
    use GrantsPermissions;
    use PaletteFixtures;

    private const PICKER = 'content.edit,content.manage,templates.manage,styles.manage';

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

    /** The route middleware's check: its parameters arrive split on commas, as the router passes them. */
    private function allows(?string $user, string $permissions): bool
    {
        $reached = false;
        (new RequirePermission($this->appContext()))->handle($this->requestAs($user), function () use (&$reached) {
            $reached = true;
            return \Glueful\Http\Response::success([]);
        }, ...explode(',', $permissions));
        return $reached;
    }
    public function testTheEndpointIsBoundUnderTheAdminRenderPrefix(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/render/style-schema', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/render/style-schema must resolve');
    }

    public function testTheSchemaCarriesTheTableTheBreakpointsAndTheActiveVocabulary(): void
    {
        $response = $this->container()->get(StyleSchemaController::class)->show();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];

        self::assertSame(StyleSchema::VERSION, $data['version']);
        self::assertSame(['base' => 0, 'md' => 768, 'lg' => 1024], $data['breakpoints']);
        self::assertCount(count(StyleSchema::properties()), $data['properties']);
        $byPath = array_column($data['properties'], null, 'path');
        self::assertSame(
            ['path' => 'spacing.padding.top', 'group' => 'spacing', 'kinds' => ['token', 'reset'],
                'responsive' => true, 'token_domain' => 'spacing', 'choices' => null],
            $byPath['spacing.padding.top'],
        );
        // The typeface: a font ID or a reset, one value for every width (block typeface plan Task 3).
        self::assertSame(
            ['path' => 'typography.family', 'group' => 'typography', 'kinds' => ['font', 'reset'],
                'responsive' => false, 'token_domain' => null, 'choices' => null],
            $byPath['typography.family'],
        );
        self::assertSame(['start', 'center', 'end'], $byPath['alignment.text']['choices']);
        self::assertFalse($byPath['radius']['responsive']);
        $advanced = ['anchor', 'css_classes', 'attributes', 'accessibility.label'];
        self::assertSame($advanced, $data['advanced']);
        $spacing = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'];
        self::assertSame($spacing, $data['vocabulary']['domains']['spacing']);
        $values = $this->container()->get(ThemeLocator::class)->vocabulary()->values();
        self::assertSame($values, $data['vocabulary']['values']);
        self::assertSame('var(--space-4)', $data['vocabulary']['values']['spacing.lg']);
        // The editor's colour choices come from here: Black among them, as #000000.
        self::assertContains('black', $data['vocabulary']['domains']['color']);
        self::assertSame('#000000', $data['vocabulary']['values']['color.black']);
    }

    public function testTheSchemaCarriesThePaletteWithStatesSwatchesAndLabels(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save([
            'theme_brand_colors' => BrandColors::encode([1 => new BrandSlot('Gold dark', '#8a6a2a')], [], 1),
        ]);
        $this->container()->get(RequestPalette::class)->refresh();
        $response = $this->container()->get(StyleSchemaController::class)->show();
        $data = json_decode((string) $response->getContent(), true)['data'];
        $slots = $data['palette']['slots'];
        self::assertSame(
            [
                'name' => 'Gold dark', 'hex' => '#8a6a2a', 'state' => 'configured',
                'reserved' => false, 'replacing' => null,
            ],
            $slots['brand-1'],
        );
        self::assertArrayNotHasKey('brand-2', $slots, 'a never-issued id is absent');
        self::assertSame('Gold dark — text', $data['palette']['labels']['color.brand-1-contrast']);
        self::assertArrayNotHasKey('color.brand-2', $data['palette']['labels']);
        self::assertSame('Surface 2', $data['palette']['labels']['color.surface-2']);
        self::assertMatchesRegularExpression('/\A#[0-9a-f]{6}\z/', $data['palette']['swatches']['color.surface']);
        self::assertArrayNotHasKey('color.transparent', $data['palette']['swatches']);
        // whether the site renders a dark mode (theme.color_mode.enabled): the dark base matters only then
        self::assertTrue($data['palette']['color_mode']);
    }

    public function testTheSchemaPaletteCarriesItsGenerationAndTheRecentReplacements(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $before = $this->state()->snapshot()->generation;
        $this->replaceAndClear(1, 'color.accent');
        $this->container()->get(RequestPalette::class)->refresh();
        $palette = $this->schemaPalette();
        self::assertSame($this->state()->snapshot()->generation, $palette['generation']);
        self::assertSame($palette['generation'], $palette['replacements']['through']);
        self::assertLessThanOrEqual($before, $palette['replacements']['after']);
        self::assertSame(
            ['color.brand-1' => 'color.accent', 'color.brand-1-contrast' => 'color.accent-contrast'],
            $palette['replacements']['records'][0]['map']
        );
    }

    public function testTheSchemaReadsItsSlotsAndGenerationConsistently(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->container()->get(RequestPalette::class)->refresh();
        // a replacement completes between the first generation read and the slot read: read again
        $this->state()->afterNextSnapshot(function (): void {
            $this->replaceAndClear(1, 'color.accent');
            $this->container()->get(RequestPalette::class)->refresh();
        });
        $palette = $this->schemaPalette();
        self::assertSame(['name' => 'Gold', 'state' => 'removed'], $palette['slots']['brand-1']);
        self::assertSame($this->state()->snapshot()->generation, $palette['generation']);
        self::assertCount(1, $palette['replacements']['records']);
    }

    /** @return array<string,mixed> */
    private function schemaPalette(): array
    {
        $response = $this->container()->get(StyleSchemaController::class)->show();
        return json_decode((string) $response->getContent(), true)['data']['palette'];
    }

    public function testAnyStyleEditorReadsTheSchema(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/render/style-schema', 'GET'));
        self::assertNotNull($match);
        self::assertContains('content_permission:' . self::PICKER, $match['route']->getMiddleware());
        // `styles.manage` is the catalog's (CapabilityCatalog) and no migration seeds its row: make it.
        $permissions = new \Glueful\Extensions\Aegis\Repositories\PermissionRepository($this->connection());
        if ($permissions->findPermissionBySlug('styles.manage') === null) {
            $this->madePermission = $permissions->createPermission([
                'slug' => 'styles.manage', 'name' => 'Manage style classes', 'category' => 'Experience',
            ])?->getUuid();
        }
        foreach (['content.edit', 'content.manage', 'templates.manage', 'styles.manage'] as $permission) {
            $user = $this->userWith('test_schema_' . str_replace('.', '_', $permission), [$permission]);
            self::assertTrue($this->allows($user, self::PICKER), $permission);
        }
        self::assertFalse($this->allows($this->userWith('test_schema_none', ['content.view']), self::PICKER));
    }

    public function testThePaletteListsColoursInOrderWithRemovedOnesAndTheLimit(): void
    {
        $this->configure(4, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->configure(7, 'Teal', '#0f766e');
        $this->container()->get(PaletteMutations::class)->clear(7, null);
        $this->container()->get(RequestPalette::class)->refresh();
        $response = $this->container()->get(StyleSchemaController::class)->show();
        $data = json_decode((string) $response->getContent(), true)['data'];
        $palette = $data['palette'];

        self::assertSame(3, $palette['limit']);
        self::assertSame(['brand-4', 'brand-2'], $palette['order']);
        self::assertSame('configured', $palette['slots']['brand-4']['state']);
        self::assertSame(['name' => 'Teal', 'state' => 'removed'], $palette['slots']['brand-7']);
        self::assertArrayNotHasKey('brand-1', $palette['slots']);
        self::assertSame('Teal', $palette['labels']['color.brand-7']);
        self::assertSame('Gold — text', $palette['labels']['color.brand-4-contrast']);
        $colours = $data['vocabulary']['domains']['color'];
        self::assertSame(
            ['brand-4', 'brand-4-contrast', 'brand-2', 'brand-2-contrast'],
            array_values(array_filter($colours, static fn (string $n): bool => str_starts_with($n, 'brand-'))),
        );
        self::assertLessThan(array_search('brand-4', $colours, true), array_search('black', $colours, true));
    }

    public function testCanManageFollowsContentManage(): void
    {
        $schema = fn (string $user): array => json_decode(
            (string) $this->container()->get(StyleSchemaController::class)->show($this->requestAs($user))->getContent(),
            true,
        )['data']['palette'];
        self::assertFalse($schema($this->userWith('test_schema_editor', ['content.edit']))['can_manage']);
        self::assertTrue($schema($this->userWith('test_schema_manager', ['content.manage']))['can_manage']);
    }

    public function testWithTheLimitAtZeroNoColourIsOfferedAndTheirNamesStay(): void
    {
        $palette = new \Thallo\Contracts\Style\Palette(
            null,
            null,
            [1 => new BrandSlot('Gold', '#8a6a2a')],
            [3 => 'Teal'],
            0,
        );
        $provider = new class ($palette) implements \Thallo\Contracts\Style\PaletteProvider {
            public function __construct(private readonly \Thallo\Contracts\Style\Palette $p)
            {
            }

            public function palette(): \Thallo\Contracts\Style\Palette
            {
                return $this->p;
            }

            public function preview(array $claim): \Thallo\Contracts\Style\Palette
            {
                return $this->p;
            }
        };
        $controller = new StyleSchemaController(
            $this->container()->get(\Thallo\Render\ThemeLocator::class),
            new RequestPalette($provider),
        );
        $data = json_decode((string) $controller->show()->getContent(), true)['data'];
        self::assertSame(0, $data['palette']['limit']);
        self::assertSame([], $data['palette']['order']);
        self::assertSame(['brand-3'], array_keys($data['palette']['slots']));
        self::assertSame('Gold', $data['palette']['labels']['color.brand-1']);
        self::assertSame([], array_filter(
            $data['vocabulary']['domains']['color'],
            static fn (string $n): bool => str_starts_with($n, 'brand-'),
        ));
    }
}
