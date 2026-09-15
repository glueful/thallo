<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * Detach (visual builder spec §4.4): remove a style class reference while preserving every
 * managed effective value at every breakpoint. Resolve every property the block has the
 * capability for with and without the class; where the outcome differs, write the previous
 * resolution into the instance at that breakpoint (`reset` where the previous outcome was a
 * reset); normalise. Pure: interactive, bulk and migration detach all run this one function.
 * Mirrored in `admin/src/style/detach.ts` against `packages/thallo-render/detach-fixtures/v1`.
 */
final class DetachTransformation
{
    /**
     * @param list<array{id: string, style: array<string,mixed>}> $classes the block's ordered classes
     * @param array<string,mixed> $instance the block's `settings.style` before
     * @return array<string,mixed> the block's `settings.style` after
     */
    public static function detach(array $classes, array $instance, string $classId, StyleCapabilities $caps): array
    {
        $without = array_values(array_filter($classes, static fn (array $c): bool => ($c['id'] ?? null) !== $classId));
        if (count($without) === count($classes)) {
            return self::normalise($instance);
        }
        $resolver = new CascadeResolver();
        $out = $instance;
        foreach (StyleSchema::properties() as $path => $def) {
            if (!$caps->allows($path)) {
                continue;
            }
            $before = $resolver->resolve($path, $classes, $instance, $def);
            $after = $resolver->resolve($path, $without, $instance, $def);
            foreach ($before as $bp => $was) {
                if (self::outcome($was) === self::outcome($after[$bp])) {
                    continue;
                }
                $write = $was->state === 'reset' ? ['type' => 'reset'] : $was->value;
                if ($write === null) {
                    continue; // removing a layer never turns a theme default into a declaration
                }
                $segments = explode('.', $path);
                if ($def->responsive) {
                    $segments[] = $bp;
                }
                $out = self::set($out, $segments, $write);
            }
        }
        return self::normalise($out);
    }

    /** The effective outcome: a managed value, a reset, or the theme default. */
    private static function outcome(Resolution $r): string
    {
        if ($r->state === 'reset') {
            return 'reset';
        }
        return $r->value === null ? 'theme-default' : (string) json_encode($r->value);
    }

    /**
     * @param array<string,mixed> $target
     * @param list<string> $segments
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function set(array $target, array $segments, array $value): array
    {
        $key = array_shift($segments);
        if ($segments === []) {
            $target[$key] = $value;
            return $target;
        }
        $child = isset($target[$key]) && is_array($target[$key]) ? $target[$key] : [];
        $target[$key] = self::set($child, $segments, $value);
        return $target;
    }

    /**
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    private static function normalise(array $style): array
    {
        $out = [];
        foreach ($style as $key => $value) {
            if (is_array($value) && !isset($value['type'])) {
                $value = self::normalise($value);
                if ($value === []) {
                    continue;
                }
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
