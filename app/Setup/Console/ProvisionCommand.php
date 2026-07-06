<?php

declare(strict_types=1);

namespace App\Setup\Console;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Doctor;
use App\Setup\PgsqlDatabaseConfigFactory;
use Glueful\Console\BaseCommand;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\EnvWriter;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function base_path;

#[AsCommand(
    name: 'thallo:provision',
    description: 'Configure the database + security keys and run migrations (Layer 1; no admin)',
)]
final class ProvisionCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Regenerate keys / rewrite .env / re-run pending migrations',
        );
        foreach (['db-host', 'db-port', 'db-name', 'db-user', 'db-password', 'db-schema', 'db-sslmode'] as $opt) {
            $this->addOption($opt, null, InputOption::VALUE_REQUIRED, "Override {$opt}");
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = base_path($this->getContext());
        $factory = new PgsqlDatabaseConfigFactory();

        // 1. Pre-prompt environment checks (read-only; no .env mutation).
        foreach ((new Doctor($basePath, PHP_VERSION, get_loaded_extensions()))->preflight() as $check) {
            if ($check->status === Check::FAIL) {
                $this->error("Preflight failed ({$check->name}): {$check->message}");
                return self::FAILURE;
            }
        }

        $quiet = !$this->isInteractive();

        // 2. Build the pgsql DatabaseConfig (engine is fixed; never prompted).
        $database = $quiet
            ? $this->fromEnvWithOverrides($factory, new EnvWriter($basePath . '/.env'), $input)
            : $this->promptForCredentials($factory, $input);

        // 3. Validate — fail LOUDLY before the Installer touches anything.
        $missing = $factory->requiredFieldErrors($database);
        if ($missing !== []) {
            $this->error('Missing/invalid database settings: ' . implode(', ', $missing)
                . ($quiet ? ' — set DB_PGSQL_* in .env or pass --db-* options.' : '.'));
            return self::FAILURE;
        }

        // 4. Hand to the framework Installer (single connection test → .env → keys → migrate).
        $result = (new Installer($basePath, $this->getContext()))->run(new InstallOptions(
            database: $database,
            force: (bool) $input->getOption('force'),
        ));

        $this->table(['Step', 'Status', 'Detail'], array_map(
            static fn ($s) => [$s->name, $s->status, $s->message],
            $result->steps,
        ));

        if (!$result->ok) {
            foreach ($result->steps as $step) {
                if ($step->status === InstallStep::FAILED) {
                    $this->error('Provision failed: ' . $step->message);
                    break;
                }
            }
            return self::FAILURE;
        }

        // Postgres is fixed; the password is never shown.
        $this->success(sprintf(
            'Database configured: %s:%d/%s (migrations applied). Next: `thallo create-admin`.',
            $database->host,
            $database->port,
            $database->database,
        ));
        return self::SUCCESS;
    }

    /** Env-derived config with any explicit --db-* option taking precedence. */
    private function fromEnvWithOverrides(
        PgsqlDatabaseConfigFactory $factory,
        EnvWriter $env,
        InputInterface $input,
    ): DatabaseConfig {
        $base = $factory->fromEnv($env);
        $portOpt = $input->getOption('db-port');
        $port = $portOpt === null
            ? $base->port
            : (ctype_digit((string) $portOpt) ? (int) $portOpt : 0); // non-numeric => 0 => fails validation

        return $factory->fromInput(
            (string) ($input->getOption('db-host') ?? $base->host),
            $port,
            (string) ($input->getOption('db-name') ?? $base->database),
            (string) ($input->getOption('db-user') ?? $base->username),
            (string) ($input->getOption('db-password') ?? $base->password),
            $input->getOption('db-schema') ?? $base->schema,
            $input->getOption('db-sslmode') ?? $base->sslMode,
        );
    }

    private function promptForCredentials(PgsqlDatabaseConfigFactory $factory, InputInterface $input): DatabaseConfig
    {
        $host = $this->ask('Postgres host', (string) ($input->getOption('db-host') ?? 'localhost'));
        $port = (int) $this->ask('Postgres port', (string) ($input->getOption('db-port') ?? '5432'));
        $database = $this->ask('Database name', (string) ($input->getOption('db-name') ?? ''));
        $username = $this->ask('Database user', (string) ($input->getOption('db-user') ?? ''));
        $password = $this->secret('Database password');
        $schema = $this->ask('Schema', (string) ($input->getOption('db-schema') ?? 'public'));
        $sslMode = $this->ask(
            'SSL mode (disable/prefer/require)',
            (string) ($input->getOption('db-sslmode') ?? 'prefer'),
        );

        return $factory->fromInput($host, $port, $database, $username, $password, $schema, $sslMode);
    }
}
