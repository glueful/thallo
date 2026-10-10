<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The site's own colours beyond the families (custom palette spec §2): a Custom neutral, the
 * dark-mode base family it uses, and the brand colours — a list the author adds to, each with a
 * permanent id (`brand-4`) that is never reused. Stored references name the id token
 * (`color.brand-4`), never the author's label; a reference to an id that is not configured —
 * removed, never issued, or any id while the limit is 0 — is valid data that renders no colour (§3.2).
 */
final class Palette
{
    /** Retired with the fixed slots: PaletteSettings, PaletteMutations and the settings controller still read it until the list setting lands. */
    public const SLOTS = [1, 2, 3];
    public const NEUTRAL_KEYS = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line'];
    /** Brand ids run from 1 to this: `brand-1` … `brand-9999` (§3.1). */
    public const MAX_ID = 9999;
    /** How many brand colours a workspace may have when the deployment says nothing (§1). */
    public const DEFAULT_LIMIT = 3;
    /** The most a deployment may allow: each colour costs every picker a choice and the colours stylesheet its utilities. */
    public const LIMIT_CEILING = 12;

    /** @var array<int,BrandSlot> id => colour, in the author's order, whatever the limit */
    public readonly array $brands;

    /**
     * @param array<string,string>|null $customNeutral NEUTRAL_KEYS => #rrggbb, null when unset
     * @param array<int,BrandSlot|null> $brands id => colour in the author's order; nulls are dropped
     * @param array<int,string> $removed cleared ids => the name each had
     * @param int $limit the deployment's limit (0 turns brand colours off)
     */
    public function __construct(
        public readonly ?array $customNeutral = null,
        public readonly ?string $darkBase = null,
        array $brands = [],
        public readonly array $removed = [],
        public readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->brands = array_filter($brands, static fn (?BrandSlot $b): bool => $b !== null);
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @return array<int,BrandSlot> the colours that apply: none while the limit is 0 */
    public function configured(): array
    {
        return $this->limit > 0 ? $this->brands : [];
    }

    /** @return list<int> the configured ids in the author's order */
    public function ids(): array
    {
        return array_keys($this->configured());
    }

    public function brand(int $slot): ?BrandSlot
    {
        return $this->configured()[$slot] ?? null;
    }

    public function isConfigured(int $slot): bool
    {
        return $this->brand($slot) !== null;
    }

    public function isRemoved(int $slot): bool
    {
        return isset($this->removed[$slot]) && !isset($this->brands[$slot]);
    }

    /** The name an id is known by: its colour's, the name it had when cleared, else "Brand N". */
    public function labelOf(int $slot): string
    {
        return $this->brands[$slot]->name ?? $this->removed[$slot] ?? "Brand {$slot}";
    }

    /** The highest id this workspace has issued, configured or removed; 0 for none. */
    public function highestIssued(): int
    {
        return max([0, ...array_keys($this->brands), ...array_keys($this->removed)]);
    }

    /** The id of color.brand-N / color.brand-N-contrast (N from 1 to 9999), else null. */
    public static function slotOf(string $token): ?int
    {
        return preg_match('/\Acolor\.brand-([1-9][0-9]{0,3})(?:-contrast)?\z/', $token, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isContrastToken(string $token): bool
    {
        return self::slotOf($token) !== null && str_ends_with($token, '-contrast');
    }

    /** True for a brand token whose id is not configured. */
    public function isUnavailable(string $token): bool
    {
        $slot = self::slotOf($token);
        return $slot !== null && !$this->isConfigured($slot);
    }

    /** Nothing applies: an existing site before its author opts in. */
    public function isEmpty(): bool
    {
        return $this->customNeutral === null && $this->darkBase === null && $this->configured() === [];
    }

    /** '' when empty (existing fingerprints stay unchanged), else sha1 of the canonical JSON. */
    public function fingerprint(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $brands = [];
        foreach ($this->configured() as $id => $brand) {
            $brands[] = [$id, $brand->name, $brand->hex];
        }
        return sha1((string) json_encode([$this->customNeutral, $this->darkBase, $brands]));
    }
}
