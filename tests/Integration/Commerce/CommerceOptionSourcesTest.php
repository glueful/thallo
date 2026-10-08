<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Core\Content\Fields\FieldOptionsController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;
use Thallo\Core\Tests\Support\SeedsShopCatalog;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Product grid spec §5.3: the store's categories and tags as block-field choices — value the slug,
 * label the name, in name order — under the catalogue's own read permission, `commerce.view`.
 */
final class CommerceOptionSourcesTest extends AppTestCase
{
    use GrantsPermissions;
    use SeedsShopCatalog;

    private const TENANT = 'optsrctenant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->scrubGrants();
        $this->clearCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /** @param list<string> $permissions @return array{int, list<array<string,mixed>>} */
    private function options(string $source, string $user, array $permissions): array
    {
        $res = $this->container()->get(FieldOptionsController::class)->show(
            $this->requestAs($this->userWith($user, $permissions)),
            $source,
        );
        $body = json_decode((string) $res->getContent(), true);
        return [$res->getStatusCode(), $body['data']['options'] ?? []];
    }

    public function testCategoriesAreSlugsLabelledByNameInNameOrder(): void
    {
        $this->category('women');
        $this->category('Men');
        $viewer = ['content.edit', 'commerce.view'];
        [$status, $options] = $this->options('thallo-commerce.categories', 'opt_viewer_c', $viewer);
        self::assertSame(200, $status);
        self::assertSame(['Men', 'women'], array_column($options, 'value'));
        self::assertSame(['Men', 'Women'], array_column($options, 'label'));
        self::assertSame([true, true], array_column($options, 'available'));
    }

    public function testTagsAreSlugsLabelledByName(): void
    {
        $this->tag('summer');
        [$status, $options] = $this->options('thallo-commerce.tags', 'opt_viewer_t', ['content.edit', 'commerce.view']);
        self::assertSame(200, $status);
        self::assertSame([['value' => 'summer', 'label' => 'Summer', 'available' => true, 'reason' => null]], $options);
    }

    public function testAContentEditorWithoutCatalogueAccessIsRefused(): void
    {
        $this->category('men');
        foreach (['thallo-commerce.categories', 'thallo-commerce.tags'] as $source) {
            [$status] = $this->options($source, 'opt_editor_' . substr($source, -4), ['content.edit']);
            self::assertSame(403, $status, $source);
        }
    }

    public function testAManagerOnlyGrantCannotListTheChoices(): void
    {
        // The field-options endpoint asks the permission authority for `commerce.view` exactly; a
        // `commerce.manage` grant alone does not satisfy it there (unlike CommerceMetaController).
        [$status] = $this->options('thallo-commerce.categories', 'opt_manager_c', ['content.edit', 'commerce.manage']);
        self::assertSame(403, $status);
    }
}
