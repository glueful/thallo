<?php

declare(strict_types=1);

namespace Thallo\Account;

use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Account\Contribution\AccountTemplatePathContributor;
use Thallo\Account\Http\AccountAuthController;
use Thallo\Account\Http\AccountPageController;
use Thallo\Account\Http\AccountPageRenderer;
use Thallo\Account\Http\Middleware\AccountSameOriginMiddleware;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Render\Contribution\RenderContributionRegistry;

use function app;

/**
 * Storefront customer accounts, as a removable capability pack. The pack owns the themed
 * `/account/*` pages and consumes the neutral account contracts — never the app-side signup
 * services (a test walks these sources to keep that boundary real). The `thallo.accounts`
 * capability gates only this product surface: the framework's `/auth/*` identity endpoints and the
 * session-cookie transport are never gated by it.
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
        ];
    }

    public static function makeAccountSameOrigin(): AccountSameOriginMiddleware
    {
        return new AccountSameOriginMiddleware(new SameOriginGuard());
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

        // Routes and templates register only while the capability is ENABLED. The framework's
        // /auth/* APIs and the session-cookie transport are never gated by it — this switch
        // controls Thallo's product surface, not global identity infrastructure.
        if (!$registry->isEnabled('thallo.accounts')) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // Without this the routes exist and every render throws a Twig loader error — the pack
        // would look wired and 500 on first request. Soft-guarded: thallo-render may be absent.
        $container = $context->getContainer();
        if ($container->has(RenderContributionRegistry::class)) {
            $container->get(RenderContributionRegistry::class)
                ->registerTemplatePaths(new AccountTemplatePathContributor());
        }
    }
}
