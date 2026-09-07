<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Setup\Console\ProvisionCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Covers the "fail loudly before any side effect" guarantee. An invalid DB field (here a
 * non-numeric --db-port override) must abort BEFORE the framework Installer runs — so the
 * command never writes .env or migrates against the real repo during the test.
 */
final class ProvisionCommandTest extends AppTestCase
{
    public function testInvalidDbPortFailsBeforeInstaller(): void
    {
        $command = new ProvisionCommand($this->container(), self::$app);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--db-port' => 'not-a-number'], ['interactive' => false]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('port', $tester->getDisplay());
    }
    /**
     * A .env that already carries real DB_PGSQL_* values gets ONE confirmation screen instead
     * of seven prompts. Every scenario passes an invalid --db-port so the run aborts on
     * validation BEFORE the Installer — no test touches the real .env or database.
     */
    public function testProvidedEnvShowsAConfirmationScreenAndAcceptsOnYes(): void
    {
        $tester = $this->testerWithEnv(<<<ENV
            DB_PGSQL_HOST=db.internal
            DB_PGSQL_PORT=5433
            DB_PGSQL_DATABASE=thallo_site
            DB_PGSQL_USERNAME=site_user
            DB_PGSQL_PASSWORD=hunter2
            ENV);
        $tester->setInputs(['yes']);

        $exit = $tester->execute(['--db-port' => 'not-a-number'], ['interactive' => true]);
        $display = $tester->getDisplay();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Use these settings', $display);
        self::assertStringContainsString('db.internal', $display);
        self::assertStringContainsString('thallo_site', $display);
        self::assertStringContainsString('site_user', $display);
        self::assertStringNotContainsString('hunter2', $display, 'the password is never echoed');
        self::assertStringNotContainsString('Postgres host', $display, 'no per-field prompts on yes');
        self::assertStringContainsString('port', $display, 'aborted on validation, before the Installer');
    }

    public function testDecliningTheConfirmationRepromptsWithTheEnvValuesAsDefaults(): void
    {
        $tester = $this->testerWithEnv(<<<ENV
            DB_PGSQL_HOST=db.internal
            DB_PGSQL_PORT=5433
            DB_PGSQL_DATABASE=thallo_site
            DB_PGSQL_USERNAME=site_user
            DB_PGSQL_PASSWORD=hunter2
            ENV);
        // no → host(default) → port(default) → database(edited) → user(default) → password(keep) → schema → ssl
        $tester->setInputs(['no', '', '', 'other_site', '', '', '', '']);

        $exit = $tester->execute(['--db-port' => 'not-a-number'], ['interactive' => true]);
        $display = $tester->getDisplay();

        self::assertSame(1, $exit);
        self::assertStringContainsString('[db.internal]', $display, 'host prompt is prefilled from .env');
        self::assertStringContainsString('[thallo_site]', $display, 'database prompt is prefilled from .env');
        self::assertStringContainsString('[site_user]', $display, 'user prompt is prefilled from .env');
        self::assertStringContainsString('port', $display, 'aborted on validation, before the Installer');
    }

    public function testPlaceholderEnvGoesStraightToThePrompts(): void
    {
        $tester = $this->testerWithEnv(<<<ENV
            DB_PGSQL_HOST=localhost
            DB_PGSQL_PORT=5432
            DB_PGSQL_DATABASE=your_database_name
            DB_PGSQL_USERNAME=your_database_user
            DB_PGSQL_PASSWORD=your_database_password
            ENV);
        $tester->setInputs(['', '', 'thallo', 'thallo_user', 'pw', '', '']);

        $exit = $tester->execute(['--db-port' => 'not-a-number'], ['interactive' => true]);
        $display = $tester->getDisplay();

        self::assertSame(1, $exit);
        self::assertStringNotContainsString('Use these settings', $display);
        self::assertStringContainsString('Postgres host [localhost]', $display);
        self::assertStringContainsString('port', $display);
    }

    private function testerWithEnv(string $contents): CommandTester
    {
        $path = tempnam(sys_get_temp_dir(), 'thallo-env');
        self::assertIsString($path);
        file_put_contents($path, $contents . "\n");
        $this->envFiles[] = $path;

        $command = new ProvisionCommand($this->container(), self::$app, envPath: $path);

        return new CommandTester($command);
    }

    /** @var list<string> */
    private array $envFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->envFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }
}
