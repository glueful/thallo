<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The site's own colours beyond the families (custom palette spec §2): a Custom neutral, the
 * dark-mode base family it uses, and three stable brand slots. Stored references name the slot
 * token (`color.brand-1`), never the author's label; a reference to an unconfigured slot is valid
 * data that renders no colour (§3.2).
 */
final class Palette
{
    public const SLOTS = [1, 2, 3];
    public const NEUTRAL_KEYS = ['bg', 'surface', 'surface_2', 'ink', 'muted', 'line'];

    /**
     * @param array<string,string>|null $customNeutral NEUTRAL_KEYS => #rrggbb, null when unset
     * @param array<int,BrandSlot|null> $brands slot => slot or null
     */
    public function __construct(
        public readonly ?array $customNeutral = null,
        public readonly ?string $darkBase = null,
        public readonly array $brands = [1 => null, 2 => null, 3 => null],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function brand(int $slot): ?BrandSlot
    {
        return $this->brands[$slot] ?? null;
    }

    public function isConfigured(int $slot): bool
    {
        return $this->brand($slot) !== null;
    }

    /** 1..3 for color.brand-N / color.brand-N-contrast, else null. */
    public static function slotOf(string $token): ?int
    {
        return preg_match('/\Acolor\.brand-([123])(?:-contrast)?\z/', $token, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isContrastToken(string $token): bool
    {
        return self::slotOf($token) !== null && str_ends_with($token, '-contrast');
    }

    /** True for a brand token whose slot is not configured. */
    public function isUnavailable(string $token): bool
    {
        $slot = self::slotOf($token);
        return $slot !== null && !$this->isConfigured($slot);
    }

    /** Nothing set: an existing site before its author opts in. */
    public function isEmpty(): bool
    {
        return $this->customNeutral === null && $this->darkBase === null
            && array_filter($this->brands) === [];
    }

    /** '' when empty (existing fingerprints stay unchanged), else sha1 of the canonical JSON. */
    public function fingerprint(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $brands = [];
        foreach (self::SLOTS as $slot) {
            $brands[$slot] = $this->brand($slot)?->toArray();
        }
        return sha1((string) json_encode([$this->customNeutral, $this->darkBase, $brands]));
    }

    /** @param array<int,BrandSlot|null> $brands */
    public function withBrands(array $brands): self
    {
        return new self($this->customNeutral, $this->darkBase, $brands + $this->brands);
    }
}
