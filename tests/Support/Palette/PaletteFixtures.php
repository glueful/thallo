<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Palette;

use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\GeneralSettings;

/**
 * Palette set-up for the custom palette tests: configure and clear brand slots, start and cancel
 * replace jobs (each under the palette row, as the real mutations take it), and build blocks naming
 * colours. For an AppTestCase.
 */
trait PaletteFixtures
{
    /** @return array{type: string, value: string} */
    protected static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    /** @return array<string,mixed> a heading whose text colour is `$token` */
    protected static function heading(string $token, string $id = 'head00000001'): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'],
            'settings' => ['style' => ['colors' => ['text' => self::tok($token)]]]];
    }

    protected function state(): PaletteState
    {
        return $this->container()->get(PaletteState::class);
    }

    protected function configure(int $slot, string $name, string $hex): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->clearStoreCache();
        [$colors, $removed, $revision] = BrandColors::parse($settings->stored('theme_brand_colors'));
        $colors[$slot] = new BrandSlot($name, $hex);
        unset($removed[$slot]);
        $settings->save(['theme_brand_colors' => BrandColors::encode($colors, $removed, $revision + 1)]);
    }

    protected function clear(int $slot): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($slot): void {
            $this->state()->lock();
            $this->state()->bump();
            $settings = $this->container()->get(GeneralSettings::class);
            $settings->save([
                'theme_brand_colors' => BrandColors::cleared($settings->stored('theme_brand_colors'), $slot),
            ]);
        });
    }

    protected function startJob(int $slot, string $to, ?string $contrastTo): string
    {
        $fence = $this->container()->get(PaletteFence::class);
        return $fence->within(function () use ($slot, $to, $contrastTo): string {
            $this->state()->lock();
            $this->state()->bump();
            return $this->container()->get(PaletteJobRepository::class)
                ->start($slot, $to, $contrastTo, 'user00000001', null);
        });
    }

    protected function cancelJob(string $id): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($id): void {
            $this->state()->lock();
            $this->container()->get(PaletteJobRepository::class)->transition($id, 'cancelled');
            $this->state()->bump();
        });
    }

    /**
     * A completed replacement, through the real job (custom palette plan Task 14): started — the
     * destination's own pair maps the text colour unless one is given — and run to completion, which
     * rewrites every current document and clears the slot.
     */
    protected function replaceAndClear(int $slot, string $to, ?string $contrastTo = 'pair'): string
    {
        $service = $this->container()->get(\Thallo\Core\Content\Palette\PaletteReplaceService::class);
        $job = $service->start($slot, $to, $contrastTo === 'pair' ? null : $contrastTo, 'user00000001');
        $result = $this->container()->get(\Thallo\Core\Content\Palette\PaletteReplaceRunner::class)->run($job);
        if ($result['status'] !== 'completed') {
            throw new \RuntimeException("the replacement did not complete: {$result['status']}");
        }
        $this->container()->get(\Thallo\Core\Settings\GeneralSettings::class)->clearStoreCache();
        return $job;
    }

    /** A running job completes now (the generation its completion bumps to is recorded). */
    protected function completeJob(string $id): void
    {
        $this->container()->get(\Thallo\Core\Content\Palette\PaletteFence::class)->within(function () use ($id): void {
            $this->state()->lock();
            $generation = $this->state()->bump();
            $this->container()->get(\Thallo\Core\Content\Palette\PaletteJobRepository::class)
                ->transition($id, 'completed', $generation);
        });
    }
}
