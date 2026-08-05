<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Billing\PlanCheckoutUrlResolver;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Task 18 (Phase C, workspace self-serve checkout plan, spec §5.4): the pricing_plan
 * block's optional `plan_key` → admin billing deep-link bridge, rendered through the
 * REAL template pipeline (mirrors PricingBlockRenderTest's own `blocks()` harness).
 *
 * Degradation matrix (spec §5.4/§6): capability off, engine off, an unbound resolver,
 * and a malformed/absent key all degrade IDENTICALLY — the CTA falls back to the
 * authored `button_url`, byte-identical to a pricing_plan block with no `plan_key` at
 * all. Capability-off and engine-off are proven directly against the real resolver
 * binding in {@see \App\Tests\Integration\Subscriptions\PlanCheckoutUrlResolverTest};
 * from the TEMPLATE's point of view every one of those reasons is simply "the resolver
 * answered null", so this suite exercises that outcome once via a stub and separately
 * proves the unbound-resolver and malformed/absent-key gates, which are genuinely
 * render-side concerns.
 */
final class PricingPlanCheckoutBridgeTest extends AppTestCase
{
    private const AUTHORED_URL = 'https://example.com/authored';

    private function env(RenderContextExtension $extension): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $data */
    private function render(RenderContextExtension $extension, array $data): string
    {
        return $this->env($extension)->createTemplate('{{ blocks(list) }}')->render(['list' => [[
            'id' => 'p1', 'type' => 'pricing_plan', 'data' => $data,
        ]]]);
    }

    private function extension(?PlanCheckoutUrlResolver $resolver): RenderContextExtension
    {
        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            planCheckoutUrls: $resolver,
            appContext: $this->appContext(),
        );
    }

    /** A resolver that answers the SAME fixed URL for any key — proves whether it was ever consulted. */
    private function alwaysAnswers(string $url): PlanCheckoutUrlResolver
    {
        return new class ($url) implements PlanCheckoutUrlResolver {
            public function __construct(private readonly string $url)
            {
            }

            public function resolve(ApplicationContext $context, string $planKey): ?string
            {
                return $this->url;
            }
        };
    }

    /** Represents capability-off/engine-off/any other degraded resolver verdict. */
    private function alwaysNull(): PlanCheckoutUrlResolver
    {
        return new class implements PlanCheckoutUrlResolver {
            public function resolve(ApplicationContext $context, string $planKey): ?string
            {
                return null;
            }
        };
    }

    // ------------------------------------------------------------------
    // Deep link: well-formed key + resolver answers a URL.
    // ------------------------------------------------------------------

    public function testDeepLinksToTheResolvedUrlWhenPlanKeyIsWellFormedRegardlessOfCatalogExistence(): void
    {
        // §5.4: the resolver makes no existence/purchasability promise — an unknown
        // key still deep-links (the billing page itself renders plan_not_purchasable).
        $resolved = 'https://admin.test/billing?plan=definitely-not-a-real-plan';
        $out = $this->render($this->extension($this->alwaysAnswers($resolved)), [
            'title' => 'Pro',
            'button_label' => 'Choose Pro',
            'button_url' => self::AUTHORED_URL,
            'plan_key' => 'definitely-not-a-real-plan',
        ]);

        self::assertStringContainsString('href="' . $resolved . '"', $out);
        self::assertStringNotContainsString(self::AUTHORED_URL, $out);
    }

    // ------------------------------------------------------------------
    // Degradation matrix: every path below must fall back to the authored URL,
    // byte-identical.
    // ------------------------------------------------------------------

    public function testFallsBackToAuthoredButtonUrlWhenResolverAnswersNull(): void
    {
        // Stands in for capability off / engine off / any other resolver-internal
        // degraded reason — see class docblock.
        $out = $this->render($this->extension($this->alwaysNull()), [
            'button_label' => 'Choose Pro',
            'button_url' => self::AUTHORED_URL,
            'plan_key' => 'pro',
        ]);

        self::assertStringContainsString('href="' . self::AUTHORED_URL . '"', $out);
    }

    public function testFallsBackToAuthoredButtonUrlWhenResolverIsUnbound(): void
    {
        $out = $this->render($this->extension(null), [
            'button_label' => 'Choose Pro',
            'button_url' => self::AUTHORED_URL,
            'plan_key' => 'pro',
        ]);

        self::assertStringContainsString('href="' . self::AUTHORED_URL . '"', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPlanKeys(): iterable
    {
        yield 'uppercase' => ['Pro'];
        yield 'space' => ['pro plan'];
        yield 'slash' => ['pro/plan'];
        yield 'too long' => [str_repeat('a', 101)];
        yield 'empty string' => [''];
    }

    /** @dataProvider malformedPlanKeys */
    public function testFallsBackToAuthoredButtonUrlWhenPlanKeyIsMalformed(string $planKey): void
    {
        // The resolver is READY to answer a real URL — proving the fallback here
        // proves the malformed-key gate short-circuits BEFORE ever consulting it.
        $out = $this->render(
            $this->extension($this->alwaysAnswers('https://admin.test/billing?plan=pro')),
            ['button_label' => 'Choose Pro', 'button_url' => self::AUTHORED_URL, 'plan_key' => $planKey],
        );

        self::assertStringContainsString('href="' . self::AUTHORED_URL . '"', $out);
        self::assertStringNotContainsString('billing?plan=', $out);
    }

    public function testFallsBackToAuthoredButtonUrlWhenPlanKeyIsAbsent(): void
    {
        $out = $this->render(
            $this->extension($this->alwaysAnswers('https://admin.test/billing?plan=pro')),
            ['button_label' => 'Choose Pro', 'button_url' => self::AUTHORED_URL],
        );

        self::assertStringContainsString('href="' . self::AUTHORED_URL . '"', $out);
        self::assertStringNotContainsString('billing?plan=', $out);
    }

    public function testDegradationNeverTouchesAnyOtherAuthoredCopyOrMarkup(): void
    {
        // Byte-identical rendering (spec §5.4): a degraded pricing_plan renders EXACTLY
        // like one authored with no plan_key at all in every respect but the field.
        $data = ['title' => 'Pro', 'button_label' => 'Choose Pro', 'button_url' => self::AUTHORED_URL];

        $resolver = $this->alwaysAnswers('https://admin.test/billing?plan=pro');
        $withoutKey = $this->render($this->extension($resolver), $data);
        $withMalformedKey = $this->render($this->extension($resolver), $data + ['plan_key' => 'INVALID KEY']);
        $withUnboundResolver = $this->render($this->extension(null), $data + ['plan_key' => 'pro']);

        self::assertSame($withoutKey, $withMalformedKey);
        self::assertSame($withoutKey, $withUnboundResolver);
    }

    // ------------------------------------------------------------------
    // Full wiring: the real container-resolved extension + the real thallo-subscriptions
    // binding, proving the URL comes from the CONFIGURED admin origin (phpunit.xml's
    // RENDER_ADMIN_URL) — never any HTTP Host header (no Request ever touches this
    // render path at all).
    // ------------------------------------------------------------------

    public function testFullyWiredDeepLinkUsesTheCanonicalAdminOriginNotAnyHostHeader(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);

        $out = $this->render($extension, [
            'button_label' => 'Choose Pro',
            'button_url' => self::AUTHORED_URL,
            'plan_key' => 'pro',
        ]);

        self::assertStringContainsString('href="https://admin.test/billing?plan=pro"', $out);
        self::assertStringNotContainsString(self::AUTHORED_URL, $out);
    }
}
