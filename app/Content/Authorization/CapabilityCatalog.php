<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;

final class CapabilityCatalog
{
    public const ALGEBRA_VERSION = 1;

    /** @var array<string, array{label:string,group:string,platform_only:bool}> */
    private const CATALOG = [
        'content.view' => ['label' => 'View content', 'group' => 'Content', 'platform_only' => false],
        'content.create' => ['label' => 'Create content', 'group' => 'Content', 'platform_only' => false],
        'content.edit' => ['label' => 'Edit content', 'group' => 'Content', 'platform_only' => false],
        'content.publish' => ['label' => 'Publish content', 'group' => 'Content', 'platform_only' => false],
        'content.delete' => ['label' => 'Delete content', 'group' => 'Content', 'platform_only' => false],
        'content.manage' => ['label' => 'Manage content models', 'group' => 'Content', 'platform_only' => false],
        'content.routes' => ['label' => 'Manage routes', 'group' => 'Content', 'platform_only' => false],
        'navigation.manage' => ['label' => 'Manage navigation', 'group' => 'Experience', 'platform_only' => false],
        'seo.manage' => ['label' => 'Manage SEO', 'group' => 'Experience', 'platform_only' => false],
        'templates.manage' => ['label' => 'Manage templates', 'group' => 'Experience', 'platform_only' => false],
        'analytics.read' => ['label' => 'View analytics', 'group' => 'Operations', 'platform_only' => false],
        'workflow.review' => ['label' => 'Review workflow', 'group' => 'Operations', 'platform_only' => false],
        'tenant.members.manage' => ['label' => 'Manage members', 'group' => 'Workspace', 'platform_only' => false],
        'tenant.domains.manage' => ['label' => 'Manage domains', 'group' => 'Workspace', 'platform_only' => false],
        'tenant.roles.manage' => ['label' => 'Manage roles', 'group' => 'Workspace', 'platform_only' => false],
        'collections.manage' => ['label' => 'Manage collections', 'group' => 'Collections', 'platform_only' => false],
        'collections.schema.manage' => [
            'label' => 'Manage collection schemas', 'group' => 'Collections', 'platform_only' => false,
        ],
        'collections.data.manage' => [
            'label' => 'Manage collection data', 'group' => 'Collections', 'platform_only' => false,
        ],
        'commerce.manage' => [
            'label' => 'Manage commerce product-content links', 'group' => 'Commerce', 'platform_only' => false,
        ],
    ];

    /** @return array<string, array{label:string,group:string,platform_only:bool}> */
    public function all(): array
    {
        return self::CATALOG;
    }

    public function has(string $slug): bool
    {
        return isset(self::CATALOG[$slug]);
    }

    public function isGrantable(string $slug): bool
    {
        return isset(self::CATALOG[$slug]) && !self::CATALOG[$slug]['platform_only'];
    }

    /** @return list<string> */
    public function ownerFloor(): array
    {
        return ['tenant.roles.manage', 'tenant.members.manage'];
    }

    /** @return list<string> */
    public function reservedRoles(): array
    {
        return ['owner', 'admin', 'member', 'viewer'];
    }

    public function baselinePolicyHash(ApplicationContext $context): string
    {
        return self::hashPayload($this->payload($context));
    }

    /** @return array<string,mixed> */
    public function payload(ApplicationContext $context): array
    {
        $matrix = config($context, 'tenancy.role_matrix', []);
        return self::canonicalize([
            'algebra_version' => self::ALGEBRA_VERSION,
            'reserved_roles' => $this->reservedRoles(),
            'owner_floor' => $this->ownerFloor(),
            'catalog' => self::CATALOG,
            'role_matrix' => is_array($matrix) ? $matrix : [],
        ]);
    }

    /** @param array<string,mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = array_map(self::canonicalize(...), $value);
            sort($items);
            return $items;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
