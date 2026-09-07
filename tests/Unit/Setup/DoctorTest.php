<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Doctor;
use Glueful\Installer\ConnectionTester;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    /** @return array<string, Check> name => Check */
    private function byName(array $checks): array
    {
        $out = [];
        foreach ($checks as $c) {
            $out[$c->name] = $c;
        }
        return $out;
    }

    private function tempProject(bool $withEnv, bool $withExample): string
    {
        $dir = sys_get_temp_dir() . '/doctor_' . uniqid('', true);
        mkdir($dir . '/storage', 0755, true);
        if ($withEnv) {
            file_put_contents($dir . '/.env', "APP_ENV=testing\n");
        }
        if ($withExample) {
            file_put_contents($dir . '/.env.example', "APP_ENV=local\n");
        }
        return $dir;
    }

    private function tempProjectWithEnv(string $env): string
    {
        $dir = $this->tempProject(withEnv: false, withExample: true);
        file_put_contents($dir . '/.env', $env);
        return $dir;
    }

    public function testDevelopmentModeWithAPublicBaseUrlWarns(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=development\nBASE_URL=https://thallo.dev\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::WARN, $checks['environment']->status);
        self::assertStringContainsString('thallo.dev', $checks['environment']->message);
        self::assertStringContainsString('APP_ENV=production', $checks['environment']->message);
    }

    public function testProductionModeWithAPublicBaseUrlIsOk(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nBASE_URL=https://thallo.dev\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['environment']->status);
    }

    public function testDevelopmentModeWithALocalBaseUrlIsOk(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=development\nBASE_URL=http://localhost:8000\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['environment']->status);
    }

    public function testEnvironmentCheckIsSkippedWithoutAnEnvFile(): void
    {
        $dir = $this->tempProject(withEnv: false, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertArrayNotHasKey('environment', $checks);
    }

    public function testPhpVersionBelowMinimumFails(): void
    {
        $dir = $this->tempProject(withEnv: true, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.2.9', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::FAIL, $checks['php']->status);
    }

    public function testPhpVersionAtMinimumPasses(): void
    {
        $dir = $this->tempProject(withEnv: true, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['php']->status);
    }

    public function testMissingPdoPgsqlExtensionFails(): void
    {
        $dir = $this->tempProject(withEnv: true, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['json', 'mbstring']))->preflight());

        self::assertSame(Check::FAIL, $checks['ext:pdo_pgsql']->status);
    }

    public function testEnvTargetOkWhenEnvAbsentButExampleReadable(): void
    {
        // Fresh checkout: no .env yet. Target is writable iff root is writable AND .env.example exists.
        $dir = $this->tempProject(withEnv: false, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['env-target']->status);
    }

    public function testEnvTargetFailsWhenEnvAndExampleBothAbsent(): void
    {
        $dir = $this->tempProject(withEnv: false, withExample: false);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::FAIL, $checks['env-target']->status);
    }

    public function testReachabilityFailsFastForUnreachableHost(): void
    {
        $dir = $this->tempProject(withEnv: true, withExample: true);
        // RFC 5737 TEST-NET-1: guaranteed unroutable, so the short connect timeout trips quickly.
        $cfg = new DatabaseConfig('pgsql', '192.0.2.1', 5432, 'thallo', 'u', 'p');
        $check = (new Doctor($dir, '8.3.0', ['pdo_pgsql']))->reachability($cfg, new ConnectionTester(null, 2));

        self::assertSame(Check::FAIL, $check->status);
        self::assertStringContainsStringIgnoringCase('connect', $check->message);
    }

    public function testKeysWarnWhenEnvAbsent(): void
    {
        $dir = $this->tempProject(withEnv: false, withExample: true);
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::WARN, $checks['keys']->status);
    }

    public function testKeysWarnWhenAnyKeyMissing(): void
    {
        $dir = $this->tempProject(withEnv: false, withExample: true);
        // .env present with APP_KEY only — TOKEN_SALT and JWT_KEY missing.
        file_put_contents($dir . '/.env', "APP_KEY=abc\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::WARN, $checks['keys']->status);
        self::assertStringContainsString('TOKEN_SALT', $checks['keys']->message);
        self::assertStringContainsString('JWT_KEY', $checks['keys']->message);
    }

    public function testKeysOkWhenAllThreePresent(): void
    {
        $dir = $this->tempProject(withEnv: false, withExample: true);
        file_put_contents($dir . '/.env', "APP_KEY=a\nTOKEN_SALT=b\nJWT_KEY=c\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['keys']->status);
    }
}
