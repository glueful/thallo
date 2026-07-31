<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Thallo\Account\Blocks\AccountBlockTypesContributor;

/**
 * The account form blocks (account-form-blocks plan Task 2): `login-form`, `register-form`,
 * `forgot-password-form` — composable, CACHE-SAFE versions of the themed account forms. The pins
 * under test: byte-identical rendering (no per-visitor state, no CSRF token), NO `return_to` in
 * static markup (the enhance script injects it, so a no-JS submit falls back to the themed page),
 * and only `login-form` carrying the inline-error enhancement surface (hidden credentials node +
 * `account-forms.js`); register/forgot are plain server forms whose neutral flows leave the page.
 */
final class AccountFormBlocksTest extends AppTestCase
{
    public function testTheContributorDefinesAuthStateAndTheThreeFormBlocks(): void
    {
        $slugs = array_map(
            static fn ($definition) => $definition->slug,
            (new AccountBlockTypesContributor())->blockTypeDefinitions(),
        );

        self::assertSame(['auth-state', 'login-form', 'register-form', 'forgot-password-form'], $slugs);
    }

    public function testLoginFormRendersTheFormWithNextHeadingLinksAndEnhancementSurface(): void
    {
        $html = $this->renderBlock('login-form', [
            'heading' => 'Welcome back',
            'next' => '/members/home',
        ]);

        self::assertStringContainsString('action="/account/login"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('<input type="hidden" name="next" value="/members/home">', $html);
        self::assertStringContainsString('Welcome back', $html);
        // show_links defaults true.
        self::assertStringContainsString('href="/account/forgot-password"', $html);
        self::assertStringContainsString('href="/account/register"', $html);
        // The Task-3 enhancement surface: hidden error node + the runtime script + the form marker.
        self::assertStringContainsString('data-account-form="login"', $html);
        self::assertStringContainsString('data-account-error="credentials"', $html);
        self::assertMatchesRegularExpression('/data-account-error="credentials"[^>]*hidden/', $html);
        self::assertStringContainsString('src="/_account/assets/account-forms.js" defer', $html);
        self::assertStringContainsString('href="/_account/assets/account-blocks.css"', $html);
    }

    public function testLoginFormCanHideTheLinksRow(): void
    {
        $html = $this->renderBlock('login-form', ['show_links' => false]);

        self::assertStringNotContainsString('href="/account/forgot-password"', $html);
        self::assertStringNotContainsString('href="/account/register"', $html);
        // The form itself still renders.
        self::assertStringContainsString('action="/account/login"', $html);
    }

    public function testRegisterFormIsAPlainServerFormWithTheFourFields(): void
    {
        $html = $this->renderBlock('register-form', []);

        self::assertStringContainsString('action="/account/register"', $html);
        foreach (['first_name', 'last_name', 'email', 'password'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $html);
        }
        // Plain: no enhancement surface of any kind.
        self::assertStringNotContainsString('data-account-form', $html);
        self::assertStringNotContainsString('data-account-error', $html);
        self::assertStringNotContainsString('account-forms.js', $html);
    }

    public function testForgotPasswordFormIsAPlainServerEmailForm(): void
    {
        $html = $this->renderBlock('forgot-password-form', []);

        self::assertStringContainsString('action="/account/forgot-password"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringNotContainsString('data-account-form', $html);
        self::assertStringNotContainsString('data-account-error', $html);
        self::assertStringNotContainsString('account-forms.js', $html);
    }

    public function testEveryFormBlockIsByteIdenticalAndCarriesNoPerVisitorState(): void
    {
        foreach (['login-form', 'register-form', 'forgot-password-form'] as $type) {
            $first = $this->renderBlock($type, ['heading' => 'H']);
            $second = $this->renderBlock($type, ['heading' => 'H']);

            self::assertSame($first, $second, "{$type} must render deterministically");
            // The cache-safety pins: no session CSRF token, and no static return_to — the enhance
            // script injects return_to, so a no-JS submit flows through the themed pages.
            self::assertStringNotContainsString('return_to', $first, "{$type} must not ship return_to");
            self::assertStringNotContainsString('_token', $first, "{$type} must not ship a CSRF token");
            self::assertStringNotContainsString('csrf', strtolower($first), "{$type} must not reference csrf");
        }
    }

    public function testACapabilityOffBootFallsBackToTheMissingTemplatePath(): void
    {
        $off = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.accounts' => false],
        ]);

        try {
            $container = $off->getContainer();
            $env = $container->get(\Thallo\Render\TwigFactory::class)->environment();
            self::assertFalse(
                $env->getLoader()->exists('blocks/login-form.twig'),
                'login-form must not resolve while thallo.accounts is disabled',
            );

            /** @var \Thallo\Render\RenderContextExtension $extension */
            $extension = $container->get(\Thallo\Render\RenderContextExtension::class);
            $extension->resetPerRenderState();
            $extension->setBlockAnnotations(false);
            $extension->setLocale('en');
            $html = $extension->blocks($env, ['entry' => null, 'site' => []], [
                ['id' => 'loginformoff1', 'type' => 'login-form', 'data' => []],
            ]);

            self::assertStringNotContainsString('action="/account/login"', $html);
            self::assertMatchesRegularExpression(
                '/no template for block "login-form"|Missing block template: blocks\/login-form\.twig/',
                $html,
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // --- helpers ------------------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function renderBlock(string $type, array $data): string
    {
        $env = $this->container()->get(\Thallo\Render\TwigFactory::class)->environment();

        /** @var \Thallo\Render\RenderContextExtension $extension */
        $extension = $this->container()->get(\Thallo\Render\RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations(false);
        $extension->setLocale('en');

        return $extension->blocks($env, ['entry' => null, 'site' => []], [
            ['id' => 'formblock0001', 'type' => $type, 'data' => $data],
        ]);
    }
}
