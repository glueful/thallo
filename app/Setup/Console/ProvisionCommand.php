<?php

declare(strict_types=1);

namespace App\Setup\Console;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Doctor;
use App\Setup\PgsqlDatabaseConfigFactory;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\EnvWriter;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function base_path;

#[AsCommand(
    name: 'thallo:provision',
    description: 'Configure the database + security keys and run migrations (Layer 1; no admin)',
)]
final class ProvisionCommand extends BaseCommand
{
    /** @param string|null $envPath Override the .env location (tests); null = <base>/.env. */
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
        private readonly ?string $envPath = null,
    ) {
        parent::__construct($container, $context);
    }

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
        $env = new EnvWriter($this->envPath ?? $basePath . '/.env');

        // 2. Build the pgsql DatabaseConfig (engine is fixed; never prompted).
        //    Interactive + a .env that already holds real values: ONE confirmation screen; a
        //    "no" re-prompts with those values prefilled. Otherwise the plain prompts.
        $fromEnv = $this->fromEnvWithOverrides($factory, $env, $input);
        if ($quiet) {
            $database = $fromEnv;
        } elseif ($factory->isProvided($fromEnv) && $this->confirmSettings($fromEnv)) {
            $database = $fromEnv;
        } else {
            $database = $this->promptForCredentials(
                $factory,
                $input,
                $factory->isProvided($fromEnv) ? $fromEnv : null,
            );
        }

        // Presence is tracked separately from the value: --db-password="" or a
        // present-but-empty .env line means "none" (trust-auth), which validates;
        // interactive mode always asks, so the answer is always provided.
        $passwordProvided = !$quiet
            || $input->getOption('db-password') !== null
            || $factory->passwordProvidedInEnv($env);

        // 3. Validate — fail LOUDLY before the Installer touches anything.
        $missing = $factory->requiredFieldErrors($database, $passwordProvided);
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

    /** Show the settings .env already holds and ask once. The password is never echoed. */
    private function confirmSettings(DatabaseConfig $config): bool
    {
        $this->line('Database settings found in .env:');
        $this->table(['Setting', 'Value'], [
            ['Host', $config->host],
            ['Port', (string) $config->port],
            ['Database', $config->database],
            ['User', $config->username],
            ['Password', $config->password === '' ? '(none — trust auth)' : str_repeat('•', 8)],
            ['Schema', $config->schema ?? 'public'],
            ['SSL mode', $config->sslMode ?? 'prefer'],
        ]);

        return $this->confirm('Use these settings? (No = review each one)', true);
    }

    /**
     * Ask for each field. With $defaults (declined confirmation) every prompt is prefilled from
     * .env, so changing one field is Enter through the rest; the password prompt keeps the
     * stored one when left empty.
     */
    private function promptForCredentials(
        PgsqlDatabaseConfigFactory $factory,
        InputInterface $input,
        ?DatabaseConfig $defaults = null,
    ): DatabaseConfig {
        $opt = static fn (string $name, ?string $fallback): ?string => $input->getOption($name) ?? $fallback;

        $host = $this->ask('Postgres host', (string) $opt('db-host', $defaults?->host ?? 'localhost'));
        $port = (int) $this->ask(
            'Postgres port',
            (string) $opt('db-port', $defaults !== null ? (string) $defaults->port : '5432'),
        );
        $database = $this->ask('Database name', (string) $opt('db-name', $defaults?->database ?? ''));
        $username = $this->ask('Database user', (string) $opt('db-user', $defaults?->username ?? ''));
        $password = $defaults === null
            ? $this->secret('Database password')
            : $this->secretOrKeep(
                'Database password (leave empty to keep the current one)',
                $defaults->password,
            );
        $schema = $this->ask('Schema', (string) $opt('db-schema', $defaults?->schema ?? 'public'));
        $sslMode = $this->ask(
            'SSL mode (disable/prefer/require)',
            (string) $opt('db-sslmode', $defaults?->sslMode ?? 'prefer'),
        );

        return $factory->fromInput($host, $port, $database, $username, $password, $schema, $sslMode);
    }

    private function secretOrKeep(string $question, string $current): string
    {
        try {
            $entered = $this->secret($question);
        } catch (\RuntimeException) {
            return $current; // empty/cancelled hidden input keeps the stored password
        }

        return $entered === '' ? $current : $entered;
    }
}
