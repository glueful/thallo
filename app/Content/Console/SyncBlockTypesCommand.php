<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Additively syncs evolved starter block-type schemas onto existing rows.
 *
 * The seeder (thallo:blocks:seed) is create-only by design — it never touches an
 * existing row, so new fields added to a StarterBlockTypes definition never reach
 * already-seeded installs. This closes that gap the SAFE way: for each starter it
 * PRESERVES the existing field order and APPENDS (via array_merge) any starter field
 * whose `name` is absent from the DB row's schema. Operator-added fields and the
 * row's label/icon/description/category are left untouched, and field REMOVAL is
 * never performed here — that is the migration flow's job — so this is non-destructive
 * and mirrors updateSchema's additive-only guard. `--dry-run` reports the same
 * "synced …" lines without writing, so it doubles as a safe pre-upgrade preview.
 */
#[AsCommand(
    name: 'thallo:blocks:sync',
    description: 'Additively add new starter block-type fields to existing rows (never removes).',
    aliases: ['blocks:sync'],
)]
final class SyncBlockTypesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BlockTypeRepository $repo */
        $repo = $this->getService(BlockTypeRepository::class);
        $dryRun = (bool) $input->getOption('dry-run');
        $synced = 0;
        $unchanged = 0;
        $missing = 0;
        foreach (StarterBlockTypes::definitions() as $definition) {
            $row = $repo->findBySlug($definition['slug']);
            if ($row === null) {
                $this->line("missing {$definition['slug']} (run thallo:blocks:seed)");
                $missing++;
                continue;
            }
            $existing = array_column($row['schema'], 'name');
            $toAdd = array_values(array_filter(
                $definition['schema'],
                static fn (array $f): bool => !in_array($f['name'], $existing, true),
            ));
            if ($toAdd === []) {
                $unchanged++;
                continue;
            }
            if (!$dryRun) {
                $repo->updateSchema(
                    (string) $row['uuid'],
                    array_merge($row['schema'], $toAdd),
                    (string) $row['label'],
                    $row['icon'] !== null ? (string) $row['icon'] : null,
                    $row['description'] !== null ? (string) $row['description'] : null,
                    $row['category'] !== null ? (string) $row['category'] : null,
                );
            }
            $names = implode(', ', array_column($toAdd, 'name'));
            $this->line("synced {$definition['slug']} (+" . count($toAdd) . ": {$names})");
            $synced++;
        }
        $summary = "Synced {$synced}, unchanged {$unchanged}, missing {$missing}.";
        $this->success($dryRun ? "[dry-run] {$summary} No changes written." : $summary);
        return self::SUCCESS;
    }
}
