<?php

declare(strict_types=1);

namespace Thallo\Account;

use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use Thallo\Account\AccountReturnPath;
use Thallo\Account\Assets\AccountAssetMap;
use Thallo\Account\Blocks\AccountBlockTypesContributor;
use Thallo\Account\Contribution\AccountTemplatePathContributor;
use Thallo\Account\Http\AccountAssetController;
use Thallo\Account\Http\AccountAuthController;
use Thallo\Account\Http\AccountPageController;
use Thallo\Account\Http\AccountPageRenderer;
use Thallo\Account\Http\AccountSessionController;
use Thallo\Account\Http\AccountSettingsController;
use Thallo\Account\Http\Middleware\AccountSameOriginMiddleware;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;
use Thallo\Render\Contribution\RenderContributionRegistry;

use function app;

/**
 * Storefront customer accounts, as a removable capability pack. The pack owns the themed
 * `/account/*` pages and consumes the neutral account
 * contracts — never the app-side signup services (a test walks these sources to keep that boundary
 * real). The `thallo.accounts` capability gates only this product surface: the framework's
 * `/auth/*` identity endpoints and the session-cookie transport are never gated by it.
 */
final class AccountServiceProvider extends ServiceProvider
{
    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function services(): array
    {
        return [
            // The same-origin guard has no constructor deps, but building it via a factory keeps
            // the pack from depending on SameOriginGuard being container-registered.
            AccountSameOriginMiddleware::class => [
                'factory' => [self::class, 'makeAccountSameOrigin'],
                'shared' => true,
                'alias' => ['account_same_origin'],
            ],
            // The single return-path authority: pure, no deps, shared by both account controllers.
            AccountReturnPath::class => [
                'class' => AccountReturnPath::class,
                'shared' => true,
                'autowire' => true,
            ],
            AccountPageRenderer::class => [
                'class' => AccountPageRenderer::class,
                'shared' => true,
                'autowire' => true,
            ],
            AccountAuthController::class => [
                'class' => AccountAuthController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AccountPageController::class => [
                'class' => AccountPageController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AccountSessionController::class => [
                'class' => AccountSessionController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AccountSettingsController::class => [
                'class' => AccountSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Built from the pack's own assets/ dir — a cheap, side-effect-free scan, so it is bound
            // unconditionally (harmless while the capability is off, when no route reaches it).
            AccountAssetMap::class => [
                'factory' => [self::class, 'makeAccountAssetMap'],
                'shared' => true,
            ],
            AccountAssetController::class => [
                'class' => AccountAssetController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeAccountSameOrigin(): AccountSameOriginMiddleware
    {
        return new AccountSameOriginMiddleware(new SameOriginGuard());
    }

    public static function makeAccountAssetMap(): AccountAssetMap
    {
        // src/ -> pack root -> /assets
        return new AccountAssetMap(dirname(__DIR__) . '/assets');
    }

    public function register(ApplicationContext $context): void
    {
        // No package config to merge; all wiring is in services() and boot().
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);
        $registry->register(new Capability(
            'thallo.accounts',
            label: 'Storefront accounts',
            description: 'Themed registration, sign-in and account pages for storefront visitors.',
        ));

        // A capability flip between boots must purge cached pages that still hold the account chrome
        // whose routes now 404. There is no flip event — the capability is deploy-time config — so
        // this is a boot-time reconciler, invoked OUTSIDE the gate so it fires precisely on the boot
        // where the capability turned OFF (a boot where the gated branch below does not run).
        $this->reconcileCapabilityState($context, $registry->isEnabled('thallo.accounts'));

        // Routes, templates and the block type register only while the capability is ENABLED. The
        // framework's /auth/* APIs and the session-cookie transport are never gated by it — this
        // switch controls Thallo's product surface, not global identity infrastructure.
        if (!$registry->isEnabled('thallo.accounts')) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        // The admin API for account settings — same capability gate as the public routes, so it is
        // absent (404) when thallo.accounts is off.
        $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');

        // Without this the routes exist and every render throws a Twig loader error — the pack would
        // look wired and 500 on first request. Soft-guarded: thallo-render may be absent.
        $container = $context->getContainer();
        if ($container->has(RenderContributionRegistry::class)) {
            $container->get(RenderContributionRegistry::class)
                ->registerTemplatePaths(new AccountTemplatePathContributor());
        }

        // Capability-boundary pin: account block types register only while enabled, so a stored
        // block falls to the missing-template fallback when the capability is off.
        $this->registerAccountBlockTypeContributor($container);
    }

    private function registerAccountBlockTypeContributor(ContainerInterface $container): void
    {
        if (!$container->has(StarterBlockTypeRegistry::class)) {
            return;
        }
        /** @var StarterBlockTypeRegistry $registry */
        $registry = $container->get(StarterBlockTypeRegistry::class);
        foreach ($registry->all() as $existing) {
            if ($existing instanceof AccountBlockTypesContributor) {
                return; // already registered — idempotent no-op on a second boot.
            }
        }
        $registry->register(new AccountBlockTypesContributor());
    }

    private function reconcileCapabilityState(ApplicationContext $context, bool $enabled): void
    {
        $container = $context->getContainer();
        if (!$container->has(CacheStore::class)) {
            return; // CLI or pre-migration boot — defer the purge to the next fully-wired boot.
        }
        $edge = $container->has(EdgeCacheInterface::class)
            ? $container->get(EdgeCacheInterface::class)
            : null;
        (new CapabilityFlipPurge($container->get(CacheStore::class), $edge))->reconcile($enabled);
    }
}
