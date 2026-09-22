<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * A relative LOG_FILE_PATH was used as given, and a web request's working directory is public/
 * (PHP changes to the script's directory for every SAPI but the CLI). So `.env.example`'s
 * `LOG_FILE_PATH=storage/logs` wrote every request's log into public/storage/logs/, where the web
 * server serves it: thallo.dev served a 22 MB framework.log to anyone. A relative path now means
 * relative to the site, whatever the process's directory.
 */
final class LogDirectoryTest extends TestCase
{
    /** @var array{0: mixed, 1: mixed, 2: string|false} */
    private array $saved;

    protected function setUp(): void
    {
        $this->saved = [$_ENV['LOG_FILE_PATH'] ?? null, $_SERVER['LOG_FILE_PATH'] ?? null, getenv('LOG_FILE_PATH')];
    }

    protected function tearDown(): void
    {
        [$env, $server, $process] = $this->saved;
        unset($_ENV['LOG_FILE_PATH'], $_SERVER['LOG_FILE_PATH']);
        if ($env !== null) {
            $_ENV['LOG_FILE_PATH'] = $env;
        }
        if ($server !== null) {
            $_SERVER['LOG_FILE_PATH'] = $server;
        }
        putenv($process === false ? 'LOG_FILE_PATH' : 'LOG_FILE_PATH=' . $process);
    }

    private function logDirectory(string $file, ?string $value): string
    {
        if ($value === null) {
            unset($_ENV['LOG_FILE_PATH'], $_SERVER['LOG_FILE_PATH']);
            putenv('LOG_FILE_PATH');
        } else {
            $_ENV['LOG_FILE_PATH'] = $_SERVER['LOG_FILE_PATH'] = $value;
            putenv('LOG_FILE_PATH=' . $value);
        }
        $config = require $file;
        return (string) $config['paths']['log_directory'];
    }

    public function testARelativePathIsTheSitesNotTheProcesss(): void
    {
        foreach (['config', 'skeleton/config'] as $dir) {
            $file = dirname(__DIR__, 3) . "/{$dir}/logging.php";
            $site = dirname($file, 2);
            $cwd = getcwd();
            chdir(sys_get_temp_dir()); // a web request's directory is public/, not the site
            try {
                self::assertSame("{$site}/storage/logs/", $this->logDirectory($file, 'storage/logs'), $dir);
                self::assertSame("{$site}/storage/logs/", $this->logDirectory($file, null), $dir);
                self::assertSame('/var/log/thallo/', $this->logDirectory($file, '/var/log/thallo'), $dir);
            } finally {
                chdir((string) $cwd);
            }
        }
    }

    public function testTheShippedEnvironmentDoesNotSetTheLogDirectory(): void
    {
        foreach (['.env.example', 'skeleton/.env.example'] as $file) {
            $env = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            self::assertDoesNotMatchRegularExpression('/^LOG_FILE_PATH=/m', $env, $file);
        }
    }
}
