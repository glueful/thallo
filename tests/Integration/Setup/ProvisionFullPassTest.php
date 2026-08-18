<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Database\Connection;
use Glueful\Extensions\Schema\MigrationManagerFactory;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

/**
 * Provision acceptance (schema program Task 4 step 7): over THIS repo's real installed manifests,
 * a fresh provision is one complete locked pass — the framework 1.80 installer builds its manager
 * through MigrationManagerFactory, so the app path, every core descriptor (framework leaves, the
 * eight Thallo packs, glueful/tenancy) and every ENABLED extension's schema apply together, and
 * afterwards zero pending files remain across the whole global-source snapshot. The only work
 * deliberately left is the `app:dependent` lane (registered at provider BOOT, which provision
 * never does) — the create-admin catch-up applies exactly that and nothing else: for every core
 * and enabled source it is a no-op belt, not the workhorse. A failing descriptor migration makes
 * provision FAIL rather than declare success.
 */
final class ProvisionFullPassTest extends TestCase
{
    private const PINNED_ENV = ['DB_DRIVER', 'DB_PGSQL_SCHEMA', 'DB_PGSQL_DATABASE'];

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::PINNED_ENV as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        foreach ($this->tempDirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->tempDirs = [];
        if ($this->schema !== null) {
            $this->bootstrapPdo()->exec('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
            $this->schema = null;
        }
    }

    private ?string $schema = null;

    private function bootstrapPdo(): \PDO
    {
        return new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=app_test', 'postgres', 'postgres');
    }

    /**
     * A provision base over THIS repo's real vendor tree: the fixture symlinks vendor/ (real
     * installed.json, real packs) and points the app migrations path at the real
     * database/migrations, but keeps its own .env and sqlite database.
     */
    private function realRepoBase(): string
    {
        $root = dirname(__DIR__, 3);
        $base = sys_get_temp_dir() . '/thallo-provision-' . uniqid('', true);
        $this->tempDirs[] = $base;
        mkdir($base . '/config', 0777, true);
        symlink($root . '/vendor', $base . '/vendor');
        file_put_contents($base . '/.env.example', "APP_ENV=local\nAPP_KEY=\n");
        file_put_contents(
            $base . '/config/app.php',
            "<?php\nreturn ['paths' => ['migrations' => " . var_export($root . '/database/migrations', true) . "]];\n"
        );
        // The REAL enabled extension list (no testing shield): provision must cover every
        // enabled engine's schema, tenancy included.
        copy($root . '/config/extensions.php', $base . '/config/extensions.php');
        return $base;
    }

    private function context(string $base): ApplicationContext
    {
        $context = new ApplicationContext($base);
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing'));
        return $context;
    }

    /**
     * An isolated pgsql schema on the test database — Thallo provision is Postgres-fixed
     * (ProvisionCommand never offers another engine), and the app migrations use pg DDL.
     * The permission-seed migrations construct `new Connection()` internally (env-driven),
     * so the default-connection env is pinned to the same schema for the pass.
     */
    private function pgTarget(): DatabaseConfig
    {
        $this->schema = 'provision_' . substr(md5(uniqid('', true)), 0, 10);
        $this->bootstrapPdo()->exec('CREATE SCHEMA "' . $this->schema . '"');
        putenv('DB_PGSQL_SCHEMA=' . $this->schema);
        $_ENV['DB_PGSQL_SCHEMA'] = $this->schema;
        return new DatabaseConfig(
            'pgsql',
            host: '127.0.0.1',
            port: 5432,
            database: 'app_test',
            username: 'postgres',
            password: 'postgres',
            schema: $this->schema,
        );
    }

    /** @param list<InstallStep> $steps */
    private function step(array $steps, string $name): ?InstallStep
    {
        foreach ($steps as $step) {
            if ($step->name === $name) {
                return $step;
            }
        }
        return null;
    }

    public function testFreshProvisionLeavesZeroPendingAcrossEveryCoreAndEnabledSource(): void
    {
        $base = $this->realRepoBase();
        $context = $this->context($base);
        $target = $this->pgTarget();

        $result = (new Installer($base, $context, skipCacheAndValidation: true))
            ->run(new InstallOptions(database: $target, skipKeys: true));

        $migrate = $this->step($result->steps, 'migrate');
        self::assertSame(InstallStep::OK, $migrate?->status, (string) $migrate?->message);

        $connection = new Connection($target->toConnectionConfig());
        $manager = MigrationManagerFactory::create($context, $connection);
        $snapshot = $manager->globalSources();

        // The snapshot really is the whole fresh-install program: app, the framework leaves,
        // the eight packs, the platform tier, and the SHIPPED-enabled engines.
        foreach (
            [
                'app', 'glueful/framework', 'glueful/framework:extensions',
                'glueful/thallo-render', 'glueful/thallo-tenancy', 'glueful/tenancy',
                'glueful/aegis', 'glueful/users', 'glueful/subscriptions', 'glueful/import-export',
            ] as $expected
        ) {
            self::assertContains($expected, $snapshot, "{$expected} must be in the provision snapshot");
        }
        // …and NOT the disabled engines: commerce/payvia ship disabled, and schema-on-enable
        // means their tables arrive through the executor's migrate-first enable, never provision.
        foreach (['glueful/commerce', 'glueful/payvia'] as $disabled) {
            self::assertNotContains($disabled, $snapshot, "{$disabled} is disabled at provision time");
        }

        self::assertSame(
            [],
            array_map(
                static fn(array $row): string => $row['source'] . '/' . basename($row['file']),
                $manager->pendingForSources($snapshot)
            ),
            'fresh provision leaves zero pending files across every core + enabled source'
        );

        // Spot-proof the pass actually built schema end to end: RBAC, a pack table, and the
        // pack permission seeds (whose internal connections ran against this same database).
        $pdo = $connection->getPDO();
        $tables = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = '" . $this->schema . "'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['permissions', 'collection_definitions', 'render_templates', 'extension_operations'] as $table) {
            self::assertContains($table, $tables, "{$table} must exist after provision");
        }
        $slugCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM permissions WHERE slug IN ('collections.manage', 'templates.manage')"
        )->fetchColumn();
        self::assertSame(2, $slugCount, 'pack permission seeds applied within the same pass');

        // The one deliberate leftover: the app:dependent lane registers at provider BOOT, so
        // provision cannot know it. The create-admin catch-up applies exactly this lane — for
        // every source in the provision snapshot it is a no-op belt, not the workhorse.
        $root = dirname(__DIR__, 3);
        $manager->addMigrationPath(
            $root . '/database/dependent-migrations',
            \Glueful\Database\Migrations\MigrationPriority::DEPENDENT,
            'app:dependent'
        );
        $remaining = $manager->pendingForSources($manager->globalSources());
        self::assertNotEmpty($remaining, 'the dependent lane is the catch-up belt\'s remaining job');
        foreach ($remaining as $row) {
            self::assertSame(
                'app:dependent',
                $row['source'],
                'nothing outside the app:dependent lane may remain after provision'
            );
        }
    }

    public function testAFailingDescriptorMigrationFailsProvisionInsteadOfDeclaringSuccess(): void
    {
        // A synthetic base: one declared core package whose migration throws. Thallo-level
        // acceptance that the installer's truthful-failure contract (proven file-by-file in the
        // framework) holds through the provision entry point this app actually uses.
        $base = sys_get_temp_dir() . '/thallo-provision-fail-' . uniqid('', true);
        $this->tempDirs[] = $base;
        mkdir($base . '/vendor/composer', 0777, true);
        mkdir($base . '/vendor/acme/broken/migrations', 0777, true);
        mkdir($base . '/database/migrations', 0777, true);
        mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/.env.example', "APP_ENV=local\nAPP_KEY=\n");
        file_put_contents(
            $base . '/config/app.php',
            "<?php\nreturn ['paths' => ['migrations' => __DIR__ . '/../database/migrations']];\n"
        );
        $suffix = 'P' . substr(md5($base), 0, 8);
        file_put_contents($base . "/vendor/acme/broken/migrations/001_BrokenFixture{$suffix}.php", <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class BrokenFixture{$suffix} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    throw new \\RuntimeException('provision fixture failure');
                }

                public function down(SchemaBuilderInterface \$schema): void
                {
                }

                public function getDescription(): string
                {
                    return 'fixture';
                }
            }
            PHP);
        file_put_contents(
            $base . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'acme/broken',
                'type' => 'library',
                'install-path' => '../acme/broken',
                'extra' => ['glueful' => ['migrations' => [
                    ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'core'],
                ]]],
            ]]], JSON_UNESCAPED_SLASHES)
        );

        $result = (new Installer($base, $this->context($base), skipCacheAndValidation: true))->run(
            new InstallOptions(
                database: new DatabaseConfig('sqlite', database: $base . '/db.sqlite'),
                skipKeys: true
            )
        );

        $migrate = $this->step($result->steps, 'migrate');
        self::assertSame(InstallStep::FAILED, $migrate?->status, 'provision must fail, never declare success');
        self::assertStringContainsString("001_BrokenFixture{$suffix}", (string) $migrate?->message);
        self::assertStringContainsString('provision fixture failure', (string) $migrate?->message);
        self::assertFalse($result->ok, 'the overall provision result is a failure');
    }
}
