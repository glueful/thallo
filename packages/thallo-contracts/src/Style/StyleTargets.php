<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The named style targets a block template exposes and where each capability lands (spec
 * §1.7). Style capabilities map by path or group; advanced paths (`advanced.anchor`,
 * `advanced.attributes`, `advanced.css_classes`, `advanced.accessibility.label`) map to exactly
 * one owner each. Kinds gate alignment: text → text target, content → row, self → box.
 */
final readonly class StyleTargets
{
    private const ADVANCED = [
        'advanced.anchor',
        'advanced.css_classes',
        'advanced.attributes',
        'advanced.accessibility.label',
    ];

    /**
     * @param array<string, array{kind: TargetKind, optional: bool}> $targets
     * @param array<string, string> $styleMap exact style path → target
     * @param array<string, string> $advancedMap advanced path → target
     */
    private function __construct(
        private array $targets,
        private array $styleMap,
        private array $advancedMap,
    ) {
    }

    /**
     * @param array{targets?: array<string, array{kind?: string, optional?: bool}>, map?: array<string, mixed>} $decl
     * @throws \InvalidArgumentException
     */
    public static function fromDeclaration(array $decl): self
    {
        $targets = [];
        foreach ((array) ($decl['targets'] ?? []) as $name => $spec) {
            $kind = TargetKind::tryFrom((string) ($spec['kind'] ?? ''));
            if (!is_string($name) || $name === '' || $kind === null) {
                throw new \InvalidArgumentException(sprintf(
                    'unknown target kind "%s" for target "%s"',
                    (string) ($spec['kind'] ?? ''),
                    (string) $name,
                ));
            }
            $targets[$name] = ['kind' => $kind, 'optional' => (bool) ($spec['optional'] ?? false)];
        }

        $styleMap = [];
        $advancedMap = [];
        foreach ((array) ($decl['map'] ?? []) as $capability => $target) {
            if (!is_string($target)) {
                if (in_array($capability, self::ADVANCED, true)) {
                    throw new \InvalidArgumentException("{$capability} is mapped more than once");
                }
                throw new \InvalidArgumentException("{$capability} must map to one target name");
            }
            if (!isset($targets[$target])) {
                throw new \InvalidArgumentException(sprintf('%s maps to undeclared target "%s"', $capability, $target));
            }
            if (in_array($capability, self::ADVANCED, true)) {
                if (isset($advancedMap[$capability])) {
                    throw new \InvalidArgumentException("{$capability} is mapped more than once");
                }
                $advancedMap[$capability] = $target;
                continue;
            }
            $paths = StyleSchema::property($capability) !== null
                ? [$capability]
                : StyleSchema::pathsInGroup($capability);
            if ($paths === []) {
                throw new \InvalidArgumentException(sprintf('unknown style capability "%s"', $capability));
            }
            foreach ($paths as $path) {
                $styleMap[$path] = $target;
            }
        }

        return new self($targets, $styleMap, $advancedMap);
    }

    /**
     * The declaration for a block styled through one non-optional `root` target of `$kind`
     * (spec §1.7): every listed capability and the author's anchor, classes and attributes land
     * on it; `$more` merges further targets and mappings (a button's control, a hero's media).
     *
     * @param list<string> $capabilities
     * @param array{targets?: array<string, array<string,mixed>>, map?: array<string,string>} $more
     * @return array{targets: array<string, array<string,mixed>>, map: array<string,string>}
     */
    public static function root(string $kind, array $capabilities, array $more = []): array
    {
        $map = ['advanced.anchor' => 'root', 'advanced.css_classes' => 'root', 'advanced.attributes' => 'root'];
        foreach ($capabilities as $capability) {
            $map[$capability] = 'root';
        }
        return [
            'targets' => ['root' => ['kind' => $kind]] + ($more['targets'] ?? []),
            'map' => $map + ($more['map'] ?? []),
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->targets);
    }

    public function kind(string $target): TargetKind
    {
        return $this->targets[$target]['kind'] ?? throw new \InvalidArgumentException("unknown target \"{$target}\"");
    }

    public function optional(string $target): bool
    {
        return $this->targets[$target]['optional']
            ?? throw new \InvalidArgumentException("unknown target \"{$target}\"");
    }

    /** The target a style or advanced path lands on, or null when unmapped. */
    public function targetFor(string $path): ?string
    {
        return $this->styleMap[$path] ?? $this->advancedMap[$path] ?? null;
    }

    /** @return list<string> style paths owned by a target, table order */
    public function stylePathsFor(string $target): array
    {
        $paths = [];
        foreach (array_keys(StyleSchema::properties()) as $path) {
            if (($this->styleMap[$path] ?? null) === $target) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /** @return list<string> advanced paths owned by a target */
    public function advancedPathsFor(string $target): array
    {
        $paths = [];
        foreach (self::ADVANCED as $path) {
            if (($this->advancedMap[$path] ?? null) === $target) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /**
     * Kind rules, coverage and single ownership against a block's capabilities.
     *
     * @return list<string> errors, empty when valid
     */
    public function validateAgainst(StyleCapabilities $caps): array
    {
        $errors = [];
        // A rule names every kind that may carry the capability (container-layout spec §3.1):
        // parent layout on a stack, the band's own properties on a box, and item sizing on any
        // outermost root — box, row, or a block-level text target such as a heading.
        $item = [TargetKind::Box, TargetKind::Row, TargetKind::Text];
        $rules = [
            'alignment.text' => [TargetKind::Text],
            'alignment.content' => [TargetKind::Row, TargetKind::Stack],
            'alignment.self' => [TargetKind::Box, TargetKind::Text],
            'layout.display' => [TargetKind::Stack],
            'layout.direction' => [TargetKind::Stack],
            'layout.wrap' => [TargetKind::Stack],
            'layout.align_items' => [TargetKind::Stack],
            'layout.columns' => [TargetKind::Stack],
            'layout.gap.column' => [TargetKind::Stack],
            'layout.gap.row' => [TargetKind::Stack],
            'layout.content_width' => [TargetKind::Stack],
            'layout.gutter' => [TargetKind::Stack],
            'layout.min_height' => [TargetKind::Box],
            'layout.overflow' => [TargetKind::Box],
            'layout.span' => $item,
            'layout.basis' => $item,
            'layout.grow' => $item,
            'layout.shrink' => $item,
            'layout.align_self' => $item,
        ];
        foreach ($caps->paths() as $path) {
            $target = $this->styleMap[$path] ?? null;
            if ($target === null) {
                $errors[] = "capability {$path} has no target";
                continue;
            }
            $required = $rules[$path] ?? null;
            $actual = $this->targets[$target]['kind'];
            if ($required !== null && !in_array($actual, $required, true)) {
                $names = array_map(static fn (TargetKind $kind): string => $kind->value, $required);
                $errors[] = sprintf(
                    '%s requires a %s target; "%s" is %s',
                    $path,
                    count($names) === 1 ? $names[0] : implode(' or ', $names),
                    $target,
                    $actual->value,
                );
            }
        }
        return $errors;
    }
}
