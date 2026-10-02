<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Setup\InstallRoleGrants;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;
use Thallo\Core\Tests\Support\RestoresPermissionRows;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The activation runner (feature activation spec §3.2–3.3): enabling the engine always ends the
 * request at the boot boundary; a fresh boot must prove the provider is loaded and its schema ready
 * before anything else runs; grants are made under the operation's fence; and the capability turns
 * on in one finalization — before that commit it is off, after it it stays on even when the
 * response is lost.
 *
 * Every engine here writes a temporary enabled list and never the application's own extension cache.
 */
final class ActivationRunnerTest extends AppTestCase
{
    use ChildProcesses;
    use RestoresPermissionRows;

    private const COMMERCE = 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider';
    private const SUBSCRIPTIONS = 'Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider';

    private static ?ContainerInterface $withoutCommerce = null;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $this->resetCommerce();
    }

    protected function tearDown(): void
    {
        ActivationRunner::$crashProbe = null;
        $this->resetCommerce();
        $this->restorePermissionRows();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** No Commerce blocks, an idle activation and no stored switch: each test starts from there. */
    private function resetCommerce(): void
    {
        $pdo = $this->connection()->getPDO();
        $this->dropCommerceBlocks();
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability = 'thallo.commerce'");
        $pdo->exec(
            "UPDATE capability_activations SET generation = 0, status = 'idle', steps_done = '[]',
               failed_step = NULL, error = NULL, remedy = NULL, owner_token = NULL,
               lease_expires_at = NULL, workspaces = '{}', result = '{}'
             WHERE capability = 'thallo.commerce'"
        );
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'");
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    private function version(): CapabilityStateVersion
    {
        return $this->container()->get(CapabilityStateVersion::class);
    }

    private function tempExtensionsConfig(array $enabled = []): string
    {
        $path = sys_get_temp_dir() . '/thallo-activation-extensions-' . bin2hex(random_bytes(6)) . '.php';
        $items = implode('', array_map(
            static fn (string $p): string => "        '" . str_replace('\\', '\\\\', $p) . "',\n",
            $enabled,
        ));
        file_put_contents($path, "<?php\nreturn [\n    'enabled' => [\n{$items}    ],\n];\n");
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * An engine whose enabled list is a temp file (Commerce listed by default: already prepared)
     * and whose cache rebuild never touches the application's cache.
     *
     * @param list<string> $enabled
     */
    private function engine(
        array $enabled = [self::COMMERCE],
        bool $writable = true,
        ?\Closure $writeCache = null,
        ?ContainerInterface $container = null,
    ): EngineActivation {
        $container ??= $this->container();
        return new EngineActivation(
            $container->get(ApplicationContext::class),
            $container->get(ExtensionStateCoordinator::class),
            $this->tempExtensionsConfig($enabled),
            static fn (): bool => $writable,
            $writeCache ?? static function (): void {
            },
        );
    }

    private function runner(?EngineActivation $engine = null, ?ContainerInterface $container = null): ActivationRunner
    {
        $container ??= $this->container();
        return new ActivationRunner(
            $container->get(ActivationStore::class),
            $container->get(CapabilityStateStore::class),
            new FeatureManagementPolicy(),
            $container->get(CapabilityBlockSeeder::class),
            $container->get(InstallRoleGrants::class),
            $engine ?? $this->engine(container: $container),
            $container,
        );
    }

    /** A runner in this process's container: booted with Commerce loaded and ready. */
    private function freshBootRunner(?EngineActivation $engine = null): ActivationRunner
    {
        return $this->runner($engine);
    }

    /** A runner in a context booted without the Commerce provider (as a stale extension cache would). */
    private function runnerBootedWithoutCommerce(): ActivationRunner
    {
        if (self::$withoutCommerce === null) {
            /** @var array{enabled: list<string>} $base */
            $base = require dirname(__DIR__, 3) . '/config/extensions.php';
            $enabled = array_values(array_diff($base['enabled'], [self::COMMERCE]));
            self::$withoutCommerce = self::bootAppWithConfigOverride('extensions', ['enabled' => $enabled])
                ->getContainer();
        }
        return $this->runner($this->engine(container: self::$withoutCommerce), self::$withoutCommerce);
    }

    private function startAndRun(string $capability, ?EngineActivation $engine = null): int
    {
        $gen = $this->store()->startOrJoin($capability, 't')->generation;
        $this->runner($engine)->run($capability, $gen, freshBoot: false);
        return $gen;
    }

    /** Prepares through the engine step, then continues to the end; $prepared runs in between. */
    private function runToFinalize(string $capability, ?\Closure $prepared = null): void
    {
        $gen = $this->startAndRun($capability);
        if ($prepared !== null) {
            $prepared();
        }
        try {
            $this->freshBootRunner()->run($capability, $gen, freshBoot: true);
        } catch (\RuntimeException) {
            // the simulated crash or lost response
        }
    }

    private function expireLease(string $capability): void
    {
        $this->connection()->getPDO()->prepare(
            "UPDATE capability_activations SET lease_expires_at = NOW() - INTERVAL '1 minute' WHERE capability = ?"
        )->execute([$capability]);
    }

    private function completeGrantStepAsNewOwner(string $capability): void
    {
        $lease = $this->store()->acquire($capability, $this->store()->find($capability)->generation);
        self::assertNotNull($lease, 'the expired lease is taken over');
        $grants = $this->container()->get(InstallRoleGrants::class);
        $this->store()->withinFenced($lease, static fn () => $grants->apply());
        $this->store()->completeStep($lease, ActivationStep::GRANT_PERMISSIONS);
        $this->store()->release($lease);
    }

    private function channel(): SystemChannel
    {
        return $this->container()->get(SystemChannel::class);
    }

    /** @return array<string, list<string>> */
    private function ledger(): array
    {
        $channel = $this->channel();
        if ($channel instanceof SystemFlags) {
            $channel->clearCache();
        }
        return (array) json_decode((string) $channel->get(InstallRoleGrants::LEDGER_KEY), true);
    }

    private function revoke(string $role, string $slug): void
    {
        $roleUuid = (new RoleRepository(null, $this->appContext()))->findRoleBySlug($role)?->getUuid();
        $permUuid = (new PermissionRepository(null, $this->appContext()))->findPermissionBySlug($slug)?->getUuid();
        if ($roleUuid === null || $permUuid === null) {
            return;
        }
        $this->connection()->getPDO()
            ->prepare('DELETE FROM role_permissions WHERE role_uuid = ? AND permission_uuid = ?')
            ->execute([$roleUuid, $permUuid]);
    }

    /** @return list<string> */
    private function roleSlugs(string $role): array
    {
        $uuid = (new RoleRepository(null, $this->appContext()))->findRoleBySlug($role)?->getUuid();
        self::assertNotNull($uuid);
        $perms = new PermissionRepository(null, $this->appContext());
        $slugs = [];
        foreach ((new RolePermissionRepository(null, $this->appContext()))->getRolePermissions($uuid) as $rp) {
            $p = $perms->findPermissionByUuid((string) $rp->getPermissionUuid());
            if ($p !== null) {
                $slugs[] = $p->getSlug();
            }
        }
        return $slugs;
    }

    private function dropPermission(string $slug): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare(
            'DELETE FROM role_permissions WHERE permission_uuid IN (SELECT uuid FROM permissions WHERE slug = ?)'
        )->execute([$slug]);
        $pdo->prepare('DELETE FROM permissions WHERE slug = ?')->execute([$slug]);
    }

    private function anAdvisoryLockWaiterAppears(int $timeoutSeconds = 15): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $waiting = (int) $this->connection()->getPDO()
                ->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")
                ->fetchColumn();
            if ($waiting > 0) {
                return true;
            }
            usleep(50_000);
        }
        return false;
    }

    private function dropCommerceBlocks(): void
    {
        $slugs = array_map(
            static fn ($d): string => $d->definitionKey,
            $this->container()->get(BlockTypeKind::class)->contributionsFor('thallo.commerce'),
        );
        if ($slugs === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $this->connection()->getPDO()->prepare("DELETE FROM block_types WHERE slug IN ({$in})")->execute($slugs);
    }

    // ── the boot boundary ──────────────────────────────────────────────────────────

    public function testTheEngineStepAlwaysStopsAtTheBootBoundary(): void
    {
        $gen = $this->store()->startOrJoin('thallo.commerce', 't')->generation;
        $out = $this->runner()->run('thallo.commerce', $gen, freshBoot: true);   // even "fresh"
        self::assertTrue($out->needsBoot);
        self::assertSame('already', $out->record->result['engine'] ?? null);
        self::assertSame(ActivationStep::VERIFY_BOOT, $out->record->nextStep());
        self::assertFalse($this->states()->fresh('thallo.commerce'));
    }

    public function testARetriedEngineStepNeedsAnotherBoot(): void
    {
        $gen = $this->startAndRun('thallo.commerce', $this->engine([], writable: false));   // fails once
        self::assertSame(ActivationStep::ENABLE_ENGINE, $this->store()->find('thallo.commerce')->failedStep);
        $out = $this->freshBootRunner($this->engine([]))->run('thallo.commerce', $gen, freshBoot: true);
        self::assertTrue($out->needsBoot, 'verification waits for a context booted after the retry');
        self::assertContains(ActivationStep::ENABLE_ENGINE, $out->record->stepsDone);
        self::assertNotContains(ActivationStep::VERIFY_BOOT, $out->record->stepsDone);
    }

    public function testTheStepsCompleteAcrossABootAndTheCapabilityIsEffectiveOnlyAtTheEnd(): void
    {
        $gen = $this->startAndRun('thallo.commerce');
        self::assertFalse($this->states()->fresh('thallo.commerce'));
        $out = $this->freshBootRunner()->run('thallo.commerce', $gen, freshBoot: true);
        self::assertFalse($out->needsBoot);
        self::assertSame(ActivationStatus::SUCCEEDED, $out->record->status);
        self::assertTrue($this->states()->fresh('thallo.commerce'));
        self::assertGreaterThan(0, $out->record->result['blocks_created']);
        self::assertArrayHasKey('grants', $out->record->result);
    }

    // ── the fresh-boot gate ─────────────────────────────────────────────────────

    public function testTheFreshBootGateRefusesAMissingProvider(): void
    {
        $gen = $this->startAndRun('thallo.commerce');
        $out = $this->runnerBootedWithoutCommerce()->run('thallo.commerce', $gen, freshBoot: true);
        self::assertSame(ActivationStatus::FAILED, $out->record->status);
        self::assertSame(ActivationStep::VERIFY_BOOT, $out->record->failedStep);
        self::assertSame('php glueful extensions:cache', $out->record->remedy);
        self::assertFalse($this->states()->fresh('thallo.commerce'));
    }

    public function testCacheStaleStaysOffUntilTheGatePasses(): void
    {
        $stale = $this->engine([], writeCache: static function (): void {
            throw new \RuntimeException('cache dir not writable');
        });
        $gen = $this->startAndRun('thallo.commerce', $stale);
        self::assertTrue($this->store()->find('thallo.commerce')->result['cache_stale']);

        $out = $this->runnerBootedWithoutCommerce()->run('thallo.commerce', $gen, freshBoot: true);
        self::assertSame(ActivationStep::VERIFY_BOOT, $out->record->failedStep);
        self::assertFalse($this->states()->fresh('thallo.commerce'));

        // the cache is rebuilt: a context booted now loads the provider
        $out = $this->freshBootRunner()->run('thallo.commerce', $gen, freshBoot: true);
        self::assertSame(ActivationStatus::SUCCEEDED, $out->record->status);
        self::assertSame($gen, $out->record->generation);
        self::assertFalse($out->record->result['cache_stale']);
        self::assertTrue($this->states()->fresh('thallo.commerce'));
    }

    // ── read-only hosts ───────────────────────────────────────────────────────────

    public function testAReadOnlyHostRefusesEnablingAnEngineUpFront(): void
    {
        $this->startAndRun('thallo.commerce', $this->engine([], writable: false));
        $record = $this->store()->find('thallo.commerce');
        self::assertSame(ActivationStatus::FAILED, $record->status);
        self::assertSame(ActivationStep::ENABLE_ENGINE, $record->failedStep);
        self::assertSame('php glueful thallo:features:enable thallo.commerce --prepare', $record->remedy);
    }

    public function testAPreparedEngineActivatesOnAReadOnlyHost(): void
    {
        $readOnly = $this->engine([self::COMMERCE], writable: false);
        $gen = $this->startAndRun('thallo.commerce', $readOnly);
        $out = $this->freshBootRunner($readOnly)->run('thallo.commerce', $gen, freshBoot: true);
        self::assertSame(ActivationStatus::SUCCEEDED, $out->record->status);
    }

    // ── finalization ────────────────────────────────────────────────────────────

    public function testACrashBeforeTheFinalizationCommitLeavesItOff(): void
    {
        ActivationRunner::$crashProbe = static function (string $at): void {
            if ($at === 'before_commit') {
                throw new \RuntimeException('killed');
            }
        };
        // Starting published the capability off (one version step); the version is taken after it.
        $version = null;
        $this->runToFinalize('thallo.commerce', function () use (&$version): void {
            $version = $this->version()->current();
        });
        self::assertFalse($this->states()->fresh('thallo.commerce'));
        self::assertSame($version, $this->version()->current());
        self::assertTrue($this->store()->find('thallo.commerce')->isOpen());
    }

    public function testALostResponseAfterTheCommitDoesNotUndoTheActivation(): void
    {
        ActivationRunner::$crashProbe = static function (string $at): void {
            if ($at === 'after_commit') {
                throw new \RuntimeException('lost');
            }
        };
        $this->runToFinalize('thallo.commerce');
        ActivationRunner::$crashProbe = null;
        self::assertTrue($this->states()->fresh('thallo.commerce'));
        self::assertSame(ActivationStatus::SUCCEEDED, $this->store()->find('thallo.commerce')->status);
    }

    // ── grants ──────────────────────────────────────────────────────────────────

    public function testActivationGrantsAndAConcurrentProvisionKeepEachOthersEntries(): void
    {
        $this->dropPermission('race.act');
        $this->dropPermission('race.prov');
        $this->channel()->put('installed', '1');
        try {
            $last = $this->runChildren(
                'activation_vs_provision_child.php',
                [['activation', 'race.act'], ['provision', 'race.prov']],
            );
            self::assertSame(['done', 'done'], $last);
            $ledger = $this->ledger();
        } finally {
            $this->channel()->forget('installed');
            $this->dropPermission('race.act');
            $this->dropPermission('race.prov');
        }
        self::assertContains('race.act', $ledger['superuser']);
        self::assertContains('race.prov', $ledger['superuser']);
    }

    public function testATakenOverRunnerGrantsNothing(): void
    {
        // The child pauses BEFORE the grant step's fenced transaction; its lease expires; a new
        // owner completes the grants; the operator revokes commerce.view, and a permission appears
        // that no run has offered yet; the child resumes. Its fence fails before apply() runs, so
        // it grants neither (an unfenced apply() would grant the new one).
        $this->dropPermission('race.stale');
        $this->channel()->put('installed', '1');
        try {
            $this->startAndRun('thallo.commerce');
            $child = $this->startChild(
                'activation_paused_runner_child.php',
                ['thallo.commerce', '--pause-before=grant_permissions'],
            );
            $child->waitFor('paused');
            $this->expireLease('thallo.commerce');
            $this->completeGrantStepAsNewOwner('thallo.commerce');
            $this->revoke('administrator', 'commerce.view');
            (new PermissionRepository(null, $this->appContext()))->create([
                'name' => 'race.stale', 'slug' => 'race.stale', 'description' => 'race test',
                'category' => 'Test', 'is_system' => true,
            ]);
            $child->signal('resume');
            self::assertStringContainsString('superseded', $child->finish());
            self::assertNotContains('commerce.view', $this->roleSlugs('administrator'));
            self::assertNotContains('race.stale', $this->roleSlugs('superuser'), 'the stale runner granted nothing');
        } finally {
            $this->channel()->forget('installed');
            $this->dropPermission('race.stale');
        }
    }

    // ── the engine ──────────────────────────────────────────────────────────────

    public function testTwoEnginesPreparedAtOnceBothSurviveInTheEnabledList(): void
    {
        // A holds the extension-state lock (paused after its list write); B must wait for it.
        $config = $this->tempExtensionsConfig();
        $a = $this->startChild('engine_prepare_child.php', ['glueful/commerce', $config, '--pause-in-lock']);
        $a->waitFor('in-lock');
        $b = $this->startChild('engine_prepare_child.php', ['glueful/subscriptions', $config]);
        self::assertTrue($this->anAdvisoryLockWaiterAppears(), 'B waits on the extension-state lock');
        self::assertFalse($b->isFinished(), 'B does not write while A holds the lock');
        $a->signal('resume');
        self::assertStringContainsString('prepared', $a->finish());
        self::assertStringContainsString('prepared', $b->finish());
        $enabled = (require $config)['enabled'];
        self::assertContains(self::COMMERCE, $enabled);
        self::assertContains(self::SUBSCRIPTIONS, $enabled);
    }
}
