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
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Opt-in starter seeding (starter-library spec §2): idempotent by slug — an existing
 * slug is SKIPPED in ANY active state (a deactivated starter is still an admin
 * decision), so reruns never overwrite edits. Deliberately no --force.
 */
#[AsCommand(
    name: 'thallo:blocks:seed',
    description: 'Seed the starter block types (skips any slug that already exists)',
    aliases: ['blocks:seed'],
)]
final class SeedBlockTypesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid.');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Seed all active tenants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flags = $this->getService(SystemFlags::class);
        if (!$flags->tenancyEnabled()) {
            return $this->seedCurrent();
        }
        $tenant = $input->getOption('tenant');
        $all = (bool) $input->getOption('all');
        if ($all === (is_string($tenant) && trim($tenant) !== '')) {
            $this->error('When tenancy is enabled, supply exactly one --tenant or --all.');
            return self::FAILURE;
        }
        $runner = $this->getService(TenantContextRunner::class);
        if ($all) {
            $runner->forEachTenant(fn() => $this->seedCurrent());
        } else {
            $runner->runAsTenant(trim((string) $tenant), fn() => $this->seedCurrent());
        }
        return self::SUCCESS;
    }

    private function seedCurrent(): int
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
