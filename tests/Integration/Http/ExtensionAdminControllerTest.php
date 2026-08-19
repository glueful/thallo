<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\ExtensionAdminController;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\SpySchemaExecutor;
use App\Tests\Support\TestableExtensionAdminController;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaNotBootstrappedException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The extensions admin surface drives the SHARED schema executor (schema policy spec B5): this
 * controller owns only HTTP concerns — authority, protected refusal, host writability — and maps
 * the executor's truthful operation record onto the framework controller's terminal semantics
 * (succeeded / enabled_cache_stale are 200, failed / manual_repair are 409 with the operation,
 * bootstrap / undeclared / lock-contention refusals are 409 with their remedy). The list endpoint
 * aggregates each package's schema readiness into a CLOSED state set that can never make the
 * endpoint throw.
 */
final class ExtensionAdminControllerTest extends AppTestCase
{
    /** @param array<string,mixed> $body */
    private function jsonPost(array $body): Request
    {
        return Request::create(
            '/v1/admin/extensions/enable',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    private function spied(): TestableExtensionAdminController
    {
        $controller = new TestableExtensionAdminController($this->appContext());
        $controller->executor = new SpySchemaExecutor();
        return $controller;
    }

    /** @return array<string, mixed> success body `data`, or an error body's `error.details` */
    private function payload(\Glueful\Http\Response $resp): array
    {
        $json = json_decode((string) $resp->getContent(), true);
        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }
        return is_array($json['error']['details'] ?? null) ? $json['error']['details'] : [];
    }

    // ── name resolution ────────────────────────────────────────────────────────────

    public function testUnknownNameFromJsonBodyIs404(): void
    {
        $controller = $this->spied();
        foreach (['enable', 'disable'] as $action) {
            $resp = $controller->{$action}($this->jsonPost(['name' => 'glueful/definitely-not-installed']));
            self::assertSame(404, $resp->getStatusCode());
            self::assertStringContainsString('glueful/definitely-not-installed', (string) $resp->getContent());
        }
        self::assertSame([], $controller->executor->calls, 'no executor call for an unknown package');
    }

    // ── preconditions ─────────────────────────────────────────────────────────────

    public function testToggleRefusesTheProtectedTenancyProviderBeforeTheExecutor(): void
    {
        // glueful/tenancy's enforcement provider is listed in extensions.protected: its
        // activation belongs to the workspaces enablement flow, so BOTH generic toggle
        // directions must 409 before the executor is ever invoked.
        $controller = $this->spied();

        foreach (['enable', 'disable'] as $action) {
            $resp = $controller->{$action}($this->jsonPost(['name' => 'glueful/tenancy']));
            self::assertSame(409, $resp->getStatusCode(), "{$action} must refuse the protected provider");
            self::assertStringContainsString('tenancy enablement flow', (string) $resp->getContent());
        }
        self::assertSame([], $controller->executor->calls);
    }

    public function testAnUnwritableHostRefusesBeforeTheExecutor(): void
    {
        $controller = $this->spied();
        $controller->hostRefusal = ['reason' => 'config_readonly', 'detail' => 'config/ is not writable'];

        $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('config_readonly', (string) $resp->getContent());
        self::assertSame([], $controller->executor->calls, 'writability refuses before delegation');
    }

    public function testProductionIsNoLongerRefused(): void
    {
        $saved = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        try {
            $controller = $this->spied();
            $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

            self::assertSame(200, $resp->getStatusCode(), 'production enablement goes through the executor');
            self::assertCount(1, $controller->executor->calls);
        } finally {
            $saved === false ? putenv('APP_ENV') : putenv("APP_ENV={$saved}");
            $saved === false ? $_ENV['APP_ENV'] = 'testing' : $_ENV['APP_ENV'] = $saved;
        }
    }

    // ── delegation + terminal semantics ───────────────────────────────────────────

    public function testDelegatesToTheExecutorWithTheAdminApiActor(): void
    {
        $controller = $this->spied();

        $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(
            [['op' => 'enable', 'package' => 'glueful/media', 'actor' => 'admin-api']],
            $controller->executor->calls
        );
        $operation = $this->payload($resp)['operation'] ?? [];
        self::assertSame(ExtensionOperation::STATUS_SUCCEEDED, $operation['status'] ?? null);
        self::assertSame(7, $operation['id'] ?? null);
    }

    public function testCacheStaleIsStillHttp200WithItsWarning(): void
    {
        $controller = $this->spied();
        $controller->executor->status = ExtensionOperation::STATUS_CACHE_STALE;
        $controller->executor->error = "Config written, but recompiling the provider cache failed: boom. "
            . "Re-run 'php glueful extensions:cache'.";

        $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

        self::assertSame(200, $resp->getStatusCode(), 'the state IS written — stale cache is a warning');
        self::assertStringContainsString('extensions:cache', (string) $resp->getContent());
        self::assertSame(
            ExtensionOperation::STATUS_CACHE_STALE,
            $this->payload($resp)['operation']['status'] ?? null
        );
    }

    public function testFailedAndManualRepairAre409WithTheOperationPayload(): void
    {
        foreach (
            [
                ExtensionOperation::STATUS_FAILED,
                ExtensionOperation::STATUS_MANUAL_REPAIR,
            ] as $status
        ) {
            $controller = $this->spied();
            $controller->executor->status = $status;
            $controller->executor->failedMigration = '004_CreateThing.php';
            $controller->executor->error = 'relation already exists';

            $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

            self::assertSame(409, $resp->getStatusCode(), $status);
            $operation = $this->payload($resp)['operation'] ?? [];
            self::assertSame($status, $operation['status'] ?? null);
            self::assertSame('004_CreateThing.php', $operation['failed_migration'] ?? null);
            self::assertSame('relation already exists', $operation['error'] ?? null);
        }
    }

    public function testExecutorRefusalExceptionsMapTo409WithTheirRemedy(): void
    {
        foreach (
            [
                [SchemaNotBootstrappedException::create(), 'migrate:run'],
                [new LockContentionException('migration sources are locked: app'), 'locked'],
            ] as [$throwable, $needle]
        ) {
            $controller = $this->spied();
            $controller->executor->throws = $throwable;

            $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

            self::assertSame(409, $resp->getStatusCode(), $throwable::class);
            self::assertStringContainsString($needle, (string) $resp->getContent());
        }
    }

    public function testOtherRuntimeRefusalsAre422(): void
    {
        $controller = $this->spied();
        $controller->executor->throws = new \RuntimeException(
            'Cannot change glueful/media: [missing_dependency] requires glueful/ghost'
        );

        $resp = $controller->enable($this->jsonPost(['name' => 'glueful/media']));

        self::assertSame(422, $resp->getStatusCode());
        self::assertStringContainsString('missing_dependency', (string) $resp->getContent());
    }

    // ── the list's schema aggregation ─────────────────────────────────────────────

    public function testEveryListedRowCarriesAClosedSchemaState(): void
    {
        $rows = $this->payload((new ExtensionAdminController($this->appContext()))->index())['extensions'] ?? [];

        self::assertNotEmpty($rows);
        $byName = array_column($rows, null, 'name');
        foreach ($rows as $row) {
            self::assertContains(
                $row['schema_state'],
                ['ready', 'pending', 'divergent', 'none', 'undeclared'],
                (string) $row['name']
            );
            self::assertIsArray($row['schema_reasons']);
            self::assertIsString($row['cli_command']);
        }
        // Spot anchors against the real installed set: explicit-none and an applied engine.
        self::assertSame('none', $byName['glueful/media']['schema_state'] ?? null);
        self::assertSame('ready', $byName['glueful/users']['schema_state'] ?? null);
    }

    public function testAnUndeclaredPackageRemainsListableWithItsAuthorFacingReason(): void
    {
        $controller = $this->spied();
        $controller->declaredMap['glueful/media'] = false;

        $rows = $this->payload($controller->index())['extensions'] ?? [];
        $row = array_column($rows, null, 'name')['glueful/media'] ?? null;

        self::assertNotNull($row, 'an undeclared package must remain visible');
        self::assertSame('undeclared', $row['schema_state']);
        self::assertStringContainsString('"migrations": "none"', implode(' ', $row['schema_reasons']));
    }

    public function testMultiDescriptorPrecedenceDivergentBeatsPendingBeatsReady(): void
    {
        $controller = $this->spied();
        $controller->readinessMap['glueful/media'] = [
            'glueful/media' => ['state' => ReadinessState::Ready, 'reasons' => []],
            'glueful/media:search' => ['state' => ReadinessState::Pending, 'reasons' => ['1 migration(s) pending']],
            'glueful/media:blobs' =>
                ['state' => ReadinessState::Divergent, 'reasons' => ['checksum mismatch for 001_X.php']],
        ];

        $rows = $this->payload($controller->index())['extensions'] ?? [];
        $row = array_column($rows, null, 'name')['glueful/media'] ?? null;

        self::assertSame('divergent', $row['schema_state'] ?? null, 'ANY divergent descriptor wins');
        self::assertStringContainsString('checksum mismatch', implode(' ', $row['schema_reasons']));
        self::assertStringNotContainsString('pending', implode(' ', $row['schema_reasons']));
        self::assertSame('php glueful migrate:verify', $row['cli_command'] ?? null);

        // Pending beats ready once the divergent source is gone.
        $controller->readinessMap['glueful/media'] = [
            'glueful/media' => ['state' => ReadinessState::Ready, 'reasons' => []],
            'glueful/media:search' => ['state' => ReadinessState::Pending, 'reasons' => ['1 migration(s) pending']],
        ];
        $rows = $this->payload($controller->index())['extensions'] ?? [];
        $row = array_column($rows, null, 'name')['glueful/media'] ?? null;
        self::assertSame('pending', $row['schema_state'] ?? null);
    }
}
