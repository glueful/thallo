<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Auth\Session\LoginOrchestrator;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Account\AccountReturnPath;
use Thallo\Account\Settings\AccountSettingsStore;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Contracts\Account\StorefrontAccountRegistration;

/**
 * The account pack's write surface: register, verify, sign in, recover, sign out. Every method
 * consumes the neutral account contracts and the framework's session primitives — never the
 * app-side signup services (a boundary a test enforces by walking these sources). Two invariants
 * live here:
 *
 *  - Login ALWAYS runs through {@see LoginOrchestrator}, never {@see \Glueful\Auth\AuthenticationService}
 *    directly, so the two-factor gate is un-bypassable. A challenge outcome fails closed: no
 *    session is issued, no cookie is set, and the visitor is told the storefront cannot complete
 *    the second factor yet.
 *  - Registration and recovery are neutral: the responses for a known and an unknown address are
 *    identical, so the storefront can never become an account-existence oracle. The redirect
 *    Location after a register attempt is fixed (never carries the intent id), and the pending
 *    intent travels in a short-lived HttpOnly cookie instead.
 */
final class AccountAuthController
{
    /** Short-lived HttpOnly pointer to the pending signup intent (never placed in a URL). */
    public const PENDING_INTENT_COOKIE = 'account_pending_intent';

    /** Short-lived HttpOnly carrier for a verified password-reset token (kept out of the URL). */
    public const RESET_TOKEN_COOKIE = 'account_reset_token';

    public function __construct(
        private readonly StorefrontAccountRegistration $registration,
        private readonly StorefrontAccountRecovery $recovery,
        private readonly LoginOrchestrator $login,
        private readonly SessionCookieIssuer $cookies,
        private readonly SessionLogout $sessionLogout,
        private readonly AccountPageRenderer $renderer,
        private readonly AccountReturnPath $returnPaths,
        private readonly AccountSettingsStore $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function login(Request $request): Response
    {
        $email = $this->email($request);
        // Revalidate the posted `next` (POST is authoritative). Only the validated value is ever
        // reflected back into the form, so a tampered hidden field can never become an open redirect.
        $safeNext = $this->safeNext($request);
        // The PRG-back contract (form-blocks plan Task 3): a custom page embedding login-form
        // posts a JS-injected `return_to`. PATH-ONLY by contract (validatePagePath), so the one
        // allowlisted error code below appends without merge/fragment ambiguity. Absent or unsafe
        // → the themed 422 fallback (exactly what a no-JS submit gets, since static block markup
        // carries no return_to).
        $returnTo = $this->returnPaths->validatePagePath($this->input($request, 'return_to'));

        try {
            $outcome = $this->login->login(['username' => $email, 'password' => $this->input($request, 'password')]);
        } catch (AuthenticationException) {
            if ($returnTo !== null) {
                // Only the allowlisted code travels — never email, provider text, or messages.
                // `credentials` covers unknown-email and wrong-password identically (neutrality).
                return new RedirectResponse($returnTo . '?account_error=credentials', Response::HTTP_SEE_OTHER);
            }

            return $this->renderer->render($request, 'account/login.twig', [
                'error' => 'We could not sign you in. Check your email and password.',
                'email' => $email,
                'next' => $safeNext,
            ], 422);
        }

        if (!$outcome->isAuthenticated()) {
            // Two-factor challenge. The storefront has no second-factor step yet, so it refuses
            // rather than route around the gate: no session, no cookie. The validated `next`
            // survives the re-render so it still applies once the visitor can complete sign-in.
            // 2FA is NAVIGATION, not an error code: `return_to` deliberately does NOT apply here —
            // the themed page is the dedicated flow until a real challenge step exists.
            return $this->renderer->render($request, 'account/login.twig', [
                'error' => 'This account needs an extra verification step the storefront cannot complete yet.',
                'email' => $email,
                'next' => $safeNext,
            ], 422);
        }

        // Precedence: a safe `next` wins, else the operator's configured post-login target, else
        // `/account`. The cookie is written onto the 303 redirect itself, so the next request
        // carries it. 303 forces the browser to GET the target after the POST.
        $target = $this->returnPaths->resolve($safeNext, $this->settings->afterLogin(), '/account');
        $response = new RedirectResponse($target, Response::HTTP_SEE_OTHER);

        return $this->cookies->issue($response, $outcome->session());
    }

    public function register(Request $request): Response
    {
        $result = $this->registration->begin(
            $this->email($request),
            $this->input($request, 'password'),
            $this->input($request, 'first_name'),
            $this->input($request, 'last_name'),
            $request->getClientIp() ?? '0.0.0.0',
        );

        // Fixed Location for every outcome (success, throttle, delivery failure, taken address),
        // so the redirect can never distinguish a registered address. The pending intent — when
        // there is one — travels in an HttpOnly cookie the verify page reads, never in the URL.
        $response = new RedirectResponse('/account/verify');
        if ($result->intentUuid !== null && $result->intentUuid !== '') {
            $response->headers->setCookie($this->shortLivedCookie(
                self::PENDING_INTENT_COOKIE,
                $result->intentUuid,
                1800,
            ));
        }

        return $response;
    }

    public function verify(Request $request, string $intentUuid): Response
    {
        try {
            $result = $this->registration->verify($intentUuid, $this->input($request, 'otp'));
        } catch (\Throwable) {
            // A wrong or expired code, or an already-consumed intent, all read the same to the
            // visitor — never a distinct "this intent belongs to a registered account" signal.
            $result = null;
        }

        if ($result === null || $result->userUuid === null) {
            return $this->renderer->render($request, 'account/verify.twig', [
                'intent_uuid' => $intentUuid,
                'error' => 'That code did not match, or it has expired. Request a new one.',
            ], 422);
        }

        // Identity created. Send them to sign in (auto-login is a later enhancement) and drop the
        // pending-intent pointer.
        $response = new RedirectResponse('/account/login');
        $response->headers->clearCookie(self::PENDING_INTENT_COOKIE, '/account');

        return $response;
    }

    public function resend(Request $request): Response
    {
        $intentUuid = (string) $request->cookies->get(self::PENDING_INTENT_COOKIE, '');
        if ($intentUuid !== '') {
            try {
                $this->registration->resend($intentUuid, $request->getClientIp() ?? '0.0.0.0');
            } catch (\Throwable) {
                // Robust + neutral: a delivery failure must not break the page. The operator sees
                // it in the log; the visitor is told a new code is on its way either way.
            }

            // The flag drives a "code sent" confirmation on the verify page.
            return new RedirectResponse('/account/verify?resent=1');
        }

        // No pending signup on this browser — nothing to resend.
        return new RedirectResponse('/account/verify');
    }

    public function forgotPassword(Request $request): Response
    {
        // Swallows unknown-address, throttle and delivery failures alike — accepted either way.
        $this->recovery->begin($this->email($request), $request->getClientIp() ?? '0.0.0.0');

        return new RedirectResponse('/account/verify-reset');
    }

    public function verifyReset(Request $request): Response
    {
        $email = $this->email($request);
        $verification = $this->recovery->verify($email, $this->input($request, 'otp'));

        if (!$verification->verified || $verification->resetToken === null) {
            return $this->renderer->render($request, 'account/verify-reset.twig', [
                'email' => $email,
                'error' => 'That code did not match, or it has expired.',
            ], 422);
        }

        // The reset token is single-use and short-lived; carry it in an HttpOnly cookie so it
        // never reaches a URL, a Referer header or a server log.
        $response = new RedirectResponse('/account/reset-password');
        $response->headers->setCookie($this->shortLivedCookie(
            self::RESET_TOKEN_COOKIE,
            $verification->resetToken,
            900,
        ));

        return $response;
    }

    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->cookies->get(self::RESET_TOKEN_COOKIE, '');
        $result = $this->recovery->complete($token, $this->input($request, 'password'));

        if (!$result->accepted) {
            return $this->renderer->render($request, 'account/reset-password.twig', [
                'error' => 'That reset link is no longer valid. Start again.',
            ], 422);
        }

        $response = new RedirectResponse('/account/login');
        $response->headers->clearCookie(self::RESET_TOKEN_COOKIE, '/account');

        return $response;
    }

    public function logout(Request $request): Response
    {
        // Precedence: a safe posted `next` wins, else the configured post-logout target, else the
        // login page. The 303 carries the cookie-clearing headers SessionLogout writes.
        $target = $this->returnPaths->resolve(
            $this->safeNext($request),
            $this->settings->afterLogout(),
            '/account/login',
        );
        $result = $this->sessionLogout->logout($request, new RedirectResponse($target, Response::HTTP_SEE_OTHER));

        if ($result->revoked) {
            return $result->response;
        }

        // Revocation FAILED: the server session may still be live, so we must NOT report a
        // successful sign-out. Return a cookie-cleared 500 (the cookies were still expired on the
        // response) and log it — a "signed out" redirect over a live session would be a lie.
        $this->logger->warning('Account sign-out could not revoke the server session', [
            'ip' => $request->getClientIp(),
        ]);
        $failed = new Response(
            'We could not complete sign-out. Please try again.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
        foreach ($result->response->headers->getCookies() as $cookie) {
            $failed->headers->setCookie($cookie);
        }

        return $failed;
    }

    private function email(Request $request): string
    {
        return strtolower(trim($this->input($request, 'email')));
    }

    /** The posted `next`, validated to a safe application-relative path, or null when absent/unsafe. */
    private function safeNext(Request $request): ?string
    {
        $next = $this->input($request, 'next');

        return $next !== '' ? $this->returnPaths->validate($next) : null;
    }

    private function shortLivedCookie(string $name, string $value, int $ttl): Cookie
    {
        return Cookie::create($name, $value)
            ->withHttpOnly(true)
            ->withPath('/account')
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withExpires(time() + $ttl);
    }

    private function input(Request $request, string $key, string $default = ''): string
    {
        $value = $request->request->get($key);
        if ($value === null && $request->getContentTypeFormat() === 'json') {
            $decoded = json_decode((string) $request->getContent(), true);
            $value = is_array($decoded) ? ($decoded[$key] ?? null) : null;
        }

        return $value === null ? $default : (string) $value;
    }
}
