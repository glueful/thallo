<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * scripts/deploy-site deploys a Thallo site FROM A TAG (charter: website-from-tag gate;
 * decision 10: until the package split, this is the upgrade path — `composer update` cannot
 * move a create-project root package). Only the guard rails and the dry run are tested here;
 * the real flow is exercised on the dogfood host.
 */
final class DeploySiteScriptTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 3) . '/scripts/deploy-site';
        self::assertFileExists($this->script);
    }

    /** @return array{int, string} exit code and combined output */
    private function deploy(string $args, string $deployRoot): array
    {
        $cmd = sprintf(
            'DEPLOY_ROOT=%s bash %s %s 2>&1',
            escapeshellarg($deployRoot),
            escapeshellarg($this->script),
            $args,
        );
        exec($cmd, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private function tempRoot(): string
    {
        $dir = sys_get_temp_dir() . '/thallo-deploy-' . uniqid();
        mkdir($dir, 0755, true);

        return $dir;
    }

    public function testRefusesAnythingThatIsNotAReleaseTag(): void
    {
        $root = $this->tempRoot();

        [$code, $out] = $this->deploy('', $root);
        self::assertSame(2, $code);
        self::assertStringContainsString('Usage:', $out);

        [$code, $out] = $this->deploy('main', $root);
        self::assertSame(2, $code);
        self::assertStringContainsString('not a release tag', $out);

        [$code, $out] = $this->deploy('1.0.0-beta.20', $root);
        self::assertSame(2, $code, 'the v prefix is part of the tag name');
        self::assertStringContainsString('not a release tag', $out);

        [$code, $out] = $this->deploy('"v1.0.0-beta.20; rm -rf /"', $root);
        self::assertSame(2, $code);
        self::assertStringContainsString('not a release tag', $out);

        self::assertSame([], glob($root . '/*'), 'a refused run touches nothing');
    }

    public function testDryRunPrintsEveryStepAndTouchesNothing(): void
    {
        $root = $this->tempRoot();

        [$code, $out] = $this->deploy('--dry-run v1.0.0-beta.20', $root);

        self::assertSame(0, $code, $out);
        foreach (
            [
                'git clone',
                'v1.0.0-beta.20',
                'releases/v1.0.0-beta.20',
                'composer install --no-dev',
                'thallo:provision',
                'route:cache:clear',
                'ln -s',                 // the current → release switch
                'php8.4-fpm',            // the FPM reload
                'thallo:doctor',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $out, "dry run must show: {$needle}");
        }
        self::assertSame([], glob($root . '/*'), 'a dry run touches nothing');
    }

    public function testSharedFilesAreListedSoAnOperatorKnowsWhatSurvivesADeploy(): void
    {
        [$code, $out] = $this->deploy('--dry-run v1.0.0-beta.20', $this->tempRoot());

        self::assertSame(0, $code);
        foreach (['shared/.env', 'shared/storage', 'shared/themes'] as $needle) {
            self::assertStringContainsString($needle, $out);
        }
    }
}
