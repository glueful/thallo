<?php

declare(strict_types=1);

namespace App\Content\Console;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opt-in starter seeding (starter-library spec §2): idempotent by slug — an existing
 * slug is SKIPPED in ANY active state (a deactivated starter is still an admin
 * decision), so reruns never overwrite edits. Deliberately no --force.
 */
#[AsCommand(
    name: 'lemma:blocks:seed',
    description: 'Seed the starter block types (skips any slug that already exists)',
    aliases: ['blocks:seed'],
)]
final class SeedBlockTypesCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BlockTypeRepository $repo */
        $repo = $this->getService(BlockTypeRepository::class);
        $created = 0;
        $skipped = 0;
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) !== null) {
                $this->line("skipped {$definition['slug']} (exists)");
                $skipped++;
                continue;
            }
            $repo->create($definition);
            $this->line("created {$definition['slug']}");
            $created++;
        }
        $this->success("Created {$created}, skipped {$skipped}.");
        return self::SUCCESS;
    }
}
