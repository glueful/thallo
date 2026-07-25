<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * A pack's contribution to the starter content-type set — mirrors the fixed definitions
 * {@see \App\Content\Starter\Kinds\ContentTypeKind} ships (pages/category/post), but sourced
 * from an installed pack instead of hard-coded. Pure value object; carries no behavior. The
 * app-owned ContentTypeKind converts each of these into its internal StarterDefinition shape,
 * validating scalar fields and the schema before any write path can see them.
 */
final readonly class StarterContentTypeDefinition
{
    /** @param list<array<string,mixed>> $schema */
    public function __construct(
        public string $sourceId,
        public string $slug,
        public string $name,
        public ?string $description,
        public ?int $cacheTtl,
        public bool $publicDelivery,
        public bool $mountAtRoot,
        public array $schema,
    ) {
    }
}
