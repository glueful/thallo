<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\BrandColorInUse;
use Thallo\Core\Content\Palette\PaletteConflict;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteHistoryPruner;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Palette\PaletteReplaceRunner;
use Thallo\Core\Content\Palette\PaletteReplaceService;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * One side of a two-session palette race, run as its own PHP process (its own database session) by
 * PaletteTwoProcessTest and PaletteReplacementsTest. Skipped unless THALLO_PALETTE_ACTOR
 * (`<scenario>:<role>`) and THALLO_PALETTE_BARRIER are set. It skips the harness's per-test
 * truncation on purpose: the launching test owns the tables while the actors run.
 *
 * A holder writes `held-<scenario>` (its backend pid) only after the statement that takes the lock
 * has returned, then waits for `release` and commits. A contender writes `pid-<scenario>` before its
 * attempt and `outcome-<scenario>` after it.
 */
final class PaletteConcurrentActorTest extends AppTestCase
{
    protected function setUp(): void
    {
        // No truncation, no seed grants: the launching test owns the tables.
    }

    private static function env(string $name): string
    {
        return (string) getenv('THALLO_PALETTE_' . $name);
    }

    /** @return array<string,mixed> */
    private static function heading(string $token): array
    {
        return ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['colors' => ['text' => ['type' => 'token', 'value' => $token]]]]];
    }

    public function testActor(): void
    {
        [$scenario, $role] = explode(':', self::env('ACTOR')) + [1 => ''];
        $dir = self::env('BARRIER');
        if ($scenario === '' || $dir === '') {
            self::markTestSkipped('launched only by PaletteTwoProcessTest');
        }
        $c = $this->container();
        $db = $c->get(Connection::class);
        $pid = (int) $db->getPDO()->query('SELECT pg_backend_pid()')->fetchColumn();
        $held = static function () use ($dir, $scenario, $pid): void {
            file_put_contents("{$dir}/held-{$scenario}", (string) $pid); // AFTER the lock is taken
            $deadline = microtime(true) + 30;
            while (!is_file("{$dir}/release")) {
                usleep(5000);
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('never released');
                }
            }
        };
        if ($role === 'contender') {
            file_put_contents("{$dir}/pid-{$scenario}", (string) $pid);
        }
        if ($scenario === 'prune') { // the batch-versus-prune proofs: prune to commit, then signal
            $c->get(PaletteHistoryPruner::class)->prune();
            touch("{$dir}/committed-prune");
            self::assertTrue(true);
            return;
        }
        $fence = $c->get(PaletteFence::class);
        $state = $c->get(PaletteState::class);
        $save = fn () => $c->get(EntryRepository::class)->saveDraft(
            self::env('ENTRY'),
            'en',
            ['body' => [self::heading('color.brand-1')]],
            1,
            (int) self::env('LOCK'),
            'user00000001',
        );
        $outcome = ['result' => 'ok', 'detail' => null];
        try {
            $outcome['detail'] = match ("{$scenario}:{$role}") {
                'save-clear:holder' => $fence->within(function () use ($state, $save, $held): void {
                    $state->lock(); // taken
                    $save();
                    $held();
                }),
                'save-clear:contender' => $c->get(PaletteMutations::class)->clear(1, 'user00000001'),
                'clear-save:holder' => $fence->within(function () use ($c, $held): void {
                    $c->get(PaletteMutations::class)->clear(1, 'user00000001'); // takes and bumps the row
                    $held();
                }),
                'clear-save:contender' => $save(),
                'cancel-worker:holder' => $fence->within(function () use ($c, $held): void {
                    $c->get(PaletteReplaceService::class)->cancel(self::env('JOB'));
                    $held();
                }),
                'cancel-worker:contender' => $c->get(PaletteReplaceRunner::class)->run(self::env('JOB')),
                'start-start:holder' => $fence->within(function () use ($c, $held): void {
                    $c->get(PaletteReplaceService::class)->start(1, 'color.brand-2', null, 'user00000001');
                    $held();
                }),
                'start-start:contender' => $c->get(PaletteReplaceService::class)
                    ->start(2, 'color.accent', null, 'user00000001'),
                'first-row:holder' => $db->transaction(function () use ($db, $held): void {
                    $db->table('palette_state')->insert([
                        'site' => 'site', 'generation' => 0, 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $held(); // uncommitted: the contender's existence check sees no row
                }),
                'first-row:contender' => $db->transaction(function () use ($db, $state): int {
                    $state->ensureRow(); // its INSERT waits on the holder's uncommitted key
                    return $db->table('palette_state')->count(); // runs only if the transaction stayed usable
                }),
            };
        } catch (BrandColorInUse) {
            $outcome['result'] = 'in_use';
        } catch (PaletteRefusal) {
            $outcome['result'] = 'refused';
        } catch (PaletteConflict) {
            $outcome['result'] = 'conflict';
        }
        if ($role === 'contender') {
            file_put_contents("{$dir}/outcome-{$scenario}", json_encode($outcome));
        }
        self::assertTrue(true);
    }
}
