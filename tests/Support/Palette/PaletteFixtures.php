<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Palette;

use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteState;
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
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_' . $slot => json_encode(['name' => $name, 'hex' => $hex]),
        ]);
    }

    protected function clear(int $slot): void
    {
        $this->container()->get(PaletteFence::class)->within(function () use ($slot): void {
            $this->state()->lock();
            $this->state()->bump();
            $this->container()->get(GeneralSettings::class)->save(['theme_brand_' . $slot => '']);
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
     * A completed replacement, standing in for Task 14's runner: the job is recorded, every draft and
     * current publication naming the slot is rewritten through the palette row, the slot is cleared,
     * and the job completes at the generation that clearing bumped to.
     */
    protected function replaceAndClear(int $slot, string $to, ?string $contrastTo = 'pair'): string
    {
        if ($contrastTo === 'pair') {
            $contrastTo = $to === 'color.accent' ? 'color.accent-contrast'
                : (\Thallo\Contracts\Style\Palette::slotOf($to) !== null ? $to . '-contrast' : null);
        }
        $job = $this->startJob($slot, $to, $contrastTo);
        $map = ["color.brand-{$slot}" => $to]
            + ($contrastTo === null ? [] : ["color.brand-{$slot}-contrast" => $contrastTo]);
        $rewrite = static function (mixed $node) use (&$rewrite, $map): mixed {
            if (!is_array($node)) {
                return $node;
            }
            $value = $node['value'] ?? null;
            if (($node['type'] ?? null) === 'token' && is_string($value) && isset($map[$value])) {
                $node['value'] = $map[$node['value']];
                return $node;
            }
            foreach ($node as $k => $v) {
                $node[$k] = $rewrite($v);
            }
            return $node;
        };
        $db = $this->connection();
        $fence = $this->container()->get(\Thallo\Core\Content\Palette\PaletteFence::class);
        $fence->within(function () use ($db, $rewrite, $slot, $job): void {
            $this->state()->lock();
            foreach ($db->table('entry_drafts')->get() as $row) {
                $fields = json_decode((string) $row['fields'], true) ?: [];
                $db->table('entry_drafts')
                    ->where('entry_uuid', '=', $row['entry_uuid'])
                    ->where('locale', '=', $row['locale'])
                    ->update(['fields' => json_encode($rewrite($fields))]);
            }
            foreach ($db->table('entry_publications')->get() as $pin) {
                $version = $db->table('entry_versions')->where('uuid', '=', $pin['version_uuid'])->first();
                if ($version !== null) {
                    $fields = json_decode((string) $version['fields'], true) ?: [];
                    $db->table('entry_versions')->where('uuid', '=', $version['uuid'])
                        ->update(['fields' => json_encode($rewrite($fields))]);
                }
            }
            $generation = $this->state()->bump();
            $this->container()->get(\Thallo\Core\Settings\GeneralSettings::class)->save(['theme_brand_' . $slot => '']);
            $this->container()->get(\Thallo\Core\Content\Palette\PaletteJobRepository::class)
                ->transition($job, 'completed', $generation);
        });
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
