<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Account\Http\AccountAssetController;
use Thallo\Account\Http\AccountAuthController;
use Thallo\Account\Http\AccountPageController;
use Thallo\Account\Http\AccountSessionController;

/** @var Router $router */

/*
 * Storefront customer accounts. Loaded only inside the `thallo.accounts` capability gate
 * (AccountServiceProvider::boot()). The /account prefix is deliberately NOT gated wholesale —
 * gating it behind auth would lock a signed-out visitor out of the very page they sign in on.
 * Each route carries exactly the policy its row of the CSRF matrix requires:
 *
 *   - Anonymous unsafe POSTs (no session exists to bind a token to): same-origin provenance plus a
 *     rate limit. Proven as a gate by AccountFlowTest's route-inventory test.
 *   - Cookie-authenticated mutations: the framework's session-bound CSRF token (`csrf`).
 *
 * Identity itself stays global, but the pages read WORKSPACE-OWNED redirect settings and render the
 * store's public chrome, so each account page/form/dashboard/logout resolves a public tenant profile
 * FIRST — `tenant_profile:public` + `tenant_bootstrap` at the front of the list, ahead of the
 * same-origin/CSRF code that may resolve the workspace's public origin — matching Render/Commerce
 * public routes; tenancy-off falls back to the `''` sentinel. Only the fingerprinted asset and the
 * private session endpoint stay `tenant_system` (system-global): they carry no tenant-scoped data.
 */
$profile = ['tenant_profile:public', 'tenant_bootstrap'];
$anonymousForm = [...$profile, 'account_same_origin', 'rate_limit:10,60'];
$page = $profile;
$authedShell = [...$profile, 'session_cookie', 'auth'];
$authedMutation = [...$profile, 'session_cookie', 'auth', 'csrf'];
$asset = ['tenant_system'];

// Sign in.
$router->get('/account/login', [AccountPageController::class, 'loginPage'])
    ->middleware($page)->name('account.login');
$router->post('/account/login', [AccountAuthController::class, 'login'])
    ->middleware($anonymousForm)->name('account.login.submit');

// Register + emailed-OTP verification.
$router->get('/account/register', [AccountPageController::class, 'registerPage'])
    ->middleware($page)->name('account.register');
$router->post('/account/register', [AccountAuthController::class, 'register'])
    ->middleware($anonymousForm)->name('account.register.submit');
$router->get('/account/verify', [AccountPageController::class, 'verifyPage'])
    ->middleware($page)->name('account.verify');
$router->post('/account/verify/{intentUuid}', [AccountAuthController::class, 'verify'])
    ->middleware($anonymousForm)->name('account.verify.submit');
$router->post('/account/resend', [AccountAuthController::class, 'resend'])
    ->middleware($anonymousForm)->name('account.resend');

// Password recovery: request a code, exchange it for a reset token, set a new password.
$router->get('/account/forgot-password', [AccountPageController::class, 'forgotPasswordPage'])
    ->middleware($page)->name('account.forgot');
$router->post('/account/forgot-password', [AccountAuthController::class, 'forgotPassword'])
    ->middleware($anonymousForm)->name('account.forgot.submit');
$router->get('/account/verify-reset', [AccountPageController::class, 'verifyResetPage'])
    ->middleware($page)->name('account.verify_reset');
$router->post('/account/verify-reset', [AccountAuthController::class, 'verifyReset'])
    ->middleware($anonymousForm)->name('account.verify_reset.submit');
$router->get('/account/reset-password', [AccountPageController::class, 'resetPasswordPage'])
    ->middleware($page)->name('account.reset');
$router->post('/account/reset-password', [AccountAuthController::class, 'resetPassword'])
    ->middleware($anonymousForm)->name('account.reset.submit');

// The signed-in shell + sign out. These — and only these — require the session cookie.
$router->get('/account', [AccountPageController::class, 'dashboard'])
    ->middleware($authedShell)->name('account.dashboard');
$router->post('/account/logout', [AccountAuthController::class, 'logout'])
    ->middleware($authedMutation)->name('account.logout');

// --- Account chrome: the header/footer block's asset + the private hydration endpoint ---

// Fingerprinted static asset (account.js): ONE route serves the stable alias (302, no-store) and
// the fingerprinted file (immutable) — the controller distinguishes them. The asset is global,
// so it carries only the tenant_system marker.
$router->get('/_account/assets/{file}', [AccountAssetController::class, 'serve'])
    ->middleware($asset)->name('account.asset');

// The private session-state endpoint the storefront account chrome hydrates from. `session_cookie:optional`
// adapts a valid cookie into a Bearer header (and drops a lapsed one to anonymous); `auth:optional`
// sets `user` when present and lets a signed-out visitor through instead of 401-ing the chrome. The
// controller marks the response `private, no-store` — it must never enter a shared cache.
$router->get('/_account/session', [AccountSessionController::class, 'show'])
    ->middleware(['session_cookie:optional', 'auth:optional', 'tenant_system'])->name('account.session');
