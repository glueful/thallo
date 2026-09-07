<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Setup\PgsqlDatabaseConfigFactory;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

final class PgsqlDatabaseConfigFactoryTest extends TestCase
{
    public function testFromInputHardcodesPgsqlEngine(): void
    {
        $cfg = (new PgsqlDatabaseConfigFactory())->fromInput(
            host: 'db.internal',
            port: 5433,
            database: 'thallo',
            username: 'app_user',
            password: 's3cr3t',
            schema: 'public',
            sslMode: 'require',
        );

        self::assertSame('pgsql', $cfg->engine);
        self::assertSame('db.internal', $cfg->host);
        self::assertSame(5433, $cfg->port);
        self::assertSame('thallo', $cfg->database);
        self::assertSame('app_user', $cfg->username);
        self::assertSame('s3cr3t', $cfg->password);
        self::assertSame('public', $cfg->schema);
        self::assertSame('require', $cfg->sslMode);
    }

    public function testFromEnvReadsPgsqlKeys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "");
        $env = new EnvWriter($path);
        $env->setMany([
            'DB_DRIVER' => 'pgsql',
            'DB_PGSQL_HOST' => 'localhost',
            'DB_PGSQL_PORT' => '5432',
            'DB_PGSQL_DATABASE' => 'thallo',
            'DB_PGSQL_USERNAME' => 'app_user',
            'DB_PGSQL_PASSWORD' => 'pw with spaces',
            'DB_PGSQL_SCHEMA' => 'public',
            'DB_PGSQL_SSL_MODE' => 'prefer',
        ]);

        $cfg = (new PgsqlDatabaseConfigFactory())->fromEnv($env);

        self::assertSame('pgsql', $cfg->engine);
        self::assertSame('localhost', $cfg->host);
        self::assertSame(5432, $cfg->port);
        self::assertSame('thallo', $cfg->database);
        self::assertSame('app_user', $cfg->username);
        self::assertSame('pw with spaces', $cfg->password);
        self::assertSame('public', $cfg->schema);
        self::assertSame('prefer', $cfg->sslMode);

        @unlink($path);
    }

    public function testFromEnvDefaultsPortAndNullsOptionalKeys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "");
        $env = new EnvWriter($path);
        $env->setMany([
            'DB_PGSQL_HOST' => 'localhost',
            'DB_PGSQL_DATABASE' => 'thallo',
            'DB_PGSQL_USERNAME' => 'u',
            'DB_PGSQL_PASSWORD' => 'p',
        ]);

        $cfg = (new PgsqlDatabaseConfigFactory())->fromEnv($env);

        self::assertSame(5432, $cfg->port);     // default when DB_PGSQL_PORT absent
        self::assertNull($cfg->schema);         // absent optional => null
        self::assertNull($cfg->sslMode);

        @unlink($path);
    }

    public function testRequiredFieldErrorsIsEmptyForCompleteConfig(): void
    {
        $cfg = new DatabaseConfig('pgsql', 'localhost', 5432, 'thallo', 'u', 'p');
        self::assertSame([], (new PgsqlDatabaseConfigFactory())->requiredFieldErrors($cfg, passwordProvided: true));
    }

    public function testRequiredFieldErrorsListsEveryMissingOrInvalidField(): void
    {
        // Empty host/db/user/pass and a zero port (what fromEnv yields from a blank/partial .env).
        $cfg = new DatabaseConfig('pgsql', '', 0, '', '', '');
        $errors = (new PgsqlDatabaseConfigFactory())->requiredFieldErrors($cfg, passwordProvided: false);

        self::assertCount(5, $errors);
        foreach (['host', 'port', 'database', 'username', 'password'] as $field) {
            self::assertNotEmpty(
                array_filter($errors, static fn (string $e): bool => str_starts_with($e, $field)),
                "expected a '{$field}' error",
            );
        }
    }

    public function testFromEnvTreatsNonNumericPortAsInvalid(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "");
        $env = new EnvWriter($path);
        $env->setMany([
            'DB_PGSQL_HOST' => 'localhost',
            'DB_PGSQL_PORT' => '5432abc',   // non-numeric: must NOT truncate to 5432
            'DB_PGSQL_DATABASE' => 'thallo',
            'DB_PGSQL_USERNAME' => 'u',
            'DB_PGSQL_PASSWORD' => 'p',
        ]);

        $factory = new PgsqlDatabaseConfigFactory();
        $cfg = $factory->fromEnv($env);

        self::assertSame(0, $cfg->port, 'a non-numeric port becomes 0, not 5432');
        self::assertNotEmpty(
            array_filter(
                $factory->requiredFieldErrors($cfg, passwordProvided: true),
                static fn (string $e): bool => str_starts_with($e, 'port'),
            ),
            'an invalid port must fail loudly',
        );

        @unlink($path);
    }

    public function testFromEnvDefaultsHostOnlyWhenAbsent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "");
        $env = new EnvWriter($path);
        $env->setMany([
            'DB_PGSQL_DATABASE' => 'thallo',
            'DB_PGSQL_USERNAME' => 'u',
        ]);

        $cfg = (new PgsqlDatabaseConfigFactory())->fromEnv($env);
        self::assertSame('localhost', $cfg->host, 'absent host defaults to localhost');

        $env->setMany(['DB_PGSQL_HOST' => '']);
        $cfg = (new PgsqlDatabaseConfigFactory())->fromEnv($env);
        self::assertSame('', $cfg->host, 'an explicitly empty host must stay invalid, not default');

        @unlink($path);
    }

    public function testPasswordProvidedInEnvDistinguishesAbsentFromExplicitlyEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "");
        $env = new EnvWriter($path);
        $factory = new PgsqlDatabaseConfigFactory();

        self::assertFalse($factory->passwordProvidedInEnv($env), 'absent line means not provided');

        $env->setMany(['DB_PGSQL_PASSWORD' => '']);
        self::assertTrue($factory->passwordProvidedInEnv($env), 'a present-but-empty line means "none", provided');

        $env->setMany(['DB_PGSQL_PASSWORD' => 'pw']);
        self::assertTrue($factory->passwordProvidedInEnv($env));

        @unlink($path);
    }

    public function testProvidedEmptyPasswordValidatesForTrustAuth(): void
    {
        $factory = new PgsqlDatabaseConfigFactory();
        $cfg = new DatabaseConfig('pgsql', 'localhost', 5432, 'thallo', 'postgres', '');

        self::assertSame([], $factory->requiredFieldErrors($cfg, passwordProvided: true));
        self::assertNotEmpty(
            array_filter(
                $factory->requiredFieldErrors($cfg, passwordProvided: false),
                static fn (string $e): bool => str_starts_with($e, 'password'),
            ),
            'an unprovided password must still refuse',
        );
    }
    /** @dataProvider providedConfigs */
    public function testIsProvidedRejectsPlaceholdersAndBlanks(
        string $db,
        string $user,
        string $pw,
        bool $expected,
    ): void {
        $cfg = new DatabaseConfig('pgsql', 'localhost', 5432, $db, $user, $pw);

        self::assertSame($expected, (new PgsqlDatabaseConfigFactory())->isProvided($cfg));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function providedConfigs(): iterable
    {
        yield 'real values' => ['thallo', 'thallo_user', 's3cr3t', true];
        yield 'real values, empty password (trust auth)' => ['thallo', 'postgres', '', true];
        yield 'placeholder database' => ['your_database_name', 'thallo_user', 's3cr3t', false];
        yield 'placeholder username' => ['thallo', 'your_database_user', 's3cr3t', false];
        yield 'placeholder password' => ['thallo', 'thallo_user', 'your_database_password', false];
        yield 'empty database' => ['', 'thallo_user', 's3cr3t', false];
        yield 'empty username' => ['thallo', '', 's3cr3t', false];
    }
}
