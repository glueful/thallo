<?php

declare(strict_types=1);

namespace App\Setup\Console;

use App\Setup\SetupService;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function config;

#[AsCommand(
    name: 'thallo:create-admin',
    description: 'Create the first admin + site settings (Layer 2). Run after thallo:provision.',
)]
final class CreateAdminCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Site name', 'Thallo');
        $this->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'First admin email');
        $this->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'First admin password');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Default locale', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SetupService $setup */
        $setup = $this->getService(SetupService::class);

        // Layer 2 is permanent: once installed, the first admin is never re-created.
        if ($setup->isInstalled()) {
            $this->info('Already installed — the first admin already exists; nothing to do.');
            return self::SUCCESS;
        }

        $quiet = !$this->isInteractive();

        if ($quiet) {
            foreach (['admin-email', 'admin-password'] as $required) {
                if ((string) ($input->getOption($required) ?? '') === '') {
                    $this->error("Missing required --{$required} in non-interactive mode.");
                    return self::FAILURE;
                }
            }
            $siteName = (string) $input->getOption('site-name');
            $adminEmail = (string) $input->getOption('admin-email');
            $adminPassword = (string) $input->getOption('admin-password');
            $locale = (string) $input->getOption('locale');
        } else {
            $siteName = $this->ask('Site name', (string) $input->getOption('site-name'));
            $adminEmail = $this->ask('First admin email');
            $adminPassword = $this->secret('First admin password (min 8 chars)');
            $locale = $this->ask('Default locale', (string) $input->getOption('locale'));
        }

        $setup->install($siteName, $adminEmail, $adminPassword, $locale);

        $baseUrl = rtrim((string) config($this->getContext(), 'app.urls.base', 'http://localhost'), '/');
        $this->success("Admin {$adminEmail} created. Sign in at {$baseUrl}/admin");
        return self::SUCCESS;
    }
}
