<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Auth\Session\LoginOrchestrator;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Routing\Router;
use Glueful\Security\OTP;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Account\Http\AccountAuthController;
use Thallo\Account\Http\AccountPageRenderer;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Contracts\Account\StorefrontAccountRegistration;
use Thallo\Render\TwigFactory;

/**
 * The account pack over real HTTP. The register→emailed-OTP leg is environment-blocked (no mail
 * transport, and the OTP is stored only as a hash), so the flagship walk seeds the intent+verifier
 * directly — exactly as the signup integration suite does — and drives everything from verify
 * onward through the kernel: OTP → identity, sign in → HttpOnly cookie, /account → 200, sign out →
 * cookie cleared, and the authority tables stay empty throughout. The remaining tests pin the CSRF
 * matrix as a gate, the two-factor fail-closed rule, template resolution, route gating, registration
 * neutrality, and that the session-cookie transport is present with Thallo's defaults and absent
 * when explicitly disabled.
 */
final class AccountFlowTest extends AppTestCase
{
    /** @var list<string> */
    private array $createdEmails = [];
    /** @var list<string> */
    private array $createdTenants = [];

    protected function tearDown(): void
    {
        foreach ($this->createdEmails as $email) {
            $user = $this->connection()->table('users')->where('email', '=', $email)->first();
            if (is_array($user)) {
                foreach (['tenant_memberships', 'user_roles', 'user_permissions', 'profiles'] as $table) {
                    $this->connection()->table($table)->where('user_uuid', '=', $user['uuid'])->forceDelete();
                }
                $this->connection()->table('users')->where('uuid', '=', $user['uuid'])->forceDelete();
            }
        }
        foreach ($this->createdTenants as $tenant) {
            $this->connection()->table('tenant_memberships')->where('tenant_uuid', '=', $tenant)->forceDelete();
            $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
        }
        parent::tearDown();
    }

    // --- The flagship: identity without authority, over HTTP ---------------------------------

    public function testAVerifiedCustomerSignsInSeesTheAccountAndSignsOut(): void
    {
        [$intentUuid, $otp] = $this->seedCustomerIntent('flow@example.test');

        // OTP -> created identity. Anonymous unsafe POST, so it must carry same-origin provenance.
        $verify = $this->postSameOrigin('/account/verify/' . $intentUuid, ['otp' => $otp]);
        self::assertSame(302, $verify->getStatusCode(), (string) $verify->getContent());

        // Sign in over the cookie transport.
        $login = $this->postSameOrigin('/account/login', [
            'email' => 'flow@example.test',
            'password' => 'correct-horse-battery',
        ]);
        $cookies = $this->cookiesFrom($login);
        self::assertArrayHasKey('gf_session', $cookies, (string) $login->getContent());
        self::assertTrue($cookies['gf_session']->isHttpOnly());

        // The signed-in shell renders for a cookie-bearing request.
        $dashboard = $this->get('/account', $cookies);
        self::assertSame(200, $dashboard->getStatusCode(), (string) $dashboard->getContent());

        // Sign out clears the session cookie (past expiry).
        $logout = $this->postSameOrigin('/account/logout', [
            '_token' => $this->csrfTokenFor($cookies),
        ], $cookies);
        $cleared = $this->cookiesFrom($logout);
        self::assertArrayHasKey('gf_session', $cleared);
        self::assertLessThan(time(), $cleared['gf_session']->getExpiresTime());

        // The whole point: a shopper carries no workspace authority.
        $userUuid = $this->userUuidFor('flow@example.test');
        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) $this->connection()->table($table)->where($column, '=', $userUuid)->count(),
                "{$table} must hold no rows for a shopper",
            );
        }
    }

    // --- The CSRF matrix as a gate -----------------------------------------------------------

    public function testEveryUnsafeAccountRouteCarriesAnApprovedCsrfPolicy(): void
    {
        $routes = $this->accountRouteInventory();
        self::assertNotSame([], $routes, 'the inventory must not be empty — an empty loop proves nothing');

        foreach ($routes as $route) {
            if (in_array($route['method'], ['GET', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $anonymous = !in_array('auth', $route['middleware'], true);
            $policy = $anonymous
                ? $this->hasSameOriginGuard($route) && $this->hasRateLimit($route)
                : $this->hasCsrfToken($route);

            self::assertTrue($policy, "{$route['method']} {$route['path']} has no approved CSRF policy");
        }
    }

    public function testAnAnonymousPostWithoutSameOriginProvenanceIsRejected(): void
    {
        $response = $this->post('/account/register', [
            'email' => 'crosssite@example.test',
            'password' => 'sufficiently-long-secret',
            'first_name' => 'X',
            'last_name' => 'Site',
        ], [], ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->userExistsByEmail('crosssite@example.test'));
    }

    public function testAnAuthenticatedMutationWithoutItsTokenIsRejected(): void
    {
        $cookies = $this->signInAs('notoken@example.test');

        $response = $this->postSameOrigin('/account/logout', [], $cookies);

        // Cookie-authenticated mutations need the session-bound token; provenance alone is not the
        // approved policy for this row of the matrix.
        self::assertContains($response->getStatusCode(), [403, 419, 422]);
    }

    // --- Rendering + gating ------------------------------------------------------------------

    public function testEveryAccountTemplateResolvesThroughTwig(): void
    {
        $names = ['login', 'register', 'verify', 'forgot-password', 'verify-reset', 'reset-password', 'dashboard'];
        foreach ($names as $name) {
            self::assertTrue(
                $this->twigEnvironment()->getLoader()->exists('account/' . $name . '.twig'),
                "account/{$name}.twig does not resolve — is the template path contributor registered?",
            );
        }
    }

    public function testTheAccountShellRequiresACookieButTheLoginPageDoesNot(): void
    {
        self::assertSame(200, $this->get('/account/login')->getStatusCode());
        self::assertContains($this->get('/account')->getStatusCode(), [302, 401]);
    }

    public function testLoginFailsClosedForATwoFactorEnabledAccount(): void
    {
        // The framework's 2FA subsystem is globally off in the test env (TWO_FACTOR_ENABLED unset),
        // so a live challenge cannot arise. The security property under test is narrower and does
        // not need that subsystem: when the orchestrator reports a challenge, the controller issues
        // NO cookie. A real LoginOrchestrator wired to a TwoFactorService that reports the account
        // as 2FA-enabled produces exactly that outcome.
        $this->seedUser('twofa@example.test');

        $twoFactor = new class implements TwoFactorServiceInterface {
            public function isEnabled(string $userUuid): bool
            {
                return true;
            }

            /** @return array{token: string, expires_in: int, delivered_to: string} */
            public function beginLogin(array $user, ?string $preferredProvider = null): array
            {
                return ['token' => 'challenge-token', 'expires_in' => 300, 'delivered_to' => 'e***@example.test'];
            }
        };

        $controller = new AccountAuthController(
            $this->container()->get(StorefrontAccountRegistration::class),
            $this->container()->get(StorefrontAccountRecovery::class),
            new LoginOrchestrator($this->container()->get(AuthenticationService::class), $twoFactor),
            $this->container()->get(SessionCookieIssuer::class),
            $this->container()->get(SessionLogout::class),
            $this->container()->get(AccountPageRenderer::class),
        );

        $request = Request::create('/account/login', 'POST', [
            'email' => 'twofa@example.test',
            'password' => 'sufficiently-long-secret',
        ]);

        self::assertArrayNotHasKey('gf_session', $this->cookiesFrom($controller->login($request)));
    }

    public function testRegistrationIsNeutralForAnAlreadyRegisteredEmail(): void
    {
        // begin() resolves the single store, which in the tenancy-disabled test state reads the
        // default-tenant flag — establish it.
        $tenant = $this->seedTenant();
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)
            ->put('tenancy.default_tenant_uuid', $tenant);
        $this->seedUser('taken@example.test');
        $this->createdEmails[] = 'fresh@example.test';

        // Same-origin so both actually reach the neutrality boundary rather than being turned away
        // at the provenance guard.
        $fresh = $this->postSameOrigin('/account/register', [
            'email' => 'fresh@example.test', 'password' => 'sufficiently-long-secret',
            'first_name' => 'A', 'last_name' => 'One',
        ]);
        $taken = $this->postSameOrigin('/account/register', [
            'email' => 'taken@example.test', 'password' => 'sufficiently-long-secret',
            'first_name' => 'B', 'last_name' => 'Two',
        ]);

        self::assertSame($fresh->getStatusCode(), $taken->getStatusCode());
        self::assertSame($fresh->headers->get('Location'), $taken->headers->get('Location'));
    }

    // --- The session-cookie transport (Task 5 decision) --------------------------------------

    public function testWithThalloDefaultsTheSessionTransportIsAvailable(): void
    {
        // Thallo defaults SESSION_COOKIE_ENABLED on, so the framework registers its cookie session
        // endpoints (carried under the API version prefix, e.g. /v1/auth/session/refresh — matched
        // by suffix so the version segment is not pinned here).
        self::assertNotNull($this->frameworkRoute('POST', '/auth/session/refresh'));
        self::assertNotNull($this->frameworkRoute('POST', '/auth/session/logout'));

        // The account shell opts into the transport per route; enabling it cookie-authenticates
        // nothing else.
        $shell = $this->findRoute('GET', '/account');
        self::assertNotNull($shell);
        self::assertContains('session_cookie', (array) $shell['middleware']);

        // Bearer login is unchanged and carries no session_cookie middleware.
        $bearer = $this->frameworkRoute('POST', '/auth/login');
        self::assertNotNull($bearer);
        self::assertNotContains('session_cookie', (array) $bearer['middleware']);
    }

    public function testWithTheTransportDisabledTheSessionRoutesAreAbsent(): void
    {
        // Explicitly disabled (SESSION_COOKIE_ENABLED=false): the framework registers the cookie
        // session endpoints ONLY when the flag is on, so they must be absent here.
        $disabled = self::bootAppWithConfigOverride('auth', [
            'api_keys' => ['prefix' => 'gf'],
            'two_factor' => ['enabled' => false],
            'session_cookie' => ['enabled' => false],
        ]);

        $router = $disabled->getContainer()->get(Router::class);
        $present = false;
        foreach ($router->getAllRoutes() as $route) {
            if (
                strtoupper((string) $route['method']) === 'POST'
                && str_ends_with((string) $route['path'], '/auth/session/refresh')
            ) {
                $present = true;
                break;
            }
        }

        self::assertFalse($present, 'the session routes must be absent when the transport is disabled');
    }

    /**
     * A framework route matched by path SUFFIX — framework routes carry the API version prefix
     * (e.g. /v1/auth/login), which this test must not hard-code.
     *
     * @return array<string,mixed>|null
     */
    private function frameworkRoute(string $method, string $suffix): ?array
    {
        foreach ($this->router()->getAllRoutes() as $route) {
            if (
                strtoupper((string) $route['method']) === $method
                && str_ends_with((string) $route['path'], $suffix)
            ) {
                return $route;
            }
        }

        return null;
    }

    // --- Helpers -----------------------------------------------------------------------------

    /** @return array{string,string} [intentUuid, otp] */
    private function seedCustomerIntent(string $email): array
    {
        $tenant = $this->seedTenant();
        $this->createdEmails[] = $email;
        $intentUuid = $this->container()->get(SignupIntentRepository::class)->create([
            'kind' => 'customer',
            'origin' => 'anonymous',
            'email' => $email,
            'username' => $email,
            'first_name' => 'Flow',
            'last_name' => 'Tester',
            'password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
            'tenant_uuid' => $tenant,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', '203.0.113.10'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->connection()->table('signup_verifiers')->insert([
            'intent_uuid' => $intentUuid,
            'otp_hash' => OTP::hashOTP('123456'),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [$intentUuid, '123456'];
    }

    private function seedTenant(): string
    {
        $uuid = 'acct' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'account-' . $uuid,
            'name' => 'Account Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdTenants[] = $uuid;

        return $uuid;
    }

    private function seedUser(string $email, string $password = 'sufficiently-long-secret'): string
    {
        $this->createdEmails[] = $email;

        return $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'status' => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,string> table => user-uuid column */
    private function authorityTables(): array
    {
        return [
            'tenant_memberships' => 'user_uuid',
            'user_roles' => 'user_uuid',
            'user_permissions' => 'user_uuid',
        ];
    }

    private function userExistsByEmail(string $email): bool
    {
        return $this->connection()->table('users')->where('email', '=', $email)->first() !== null;
    }

    private function userUuidFor(string $email): string
    {
        $row = $this->connection()->table('users')->where('email', '=', $email)->first();

        return is_array($row) ? (string) $row['uuid'] : '';
    }

    /**
     * @return array<int, array{email: string, password: string}>|array<string, Cookie>
     */
    private function signInAs(string $email, string $password = 'sufficiently-long-secret'): array
    {
        $this->seedUser($email, $password);

        return $this->cookiesFrom($this->postSameOrigin('/account/login', [
            'email' => $email,
            'password' => $password,
        ]));
    }

    private function csrfTokenFor(array $cookies): string
    {
        $html = (string) $this->get('/account', $cookies)->getContent();

        return preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    /** @return list<array{method: string, path: string, middleware: list<string>}> */
    private function accountRouteInventory(): array
    {
        $routes = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            if (str_starts_with((string) $route['path'], '/account')) {
                $routes[] = [
                    'method' => strtoupper((string) $route['method']),
                    'path' => (string) $route['path'],
                    'middleware' => array_map('strval', (array) ($route['middleware'] ?? [])),
                ];
            }
        }

        return $routes;
    }

    /** @param array{middleware: list<string>} $route */
    private function hasSameOriginGuard(array $route): bool
    {
        return in_array('account_same_origin', $route['middleware'], true);
    }

    /** @param array{middleware: list<string>} $route */
    private function hasRateLimit(array $route): bool
    {
        foreach ($route['middleware'] as $middleware) {
            if (str_starts_with($middleware, 'rate_limit')) {
                return true;
            }
        }

        return false;
    }

    /** @param array{middleware: list<string>} $route */
    private function hasCsrfToken(array $route): bool
    {
        return in_array('csrf', $route['middleware'], true);
    }

    private function twigEnvironment(): \Twig\Environment
    {
        return $this->container()->get(TwigFactory::class)->environment();
    }

    // --- HTTP request plumbing ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function get(string $path, array $cookies = []): Response
    {
        return $this->handle($this->buildRequest('GET', $path, [], $cookies, []));
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function post(string $path, array $body = [], array $cookies = [], array $headers = []): Response
    {
        return $this->handle($this->buildRequest('POST', $path, $body, $cookies, $headers));
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function postSameOrigin(string $path, array $body = [], array $cookies = [], array $headers = []): Response
    {
        return $this->post($path, $body, $cookies, $headers + ['Sec-Fetch-Site' => 'same-origin']);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,Cookie|string> $cookies
     * @param array<string,string> $headers
     */
    private function buildRequest(string $method, string $path, array $body, array $cookies, array $headers): Request
    {
        $cookieValues = [];
        foreach ($cookies as $name => $cookie) {
            $cookieValues[$name] = $cookie instanceof Cookie ? (string) $cookie->getValue() : (string) $cookie;
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create($path, $method, $body, $cookieValues, [], $server);
    }

    /** @return array<string, Cookie> */
    private function cookiesFrom(Response $response): array
    {
        $map = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $map[$cookie->getName()] = $cookie;
        }

        return $map;
    }
}
