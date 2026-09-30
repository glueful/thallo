<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Patterns\LayoutTarget;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Patterns\LayoutPatterns;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;

/**
 * The content type shapes a layout pattern must fit (sections and templates design §3.2): a `body`
 * of blocks, a body named `content`, a rich-text body, no body, no cover, and filterable scalars —
 * each public, with its listing pages on, and `lp_body` archived by its categories.
 */
trait LayoutTypeShapes
{
    /** @var list<string> */
    private static array $shapes = ['lp_body', 'lp_content', 'lp_rich', 'lp_nobody', 'lp_nocover', 'lp_filter'];

    private function createLayoutTypeShapes(): void
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $title = ['name' => 'title', 'type' => 'string', 'required' => true];
        $types->create(['slug' => 'lp_cat', 'name' => 'LP categories', 'public_delivery' => true, 'schema' => [
            $title,
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]]);
        $schemas = [
            'lp_body' => [
                $title,
                ['name' => 'excerpt', 'type' => 'string'],
                ['name' => 'cover', 'type' => 'asset'],
                ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'lp_cat',
                    'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
            'lp_content' => [$title, ['name' => 'content', 'type' => 'blocks'], ['name' => 'cover', 'type' => 'asset']],
            'lp_rich' => [$title, ['name' => 'text', 'type' => 'text', 'format' => 'rich']],
            'lp_nobody' => [$title, ['name' => 'summary', 'type' => 'string']],
            'lp_nocover' => [$title, ['name' => 'body', 'type' => 'blocks']],
            'lp_filter' => [
                $title,
                ['name' => 'sku', 'type' => 'string', 'filterable' => true, 'filter_type' => 'string'],
                ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ];
        foreach ($schemas as $slug => $schema) {
            $types->create([
                'slug' => $slug, 'name' => strtoupper(substr($slug, 0, 2)) . ' ' . substr($slug, 3) . ' pages',
                'public_delivery' => true, 'schema' => $schema,
            ]);
        }
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => self::$shapes]);
    }

    /** Turn a type's listing pages off: its listing and archive targets close. */
    private function closeListing(string $slug): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'listing_types' => array_values(array_diff(self::$shapes, [$slug])),
        ]);
    }

    /** @return list<string> the targets of a surface among the shapes */
    private function shapeTargets(string $surface): array
    {
        return $surface === 'archive' ? ['lp_body:categories'] : self::$shapes;
    }

    private function layoutTarget(string $surface, string $target): LayoutTarget
    {
        $type = LayoutSaver::typeOf($surface, $target);
        $row = $type === null ? null : $this->container()->get(ContentTypeRepository::class)->findBySlug($type);
        return LayoutPatterns::targetFor($surface, $target, $row === null ? null : (array) $row['schema']);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>> every block, nested ones too, with a 12-character id
     */
    private static function withIds(array $blocks, int &$n = 0): array
    {
        foreach ($blocks as $i => $block) {
            $blocks[$i]['id'] = 'shape' . str_pad((string) ++$n, 7, '0', STR_PAD_LEFT);
            foreach ($block['data'] ?? [] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::withIds($value, $n);
                }
            }
        }
        return $blocks;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return array<string,mixed>|null the first block of `$type`, nested ones included
     */
    private static function findBlock(array $blocks, string $type): ?array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
            foreach ($block['data'] ?? [] as $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $found = self::findBlock($value, $type);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }
        return null;
    }
}
