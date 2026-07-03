<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Seo\CanonicalProjector;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The delivery item shaping, extracted from DeliveryController so the render-facing
 * PublicRouteResolver serves the IDENTICAL public shape. shape()/item() carry the
 * controller's exact semantics (the delivery suite is the regression harness);
 * shapePublic() is the anonymous, unselected variant with `seo` stamped the way the
 * delivery resolve endpoint stamps it.
 */
final class DeliveryItemShaper
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly ReferenceResolver $references,
        private readonly Projector $projector,
        private readonly CanonicalProjector $canonical,
        private readonly ?SchemaProjector $schemaProjector = null,
    ) {
    }

    /**
     * Resolve references then project each row's schema fields against the schema-derived
     * allow-list. The envelope keys (uuid/version/published_at) are not projectable — only
     * the `fields` sub-object honours `?fields`.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>|null $grantedScopes null = anonymous
     * @param ExpandedTargets|null $expanded records expansion targets for the
     *        caller's Cache-Tag/ETag (spec §4); never serialized into the rows
     * @return list<array<string,mixed>>
     */
    public function shape(
        array $rows,
        ContentTypeSchema $schema,
        FieldSelector $selector,
        string $locale,
        string $typeUuid,
        ?array $grantedScopes,
        ?ExpandedTargets $expanded = null,
    ): array {
        if ($rows === []) {
            return [];
        }

        if ($this->schemaProjector !== null) {
            foreach ($rows as $i => $row) {
                $rows[$i]['fields'] = $this->schemaProjector->project(
                    $typeUuid,
                    (int) ($row['schema_version'] ?? 0),
                    (array) ($row['fields'] ?? []),
                );
            }
        }

        $rows = $this->references->expand(
            $rows,
            $schema,
            $selector->empty() ? null : $selector,
            $locale,
            2,
            $grantedScopes,
            $expanded,
        );

        if ($selector->empty()) {
            return $rows;
        }

        $allowed = array_map(static fn($f): string => $f->name, $schema->fields());
        foreach ($rows as $i => $row) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            /** @var array<string,mixed> $projected */
            $projected = (array) $this->projector->project($fields, $selector, $allowed);
            $rows[$i]['fields'] = $projected;
        }
        return $rows;
    }

    /**
     * The public envelope for one hydrated row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function item(array $row): array
    {
        return [
            'uuid' => $row['entry_uuid'] ?? null,
            'locale' => $row['locale'] ?? null,
            'version' => $row['version'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'fields' => $row['fields'] ?? [],
        ];
    }

    /**
     * The FULL public item for one published row — no field selection, anonymous scopes —
     * with `seo` stamped exactly as the delivery resolve endpoint stamps it. This is the
     * render-facing shape; it MUST stay byte-identical to an unselected delivery item.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function shapePublic(
        array $row,
        string $typeUuid,
        string $typeSlug,
        ?ExpandedTargets $expanded = null,
    ): array {
        $typeRow = $this->types->findByUuid($typeUuid);
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        // A bare request yields the empty selector (no ?fields/?expand) — full item.
        $selector = FieldSelector::fromRequest(Request::create('/'));

        $shaped = $this->shape([$row], $schema, $selector, (string) $row['locale'], $typeUuid, null, $expanded);
        $item = $this->item($shaped[0]);
        $item['seo'] = $this->canonical->project(
            (string) $row['entry_uuid'],
            $typeUuid,
            $typeSlug,
            (string) $row['locale'],
        );
        return $item;
    }
}
