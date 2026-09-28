<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;

/**
 * A pack's surface, as a test registers one (type layouts spec §3): site-wide (`@site`), no field
 * blocks and no bindings, one required block without a field (`button`), and no rendered-page tags —
 * the shape the shop's product page has, without the shop. {@see self::unregister()} takes it out of
 * the process-shared registry again.
 */
final class FixtureLayoutSurface implements LayoutSurface
{
    public const KEY = 'fixture';
    public const TARGET = '@site';

    /** @var list<array{type: string, card: string, items: list<string>}> a test's loop declaration */
    public static array $loops = [];

    /** @var list<array{type: string, field?: string}>|null a test's required blocks; null: `button` */
    public static ?array $required = null;

    /** @var list<array<string,mixed>>|null a test's target rows, as given; null: the one `@site` row */
    public static ?array $targets = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        return 'Fixtures — site page';
    }

    public function reach(string $target): string
    {
        return 'Applies to every fixture';
    }

    public function targets(): array
    {
        if (self::$targets !== null) {
            return self::$targets;
        }
        return [[
            'target' => self::TARGET, 'label' => 'Fixtures — site page', 'enabled' => true, 'reason' => null,
            'link' => null,
        ]];
    }

    public function samples(string $target, ?string $query): array
    {
        return [];
    }

    public function defaultSample(string $target): ?string
    {
        return null;
    }

    public function placeholder(string $target): array
    {
        return ['fields' => ['title' => 'Sample fixture'], 'placeholder' => true];
    }

    public function palette(): array
    {
        return [];
    }

    public function required(string $target): array
    {
        return self::$required ?? [['type' => 'button']];
    }

    public function loops(string $target): array
    {
        return self::$loops;
    }

    public function bindable(string $target): array
    {
        return [];
    }

    public function frame(): string
    {
        return 'layouts/entry.twig';
    }

    public function pageTags(string $target): array
    {
        return [];
    }

    public function starter(string $target): array
    {
        return [
            ['type' => 'heading', 'data' => ['text' => 'Fixture'], 'settings' => []],
            ['type' => 'button', 'data' => ['label' => 'Go', 'url' => '/go'], 'settings' => []],
        ];
    }

    /** Take the fixture back out of the process-shared registry. */
    public static function unregister(LayoutSurfaceRegistry $registry): void
    {
        $property = new \ReflectionProperty($registry, 'surfaces');
        $surfaces = $property->getValue($registry);
        unset($surfaces[self::KEY]);
        $property->setValue($registry, $surfaces);
    }
}
