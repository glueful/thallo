<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Account\StorefrontAccountProfile;

/**
 * The signed-in customer's profile page: their name, their email (read-only for now), and a
 * password change. Every route runs behind `auth`, so the account is the one the session proved;
 * the mutations also carry the session-bound CSRF token.
 */
final class AccountProfileController
{
    public function __construct(
        private readonly StorefrontAccountProfile $profiles,
        private readonly AccountPageRenderer $renderer,
        private readonly CSRFMiddleware $csrf,
        private readonly SessionCookieIssuer $cookies,
    ) {
    }

    public function page(Request $request): Response
    {
        $saved = (string) $request->query->get('saved', '');

        return $this->render($request, [
            'notice' => match ($saved) {
                'name' => 'Your name is saved.',
                'password' => 'Your password is changed. Other devices are signed out.',
                default => null,
            },
        ]);
    }

    public function saveName(Request $request): Response
    {
        $first = $this->input($request, 'first_name');
        $last = $this->input($request, 'last_name');
        $result = $this->profiles->rename($this->userUuid($request), $first, $last);
        if (!$result->saved) {
            return $this->render($request, [
                'name_errors' => $result->errors,
                'first_name' => $first,
                'last_name' => $last,
            ], 422);
        }

        return new RedirectResponse('/account/profile?saved=name', Response::HTTP_SEE_OTHER);
    }

    public function changePassword(Request $request): Response
    {
        $result = $this->profiles->changePassword(
            $this->userUuid($request),
            $this->input($request, 'current_password'),
            $this->input($request, 'password'),
        );
        if (!$result->changed()) {
            return $this->render($request, ['password_error' => $result->message], 422);
        }

        $response = new RedirectResponse('/account/profile?saved=password', Response::HTTP_SEE_OTHER);
        if ($result->session === null) {
            // Every session was revoked and none could be opened: sign in with the new password.
            return new RedirectResponse('/account/login', Response::HTTP_SEE_OTHER);
        }

        return $this->cookies->issue($response, AuthenticatedSession::fromSessionArray($result->session));
    }

    /** @param array<string,mixed> $extra */
    private function render(Request $request, array $extra, int $status = 200): Response
    {
        $profile = $this->profiles->profile($this->userUuid($request));

        return $this->renderer->render($request, 'account/profile.twig', $extra + [
            'email' => $profile?->email ?? '',
            'first_name' => $profile?->firstName ?? '',
            'last_name' => $profile?->lastName ?? '',
            'csrf_token' => $this->csrf->generateToken($request),
        ], $status, chrome: true);
    }

    private function userUuid(Request $request): string
    {
        // The post-auth principal the `auth` middleware sets (see AccountPageController::dashboard).
        $user = $request->attributes->get('user');

        return is_array($user) ? (string) ($user['uuid'] ?? '') : '';
    }

    private function input(Request $request, string $key): string
    {
        $value = $request->request->get($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
