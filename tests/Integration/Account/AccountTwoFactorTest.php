<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Account;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Auth\Session\LoginOrchestrator;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Account\AccountReturnPath;
use Thallo\Account\Http\AccountAuthController;
use Thallo\Account\Http\AccountPageRenderer;
use Thallo\Account\Settings\AccountSettingsStore;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Contracts\Account\StorefrontAccountRegistration;
use Thallo\Contracts\Account\StorefrontTwoFactor;
use Thallo\Core\Account\AppStorefrontTwoFactor;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A visitor whose account has two-factor on used to be refused at the storefront ("an extra
 * verification step the storefront cannot complete yet"): no way to sign in. Login now answers the
 * challenge with the code page, the challenge token travels only in a short-lived HttpOnly cookie,
 * and the emailed code completes sign-in. No session is ever issued before the code.
 */
final class AccountTwoFactorTest extends AppTestCase
{
    use AccountHttpHelpers;

    protected function tearDown(): void
    {
        $this->cleanupAccountArtifacts();
        parent::tearDown();
    }

    private function challengingOrchestrator(): LoginOrchestrator
    {
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

        return new LoginOrchestrator($this->container()->get(AuthenticationService::class), $twoFactor);
    }

    /** @param array<string, mixed>|null $session what the second factor answers */
    private function controller(?array $session = null): AccountAuthController
    {
        $verifier = new class ($session) implements StorefrontTwoFactor {
            /** @var list<array{string, string}> */
            public array $calls = [];

            /** @param array<string, mixed>|null $session */
            public function __construct(private readonly ?array $session)
            {
            }

            public function completeLogin(string $challengeToken, string $code): ?array
            {
                $this->calls[] = [$challengeToken, $code];

                return $this->session;
            }
        };

        return new AccountAuthController(
            $this->container()->get(StorefrontAccountRegistration::class),
            $this->container()->get(StorefrontAccountRecovery::class),
            $verifier,
            $this->challengingOrchestrator(),
            $this->container()->get(SessionCookieIssuer::class),
            $this->container()->get(SessionLogout::class),
            $this->container()->get(AccountPageRenderer::class),
            $this->container()->get(AccountReturnPath::class),
            $this->container()->get(AccountSettingsStore::class),
            $this->container()->get(LoggerInterface::class),
        );
    }

    public function testAChallengeSendsTheVisitorToTheCodePageWithoutASession(): void
    {
        $this->seedUser('twofa@example.test');

        $response = $this->controller()->login(Request::create('/account/login', 'POST', [
            'email' => 'twofa@example.test',
            'password' => 'sufficiently-long-secret',
            'next' => '/account/orders',
            'return_to' => '/signin',
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account/login/verify?next=%2Faccount%2Forders', $response->headers->get('Location'));
        $cookies = $this->cookiesFrom($response);
        self::assertArrayNotHasKey('gf_session', $cookies);
        $challenge = $cookies[AccountAuthController::TWO_FACTOR_COOKIE] ?? null;
        self::assertInstanceOf(Cookie::class, $challenge);
        self::assertSame('challenge-token', $challenge->getValue());
        self::assertTrue($challenge->isHttpOnly());
    }

    public function testTheRightCodeSignsTheVisitorIn(): void
    {
        $controller = $this->controller([
            'access_token' => 'access-jwt', 'refresh_token' => 'refresh-token', 'expires_in' => 900,
            'user' => ['uuid' => 'u1', 'email' => 'twofa@example.test'],
        ]);
        $request = Request::create('/account/login/verify', 'POST', ['code' => '123456', 'next' => '/account/orders']);
        $request->cookies->set(AccountAuthController::TWO_FACTOR_COOKIE, 'challenge-token');

        $response = $controller->completeTwoFactor($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account/orders', $response->headers->get('Location'));
        $cookies = $this->cookiesFrom($response);
        self::assertArrayHasKey('gf_session', $cookies);
        self::assertTrue($cookies[AccountAuthController::TWO_FACTOR_COOKIE]->isCleared());
    }

    public function testAWrongCodeStaysOnTheCodePageWithoutASession(): void
    {
        $request = Request::create('/account/login/verify', 'POST', ['code' => '000000']);
        $request->cookies->set(AccountAuthController::TWO_FACTOR_COOKIE, 'challenge-token');

        $response = $this->controller(null)->completeTwoFactor($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('wrong or has expired', (string) $response->getContent());
        self::assertArrayNotHasKey('gf_session', $this->cookiesFrom($response));
    }

    public function testWithoutAChallengeTheCodeStepSendsTheVisitorBackToSignIn(): void
    {
        $response = $this->controller()->completeTwoFactor(
            Request::create('/account/login/verify', 'POST', ['code' => '123456'])
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account/login', $response->headers->get('Location'));
    }

    public function testTheCoreVerifierOnlyEverCompletesALoginChallenge(): void
    {
        $session = ['access_token' => 'a', 'refresh_token' => 'r', 'user' => []];
        $login = new AppStorefrontTwoFactor(static fn(): array => ['purpose' => 'login', 'session' => $session]);
        $enrol = new AppStorefrontTwoFactor(static fn(): array => ['purpose' => 'enabled']);
        $wrong = new AppStorefrontTwoFactor(static fn(): array => throw new \RuntimeException('Invalid code'));
        $off = new AppStorefrontTwoFactor(null);

        self::assertSame($session, $login->completeLogin('t', '123456'));
        self::assertNull($enrol->completeLogin('t', '123456'), 'an enrollment token never signs anyone in');
        self::assertNull($wrong->completeLogin('t', '000000'));
        self::assertNull($off->completeLogin('t', '123456'), 'with two-factor off there is nothing to verify');
    }
}
