<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Account;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The signed-in customer's profile page over real HTTP: it shows their name and email, saves a
 * new name, and changes the password — asking for the current one, signing out every other
 * device and keeping this one signed in.
 */
final class AccountProfileTest extends AppTestCase
{
    use AccountHttpHelpers;

    protected function tearDown(): void
    {
        $this->cleanupAccountArtifacts();
        parent::tearDown();
    }

    public function testTheProfilePageShowsTheCustomersNameAndEmail(): void
    {
        $cookies = $this->signInAs('profile-show@example.test');
        $this->connection()->table('profiles')->insert([
            'uuid' => 'prof' . bin2hex(random_bytes(4)),
            'user_uuid' => $this->userUuidFor('profile-show@example.test'),
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $page = $this->get('/account/profile', $cookies);

        self::assertSame(200, $page->getStatusCode(), (string) $page->getContent());
        $html = (string) $page->getContent();
        self::assertStringContainsString('value="Ama"', $html);
        self::assertStringContainsString('value="Mensah"', $html);
        self::assertStringContainsString('profile-show@example.test', $html);
        $dashboard = (string) $this->get('/account', $cookies)->getContent();
        self::assertStringContainsString('href="/account/profile"', $dashboard);
    }

    public function testTheProfilePageNeedsASignedInCustomer(): void
    {
        self::assertNotSame(200, $this->get('/account/profile')->getStatusCode());
    }

    public function testSavingANameUpdatesTheProfile(): void
    {
        $cookies = $this->signInAs('profile-name@example.test');

        $response = $this->postSameOrigin('/account/profile', [
            '_token' => $this->tokenFor($cookies),
            'first_name' => ' Kofi ',
            'last_name' => 'Boateng',
        ], $cookies);

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/account/profile?saved=name', $response->headers->get('Location'));
        $profile = $this->connection()->table('profiles')
            ->where('user_uuid', '=', $this->userUuidFor('profile-name@example.test'))
            ->first();
        self::assertSame('Kofi', $profile['first_name'] ?? null);
        self::assertSame('Boateng', $profile['last_name'] ?? null);
    }

    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $cookies = $this->signInAs('profile-wrong@example.test');

        $response = $this->postSameOrigin('/account/password', [
            '_token' => $this->tokenFor($cookies),
            'current_password' => 'not-the-password',
            'password' => 'a-brand-new-secret',
        ], $cookies);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('current password is wrong', (string) $response->getContent());
        self::assertTrue($this->canSignIn('profile-wrong@example.test', 'sufficiently-long-secret'));
    }

    public function testAShortNewPasswordIsRefused(): void
    {
        $cookies = $this->signInAs('profile-short@example.test');

        $response = $this->postSameOrigin('/account/password', [
            '_token' => $this->tokenFor($cookies),
            'current_password' => 'sufficiently-long-secret',
            'password' => 'short',
        ], $cookies);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at least 8 characters', (string) $response->getContent());
        self::assertTrue($this->canSignIn('profile-short@example.test', 'sufficiently-long-secret'));
    }

    public function testChangingThePasswordSignsOutOtherDevicesAndKeepsThisOne(): void
    {
        $cookies = $this->signInAs('profile-pass@example.test');
        $otherDevice = $this->cookiesFrom($this->postSameOrigin('/account/login', [
            'email' => 'profile-pass@example.test',
            'password' => 'sufficiently-long-secret',
        ]));

        $response = $this->postSameOrigin('/account/password', [
            '_token' => $this->tokenFor($cookies),
            'current_password' => 'sufficiently-long-secret',
            'password' => 'a-brand-new-secret',
        ], $cookies);

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/account/profile?saved=password', $response->headers->get('Location'));
        $fresh = $this->cookiesFrom($response);
        self::assertArrayHasKey('gf_session', $fresh);
        self::assertSame(200, $this->get('/account/profile', $fresh)->getStatusCode());
        self::assertNotSame(200, $this->get('/account/profile', $otherDevice)->getStatusCode());
        self::assertTrue($this->canSignIn('profile-pass@example.test', 'a-brand-new-secret'));
        self::assertFalse($this->canSignIn('profile-pass@example.test', 'sufficiently-long-secret'));
    }

    /** @param array<string,\Symfony\Component\HttpFoundation\Cookie> $cookies */
    private function tokenFor(array $cookies): string
    {
        $html = (string) $this->get('/account/profile', $cookies)->getContent();

        return preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    private function canSignIn(string $email, string $password): bool
    {
        $login = $this->postSameOrigin('/account/login', ['email' => $email, 'password' => $password]);

        return array_key_exists('gf_session', $this->cookiesFrom($login));
    }
}
