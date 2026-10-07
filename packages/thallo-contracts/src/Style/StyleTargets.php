<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The named style targets a block template exposes and where each capability lands (spec
 * §1.7). Style capabilities map by path or group; advanced paths (`advanced.anchor`,
 * `advanced.attributes`, `advanced.css_classes`, `advanced.accessibility.label`) map to exactly
 * one owner each. Kinds gate alignment: text → text target, content → row, self → box.
 *
 * A block may also declare PARTS: repeated sub-elements (a links block's links) styled on their
 * own. A part is not a target — none of the block's style lands on it — but a style record of
 * its own (`settings.parts.<name>`) holding only the part's capabilities.
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
     * @param array<string, array{kind: TargetKind, optional: bool, defaults: ?array<string,mixed>}> $targets
     * @param array<string, string> $styleMap exact style path → target
     * @param array<string, string> $advancedMap advanced path → target
     * @param array<string, array{label: string, capabilities: StyleCapabilities}> $parts
     */
    private function __construct(
        private array $targets,
        private array $styleMap,
        private array $advancedMap,
        private array $parts = [],
    ) {
    }

    /**
     * @param array{
     *   targets?: array<string, array{kind?: string, optional?: bool, defaults?: mixed}>,
     *   map?: array<string, mixed>,
     * } $decl
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
            $targets[$name] = [
                'kind' => $kind,
                'optional' => (bool) ($spec['optional'] ?? false),
                'defaults' => array_key_exists('defaults', (array) $spec)
                    ? self::defaultsFrom($name, $spec['defaults'])
                    : null,
            ];
        }

        $styleMap = [];
        $advancedMap = [];
        /** @var array<string, string> $hoverEntries mapped after everything else (hover state spec §2.2) */
        $hoverEntries = [];
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
            // The hover state is mapped in a second pass, once every resting path has its target, so
            // the result does not depend on the declaration's order.
            if ($capability === 'hover' || StyleSchema::restingPathOf((string) $capability) !== null) {
                $hoverEntries[(string) $capability] = $target;
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

        foreach ($hoverEntries as $capability => $target) {
            if ($capability === 'hover') {
                // The group: the hover paths whose resting path this target owns; the rest are not offered.
                foreach (StyleSchema::HOVER as $hover => $resting) {
                    if (($styleMap[$resting] ?? null) === $target) {
                        $styleMap[$hover] = $target;
                    }
                }
                continue;
            }
            // One hover path, named: asked for on a target that cannot have it is an error, not a drop.
            $resting = (string) StyleSchema::restingPathOf($capability);
            if (($styleMap[$resting] ?? null) !== $target) {
                throw new \InvalidArgumentException(sprintf(
                    '%s maps to "%s", but %s is on "%s"',
                    $capability,
                    $target,
                    $resting,
                    (string) ($styleMap[$resting] ?? 'no target'),
                ));
            }
            $styleMap[$capability] = $target;
        }

        $parts = [];
        foreach ((array) ($decl['parts'] ?? []) as $name => $spec) {
            if (!is_string($name) || $name === '' || isset($targets[$name])) {
                throw new \InvalidArgumentException(
                    sprintf('part "%s" must be named apart from every target', (string) $name),
                );
            }
            $spec = is_array($spec) ? $spec : [];
            $capabilities = StyleCapabilities::fromDeclaration(
                is_array($spec['capabilities'] ?? null) ? array_values($spec['capabilities']) : null,
            );
            $parts[$name] = [
                'label' => is_string($spec['label'] ?? null) ? $spec['label'] : ucfirst($name),
                'capabilities' => $capabilities,
                // Drawn by the block's CHILD blocks (a Social links block's icons are its Social
                // links'), which read it with parent_style_classes(), not by the block's own template.
                'children' => ($spec['children'] ?? false) === true,
            ];
        }

        return new self($targets, $styleMap, $advancedMap, $parts);
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

    /** @return list<string> the block's parts, declaration order */
    public function parts(): array
    {
        return array_keys($this->parts);
    }

    public function isPart(string $name): bool
    {
        return isset($this->parts[$name]);
    }

    public function partLabel(string $part): string
    {
        return $this->parts[$part]['label'] ?? throw new \InvalidArgumentException("unknown part \"{$part}\"");
    }

    /** Whether a part is drawn by the block's child blocks rather than its own template. */
    public function drawnByChildren(string $part): bool
    {
        return $this->parts[$part]['children'] ?? throw new \InvalidArgumentException("unknown part \"{$part}\"");
    }

    /** What a part may be styled with: its own capabilities, never the block's. */
    public function partCapabilities(string $part): StyleCapabilities
    {
        return $this->parts[$part]['capabilities'] ?? throw new \InvalidArgumentException("unknown part \"{$part}\"");
    }

    public function kind(string $target): TargetKind
    {
        return $this->targets[$target]['kind'] ?? throw new \InvalidArgumentException("unknown target \"{$target}\"");
    }

    /**
     * The arrangement the theme gives a target when nothing is set (type layouts plan C2): its mode,
     * a label for tracks the vocabulary cannot name, and its gaps as the theme writes them. The
     * inspector shows them as the unset state; the emitter keeps the theme's own tracks where they
     * are declared. Null when the target declares none (a flex column, the container default).
     *
     * @return array{
     *   display: 'flex'|'grid', columns?: array{label: string}, gap?: array{row?: string, column?: string},
     * }|null
     */
    public function defaults(string $target): ?array
    {
        if (!isset($this->targets[$target])) {
            throw new \InvalidArgumentException("unknown target \"{$target}\"");
        }
        return $this->targets[$target]['defaults'];
    }

    /**
     * @return array{display: 'flex'|'grid', columns?: array{label: string}, gap?: array{row?: string, column?: string}}
     * @throws \InvalidArgumentException
     */
    private static function defaultsFrom(string $target, mixed $defaults): array
    {
        $refuse = static fn (string $why): \InvalidArgumentException
            => new \InvalidArgumentException("target \"{$target}\" defaults: {$why}");
        if (!is_array($defaults) || array_is_list($defaults)) {
            throw $refuse('must be a map');
        }
        $unknown = array_diff(array_keys($defaults), ['display', 'columns', 'gap']);
        if ($unknown !== []) {
            throw $refuse('unknown key "' . implode('", "', $unknown) . '"');
        }
        if (!in_array($defaults['display'] ?? null, ['flex', 'grid'], true)) {
            throw $refuse('display must be flex or grid');
        }
        $out = ['display' => $defaults['display']];
        if (array_key_exists('columns', $defaults)) {
            $columns = $defaults['columns'];
            if (
                !is_array($columns) || array_keys($columns) !== ['label']
                || !is_string($columns['label']) || trim($columns['label']) === ''
            ) {
                throw $refuse('columns must be {label} with a label');
            }
            $out['columns'] = ['label' => $columns['label']];
        }
        if (array_key_exists('gap', $defaults)) {
            $gap = $defaults['gap'];
            if (!is_array($gap) || array_is_list($gap) || array_diff(array_keys($gap), ['row', 'column']) !== []) {
                throw $refuse('gap must be {row?, column?}');
            }
            foreach ($gap as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw $refuse('a gap must be the theme\'s written value');
                }
            }
            $out['gap'] = $gap;
        }
        return $out;
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

    /** $caps without the hover paths no target owns (hover state spec §2.2). */
    public function effective(StyleCapabilities $caps): StyleCapabilities
    {
        return $caps->filter(
            fn (string $path): bool => StyleSchema::restingPathOf($path) === null || isset($this->styleMap[$path]),
        );
    }

    /**
     * What the block and each part offer, as published (hover state spec §2.2.1): effective, and in
     * schema table order whatever order the declaration used.
     *
     * @return array{block: list<string>, parts: array<string, list<string>>}
     */
    public function stylePaths(StyleCapabilities $caps): array
    {
        $parts = [];
        foreach ($this->parts() as $part) {
            $parts[$part] = StyleSchema::ordered($this->partCapabilities($part)->paths());
        }
        return ['block' => StyleSchema::ordered($this->effective($caps)->paths()), 'parts' => $parts];
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
            if ($target === null && StyleSchema::restingPathOf($path) !== null) {
                continue; // dropped by the target-aware rule (hover state spec §2.2), not missing
            }
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
