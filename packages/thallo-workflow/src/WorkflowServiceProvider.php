<?php

declare(strict_types=1);

namespace Thallo\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Authoring\DraftSummaryReader;
use Thallo\Contracts\Events\ContentLifecycleEvent;
use Thallo\Workflow\Http\Controllers\WorkflowController;
use Glueful\Permissions\PermissionManager;
use Psr\Container\ContainerInterface;

final class WorkflowServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            WorkflowStateRepository::class => [
                'class' => WorkflowStateRepository::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowService::class => [
                'class' => WorkflowService::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowPublishGate::class => [
                'shared' => true,
                'factory' => [self::class, 'makeWorkflowPublishGate'],
                'tags' => ['thallo.publish_gate'],
            ],
            WorkflowLifecycleListener::class => [
                'class' => WorkflowLifecycleListener::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeWorkflowController'],
            ],
        ];
    }

    public static function makeWorkflowController(ContainerInterface $container): WorkflowController
    {
        return new WorkflowController(
            $container->get(WorkflowService::class),
            $container->get(WorkflowStateRepository::class),
            $container->get(DraftSummaryReader::class),
            self::resolvePermissionManager($container),
        );
    }

    public static function makeWorkflowPublishGate(ContainerInterface $container): WorkflowPublishGate
    {
        return new WorkflowPublishGate(
            $container->get(CapabilityRegistry::class),
            $container->get(WorkflowStateRepository::class),
            self::resolvePermissionManager($container),
        );
    }

    /** Dual-id PermissionManager lookup — the RequirePermission convention. */
    private static function resolvePermissionManager(ContainerInterface $container): ?PermissionManager
    {
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                return $m;
            }
        }
        return null;
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'workflow'.
        $this->mergeConfig('workflow', require __DIR__ . '/../config/workflow.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.workflow',
            label: 'Approval workflow',
            description: 'Single-stage editorial review over draft/publish.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-workflow',
        );

        if ($registry->isEnabled('thallo.workflow')) {
            // Automatic transitions ride the CONTRACT lifecycle events (interface-typed
            // listener — the thallo-seo invalidator pattern). Wired only when enabled:
            // disabled means no state mutations at all.
            $events = app($context, EventService::class);
            $listener = app($context, WorkflowLifecycleListener::class);
            $events->addListener(ContentLifecycleEvent::class, [$listener, 'onContentChanged']);

            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
        }
    }
}
