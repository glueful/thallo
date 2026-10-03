<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\Console\CapabilitiesCommand;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Setup\CapabilityProvisioning;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;
use Thallo\Core\Tests\Support\RestoresPermissionRows;

/**
 * The feature-owned CLI (feature activation spec §3.9): `thallo:capabilities:enable` runs an
 * activation to the end, verifying in a fresh process it starts itself; `--prepare` stops before
 * the runtime steps (deploy time); `resume` finishes, also on a host whose application files are
 * read-only; provision resumes open activations in a child process and puts back required
 * providers; `thallo:capabilities` can't turn an activation capability on directly.
 *
 * The commands run as real `php glueful` processes in the testing environment. The engine's
 * enabled list is a temp file (THALLO_TEST_EXTENSIONS_CONFIG) and the extension cache is never
 * rebuilt (THALLO_TEST_SKIP_CACHE_REBUILD), so no run writes the application's own files.
 */
final class CapabilitiesCommandsTest extends AppTestCase
{
    use ActivationRunners;
    use ResetsCommerceActivation;
    use RestoresPermissionRows;

    private const ID = 'thallo.commerce';
    private const TEST_ENV = [
        'THALLO_TEST_EXTENSIONS_CONFIG',
        'THALLO_TEST_SKIP_CACHE_REBUILD',
        'THALLO_TEST_APP_FILES_READONLY',
    ];
    private const READ_ONLY = ['THALLO_TEST_APP_FILES_READONLY' => '1'];

    /** @var list<string> what provision's steps printed */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $this->resetCommerceActivation();
    }

    protected function tearDown(): void
    {
        foreach (self::TEST_ENV as $name) {
            putenv($name);
        }
        $this->resetCommerceActivation();
        $this->restorePermissionRows();
        $this->removeActivationTempFiles();
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    /**
     * Runs `php glueful …` against the temp enabled list.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit code, stdout and stderr
     */
    private function glueful(array $args, string $config, array $env = []): array
    {
        $vars = getenv();
        $vars['APP_ENV'] = 'testing';
        $vars['THALLO_TEST_EXTENSIONS_CONFIG'] = $config;
        $vars['THALLO_TEST_SKIP_CACHE_REBUILD'] = '1';
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/glueful', ...$args, '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            [...$vars, ...$env],
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    /** @return list<string> */
    private function listed(string $config): array
    {
        return (array) (require $config)['enabled'];
    }

    // ── capabilities:enable / resume ───────────────────────────────────────────────────

    public function testEnableRunsToTheEndThroughAFreshProcess(): void
    {
        $config = $this->tempExtensionsConfig([self::COMMERCE_PROVIDER]);
        [$code, $out] = $this->glueful(['thallo:capabilities:enable', self::ID], $config);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Continuing in a fresh process', $out);
        self::assertStringContainsString('Commerce is on.', $out);
        self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find(self::ID)->status);
        self::assertTrue($this->states()->fresh(self::ID));
    }

    public function testResumeAfterAnEngineFailureRetriesInOneProcessAndVerifiesInAnother(): void
    {
        $config = $this->tempExtensionsConfig([]);
        [$code, $out] = $this->glueful(['thallo:capabilities:enable', self::ID], $config, self::READ_ONLY);
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('--prepare', $out);
        self::assertSame(ActivationStep::ENABLE_ENGINE, $this->store()->find(self::ID)->failedStep);

        [$code, $out] = $this->glueful(['thallo:capabilities:resume', self::ID], $config);
        self::assertSame(0, $code, $out);
        self::assertSame(1, substr_count($out, 'Continuing in a fresh process'), 'the retry, then one fresh child');
        self::assertContains(self::COMMERCE_PROVIDER, $this->listed($config));
        self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find(self::ID)->status);
        self::assertTrue($this->states()->fresh(self::ID));
    }

    public function testPrepareStopsBeforeTheRuntimeSteps(): void
    {
        $config = $this->tempExtensionsConfig([]);
        [$code, $out] = $this->glueful(['thallo:capabilities:enable', self::ID, '--prepare'], $config);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Prepared. Finish on the running site', $out);
        self::assertStringNotContainsString('Continuing in a fresh process', $out);
        self::assertContains(self::COMMERCE_PROVIDER, $this->listed($config));
        self::assertSame(ActivationStep::VERIFY_BOOT, $this->store()->find(self::ID)->nextStep());
        self::assertFalse($this->states()->fresh(self::ID));
    }

    public function testResumeFinishesWithApplicationFilesReadOnly(): void
    {
        $config = $this->tempExtensionsConfig([]);
        $this->glueful(['thallo:capabilities:enable', self::ID, '--prepare'], $config);
        [$code, $out] = $this->glueful(['thallo:capabilities:resume'], $config, self::READ_ONLY);
        self::assertSame(0, $code, $out);
        self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find(self::ID)->status);
    }

    public function testStatusListsEveryActivationFeature(): void
    {
        $this->glueful(['thallo:capabilities:enable', self::ID, '--prepare'], $this->tempExtensionsConfig([]));
        [$code, $out] = $this->glueful(['thallo:capabilities:status'], $this->tempExtensionsConfig([]));
        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/thallo\.commerce\s*\|\s*preparing\s*\|\s*verify_boot/', $out);
        self::assertStringContainsString('thallo.subscriptions', $out);
    }

    public function testExtensionsEnableOnAManagedEngineNamesThisCommand(): void
    {
        $args = ['extensions:enable', 'glueful/commerce', '--dry-run'];
        [$code, $out] = $this->glueful($args, $this->tempExtensionsConfig());
        self::assertNotSame(0, $code, $out);
        self::assertStringContainsString('php glueful thallo:capabilities:enable thallo.commerce', $out);
    }

    // ── provision's part ─────────────────────────────────────────────────────────

    private function provisioning(?EngineActivation $engine = null): CapabilityProvisioning
    {
        return new CapabilityProvisioning(
            $this->appContext(),
            $this->store(),
            $this->container()->get(FeatureManagementPolicy::class),
            $engine ?? $this->engine(),
        );
    }

    private function collect(): \Closure
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function said(): string
    {
        return implode("\n", $this->lines);
    }

    private function lastTempConfig(): string
    {
        return $this->activationTempFiles[array_key_last($this->activationTempFiles)];
    }

    public function testProvisionResumesAnOpenActivationInAChildProcess(): void
    {
        $gen = $this->store()->startOrJoin(self::ID, 't')->generation;
        $this->runner()->run(self::ID, $gen, freshBoot: false);       // prepared, needs a boot
        putenv('THALLO_TEST_EXTENSIONS_CONFIG=' . $this->tempExtensionsConfig([self::COMMERCE_PROVIDER]));
        putenv('THALLO_TEST_SKIP_CACHE_REBUILD=1');

        $code = $this->provisioning()->resumeOpenActivations($this->collect());

        self::assertSame(0, $code, $this->said());
        self::assertStringContainsString('Commerce is on.', $this->said(), 'the child process reported');
        self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find(self::ID)->status);
    }

    public function testProvisionLeavesNothingToResumeAlone(): void
    {
        self::assertNull($this->provisioning()->resumeOpenActivations($this->collect()));
        self::assertSame([], $this->lines);
    }

    public function testProvisionReEnablesADisabledRequiredProvider(): void
    {
        $engine = $this->engine([self::COMMERCE_PROVIDER]);
        $added = $this->provisioning($engine)->repairRequiredProviders($this->collect());
        $aegis = 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider';
        $users = 'Glueful\\Extensions\\Users\\UsersServiceProvider';
        self::assertSame([$aegis, $users], $added);
        $listed = $this->listed($this->lastTempConfig());
        self::assertContains($aegis, $listed);
        self::assertContains($users, $listed);
        self::assertStringContainsString('glueful/aegis', $this->said());
    }

    public function testARequiredProviderIsNotRepairedOnAReadOnlyHost(): void
    {
        $engine = $this->engine([self::COMMERCE_PROVIDER], writable: false);
        $added = $this->provisioning($engine)->repairRequiredProviders($this->collect());
        self::assertSame([], $added);
        self::assertSame([self::COMMERCE_PROVIDER], $this->listed($this->lastTempConfig()));
        self::assertStringContainsString('thallo:provision', $this->said(), 'names the deploy-time step');
    }

    // ── thallo:capabilities ──────────────────────────────────────────────────────

    public function testCapabilitiesCommandCannotEnableAnActivationCapabilityWithoutPreparation(): void
    {
        $tester = new CommandTester($this->container()->get(CapabilitiesCommand::class));
        $exit = $tester->execute(['--enable' => self::ID]);
        self::assertSame(1, $exit);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());   // the error block wraps
        self::assertStringContainsString('php glueful thallo:capabilities:enable thallo.commerce', $display);
        self::assertNull($this->states()->fresh(self::ID), 'still off: nothing stored');
        self::assertSame(0, $this->store()->find(self::ID)->generation, 'no activation started');
    }

    public function testCapabilitiesCommandDisableFencesAnOutstandingRunner(): void
    {
        $gen = $this->store()->startOrJoin(self::ID, 't')->generation;
        $lease = $this->store()->acquire(self::ID, $gen);
        self::assertNotNull($lease);

        $tester = new CommandTester($this->container()->get(CapabilitiesCommand::class));
        self::assertSame(0, $tester->execute(['--disable' => self::ID]));

        try {
            $this->store()->withinFenced($lease, fn () => $this->states()->put(self::ID, true));
            self::fail('the outstanding runner wrote');
        } catch (ActivationSuperseded) {
        }
        self::assertFalse($this->states()->fresh(self::ID));
        self::assertSame(ActivationStatus::SUPERSEDED, $this->store()->find(self::ID)->status);
    }
}
