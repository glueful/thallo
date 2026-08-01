# Approval / Review Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `glueful/lemma-workflow` capability pack (single-stage editorial review: draft → in_review → approved/changes_requested) plus the one core `PublishGate` seam, per `docs/superpowers/specs/2026-07-02-approval-workflow-design.md`.

**Architecture:** A removable capability pack owns review state, transitions, permissions, history, and admin UI. Core `PublishService` consults container-tagged `lemma.publish_gate` services before any write; the pack's gate requires `approved` or the `workflow.bypass` permission. Automatic transitions ride the existing `ContentLifecycleEvent` contract.

**Tech Stack:** PHP 8.3 / Glueful framework (existing), Postgres (test harness), Vue 3 + Nuxt UI + Pinia Colada (admin SPA), PHPUnit 10, Vitest.

## Global Constraints

- The spec is the contract: `docs/superpowers/specs/2026-07-02-approval-workflow-design.md`. Re-read it before starting.
- Pack namespace `Glueful\Lemma\Workflow\`; pack depends on `glueful/lemma-contracts` + `glueful/framework` ONLY. Never import `App\*` or Aegis classes in `packages/lemma-workflow/src/` (`composer boundaries` enforces).
- NO `enabled` config key in the pack config — the `lemma.capabilities` switchboard is the only gate.
- PHP: `use` imports (no inline FQCNs), phpcs 120-char lines (`vendor/bin/phpcs -q <paths>` must be clean), `declare(strict_types=1)`.
- Migrations: flat `migrations/` at pack root, `MigrationPriority::DEPENDENT`, idempotent guards, no cross-package FKs. Timestamps via `gmdate('Y-m-d H:i:s')`.
- Commits: on `dev`, batched at the 4 marked commit points (NOT per task). No AI attribution/Co-Authored-By trailers.
- Backend tests: `vendor/bin/phpunit tests/Integration/Workflow/` (Postgres harness; `LemmaTestCase` gives `container()/connection()/appContext()/findRoute()`). SPA tests: `npx vitest run <spec>`; assert `data-test` hooks, never Nuxt-UI component internals.
- SPA: Pinia setup-store style; query modules follow `admin/src/queries/seo.ts` (authFetch) conventions.

## File Map

| Area | Files |
|---|---|
| Contracts | `packages/lemma-contracts/src/Authoring/{PublishGate,PublishBlocked,DraftSummaryReader}.php` |
| Core seam | `app/Content/Services/PublishService.php`, `app/Providers/LemmaServiceProvider.php`, `app/Content/Http/Controllers/PublicationController.php`, `app/Content/Authoring/EngineDraftSummaryReader.php` |
| Pack | `packages/lemma-workflow/{composer.json,README.md,config/lemma-workflow.php,routes/admin-routes.php,migrations/*,src/*}` |
| App wiring | root `composer.json`, `config/extensions.php`, `database/dependent-migrations/009_GrantWorkflowPermissionsToAdministrator.php` |
| Backend tests | `tests/Integration/Workflow/*` |
| SPA | `admin/src/queries/workflow.ts`, `admin/src/pages/content/[type]/[uuid]/components/WorkflowPanel.vue`, `admin/src/pages/content/[type]/[uuid]/index.vue`, `admin/src/pages/workflow/index.vue`, `admin/src/registry/workflowModule.ts`, `admin/src/layouts/default.vue`, `admin/src/__tests__/workflow*.spec.ts` |

---

### Task 1: Contracts + core PublishGate seam

**Files:**
- Create: `packages/lemma-contracts/src/Authoring/PublishGate.php`
- Create: `packages/lemma-contracts/src/Authoring/PublishBlocked.php`
- Create: `packages/lemma-contracts/src/Authoring/DraftSummaryReader.php`
- Create: `app/Content/Authoring/EngineDraftSummaryReader.php`
- Modify: `app/Content/Services/PublishService.php` (constructor + gate loop in `publish()`)
- Modify: `app/Providers/LemmaServiceProvider.php` (PublishService factory, DraftSummaryReader binding)
- Modify: `app/Content/Http/Controllers/PublicationController.php` (409 mapping)
- Test: `tests/Integration/Workflow/PublishGateSeamTest.php`

**Interfaces:**
- Produces: `PublishGate::assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void`; `PublishBlocked(string $reason, ?string $state)` with public readonly `$reason`/`$state`; `DraftSummaryReader::summary(string $entryUuid, string $locale): ?array{entry_uuid:string,locale:string,title:?string,type_uuid:string,type_slug:string}`; container tag `lemma.publish_gate`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\DraftSummaryReader;
use Glueful\Lemma\Contracts\Authoring\PublishBlocked;
use Glueful\Lemma\Contracts\Authoring\PublishGate;

final class PublishGateSeamTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    public function testGateBlocksPublishBeforeAnyWrite(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // publishes en+fr via container service
        $versionsBefore = (int) $this->connection()->getPDO()
            ->query("SELECT COUNT(*) FROM entry_versions WHERE entry_uuid = '{$entry}'")->fetchColumn();

        $blocking = new class implements PublishGate {
            public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
            {
                throw new PublishBlocked('blocked by test gate', 'in_review');
            }
        };
        $publisher = $this->makePublishServiceWithGates([$blocking]);

        try {
            $publisher->publish($entry, 'en', 'user00000001');
            self::fail('expected PublishBlocked');
        } catch (PublishBlocked $e) {
            self::assertSame('in_review', $e->state);
        }
        $versionsAfter = (int) $this->connection()->getPDO()
            ->query("SELECT COUNT(*) FROM entry_versions WHERE entry_uuid = '{$entry}'")->fetchColumn();
        self::assertSame($versionsBefore, $versionsAfter, 'a blocked publish must write NOTHING');
    }

    public function testAllowingGatepublishes(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $allowing = new class implements PublishGate {
            public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
            {
            }
        };
        $version = $this->makePublishServiceWithGates([$allowing])->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
    }

    public function testEmptyGateListPublishesAsToday(): void
    {
        // The seam must be INERT with no gates. Deliberately constructed with gates: [] (not
        // the container instance): once the workflow pack installs (Task 5), the container
        // service carries a real gate — this test must keep proving the no-gates behaviour
        // regardless of what is installed.
        $entry = $this->seedBilingualPublishedEntry();
        $version = $this->makePublishServiceWithGates([])->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
    }

    public function testDraftSummaryReaderReturnsTitleAndType(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // type 'blog', title field 'Hello'
        $summary = $this->container()->get(DraftSummaryReader::class)->summary($entry, 'en');
        self::assertNotNull($summary);
        self::assertSame('Hello', $summary['title']);
        self::assertSame('blog', $summary['type_slug']);
        self::assertNull($this->container()->get(DraftSummaryReader::class)->summary('nope00000000', 'en'));
    }

    /** @param list<PublishGate> $gates */
    private function makePublishServiceWithGates(array $gates): \App\Content\Services\PublishService
    {
        $c = $this->container();
        return new \App\Content\Services\PublishService(
            $this->appContext(),
            $c->get(\App\Content\Repositories\EntryRepository::class),
            $c->get(\App\Content\Repositories\VersionRepository::class),
            $c->get(\App\Content\Repositories\ContentTypeRepository::class),
            $c->get(\App\Content\Validation\FieldValidator::class),
            $c->get(\App\Content\Repositories\ReferenceProjectionRepository::class),
            null,
            null,
            $gates,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Workflow/PublishGateSeamTest.php`
Expected: FAIL — `Interface "Glueful\Lemma\Contracts\Authoring\PublishGate" not found`.

- [ ] **Step 3: Create the three contracts**

`packages/lemma-contracts/src/Authoring/PublishGate.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Authoring;

/**
 * Core publish asks every registered gate "may I publish this draft?" before any write.
 * Gates register under the `lemma.publish_gate` container tag; deterministic tag-priority
 * order; the first thrown PublishBlocked stops the publish; unexpected exceptions bubble.
 * No gates registered → publish behaves exactly as before this seam existed.
 */
interface PublishGate
{
    /** @throws PublishBlocked when the draft may not be published. */
    public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void;
}
```

`packages/lemma-contracts/src/Authoring/PublishBlocked.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Authoring;

/**
 * A publish gate refused the publish. `reason` is the human-readable sentence shown by
 * clients; `state` is the workflow state when known (drives UI badges without message
 * parsing). Maps to HTTP 409 (valid request, wrong workflow state).
 */
final class PublishBlocked extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $state = null,
    ) {
        parent::__construct($reason);
    }
}
```

`packages/lemma-contracts/src/Authoring/DraftSummaryReader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Authoring;

/**
 * Read-only draft identity summary for authoring-adjacent packs (e.g. a review queue that
 * must show titles without coupling to the engine's storage model). Returns null for a
 * missing draft or a soft-deleted entry.
 */
interface DraftSummaryReader
{
    /**
     * @return array{entry_uuid:string,locale:string,title:?string,type_uuid:string,type_slug:string}|null
     */
    public function summary(string $entryUuid, string $locale): ?array;
}
```

- [ ] **Step 4: App-side DraftSummaryReader implementation**

`app/Content/Authoring/EngineDraftSummaryReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Authoring;

use Glueful\Database\Connection;
use Glueful\Lemma\Contracts\Authoring\DraftSummaryReader;

/** Engine-backed DraftSummaryReader over entries/entry_drafts/content_types. */
final class EngineDraftSummaryReader implements DraftSummaryReader
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function summary(string $entryUuid, string $locale): ?array
    {
        $draft = $this->db->table('entry_drafts')->select(['fields'])
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        if ($draft === null) {
            return null;
        }
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)
            ->first();
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return null;
        }
        $typeUuid = (string) $entry['content_type_uuid'];
        $type = $this->db->table('content_types')->select(['slug'])
            ->where('uuid', '=', $typeUuid)
            ->first();

        $fields = json_decode((string) $draft['fields'], true);
        $title = is_array($fields) && is_string($fields['title'] ?? null) ? $fields['title'] : null;

        return [
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'title' => $title,
            'type_uuid' => $typeUuid,
            'type_slug' => (string) ($type['slug'] ?? ''),
        ];
    }
}
```

- [ ] **Step 5: Gate loop in PublishService**

In `app/Content/Services/PublishService.php`, add the import `use Glueful\Lemma\Contracts\Authoring\PublishGate;` and extend the constructor with a trailing promoted param:

```php
        private readonly ?PublishEventEmitter $events = null,
        private readonly ?SchemaProjector $projector = null,
        /** @var list<PublishGate> Tag-discovered (`lemma.publish_gate`); empty = ungated. */
        private readonly array $publishGates = [],
```

In `publish()`, immediately AFTER the existing `$draft === null` throw (so 404s beat 409s) and before any other work, insert:

```php
        // Ask every registered publish gate (workflow pack etc.) BEFORE any write. The first
        // PublishBlocked stops the publish; unexpected exceptions bubble — a broken gate must
        // not silently allow publishes. No gates → exactly the pre-seam behaviour.
        foreach ($this->publishGates as $gate) {
            $gate->assertCanPublish($entryUuid, $locale, $actor);
        }
```

- [ ] **Step 6: Wire the factory + DraftSummaryReader in LemmaServiceProvider**

In `app/Providers/LemmaServiceProvider.php` replace the `PublishService::class` definition with:

```php
            PublishService::class => [
                'factory' => [self::class, 'makePublishService'],
                'shared' => true,
            ],
            \Glueful\Lemma\Contracts\Authoring\DraftSummaryReader::class => [
                'class'    => \App\Content\Authoring\EngineDraftSummaryReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
```

and add the factory method (imports: `Psr\Container\ContainerInterface` is already imported; add `use Glueful\Lemma\Contracts\Authoring\PublishGate;` and `use App\Content\Pipeline\PublishEventEmitter;` / `use App\Content\Schema\Migration\SchemaProjector;` if not present):

```php
    public static function makePublishService(ContainerInterface $c): PublishService
    {
        // Collect tag-registered publish gates (the import_export.importer pattern). The tag
        // collection is priority-ordered by the container compiler.
        $gates = $c->has('lemma.publish_gate') ? $c->get('lemma.publish_gate') : [];
        if ($gates instanceof \Traversable) {
            $gates = iterator_to_array($gates);
        }
        return new PublishService(
            $c->get(ApplicationContext::class),
            $c->get(\App\Content\Repositories\EntryRepository::class),
            $c->get(\App\Content\Repositories\VersionRepository::class),
            $c->get(\App\Content\Repositories\ContentTypeRepository::class),
            $c->get(\App\Content\Validation\FieldValidator::class),
            $c->get(\App\Content\Repositories\ReferenceProjectionRepository::class),
            $c->has(PublishEventEmitter::class) ? $c->get(PublishEventEmitter::class) : null,
            $c->has(SchemaProjector::class) ? $c->get(SchemaProjector::class) : null,
            array_values(array_filter((array) $gates, static fn($g): bool => $g instanceof PublishGate)),
        );
    }
```

(Use `use` imports and short names for the repository/validator classes — match the file's existing import style; the FQCNs above are for unambiguity in this plan only.)

- [ ] **Step 7: 409 mapping in PublicationController**

In `app/Content/Http/Controllers/PublicationController.php::publish()`, add `use Glueful\Lemma\Contracts\Authoring\PublishBlocked;` and insert a catch BEFORE the existing `\RuntimeException` catch (PublishBlocked extends RuntimeException — order matters):

```php
        } catch (PublishBlocked $e) {
            // Spec-pinned shape: message is the SPA-ready sentence; details.workflow_state
            // drives the editor badge without message parsing.
            return Response::error($e->reason, 409, ['workflow_state' => $e->state]);
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Workflow/PublishGateSeamTest.php`
Expected: PASS (4 tests). Then run `vendor/bin/phpunit` (full suite) — the factory rewiring must not break any existing publish/schedule tests.

---

### Task 2: Pack skeleton + capability

**Files:**
- Create: `packages/lemma-workflow/composer.json`
- Create: `packages/lemma-workflow/config/lemma-workflow.php`
- Create: `packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (skeleton; services grow in later tasks)
- Modify: root `composer.json` (path repo + require `glueful/lemma-workflow: "*"`)
- Modify: `config/extensions.php` (append provider FQCN)
- Test: `tests/Integration/Workflow/WorkflowCapabilityTest.php`

**Interfaces:**
- Produces: capability id `lemma.workflow`; config key `lemma_workflow.allow_self_review` (bool, default false); provider FQCN `Glueful\Lemma\Workflow\LemmaWorkflowServiceProvider`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class WorkflowCapabilityTest extends LemmaTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.workflow'),
            'lemma.workflow must be registered and enabled by default',
        );
    }

    public function testSelfReviewConfigDefaultsFalse(): void
    {
        self::assertFalse((bool) config($this->appContext(), 'lemma_workflow.allow_self_review', null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — `vendor/bin/phpunit tests/Integration/Workflow/WorkflowCapabilityTest.php` → FAIL (capability unknown).

- [ ] **Step 3: Create the pack**

`packages/lemma-workflow/composer.json`:

```json
{
  "name": "glueful/lemma-workflow",
  "description": "Editorial approval workflow for Lemma: single-stage review state machine over draft/publish, as a removable capability pack.",
  "type": "glueful-extension",
  "license": "MIT",
  "authors": [
    { "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }
  ],
  "version": "0.1.0",
  "require": {
    "php": "^8.3",
    "glueful/lemma-contracts": "*",
    "glueful/framework": "^1.65.0"
  },
  "autoload": {
    "psr-4": { "Glueful\\Lemma\\Workflow\\": "src/" }
  },
  "extra": {
    "glueful": {
      "provider": "Glueful\\Lemma\\Workflow\\LemmaWorkflowServiceProvider"
    }
  },
  "minimum-stability": "stable"
}
```

`packages/lemma-workflow/config/lemma-workflow.php`:

```php
<?php

declare(strict_types=1);

return [
    // NOTE: enable/disable is NOT configured here — the capability switchboard in the app's
    // config/lemma.php ('capabilities' => ['lemma.workflow' => false]) is the only gate.

    // When true, the submitter may approve their own submission (tiny-team escape hatch).
    // Default false: an approval means a second person looked at the draft.
    'allow_self_review' => (bool) env('WORKFLOW_ALLOW_SELF_REVIEW', false),
];
```

`packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (skeleton — later tasks add services/routes/listeners; keep this exact shape so their diffs slot in):

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaWorkflowServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'lemma_workflow'.
        $this->mergeConfig('lemma_workflow', require __DIR__ . '/../config/lemma-workflow.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.workflow',
            label: 'Approval workflow',
            description: 'Single-stage editorial review over draft/publish.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-workflow',
        );
    }
}
```

- [ ] **Step 4: Wire into the app**

Root `composer.json`: add `{ "type": "path", "url": "packages/lemma-workflow" }` to `repositories` (alphabetical, after lemma-seo) and `"glueful/lemma-workflow": "*"` to `require`. Then run:

Run: `composer update glueful/lemma-workflow` — expected: `Installing glueful/lemma-workflow (0.1.0)` (symlinked path package).

`config/extensions.php`: append `'Glueful\Lemma\Workflow\LemmaWorkflowServiceProvider',` to the `enabled` list (after the Seo provider). Then clear the compiled extension cache:

Run: `php glueful extensions:cache 2>/dev/null || rm -f storage/cache/extensions_*.php` — a stale compiled cache would hide the new provider.

- [ ] **Step 5: Run the test** — `vendor/bin/phpunit tests/Integration/Workflow/WorkflowCapabilityTest.php` → PASS.

- [ ] **Step 6: COMMIT 1 (core seam + skeleton)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add packages/lemma-contracts/src/Authoring/ app/Content/Authoring/EngineDraftSummaryReader.php \
  app/Content/Services/PublishService.php app/Providers/LemmaServiceProvider.php \
  app/Content/Http/Controllers/PublicationController.php packages/lemma-workflow/ \
  composer.json composer.lock config/extensions.php tests/Integration/Workflow/
git commit -m "Add PublishGate seam and lemma-workflow pack skeleton

- lemma-contracts: PublishGate, PublishBlocked (reason+state), DraftSummaryReader.
- PublishService consults tag-discovered lemma.publish_gate services before any
  write; no gates = pre-seam behaviour byte for byte.
- PublicationController maps PublishBlocked to 409 with details.workflow_state.
- New glueful/lemma-workflow pack skeleton: capability lemma.workflow,
  lemma_workflow.allow_self_review config, DEPENDENT migrations dir."
```

---

### Task 3: Migrations (states, transitions, permissions, admin grant)

**Files:**
- Create: `packages/lemma-workflow/migrations/001_CreateWorkflowReviewStatesTable.php`
- Create: `packages/lemma-workflow/migrations/002_CreateWorkflowTransitionsTable.php`
- Create: `packages/lemma-workflow/migrations/003_SeedWorkflowPermissions.php`
- Create: `database/dependent-migrations/009_GrantWorkflowPermissionsToAdministrator.php`
- Test: `tests/Integration/Workflow/WorkflowMigrationSmokeTest.php`

**Interfaces:**
- Produces: tables `workflow_review_states` (unique entry_uuid+locale) and `workflow_transitions` (append-only, with `metadata` json); permission slugs `workflow.review`, `workflow.bypass` granted to `administrator`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\LemmaTestCase;

final class WorkflowMigrationSmokeTest extends LemmaTestCase
{
    public function testTablesExist(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (['workflow_review_states', 'workflow_transitions'] as $table) {
            self::assertNotNull(
                $pdo->query("SELECT to_regclass('public.{$table}')")->fetchColumn(),
                "{$table} exists after migrations",
            );
        }
    }

    public function testAdministratorHoldsWorkflowPermissions(): void
    {
        foreach (['workflow.review', 'workflow.bypass'] as $slug) {
            $granted = $this->connection()->getPDO()->query(
                "SELECT COUNT(*) FROM role_permissions rp
                   JOIN roles r ON r.uuid = rp.role_uuid
                   JOIN permissions p ON p.uuid = rp.permission_uuid
                  WHERE r.slug = 'administrator' AND p.slug = '{$slug}'"
            )->fetchColumn();
            self::assertSame(1, (int) $granted, "administrator holds {$slug}");
        }
    }
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Integration/Workflow/WorkflowMigrationSmokeTest.php` → FAIL (tables absent).

- [ ] **Step 3: Pack migrations**

`packages/lemma-workflow/migrations/001_CreateWorkflowReviewStatesTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateWorkflowReviewStatesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('workflow_review_states')) {
            return;
        }
        $schema->createTable('workflow_review_states', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            // draft | in_review | approved | changes_requested; absent row ≡ draft
            $table->string('state', 24)->default('draft');
            $table->string('submitted_by', 12)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reviewed_by', 12)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['entry_uuid', 'locale'], 'uniq_workflow_state_entry_locale');
            $table->index('state');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('workflow_review_states');
    }

    public function getDescription(): string
    {
        return 'Create workflow_review_states (per entry+locale review state).';
    }
}
```

`packages/lemma-workflow/migrations/002_CreateWorkflowTransitionsTable.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateWorkflowTransitionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('workflow_transitions')) {
            return;
        }
        $schema->createTable('workflow_transitions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            // submit | approve | request_changes | withdraw | edit_invalidated
            // | published | published_with_bypass
            $table->string('action', 32);
            $table->string('actor_uuid', 12)->nullable();
            $table->text('note')->nullable();
            // Forward seam: source/channel enrichment if the publish event ever grows one.
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['entry_uuid', 'locale']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('workflow_transitions');
    }

    public function getDescription(): string
    {
        return 'Create workflow_transitions (append-only review history).';
    }
}
```

`packages/lemma-workflow/migrations/003_SeedWorkflowPermissions.php` — copy the exact shape of `packages/lemma-seo/migrations/002_SeedSeoPermissions.php`, with:

```php
    private const PERMISSIONS = [
        'workflow.review' => 'Review content submissions (approve / request changes)',
        'workflow.bypass' => 'Publish without an approved review',
    ];
```

and `'category' => 'workflow'`. `down()` is a no-op (same rationale comment: removing the pack must not strip permission rows roles may reference).

- [ ] **Step 4: App-side grant migration**

`database/dependent-migrations/009_GrantWorkflowPermissionsToAdministrator.php` — copy the exact structure of `005_GrantI18nPermissionsToAdministrator.php` (ensurePermissions / lookup-administrator-never-create / assign / reversible down that deletes only our grants), with:

```php
    private const ROLE = 'administrator';
    private const PERMISSIONS = [
        'workflow.review' => 'Review content submissions (approve / request changes)',
        'workflow.bypass' => 'Publish without an approved review',
    ];
```

and `getDescription()` returning `'Grant workflow.review/bypass to the administrator role.'`.

- [ ] **Step 5: Run the smoke test** — the harness runs migrations on boot; `vendor/bin/phpunit tests/Integration/Workflow/WorkflowMigrationSmokeTest.php` → PASS. (If tables are missing, the test database may need re-migration: `php glueful migrate:run` against the test env per `phpunit.xml`.)

---

### Task 4: State repository + WorkflowService (the transition matrix)

**Files:**
- Create: `packages/lemma-workflow/src/WorkflowStateRepository.php`
- Create: `packages/lemma-workflow/src/WorkflowService.php`
- Create: `packages/lemma-workflow/src/IllegalTransition.php`
- Create: `packages/lemma-workflow/src/WorkflowForbidden.php`
- Create: `packages/lemma-workflow/src/Events/ReviewSubmitted.php`, `.../ReviewApproved.php`, `.../ChangesRequested.php`
- Modify: `packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (register the two services)
- Test: `tests/Integration/Workflow/WorkflowTransitionsTest.php`

**Interfaces:**
- Produces (used by Tasks 5–7):
  - `WorkflowStateRepository::find(string,string): ?array`, `stateOf(string,string): string` (absent row → `'draft'`), `setState(string $entry, string $locale, string $state, array $attrs = []): void` (atomic upsert; `$attrs` ⊂ {submitted_by, submitted_at, reviewed_by, reviewed_at}), `record(string $entry, string $locale, string $from, string $to, string $action, ?string $actor, ?string $note = null): void`, `queuePage(string $state, int $page, int $perPage): array{items: list<array<string,mixed>>, total: int}`, `history(string $entry, string $locale, int $limit = 20): list<array<string,mixed>>`
  - `WorkflowService::overview(string,string): array{state:string, submitted_by:?string, submitted_at:?string, reviewed_by:?string, reviewed_at:?string, history: list<array<string,mixed>>}`, `submit(string $entry, string $locale, string $actor, ?string $note): array`, `approve(string $entry, string $locale, string $actor, ?string $note): array`, `requestChanges(string $entry, string $locale, string $actor, string $note): array`, `withdraw(string $entry, string $locale, string $actor, bool $actorIsReviewer): array` (all transition methods return `overview()`), `invalidateOnEdit(string,string,?string): void`, `recordPublish(string,string,?string): void`
  - `IllegalTransition extends \RuntimeException` with `public readonly string $state`; `WorkflowForbidden extends \RuntimeException`.
  - Events `ReviewSubmitted`/`ReviewApproved`/`ChangesRequested` extend `Glueful\Events\Contracts\BaseEvent`, constructor `(public readonly string $entry, public readonly string $locale, public readonly ?string $actor)` (ChangesRequested adds `public readonly ?string $note`), all calling `parent::__construct()`.

- [ ] **Step 1: Write the failing tests** — `tests/Integration/Workflow/WorkflowTransitionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Workflow\IllegalTransition;
use Glueful\Lemma\Workflow\WorkflowForbidden;
use Glueful\Lemma\Workflow\WorkflowService;

final class WorkflowTransitionsTest extends LemmaTestCase
{
    private function svc(): WorkflowService
    {
        return $this->container()->get(WorkflowService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    public function testHappyPathSubmitApprove(): void
    {
        $o = $this->svc()->submit('entryaaa0001', 'en', 'author000001', null);
        self::assertSame('in_review', $o['state']);
        self::assertSame('author000001', $o['submitted_by']);

        $o = $this->svc()->approve('entryaaa0001', 'en', 'review000001', 'lgtm');
        self::assertSame('approved', $o['state']);
        self::assertSame('review000001', $o['reviewed_by']);
        self::assertSame(
            ['submit', 'approve'],
            array_column(array_reverse($o['history']), 'action'),
        );
    }

    public function testRequestChangesThenResubmit(): void
    {
        $this->svc()->submit('entryaaa0002', 'en', 'author000001', null);
        $o = $this->svc()->requestChanges('entryaaa0002', 'en', 'review000001', 'fix the intro');
        self::assertSame('changes_requested', $o['state']);

        $o = $this->svc()->submit('entryaaa0002', 'en', 'author000001', 'fixed');
        self::assertSame('in_review', $o['state']);
    }

    public function testIllegalTransitionsThrow(): void
    {
        // approve from draft
        try {
            $this->svc()->approve('entryaaa0003', 'en', 'review000001', null);
            self::fail('expected IllegalTransition');
        } catch (IllegalTransition $e) {
            self::assertSame('draft', $e->state);
        }
        // double submit
        $this->svc()->submit('entryaaa0003', 'en', 'author000001', null);
        $this->expectException(IllegalTransition::class);
        $this->svc()->submit('entryaaa0003', 'en', 'author000001', null);
    }

    public function testSelfReviewBlocked(): void
    {
        $this->svc()->submit('entryaaa0004', 'en', 'author000001', null);
        $this->expectException(WorkflowForbidden::class);
        $this->svc()->approve('entryaaa0004', 'en', 'author000001', null);
    }

    public function testWithdrawRules(): void
    {
        $this->svc()->submit('entryaaa0005', 'en', 'author000001', null);
        // a stranger (not submitter, not reviewer) may not withdraw
        try {
            $this->svc()->withdraw('entryaaa0005', 'en', 'stranger0001', false);
            self::fail('expected WorkflowForbidden');
        } catch (WorkflowForbidden) {
            $this->addToAssertionCount(1);
        }
        // the submitter may
        $o = $this->svc()->withdraw('entryaaa0005', 'en', 'author000001', false);
        self::assertSame('draft', $o['state']);
        self::assertNull($o['submitted_by'], 'withdraw clears submission attribution');
    }

    public function testEditInvalidation(): void
    {
        $this->svc()->submit('entryaaa0006', 'en', 'author000001', null);
        $this->svc()->invalidateOnEdit('entryaaa0006', 'en', 'author000001');
        self::assertSame('draft', $this->svc()->overview('entryaaa0006', 'en')['state']);

        // changes_requested SURVIVES edits (spec: submit is the only transition that clears it)
        $this->svc()->submit('entryaaa0006', 'en', 'author000001', null);
        $this->svc()->requestChanges('entryaaa0006', 'en', 'review000001', 'more');
        $this->svc()->invalidateOnEdit('entryaaa0006', 'en', 'author000001');
        self::assertSame('changes_requested', $this->svc()->overview('entryaaa0006', 'en')['state']);
    }

    public function testRecordPublishConsumesApprovalAndRecordsBypass(): void
    {
        // approved → published
        $this->svc()->submit('entryaaa0007', 'en', 'author000001', null);
        $this->svc()->approve('entryaaa0007', 'en', 'review000001', null);
        $this->svc()->recordPublish('entryaaa0007', 'en', 'admin0000001');
        $o = $this->svc()->overview('entryaaa0007', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published', $o['history'][0]['action']);

        // in_review → published_with_bypass
        $this->svc()->submit('entryaaa0008', 'en', 'author000001', null);
        $this->svc()->recordPublish('entryaaa0008', 'en', 'admin0000001');
        $o = $this->svc()->overview('entryaaa0008', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published_with_bypass', $o['history'][0]['action']);
    }
}
```

- [ ] **Step 2: Run to verify failure** — class-not-found on `WorkflowService`.

- [ ] **Step 3: Exceptions and events**

`packages/lemma-workflow/src/IllegalTransition.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

/** The requested transition is not legal from the current state. Maps to HTTP 409. */
final class IllegalTransition extends \RuntimeException
{
    public function __construct(string $message, public readonly string $state)
    {
        parent::__construct($message);
    }
}
```

`packages/lemma-workflow/src/WorkflowForbidden.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

/** The actor may not perform this transition (self-review, foreign withdraw). Maps to 403. */
final class WorkflowForbidden extends \RuntimeException
{
}
```

`packages/lemma-workflow/src/Events/ReviewSubmitted.php` (ReviewApproved is identical with its name; ChangesRequested adds the note param):

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow\Events;

use Glueful\Events\Contracts\BaseEvent;

final class ReviewSubmitted extends BaseEvent
{
    public function __construct(
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $actor,
    ) {
        parent::__construct();
    }
}
```

```php
// ChangesRequested constructor signature:
    public function __construct(
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $actor,
        public readonly ?string $note,
    ) {
        parent::__construct();
    }
```

- [ ] **Step 4: WorkflowStateRepository**

`packages/lemma-workflow/src/WorkflowStateRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Database\Connection;

/**
 * Reads/writes the review-state row (one per entry+locale; absent ≡ draft) and the
 * append-only transition history. setState() is an atomic ON CONFLICT upsert (Postgres,
 * the app's database — the lemma-seo/analytics pattern).
 */
final class WorkflowStateRepository
{
    private const ATTRS = ['submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('workflow_review_states')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        return $row === null ? null : (array) $row;
    }

    public function stateOf(string $entryUuid, string $locale): string
    {
        return (string) ($this->find($entryUuid, $locale)['state'] ?? 'draft');
    }

    /** @param array<string, string|null> $attrs subset of submitted_by/at, reviewed_by/at */
    public function setState(string $entryUuid, string $locale, string $state, array $attrs = []): void
    {
        $payload = ['state' => $state];
        foreach (self::ATTRS as $attr) {
            if (array_key_exists($attr, $attrs)) {
                $payload[$attr] = $attrs[$attr];
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $insert = $payload + ['entry_uuid' => $entryUuid, 'locale' => $locale, 'updated_at' => $now];

        $sets = ['updated_at = excluded.updated_at'];
        foreach (array_keys($payload) as $col) {
            $sets[] = $col . ' = excluded.' . $col;
        }
        $cols = array_keys($insert);
        $sql = 'INSERT INTO workflow_review_states (' . implode(', ', $cols) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT (entry_uuid, locale) DO UPDATE SET ' . implode(', ', $sets);
        $this->db->getPDO()->prepare($sql)->execute(array_values($insert));
    }

    public function record(
        string $entryUuid,
        string $locale,
        string $from,
        string $to,
        string $action,
        ?string $actor,
        ?string $note = null,
    ): void {
        $this->db->table('workflow_transitions')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'from_state' => $from,
            'to_state' => $to,
            'action' => $action,
            'actor_uuid' => $actor,
            'note' => $note,
            'metadata' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function queuePage(string $state, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $pdo = $this->db->getPDO();

        $count = $pdo->prepare('SELECT COUNT(*) FROM workflow_review_states WHERE state = ?');
        $count->execute([$state]);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT entry_uuid, locale, state, submitted_by, submitted_at FROM workflow_review_states'
            . ' WHERE state = ? ORDER BY submitted_at ASC NULLS LAST, id ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$state, $perPage, ($page - 1) * $perPage]);

        return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** @return list<array<string,mixed>> newest first */
    public function history(string $entryUuid, string $locale, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->getPDO()->prepare(
            'SELECT from_state, to_state, action, actor_uuid, note, created_at'
            . ' FROM workflow_transitions WHERE entry_uuid = ? AND locale = ?'
            . ' ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$entryUuid, $locale]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

- [ ] **Step 5: WorkflowService**

`packages/lemma-workflow/src/WorkflowService.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Lemma\Workflow\Events\ChangesRequested;
use Glueful\Lemma\Workflow\Events\ReviewApproved;
use Glueful\Lemma\Workflow\Events\ReviewSubmitted;

use function config;

/**
 * The single-stage review state machine (spec: 2026-07-02-approval-workflow-design.md §3).
 * Explicit transitions throw IllegalTransition (409) / WorkflowForbidden (403); the
 * automatic rules (invalidateOnEdit / recordPublish) are driven by the lifecycle listener.
 */
final class WorkflowService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly WorkflowStateRepository $states,
        private readonly EventService $events,
    ) {
    }

    /**
     * @return array{state:string, submitted_by:?string, submitted_at:?string,
     *   reviewed_by:?string, reviewed_at:?string, history: list<array<string,mixed>>}
     */
    public function overview(string $entryUuid, string $locale): array
    {
        $row = $this->states->find($entryUuid, $locale) ?? [];
        return [
            'state' => (string) ($row['state'] ?? 'draft'),
            'submitted_by' => isset($row['submitted_by']) ? (string) $row['submitted_by'] : null,
            'submitted_at' => isset($row['submitted_at']) ? (string) $row['submitted_at'] : null,
            'reviewed_by' => isset($row['reviewed_by']) ? (string) $row['reviewed_by'] : null,
            'reviewed_at' => isset($row['reviewed_at']) ? (string) $row['reviewed_at'] : null,
            'history' => $this->states->history($entryUuid, $locale),
        ];
    }

    /** @return array<string,mixed> */
    public function submit(string $entryUuid, string $locale, string $actor, ?string $note): array
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        if (!in_array($from, ['draft', 'changes_requested'], true)) {
            throw new IllegalTransition("Cannot submit for review from state \"{$from}\".", $from);
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->states->setState($entryUuid, $locale, 'in_review', [
            'submitted_by' => $actor,
            'submitted_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->states->record($entryUuid, $locale, $from, 'in_review', 'submit', $actor, $note);
        $this->events->dispatch(new ReviewSubmitted($entryUuid, $locale, $actor));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function approve(string $entryUuid, string $locale, string $actor, ?string $note): array
    {
        $row = $this->requireState($entryUuid, $locale, 'in_review', 'approve');
        $allowSelf = (bool) config($this->context, 'lemma_workflow.allow_self_review', false);
        if (!$allowSelf && (string) ($row['submitted_by'] ?? '') === $actor) {
            throw new WorkflowForbidden('The submitter cannot approve their own submission.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->states->setState($entryUuid, $locale, 'approved', [
            'reviewed_by' => $actor,
            'reviewed_at' => $now,
        ]);
        $this->states->record($entryUuid, $locale, 'in_review', 'approved', 'approve', $actor, $note);
        $this->events->dispatch(new ReviewApproved($entryUuid, $locale, $actor));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function requestChanges(string $entryUuid, string $locale, string $actor, string $note): array
    {
        $this->requireState($entryUuid, $locale, 'in_review', 'request changes on');
        $now = gmdate('Y-m-d H:i:s');
        $this->states->setState($entryUuid, $locale, 'changes_requested', [
            'reviewed_by' => $actor,
            'reviewed_at' => $now,
        ]);
        $this->states->record(
            $entryUuid,
            $locale,
            'in_review',
            'changes_requested',
            'request_changes',
            $actor,
            $note,
        );
        $this->events->dispatch(new ChangesRequested($entryUuid, $locale, $actor, $note));
        return $this->overview($entryUuid, $locale);
    }

    /** @return array<string,mixed> */
    public function withdraw(string $entryUuid, string $locale, string $actor, bool $actorIsReviewer): array
    {
        $row = $this->requireState($entryUuid, $locale, 'in_review', 'withdraw');
        if (!$actorIsReviewer && (string) ($row['submitted_by'] ?? '') !== $actor) {
            throw new WorkflowForbidden('Only the submitter or a reviewer may withdraw a submission.');
        }
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, 'in_review', 'draft', 'withdraw', $actor);
        return $this->overview($entryUuid, $locale);
    }

    /** Spec rule: edits invalidate ACTIVE review/approval; changes_requested survives. */
    public function invalidateOnEdit(string $entryUuid, string $locale, ?string $actor): void
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        if (!in_array($from, ['in_review', 'approved'], true)) {
            return;
        }
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, $from, 'draft', 'edit_invalidated', $actor);
    }

    /**
     * Single history writer for publishes (spec §4): approved → 'published'; anything else
     * necessarily passed the gate via bypass → 'published_with_bypass'. Then the approval
     * is consumed: state resets to draft.
     */
    public function recordPublish(string $entryUuid, string $locale, ?string $actor): void
    {
        $from = $this->states->stateOf($entryUuid, $locale);
        $action = $from === 'approved' ? 'published' : 'published_with_bypass';
        $this->resetToDraft($entryUuid, $locale);
        $this->states->record($entryUuid, $locale, $from, 'draft', $action, $actor);
    }

    /** @return array<string,mixed> the current row */
    private function requireState(string $entryUuid, string $locale, string $required, string $verb): array
    {
        $row = $this->states->find($entryUuid, $locale) ?? ['state' => 'draft'];
        $state = (string) ($row['state'] ?? 'draft');
        if ($state !== $required) {
            throw new IllegalTransition("Cannot {$verb} a submission in state \"{$state}\".", $state);
        }
        return $row;
    }

    private function resetToDraft(string $entryUuid, string $locale): void
    {
        $this->states->setState($entryUuid, $locale, 'draft', [
            'submitted_by' => null,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }
}
```

- [ ] **Step 6: Register in the provider** — in `LemmaWorkflowServiceProvider::services()` (add `use` imports for both classes):

```php
        return [
            WorkflowStateRepository::class => [
                'class' => WorkflowStateRepository::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowService::class => [
                'class' => WorkflowService::class, 'shared' => true, 'autowire' => true,
            ],
        ];
```

Then `php glueful extensions:cache 2>/dev/null || true` (recompile so autowire sees the new services).

- [ ] **Step 7: Run the tests** — `vendor/bin/phpunit tests/Integration/Workflow/WorkflowTransitionsTest.php` → PASS (7 tests). Also `vendor/bin/phpcs -q packages/lemma-workflow/` → clean.

---

### Task 5: WorkflowPublishGate (enforcement)

**Files:**
- Create: `packages/lemma-workflow/src/WorkflowPublishGate.php`
- Modify: `packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (gate factory + `lemma.publish_gate` tag)
- Test: `tests/Integration/Workflow/WorkflowPublishGateTest.php`
- Create: `tests/Integration/Workflow/Concerns/GrantsPermissions.php` (test helper)

**Interfaces:**
- Consumes: `PublishGate`/`PublishBlocked` (Task 1), `WorkflowStateRepository::stateOf` (Task 4), `Glueful\Permissions\PermissionManager::can(string $userUuid, string $permission, string $resource, array $context): bool` (framework), `CapabilityRegistry::isEnabled` (contracts).
- Produces: gate service tagged `lemma.publish_gate`; permission check resource string `"locale:{$locale}"` (mirrors `RequireLemmaPermission::resourceFor`).

- [ ] **Step 1: Test helper** — `tests/Integration/Workflow/Concerns/GrantsPermissions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow\Concerns;

use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

/**
 * Grants a permission slug to a user via a per-user test role (permission + role +
 * role_permissions via SQL, user link via Aegis). Use FRESH user uuids per test — the
 * permission provider may cache per-user lookups within a process.
 */
trait GrantsPermissions
{
    private function grantPermission(string $userUuid, string $slug): void
    {
        $db = $this->connection();
        $perm = $db->table('permissions')->select(['uuid'])->where('slug', '=', $slug)->first();
        $permUuid = $perm !== null ? (string) $perm['uuid'] : Utils::generateNanoID();
        if ($perm === null) {
            $db->table('permissions')->insert([
                'uuid' => $permUuid, 'slug' => $slug, 'name' => $slug,
                'category' => 'test', 'description' => $slug, 'is_system' => false,
            ]);
        }
        $roleSlug = 'testrole-' . substr(hash('sha256', $userUuid . $slug), 0, 8);
        $role = $db->table('roles')->select(['uuid'])->where('slug', '=', $roleSlug)->first();
        $roleUuid = $role !== null ? (string) $role['uuid'] : Utils::generateNanoID();
        if ($role === null) {
            $db->table('roles')->insert([
                'uuid' => $roleUuid, 'slug' => $roleSlug, 'name' => $roleSlug,
            ]);
            $db->table('role_permissions')->insert([
                'role_uuid' => $roleUuid, 'permission_uuid' => $permUuid,
            ]);
        }
        $assigned = $this->container()->get(AegisPermissionProvider::class)
            ->assignRole($userUuid, $roleSlug);
        if (!$assigned) {
            self::fail("could not assign test role {$roleSlug} to {$userUuid}");
        }
    }
}
```

(If `roles`/`role_permissions` require extra NOT NULL columns at insert time, mirror the columns used in `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` — that file is the authoritative shape.)

- [ ] **Step 2: Write the failing tests** — `tests/Integration/Workflow/WorkflowPublishGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Content\Services\PublishService;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Integration\Workflow\Concerns\GrantsPermissions;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\PublishBlocked;
use Glueful\Lemma\Workflow\WorkflowService;

final class WorkflowPublishGateTest extends LemmaTestCase
{
    use GrantsPermissions;
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    public function testUnapprovedPublishIsBlocked(): void
    {
        // The seed actor holds workflow.bypass suite-wide (see Step 5) so fixture publishes
        // pass the gate; the blocking assertion uses a DIFFERENT, bypass-less actor.
        $entry = $this->seedBilingualPublishedEntry();
        $this->expectException(PublishBlocked::class);
        $this->container()->get(PublishService::class)->publish($entry, 'en', 'nobody000001');
    }

    public function testApprovedPublishSucceedsAndBypassWorks(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $wf = $this->container()->get(WorkflowService::class);

        // approved → publishes
        $wf->submit($entry, 'en', 'author000001', null);
        $wf->approve($entry, 'en', 'review000001', null);
        $version = $this->container()->get(PublishService::class)->publish($entry, 'en', 'author000001');
        self::assertNotSame('', $version);

        // bypass holder → publishes from bare draft
        $admin = 'bypasser' . substr(hash('sha256', __FUNCTION__), 0, 4);
        $this->grantPermission($admin, 'workflow.bypass');
        $version = $this->container()->get(PublishService::class)->publish($entry, 'en', $admin);
        self::assertNotSame('', $version);
    }

    public function testGateAllowsEverythingWhenCapabilityDisabled(): void
    {
        // Simulate the switchboard-disabled capability by constructing the gate against a
        // registry where lemma.workflow is not enabled.
        $registry = new \App\Capabilities\DefaultCapabilityRegistry(['lemma.workflow' => false]);
        $registry->register(new \Glueful\Lemma\Contracts\Capability\Capability('lemma.workflow'));
        $gate = new \Glueful\Lemma\Workflow\WorkflowPublishGate(
            $registry,
            $this->container()->get(\Glueful\Lemma\Workflow\WorkflowStateRepository::class),
            null,
        );
        $gate->assertCanPublish('anyentry0001', 'en', null); // must not throw
        $this->addToAssertionCount(1);
    }
}
```

**Important seeding caveat:** once the gate is live, EVERY publish in the suite goes through it — including `SeedsPublishedContent` fixture publishes (state `draft`, actor `user00000001`), which would otherwise be blocked and break every pre-existing publish-path test (Seo, content, schedule suites). The spec-conformant resolution (the capability stays default-ON): grant `workflow.bypass` to the seed actor **once, suite-wide**, in the shared test bootstrap — Step 5 below. Workflow tests that want to observe blocking use distinct bypass-less actors (as the tests above do).

- [ ] **Step 3: The gate**

`packages/lemma-workflow/src/WorkflowPublishGate.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Lemma\Contracts\Authoring\PublishBlocked;
use Glueful\Lemma\Contracts\Authoring\PublishGate;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Permissions\PermissionManager;

/**
 * The workflow pack's publish gate: allow when the review state is `approved`, or when the
 * actor holds `workflow.bypass`; otherwise throw PublishBlocked (409).
 *
 * The capability check lives HERE (not in registration): container tags come from the
 * compile-time services() map, so this gate is collected by PublishService even when
 * lemma.workflow is disabled — disabled must mean "publish behaves as current core".
 */
final class WorkflowPublishGate implements PublishGate
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly WorkflowStateRepository $states,
        private readonly ?PermissionManager $permissions,
    ) {
    }

    public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
    {
        if (!$this->capabilities->isEnabled('lemma.workflow')) {
            return;
        }
        $state = $this->states->stateOf($entryUuid, $locale);
        if ($state === 'approved') {
            return;
        }
        // Resource mirrors RequireLemmaPermission::resourceFor() for locale routes.
        if (
            $actorUuid !== null
            && $this->permissions !== null
            && $this->permissions->can($actorUuid, 'workflow.bypass', "locale:{$locale}", [])
        ) {
            return;
        }
        throw new PublishBlocked(
            "Publishing requires an approved review (current state: {$state}).",
            $state,
        );
    }
}
```

- [ ] **Step 4: Register + tag in the provider** — add to `services()` (with `use Psr\Container\ContainerInterface;`, `use Glueful\Permissions\PermissionManager;` imports):

```php
            WorkflowPublishGate::class => [
                'shared' => true,
                'factory' => [self::class, 'makeWorkflowPublishGate'],
                'tags' => ['lemma.publish_gate'],
            ],
```

and the factory (dual-id PermissionManager lookup, the `RequireLemmaPermission` convention):

```php
    public static function makeWorkflowPublishGate(ContainerInterface $container): WorkflowPublishGate
    {
        $permissions = null;
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                $permissions = $m;
                break;
            }
        }
        return new WorkflowPublishGate(
            $container->get(CapabilityRegistry::class),
            $container->get(WorkflowStateRepository::class),
            $permissions,
        );
    }
```

Recompile: `php glueful extensions:cache 2>/dev/null || true`.

- [ ] **Step 5: Add the suite-wide seed-actor bypass (TEST HARNESS ONLY)** — in `App\Tests\Support\LemmaTestCase` (or its boot helper), after migrations, grant `workflow.bypass` to `user00000001` once (guard on existing grant; reuse the `GrantsPermissions` SQL inline or extract a small static helper). This grant lives **only under `tests/Support/`** — never in a pack migration, dependent migration, or seed. The only production grant is `administrator` via `009_GrantWorkflowPermissionsToAdministrator.php`:

```php
        // TEST HARNESS ONLY: the workflow publish gate is live suite-wide, and the shared
        // seeding actor publishes fixture content without going through review — grant it
        // workflow.bypass once so pre-existing publish-path tests behave exactly as before
        // the gate existed. Production grants are ONLY the administrator dependent migration.
```

- [ ] **Step 6: Run** — `vendor/bin/phpunit tests/Integration/Workflow/` → PASS, then the FULL suite `vendor/bin/phpunit` → all green (this proves the seed-actor strategy). phpcs clean.

- [ ] **Step 7: COMMIT 2 (state machine + enforcement)**

```bash
git add packages/lemma-workflow/ database/dependent-migrations/009_GrantWorkflowPermissionsToAdministrator.php \
  tests/Integration/Workflow/ tests/Support/
git commit -m "lemma-workflow: state machine, migrations, publish gate

- workflow_review_states + workflow_transitions (+ metadata forward seam),
  workflow.review/bypass permissions seeded; administrator granted via
  dependent migration.
- WorkflowService: submit/approve/request-changes/withdraw with self-review
  block (config escape hatch), edit-invalidation and publish-consumption
  rules per the spec; append-only history incl. published_with_bypass.
- WorkflowPublishGate tagged lemma.publish_gate: approved-or-bypass at
  publish time; capability check inside the gate (tags are compile-time).
- Test seed actor granted workflow.bypass suite-wide so pre-existing
  publish-path tests are unaffected."
```

---

### Task 6: Lifecycle listener (automatic transitions)

**Files:**
- Create: `packages/lemma-workflow/src/WorkflowLifecycleListener.php`
- Modify: `packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (register listener service + wire in `boot()` inside `isEnabled`)
- Test: `tests/Integration/Workflow/WorkflowLifecycleTest.php`

**Interfaces:**
- Consumes: `ContentLifecycleEvent::name()/payload()` (contracts), `WorkflowService::invalidateOnEdit/recordPublish` (Task 4), `EventService::addListener` (framework).

- [ ] **Step 1: Write the failing test** — dispatch REAL App events through the container `EventService` (tests are App-side; the PACK never imports them):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUpdated;
use App\Tests\Support\LemmaTestCase;
use Glueful\Events\EventService;
use Glueful\Lemma\Workflow\WorkflowService;

final class WorkflowLifecycleTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    public function testDraftSaveInvalidatesInReview(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit('entrylife001', 'en', 'author000001', null);

        $this->container()->get(EventService::class)->dispatch(new EntryUpdated(
            entry: 'entrylife001',
            type: 'typeuuid0001',
            locale: 'en',
            version: null,
            actor: 'author000001',
        ));

        $o = $wf->overview('entrylife001', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('edit_invalidated', $o['history'][0]['action']);
    }

    public function testPublishEventRecordsBypassAndResets(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit('entrylife002', 'en', 'author000001', null);

        $this->container()->get(EventService::class)->dispatch(new EntryPublished(
            entry: 'entrylife002',
            type: 'typeuuid0001',
            locale: 'en',
            version: 'version00001',
            actor: 'admin0000001',
        ));

        $o = $wf->overview('entrylife002', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published_with_bypass', $o['history'][0]['action']);
    }
}
```

(Check `BaseEntryEvent`'s constructor signature — the named args above mirror `EntryRepository::createEntry`'s `EntryCreated(entry:, type:, locale:, version:, actor:)` usage; adjust to the real signature if it differs.)

- [ ] **Step 2: Run to verify failure** — the state stays `in_review` (no listener yet).

- [ ] **Step 3: The listener** — `packages/lemma-workflow/src/WorkflowLifecycleListener.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Lemma\Contracts\Events\ContentLifecycleEvent;

/**
 * Automatic state rules over the CONTRACT lifecycle surface — name()/payload() only,
 * never the concrete App\Content\Events classes (pack boundary).
 *   entry.updated   → edits invalidate active review/approval (changes_requested survives)
 *   entry.published → record published / published_with_bypass, consume the approval
 */
final class WorkflowLifecycleListener
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    public function onContentChanged(ContentLifecycleEvent $event): void
    {
        $payload = $event->payload();
        $entry = $payload['entry'] ?? null;
        $locale = $payload['locale'] ?? null;
        if (!is_string($entry) || $entry === '' || !is_string($locale) || $locale === '') {
            return;
        }
        $actor = is_string($payload['actor'] ?? null) ? $payload['actor'] : null;

        match ($event->name()) {
            'entry.updated' => $this->workflow->invalidateOnEdit($entry, $locale, $actor),
            'entry.published' => $this->workflow->recordPublish($entry, $locale, $actor),
            default => null,
        };
    }
}
```

- [ ] **Step 4: Register + wire** — add to `services()`:

```php
            WorkflowLifecycleListener::class => [
                'class' => WorkflowLifecycleListener::class, 'shared' => true, 'autowire' => true,
            ],
```

and in `boot()` (imports: `Glueful\Events\EventService`, `Glueful\Lemma\Contracts\Events\ContentLifecycleEvent`), after `loadMigrationsFrom`:

```php
        if ($registry->isEnabled('lemma.workflow')) {
            $events = app($context, EventService::class);
            $listener = app($context, WorkflowLifecycleListener::class);
            $events->addListener(ContentLifecycleEvent::class, [$listener, 'onContentChanged']);
        }
```

Recompile extension cache; run the test → PASS.

---

### Task 7: HTTP API (controller, DTO, routes, queue)

**Files:**
- Create: `packages/lemma-workflow/src/Http/WorkflowNoteDTO.php`
- Create: `packages/lemma-workflow/src/Http/Controllers/WorkflowController.php`
- Create: `packages/lemma-workflow/routes/admin-routes.php`
- Modify: `packages/lemma-workflow/src/LemmaWorkflowServiceProvider.php` (controller service + `loadRoutesFrom` inside the `isEnabled` block)
- Test: `tests/Integration/Workflow/WorkflowApiTest.php`

**Interfaces:**
- Consumes: `WorkflowService` (Task 4), `DraftSummaryReader` (Task 1), `PermissionManager` (Task 5's dual-id factory pattern), `Response::success/error`, `Glueful\Validation\{Validator, ValidationException, Rules\{Required, Sanitize, Length}}`.
- Produces routes under `/v1/admin/workflow` per the spec table (submit/approve/request-changes/withdraw/show/queue).

- [ ] **Step 1: Write the failing tests** — `tests/Integration/Workflow/WorkflowApiTest.php` (drive the container controller directly, the `SeoMetaEndpointTest` style):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Integration\Workflow\Concerns\GrantsPermissions;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Workflow\Http\Controllers\WorkflowController;
use Symfony\Component\HttpFoundation\Request;

final class WorkflowApiTest extends LemmaTestCase
{
    use GrantsPermissions;
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM workflow_transitions');
        $pdo->exec('DELETE FROM workflow_review_states');
    }

    /** Build a request whose 'user' attribute carries the acting uuid (post-auth shape). */
    private function req(string $actor, array $body = []): Request
    {
        $r = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body));
        $r->attributes->set('user', ['uuid' => $actor, 'roles' => [], 'scopes' => []]);
        return $r;
    }

    public function testSubmitApproveRoundTrip(): void
    {
        $this->grantPermission('user00000001', 'workflow.bypass');
        $entry = $this->seedBilingualPublishedEntry();
        $c = $this->container()->get(WorkflowController::class);

        $res = $c->submit($this->req('author000001'), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('in_review', json_decode((string) $res->getContent(), true)['data']['state']);

        $res = $c->approve($this->req('review000001', ['note' => 'ship it']), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('approved', json_decode((string) $res->getContent(), true)['data']['state']);
    }

    public function testIllegalTransitionIs409AndSelfReview403(): void
    {
        $this->grantPermission('user00000001', 'workflow.bypass');
        $entry = $this->seedBilingualPublishedEntry();
        $c = $this->container()->get(WorkflowController::class);

        self::assertSame(409, $c->approve($this->req('review000001'), $entry, 'en')->getStatusCode());

        $c->submit($this->req('author000001'), $entry, 'en');
        self::assertSame(403, $c->approve($this->req('author000001'), $entry, 'en')->getStatusCode());
    }

    public function testRequestChangesRequiresNote(): void
    {
        $this->grantPermission('user00000001', 'workflow.bypass');
        $entry = $this->seedBilingualPublishedEntry();
        $c = $this->container()->get(WorkflowController::class);
        $c->submit($this->req('author000001'), $entry, 'en');

        try {
            $c->requestChanges($this->req('review000001', []), $entry, 'en');
            self::fail('expected ValidationException (note required)');
        } catch (\Glueful\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUnknownEntryIs404(): void
    {
        $c = $this->container()->get(WorkflowController::class);
        self::assertSame(404, $c->submit($this->req('author000001'), 'missing00001', 'en')->getStatusCode());
    }

    public function testQueueListsInReviewWithSummaries(): void
    {
        $this->grantPermission('user00000001', 'workflow.bypass');
        $entry = $this->seedBilingualPublishedEntry();
        $c = $this->container()->get(WorkflowController::class);
        $c->submit($this->req('author000001'), $entry, 'en');

        $res = $c->queue(Request::create('/x', 'GET'));
        $data = json_decode((string) $res->getContent(), true)['data'];
        self::assertSame(1, $data['total']);
        self::assertSame($entry, $data['items'][0]['entry_uuid']);
        self::assertSame('Hello', $data['items'][0]['title']);
        self::assertSame('blog', $data['items'][0]['type_slug']);
    }

    public function testRoutesAreRegisteredWithPermissions(): void
    {
        $route = $this->findRoute('POST', '/v1/admin/workflow/entries/{uuid}/{locale}/approve');
        self::assertNotNull($route);
        self::assertContains('lemma_permission:workflow.review', (array) ($route['middleware'] ?? []));
        $route = $this->findRoute('GET', '/v1/admin/workflow/queue');
        self::assertNotNull($route);
        self::assertContains('lemma_permission:workflow.review', (array) ($route['middleware'] ?? []));
    }
}
```

- [ ] **Step 2: Run to verify failure** — controller class not found.

- [ ] **Step 3: The DTO** — `packages/lemma-workflow/src/Http/WorkflowNoteDTO.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow\Http;

use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Sanitize;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;

/** Validates the optional/required transition note (request-changes REQUIRES one). */
final class WorkflowNoteDTO
{
    public function __construct(public readonly ?string $note)
    {
    }

    /** @param array<string,mixed> $body */
    public static function fromRequest(array $body, bool $required): self
    {
        $rules = $required
            ? ['note' => [new Required(), new Sanitize(['trim']), new Type('string'), new Length(1, 2000)]]
            : ['note' => [new Sanitize(['trim']), new Type('string'), new Length(0, 2000)]];

        $validator = new Validator($rules);
        $errors = $validator->validate(['note' => $body['note'] ?? null]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $clean = $validator->filtered();
        $note = is_string($clean['note'] ?? null) && $clean['note'] !== '' ? $clean['note'] : null;
        return new self($note);
    }
}
```

- [ ] **Step 4: The controller** — `packages/lemma-workflow/src/Http/Controllers/WorkflowController.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Authoring\DraftSummaryReader;
use Glueful\Lemma\Workflow\Http\WorkflowNoteDTO;
use Glueful\Lemma\Workflow\IllegalTransition;
use Glueful\Lemma\Workflow\WorkflowForbidden;
use Glueful\Lemma\Workflow\WorkflowService;
use Glueful\Lemma\Workflow\WorkflowStateRepository;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin review-workflow API. Route-gated (capability → auth → lemma_permission); the
 * finer rules (self-review, withdraw ownership, note-required) live in the service/DTO.
 */
final class WorkflowController
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly WorkflowStateRepository $states,
        private readonly DraftSummaryReader $drafts,
        private readonly ?PermissionManager $permissions,
    ) {
    }

    #[ApiOperation(summary: 'Submit a draft for review', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(404, description: 'Unknown entry/locale (no draft).')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function submit(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition($request, $uuid, $locale, function (string $actor, ?string $note) use ($uuid, $locale) {
            return $this->workflow->submit($uuid, $locale, $actor, $note);
        }, requireNote: false);
    }

    #[ApiOperation(summary: 'Approve a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(403, description: 'Self-review blocked.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function approve(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition($request, $uuid, $locale, function (string $actor, ?string $note) use ($uuid, $locale) {
            return $this->workflow->approve($uuid, $locale, $actor, $note);
        }, requireNote: false);
    }

    #[ApiOperation(summary: 'Request changes on a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    #[ApiResponse(422, description: 'A note is required.')]
    public function requestChanges(Request $request, string $uuid, string $locale): Response
    {
        return $this->transition($request, $uuid, $locale, function (string $actor, ?string $note) use ($uuid, $locale) {
            return $this->workflow->requestChanges($uuid, $locale, $actor, (string) $note);
        }, requireNote: true);
    }

    #[ApiOperation(summary: 'Withdraw a submission', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'The review state after the transition.')]
    #[ApiResponse(403, description: 'Only the submitter or a reviewer may withdraw.')]
    #[ApiResponse(409, description: 'Illegal transition from the current state.')]
    public function withdraw(Request $request, string $uuid, string $locale): Response
    {
        $isReviewer = false;
        $actor = $this->actor($request);
        if ($actor !== null && $this->permissions !== null) {
            $isReviewer = $this->permissions->can($actor, 'workflow.review', "locale:{$locale}", []);
        }
        return $this->transition($request, $uuid, $locale, function (string $a, ?string $n) use ($uuid, $locale, $isReviewer) {
            return $this->workflow->withdraw($uuid, $locale, $a, $isReviewer);
        }, requireNote: false);
    }

    #[ApiOperation(summary: 'Review state + history for an entry/locale', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'State row (draft default) + recent history.')]
    public function show(Request $request, string $uuid, string $locale): Response
    {
        return Response::success($this->workflow->overview($uuid, $locale));
    }

    #[ApiOperation(summary: 'Review queue (in_review submissions)', tags: ['Lemma Workflow'])]
    #[ApiResponse(200, description: 'Paginated in-review items enriched with draft summaries.')]
    public function queue(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min((int) $request->query->get('perPage', 25), 100));
        $result = $this->states->queuePage('in_review', $page, $perPage);

        $items = [];
        foreach ($result['items'] as $row) {
            $summary = $this->drafts->summary((string) $row['entry_uuid'], (string) $row['locale']);
            $items[] = [
                'entry_uuid' => (string) $row['entry_uuid'],
                'locale' => (string) $row['locale'],
                'submitted_by' => $row['submitted_by'] !== null ? (string) $row['submitted_by'] : null,
                'submitted_at' => $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
                'title' => $summary['title'] ?? null,
                'type_slug' => $summary['type_slug'] ?? null,
            ];
        }
        return Response::success([
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    /** @param callable(string, ?string): array<string,mixed> $fn */
    private function transition(Request $request, string $uuid, string $locale, callable $fn, bool $requireNote): Response
    {
        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::error('Unauthenticated.', 401);
        }
        if ($this->drafts->summary($uuid, $locale) === null) {
            return Response::error('Unknown entry or locale.', 404);
        }
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $note = WorkflowNoteDTO::fromRequest($body, $requireNote)->note; // throws 422

        try {
            return Response::success($fn($actor, $note));
        } catch (IllegalTransition $e) {
            return Response::error($e->getMessage(), 409, ['workflow_state' => $e->state]);
        } catch (WorkflowForbidden $e) {
            return Response::error($e->getMessage(), 403);
        }
    }

    private function actor(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user');
        return is_string($user['uuid'] ?? null) && $user['uuid'] !== '' ? $user['uuid'] : null;
    }
}
```

- [ ] **Step 5: Routes** — `packages/lemma-workflow/routes/admin-routes.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Lemma\Workflow\Http\Controllers\WorkflowController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin review-workflow API. Triple-gated like the other packs:
 *   1. capability       — this file loads only when lemma.workflow is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. lemma_permission — per-route slug. Withdraw is gated content.view only: a reviewer
 *      may lack content.edit; the submitter-or-reviewer rule is enforced in the service (403).
 */
$router->group(
    ['prefix' => '/v1/admin/workflow', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->post('/entries/{uuid}/{locale}/submit', [WorkflowController::class, 'submit'])
            ->middleware('lemma_permission:content.edit');
        $router->post('/entries/{uuid}/{locale}/approve', [WorkflowController::class, 'approve'])
            ->middleware('lemma_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/request-changes', [WorkflowController::class, 'requestChanges'])
            ->middleware('lemma_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/withdraw', [WorkflowController::class, 'withdraw'])
            ->middleware('lemma_permission:content.view');
        $router->get('/entries/{uuid}/{locale}', [WorkflowController::class, 'show'])
            ->middleware('lemma_permission:content.view');
        $router->get('/queue', [WorkflowController::class, 'queue'])
            ->middleware('lemma_permission:workflow.review');
    },
);
```

- [ ] **Step 6: Provider wiring** — controller service (factory reusing the dual-id PermissionManager lookup):

```php
            WorkflowController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeWorkflowController'],
            ],
```

```php
    public static function makeWorkflowController(ContainerInterface $container): WorkflowController
    {
        $permissions = null;
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                $permissions = $m;
                break;
            }
        }
        return new WorkflowController(
            $container->get(WorkflowService::class),
            $container->get(WorkflowStateRepository::class),
            $container->get(DraftSummaryReader::class),
            $permissions,
        );
    }
```

and in `boot()` inside the `isEnabled` block: `$this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');`

- [ ] **Step 7: Run** — `vendor/bin/phpunit tests/Integration/Workflow/WorkflowApiTest.php` → PASS; phpcs clean.

---

### Task 8: Scheduled publish semantics tests

**Files:**
- Test: `tests/Integration/Workflow/WorkflowScheduledPublishTest.php`

**Interfaces:**
- Consumes: `App\Content\Scheduling\ScheduleRunner`, `App\Content\Repositories\ScheduleRepository` (table `entry_schedules` with `created_by`), the gate (Task 5).

- [ ] **Step 1: Write the tests** — inspect `ScheduleRepository`'s insert/claim API first (`app/Content/Repositories/ScheduleRepository.php`) and `ScheduleRunner`'s entry point, then write:

```php
public function testScheduledPublishOfUnapprovedContentFails(): void
{
    // seed entry (seed actor has bypass); create a due schedule row with created_by = a
    // NON-bypass user; run the runner; assert the schedule row outcome is 'failed' and the
    // failure message contains 'approved review'.
}

public function testScheduledPublishByBypassHolderSucceeds(): void
{
    // same, but created_by = a user granted workflow.bypass (GrantsPermissions);
    // assert outcome 'done' AND workflow_transitions records published_with_bypass.
}

public function testScheduledPublishOfApprovedContentSucceeds(): void
{
    // submit + approve first (distinct author/reviewer); schedule with non-bypass creator;
    // assert outcome 'done' and history action 'published'.
}
```

Write these as full tests against the real `ScheduleRunner` API (the shape above is the assertion contract; the exact insert/claim calls come from `ScheduleRepository` — mirror how `tests/` already exercises the runner if a precedent test exists, e.g. grep `ScheduleRunner` under `tests/`).

- [ ] **Step 2: Run** — all three PASS with no production changes (this task validates the spec's "uniform rule"; if any fails, the gate or runner wiring is wrong — fix there, not in the test).

---

### Task 9: Removability, boundaries, docs — backend wrap-up

**Files:**
- Test: `tests/Integration/Workflow/WorkflowRemovabilityTest.php`
- Create: `packages/lemma-workflow/README.md`
- Modify: `CHANGELOG.md` ([Unreleased] → Added), `docs/NEXT.md` (mark the roadmap item shipped)

- [ ] **Step 1: Removability/disabled tests** — mirror `SeoRemovabilityTest`/`AnalyticsRoutesGatedTest` (read them first for the harness idioms):

```php
public function testDisabledCapabilityUngatesPublishAndHidesRoutes(): void
{
    // Gate constructed against a DefaultCapabilityRegistry with ['lemma.workflow' => false]
    // allows a draft-state publish (already covered in WorkflowPublishGateTest — keep the
    // route side here): assert the workflow routes are absent when the capability is
    // disabled, following the exact pattern the seo/analytics gated-routes tests use.
}

public function testContractCouplingGuards(): void
{
    // Reflection over pack classes: no App\ references in packages/lemma-workflow/src
    // (mirror ContentImporterWritesViaContractTest::testNoAdapterReferencesAppNamespace,
    // including the leading-backslash regex).
}
```

- [ ] **Step 2: README** — `packages/lemma-workflow/README.md`, modeled on `packages/lemma-seo/README.md`: what it provides (state machine table from the spec §3), the capability + switchboard, the publish gate + bypass + `published_with_bypass` history, self-review config, scheduled-publish semantics, install/remove, out-of-scope list. Copy the spec's tables rather than paraphrasing.

- [ ] **Step 3: CHANGELOG + NEXT.md** — CHANGELOG `[Unreleased]` gets an `### Added` entry: the pack, the `PublishGate` seam, the permissions, the SPA surfaces (SPA lands next tasks — write the entry to cover the whole feature). `docs/NEXT.md`: mark the "Approval / review workflow" bullet ✅ shipped with today's date, same style as the Localization UI bullet.

- [ ] **Step 4: Full verification** — `vendor/bin/phpunit` (full suite green), `vendor/bin/phpcs -q packages/lemma-workflow/ tests/Integration/Workflow/ app/Content/ database/dependent-migrations/`, `composer boundaries` → "Pack boundaries OK (7 package(s) checked)".

- [ ] **Step 5: COMMIT 3 (backend complete)**

```bash
git add packages/lemma-workflow/ tests/Integration/Workflow/ CHANGELOG.md docs/NEXT.md
git commit -m "lemma-workflow: HTTP API, lifecycle listener, scheduler semantics, docs

- /v1/admin/workflow endpoints (submit/approve/request-changes/withdraw/show/
  queue) triple-gated; illegal transitions 409 with workflow_state, self-review
  and foreign-withdraw 403, note required on request-changes (422).
- Contract-only lifecycle listener: entry.updated invalidates active review/
  approval; entry.published records published/published_with_bypass and
  consumes the approval.
- Scheduled publishes follow the uniform gate rule at run time (stored
  created_by actor); blocked schedules mark failed.
- Removability + boundary guards; README; CHANGELOG; NEXT.md marked shipped."
```

---

### Task 10: SPA query module

**Files:**
- Create: `admin/src/queries/workflow.ts`
- Test: `admin/src/__tests__/workflowQueries.spec.ts`

**Interfaces:**
- Produces (used by Tasks 11–12): `WorkflowState` (`state: 'draft'|'in_review'|'approved'|'changes_requested'`, `submitted_by/at`, `reviewed_by/at`, `history`), `fetchWorkflowState(uuid, locale)`, `useWorkflowState(uuid, locale, enabled?)`, `transitionWorkflow(uuid, locale, action: 'submit'|'approve'|'request-changes'|'withdraw', note?)`, `useWorkflowMutations(uuid, locale)`, `fetchWorkflowQueue(page?)` / `useWorkflowQueue(enabled?)` returning `{ items, total, page, perPage }`.

- [ ] **Step 1: Write the module** — follow `admin/src/queries/seo.ts` exactly (authFetch + `qk` keys added to `admin/src/queries/keys.ts`):

```ts
import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { qk } from './keys'

export type WorkflowStateName = 'draft' | 'in_review' | 'approved' | 'changes_requested'
export interface WorkflowTransitionRow {
  from_state: string
  to_state: string
  action: string
  actor_uuid: string | null
  note: string | null
  created_at: string | null
}
export interface WorkflowState {
  state: WorkflowStateName
  submitted_by: string | null
  submitted_at: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  history: WorkflowTransitionRow[]
}
export interface WorkflowQueueItem {
  entry_uuid: string
  locale: string
  submitted_by: string | null
  submitted_at: string | null
  title: string | null
  type_slug: string | null
}

const base = () => `${runtimeConfig.apiBase}/workflow`

export async function fetchWorkflowState(uuid: string, locale: string): Promise<WorkflowState> {
  const json = await authFetch(`${base()}/entries/${uuid}/${locale}`)
  return (json.data ?? json) as WorkflowState
}

export type WorkflowAction = 'submit' | 'approve' | 'request-changes' | 'withdraw'
export async function transitionWorkflow(
  uuid: string,
  locale: string,
  action: WorkflowAction,
  note?: string,
): Promise<WorkflowState> {
  const json = await authFetch(`${base()}/entries/${uuid}/${locale}/${action}`, {
    method: 'POST',
    body: JSON.stringify(note ? { note } : {}),
  })
  return (json.data ?? json) as WorkflowState
}

export async function fetchWorkflowQueue(page = 1): Promise<{
  items: WorkflowQueueItem[]
  total: number
  page: number
  perPage: number
}> {
  const qs = new URLSearchParams({ page: String(page) })
  const json = await authFetch(`${base()}/queue?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    items: (d.items as WorkflowQueueItem[] | undefined) ?? [],
    total: Number(d.total ?? 0),
    page: Number(d.page ?? 1),
    perPage: Number(d.perPage ?? 25),
  }
}

export function useWorkflowState(
  uuid: MaybeRefOrGetter<string>,
  locale: MaybeRefOrGetter<string>,
  enabled?: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => qk.workflowState(toValue(uuid), toValue(locale)),
    query: () => fetchWorkflowState(toValue(uuid), toValue(locale)),
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useWorkflowQueue(enabled?: MaybeRefOrGetter<boolean>) {
  return useQuery({
    key: () => qk.workflowQueue(),
    query: () => fetchWorkflowQueue(),
    enabled: () => (enabled === undefined ? true : toValue(enabled)),
  })
}

export function useWorkflowMutations(uuid: string, locale: string) {
  const cache = useQueryCache()
  const invalidate = () => {
    cache.invalidateQueries({ key: qk.workflowState(uuid, locale) })
    cache.invalidateQueries({ key: qk.workflowQueue() })
  }
  const run = (action: WorkflowAction) =>
    useMutation({
      mutation: (note?: string) => transitionWorkflow(uuid, locale, action, note),
      onSettled: invalidate,
    })
  return {
    submit: run('submit'),
    approve: run('approve'),
    requestChanges: run('request-changes'),
    withdraw: run('withdraw'),
  }
}
```

Add to `admin/src/queries/keys.ts` (match the file's existing style):

```ts
  workflowState: (uuid: string, locale: string) => ['workflow', 'state', uuid, locale] as const,
  workflowQueue: () => ['workflow', 'queue'] as const,
```

- [ ] **Step 2: Spec** — `admin/src/__tests__/workflowQueries.spec.ts` following `analyticsQueries.spec.ts` (mock `authFetch`, assert URL shapes for state/transition/queue, envelope unwrapping, note-body inclusion for request-changes).

- [ ] **Step 3: Run** — `cd admin && npx vitest run src/__tests__/workflowQueries.spec.ts` → PASS. `npx vue-tsc --noEmit` (do NOT pipe through tail — the exit code must be visible) → clean.

---

### Task 11: SPA editor WorkflowPanel

**Files:**
- Create: `admin/src/pages/content/[type]/[uuid]/components/WorkflowPanel.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue` (mount next to SeoPanel, gated on `caps.isEnabled('lemma.workflow')`)
- Test: `admin/src/__tests__/workflowPanel.spec.ts`

**Interfaces:**
- Consumes: `useWorkflowState`, `useWorkflowMutations` (Task 10); props `{ uuid: string; locale: string; enabled: boolean }` (the SeoPanel prop shape).

- [ ] **Step 1: The panel** — UCard + state badge + actions; request-changes uses an inline note textarea (SeoPanel's collapsible idiom, not a portal modal — portals are untestable in jsdom per the repo's own test notes). `data-test` hooks: `workflow-panel`, `workflow-state`, `workflow-submit`, `workflow-approve`, `workflow-request-changes`, `workflow-request-changes-note`, `workflow-request-changes-confirm`, `workflow-withdraw`. Action visibility by state: draft/changes_requested → Submit; in_review → Approve, Request changes, Withdraw; approved → (badge only). Errors surface via `useNotify().error` (403/409 toasts); success invalidates via the mutations' `onSettled`. Show `history[0].note` when state is `changes_requested` (`data-test="workflow-last-note"`).

- [ ] **Step 2: Mount in the editor** — in `admin/src/pages/content/[type]/[uuid]/index.vue`, next to the SeoPanel import/usage:

```ts
import WorkflowPanel from './components/WorkflowPanel.vue'
const workflowEnabled = computed(() => caps.isEnabled('lemma.workflow'))
```

```vue
          <WorkflowPanel
            :uuid="uuid"
            :locale="locale"
            :enabled="workflowEnabled"
            :key="`wf-${uuid}-${locale}`"
          />
```

(Mirror exactly how SeoPanel receives `uuid`/`locale` in that file — reuse the same source variables.)

- [ ] **Step 3: Spec** — `workflowPanel.spec.ts`: mock the query module; assert per-state action visibility via `data-test` hooks, note textarea required before confirm enables, and that confirm calls `requestChanges.mutateAsync` with the note.

- [ ] **Step 4: Run** — `npx vitest run src/__tests__/workflowPanel.spec.ts` → PASS; `npx vue-tsc --noEmit` → clean (note: if the page uses `definePage`, remember the repo quirk — `/settings/import-export` needed a `<route>` block; content pages are fine).

---

### Task 12: SPA review queue page + module registration

**Files:**
- Create: `admin/src/pages/workflow/index.vue`
- Create: `admin/src/registry/workflowModule.ts`
- Modify: `admin/src/layouts/default.vue` (call `registerWorkflowModule()` next to `registerAnalyticsModule()`)
- Test: `admin/src/__tests__/workflowQueuePage.spec.ts`, `admin/src/__tests__/workflowModule.spec.ts`

- [ ] **Step 1: Module registry** — `admin/src/registry/workflowModule.ts` (the analyticsModule pattern verbatim):

```ts
import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Review-queue nav — gated on the `lemma.workflow` capability; disappears when the pack
// is disabled or removed (the backend 404s those routes too).
const main: NavigationMenuItem[] = [
  {
    label: 'Review queue',
    icon: 'i-lucide-list-checks',
    to: '/workflow',
  },
]

export function registerWorkflowModule(): void {
  registerAdminModule({ id: 'workflow', requires: ['lemma.workflow'], nav: { main } })
}
```

`admin/src/layouts/default.vue`: add the import + `registerWorkflowModule()` call directly under the analytics ones.

- [ ] **Step 2: Queue page** — `admin/src/pages/workflow/index.vue`: `useWorkflowQueue(caps.isEnabled('lemma.workflow'))`; table (title → link to `/content/{type_slug}/{entry_uuid}`, locale, submitted_by, submitted_at); empty state ("Nothing waiting for review"); error state via `useNotify`. `data-test` hooks: `workflow-queue`, `workflow-queue-row`, `workflow-queue-empty`.

- [ ] **Step 3: Specs** — `workflowQueuePage.spec.ts` (mock queue query: rows render with `data-test="workflow-queue-row"`, empty state renders) and `workflowModule.spec.ts` (registration adds the nav item gated on `lemma.workflow` — mirror `analyticsModule.spec.ts`).

- [ ] **Step 4: Run everything** — `cd admin && npx vitest run` → all green; `npx vue-tsc --noEmit` → clean. Backend: `vendor/bin/phpunit` full suite green; `composer boundaries` OK.

- [ ] **Step 5: COMMIT 4 (SPA complete)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add admin/src/queries/workflow.ts admin/src/queries/keys.ts \
  "admin/src/pages/content/[type]/[uuid]/components/WorkflowPanel.vue" \
  "admin/src/pages/content/[type]/[uuid]/index.vue" admin/src/pages/workflow/ \
  admin/src/registry/workflowModule.ts admin/src/layouts/default.vue admin/src/__tests__/
git commit -m "admin SPA: workflow panel + review queue

- Entry editor WorkflowPanel: state badge, submit/approve/request-changes
  (note required)/withdraw, gated on the lemma.workflow capability.
- Review queue page + nav module (capability-gated, analytics pattern).
- workflow query module (state/transitions/queue) + vitest specs."
```

---

## Self-Review Checklist (run after writing, before execution)

- Spec §2 gate semantics → Task 1/5. §3 state machine → Task 4. §4 listener → Task 6. §5 storage/permissions → Task 3. §6 API → Task 7. §7 SPA → Tasks 10–12. §8 testing → every task + Tasks 8–9. Scheduled semantics → Task 8. Disabled behavior → Tasks 5/9.
- The Task 5 seed-actor decision (suite-wide `workflow.bypass` for `user00000001`) is the one deliberate deviation an executor must not "simplify away" — without it every pre-existing publish test breaks.
- Signature consistency: `WorkflowService` names used in Tasks 6/7 match Task 4's definitions; `qk.workflowState/workflowQueue` match between Tasks 10–12.
