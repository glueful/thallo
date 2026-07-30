<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Auth\Session\LoginOrchestrator;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    ) {
    }

    public function login(Request $request): Response
    {
        $email = $this->email($request);

        try {
            $outcome = $this->login->login(['username' => $email, 'password' => $this->input($request, 'password')]);
        } catch (AuthenticationException) {
            return $this->renderer->render($request, 'account/login.twig', [
                'error' => 'We could not sign you in. Check your email and password.',
                'email' => $email,
            ], 422);
        }

        if (!$outcome->isAuthenticated()) {
            // Two-factor challenge. The storefront has no second-factor step yet, so it refuses
            // rather than route around the gate: no session, no cookie.
            return $this->renderer->render($request, 'account/login.twig', [
                'error' => 'This account needs an extra verification step the storefront cannot complete yet.',
                'email' => $email,
            ], 422);
        }

        // The cookie is written onto the redirect itself, so the very next request carries it.
        $response = new RedirectResponse('/account');

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
            $this->registration->resend($intentUuid, $request->getClientIp() ?? '0.0.0.0');
        }

        // Neutral regardless: a present or absent pointer both land back on the verify page.
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
        // SessionLogout revokes the server session (best effort) and always clears both cookies on
        // the response it returns.
        $response = new RedirectResponse('/account/login');

        return $this->sessionLogout->logout($request, $response)->response;
    }

    private function email(Request $request): string
    {
        return strtolower(trim($this->input($request, 'email')));
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
