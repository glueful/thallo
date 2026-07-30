<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Routing\Middleware\CSRFMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Account\AccountNavigationItem;
use Thallo\Contracts\Account\AccountNavigationRegistry;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * The account pack's read surface: the eight themed pages. Each renders a plain form (or, for the
 * dashboard, the signed-in shell) through {@see AccountPageRenderer}, so they inherit the theme's
 * `layout.twig` chrome.
 */
final class AccountPageController
{
    public function __construct(
        private readonly AccountPageRenderer $renderer,
        private readonly AccountNavigationRegistry $navigation,
        private readonly CapabilityRegistry $capabilities,
        private readonly CSRFMiddleware $csrf,
    ) {
    }

    public function loginPage(Request $request): Response
    {
        return $this->renderer->render($request, 'account/login.twig');
    }

    public function registerPage(Request $request): Response
    {
        return $this->renderer->render($request, 'account/register.twig');
    }

    public function verifyPage(Request $request): Response
    {
        // The pending intent is the one register set — the form posts to /account/verify/{id}.
        return $this->renderer->render($request, 'account/verify.twig', [
            'intent_uuid' => (string) $request->cookies->get(AccountAuthController::PENDING_INTENT_COOKIE, ''),
            'resent' => $request->query->get('resent') === '1',
        ]);
    }

    public function forgotPasswordPage(Request $request): Response
    {
        return $this->renderer->render($request, 'account/forgot-password.twig');
    }

    public function verifyResetPage(Request $request): Response
    {
        return $this->renderer->render($request, 'account/verify-reset.twig');
    }

    public function resetPasswordPage(Request $request): Response
    {
        return $this->renderer->render($request, 'account/reset-password.twig');
    }

    public function dashboard(Request $request): Response
    {
        // The post-auth principal set by the `auth` middleware (the `user` attribute — never
        // `auth.user`, which the optional enricher owns). Uncached, cookie-authenticated route,
        // so rendering the visitor's own name is safe here.
        $user = $request->attributes->get('user');

        return $this->renderer->render($request, 'account/dashboard.twig', [
            'user' => is_array($user) ? $user : [],
            'nav_items' => $this->visibleNavigation(),
            // Plain generateToken: `auth` has already attached the identity, so the middleware
            // binds the token to THIS session's uuid. Uncached page, so embedding it is safe.
            'csrf_token' => $this->csrf->generateToken($request),
        ], 200, chrome: true);
    }

    /**
     * The registry ships with no items of its own; later packs contribute orders and addresses.
     * A section whose capability is disabled disappears without deleting its registration.
     *
     * @return list<AccountNavigationItem>
     */
    private function visibleNavigation(): array
    {
        return array_values(array_filter(
            $this->navigation->items(),
            fn (AccountNavigationItem $item): bool =>
                $item->capability === null || $this->capabilities->isEnabled($item->capability),
        ));
    }
}
