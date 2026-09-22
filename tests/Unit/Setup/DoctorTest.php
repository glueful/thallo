<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use Thallo\Core\Setup\Doctor\Check;
use Thallo\Core\Setup\Doctor\Doctor;
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

    // ── asset routing probe ────────────────────────────────────────────────────

    public function testAssetRoutingWarnsWhenTheWebServerAnswers404ForAPhpServedAsset(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nBASE_URL=https://thallo.dev\n");
        $probed = [];
        $doctor = new Doctor($dir, '8.3.0', ['pdo_pgsql'], static function (string $url) use (&$probed): ?int {
            $probed[] = $url;
            return 404;
        });

        $check = $this->byName($doctor->preflight())['asset-routing'];

        self::assertSame(Check::WARN, $check->status);
        self::assertStringContainsString('/theme-assets/', $check->message);
        self::assertStringContainsString('docs/production.md', $check->message);
        self::assertSame(
            [
                'https://thallo.dev/theme-assets/site.css?t=default',
                'https://thallo.dev/v1/admin/render/templates/custom.css?theme=default',
            ],
            $probed,
        );
    }

    public function testApiRoutingWarnsWhenAFileShapedApiPathIsServedFromDisk(): void
    {
        // A static-file location that matches *.css takes the custom-stylesheet template path
        // before PHP sees it: 404 on GET, 405 on PUT (nginx). A 401 means the request reached the
        // API (the probe is anonymous), which is the healthy answer.
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nBASE_URL=https://thallo.dev\n");
        $doctor = new Doctor($dir, '8.3.0', ['pdo_pgsql'], static fn (string $url): ?int =>
            str_contains($url, '/theme-assets/') ? 200 : 404);

        $checks = $this->byName($doctor->preflight());

        self::assertSame(Check::OK, $checks['asset-routing']->status);
        self::assertSame(Check::WARN, $checks['api-routing']->status);
        self::assertStringContainsString('/v1/', $checks['api-routing']->message);
        self::assertStringContainsString('docs/production.md', $checks['api-routing']->message);

        $healthy = new Doctor($dir, '8.3.0', ['pdo_pgsql'], static fn (string $url): ?int =>
            str_contains($url, '/theme-assets/') ? 200 : 401);
        self::assertSame(Check::OK, $this->byName($healthy->preflight())['api-routing']->status);
    }

    public function testAssetRoutingIsOkWhenThePhpServedAssetIsReachable(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nBASE_URL=https://thallo.dev\n");
        $doctor = new Doctor($dir, '8.3.0', ['pdo_pgsql'], static fn (string $url): ?int => 200);

        self::assertSame(Check::OK, $this->byName($doctor->preflight())['asset-routing']->status);
    }

    public function testAssetRoutingIsSkippedForLocalHostsAndUnreachableServers(): void
    {
        $local = $this->tempProjectWithEnv("APP_ENV=development\nBASE_URL=http://localhost:8000\n");
        $calls = 0;
        $doctor = new Doctor($local, '8.3.0', ['pdo_pgsql'], static function () use (&$calls): ?int {
            $calls++;
            return 404;
        });
        self::assertArrayNotHasKey('asset-routing', $this->byName($doctor->preflight()));
        self::assertSame(0, $calls, 'a local BASE_URL is never probed');

        $public = $this->tempProjectWithEnv("APP_ENV=production\nBASE_URL=https://thallo.dev\n");
        $doctor = new Doctor($public, '8.3.0', ['pdo_pgsql'], static fn (string $url): ?int => null);
        self::assertArrayNotHasKey('asset-routing', $this->byName($doctor->preflight()), 'unreachable => no verdict');
    }

    public function testTheShippedThemeVocabularyIsOk(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::OK, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('default', $checks['theme-vocabulary']->message);
    }

    public function testAnAppThemeWithoutAVocabularyFailsBeforeActivation(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nRENDER_THEME=custom\n");
        mkdir($dir . '/themes/custom/templates', 0755, true);
        file_put_contents($dir . '/themes/custom/theme.json', json_encode(['name' => 'custom']));
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('vocabulary is missing', $checks['theme-vocabulary']->message);
        self::assertStringContainsString('RENDER_THEME', $checks['theme-vocabulary']->message);
    }

    public function testAMissingAppThemeDirectoryFails(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nRENDER_THEME=ghost\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('themes/ghost', $checks['theme-vocabulary']->message);
    }

    public function testTheThemeChosenOnTheAppearancePageIsCheckedOverRenderTheme(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\n");
        mkdir($dir . '/themes/custom/templates', 0755, true);
        file_put_contents($dir . '/themes/custom/theme.json', json_encode(['name' => 'custom']));
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql'], null, 'custom'))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('"custom"', $checks['theme-vocabulary']->message);
        self::assertStringContainsString('Appearance', $checks['theme-vocabulary']->message);
        self::assertStringContainsString('RENDER_THEME=default', $checks['theme-vocabulary']->message);
    }

    public function testAMissingAppearanceThemeSaysTheSiteFallsBack(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql'], null, 'ghost'))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('themes/ghost', $checks['theme-vocabulary']->message);
        self::assertStringContainsString('Appearance', $checks['theme-vocabulary']->message);
    }

    public function testAnUnsafeAppearanceThemeNameIsRefusedWithoutTouchingThePath(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql'], null, '../etc'))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('not a valid theme name', $checks['theme-vocabulary']->message);
    }

    public function testAValidAppearanceThemeIsOkAndNamesItsSource(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nRENDER_THEME=ghost\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql'], null, 'default'))->preflight());

        self::assertSame(Check::OK, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('Appearance', $checks['theme-vocabulary']->message);
    }

    public function testNoStoredChoiceFallsBackToRenderTheme(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nRENDER_THEME=ghost\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql'], null, ''))->preflight());

        self::assertSame(Check::FAIL, $checks['theme-vocabulary']->status);
        self::assertStringContainsString('RENDER_THEME=ghost', $checks['theme-vocabulary']->message);
    }

    public function testTheStyleArtifactCheckWarnsUntilProvisionCompilesIt(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\n");
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());
        self::assertSame(Check::WARN, $checks['style-artifact']->status);
        self::assertStringContainsString('thallo:provision', $checks['style-artifact']->message);

        $json = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/theme.json',
        ), true);
        $hash = \Thallo\Render\Style\StyleCompiler::hash(\Thallo\Render\Style\ThemeVocabulary::fromThemeJson(
            $json,
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default',
        ));
        mkdir($dir . '/storage/cache/style', 0755, true);
        file_put_contents($dir . '/storage/cache/style/settings-' . $hash . '.css', '@layer settings {}');
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());
        self::assertSame(Check::OK, $checks['style-artifact']->status);
        self::assertStringContainsString('settings-' . $hash . '.css', $checks['style-artifact']->message);
    }

    public function testTheStyleArtifactCheckIsSkippedWhenTheVocabularyIsBroken(): void
    {
        $dir = $this->tempProjectWithEnv("APP_ENV=production\nRENDER_THEME=custom\n");
        mkdir($dir . '/themes/custom/templates', 0755, true);
        file_put_contents($dir . '/themes/custom/theme.json', json_encode(['name' => 'custom']));
        $checks = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());
        self::assertArrayNotHasKey('style-artifact', $checks, 'the vocabulary failure is the verdict');
    }

    public function testALogFileUnderTheWebRootWarnsAndSaysWhatToDelete(): void
    {
        // A relative LOG_FILE_PATH once wrote request logs into public/storage/logs/, which the web
        // server serves. The config no longer does it; a site that already has such files must
        // be told, because nothing else will remove them.
        $dir = $this->tempProject(withEnv: true, withExample: true);
        $clean = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());
        self::assertSame(Check::OK, $clean['log-exposure']->status);

        mkdir($dir . '/public/storage/logs', 0755, true);
        file_put_contents($dir . '/public/storage/logs/framework.log', 'x');
        $exposed = $this->byName((new Doctor($dir, '8.3.0', ['pdo_pgsql']))->preflight());
        self::assertSame(Check::WARN, $exposed['log-exposure']->status);
        self::assertStringContainsString('public/storage/logs/framework.log', $exposed['log-exposure']->message);
        self::assertStringContainsString('LOG_FILE_PATH', $exposed['log-exposure']->message);
    }
}
