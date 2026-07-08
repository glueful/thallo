<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.2/§4.3: the function + filter as templates see them. */
final class ThemeStyleScopeTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $ctx */
    private function render(string $tpl, array $ctx = []): string
    {
        return $this->env()->createTemplate($tpl)->render($ctx);
    }

    public function testFunctionReturnsClassAndInlineStyle(): void
    {
        $out = $this->render(
            '{{ theme_style_scope("rose", "zinc").class }}|{{ theme_style_scope("rose", "zinc").style }}',
        );
        self::assertStringContainsString('thallo-skin-rose-zinc', $out);
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertStringContainsString('html[data-theme="dark"] .thallo-skin-rose-zinc{', $out);
    }

    public function testFunctionEmitsNothingForInherit(): void
    {
        $out = $this->render(
            '[{{ theme_style_scope("inherit", "inherit").class }}]'
            . '[{{ theme_style_scope("inherit", "inherit").style }}]',
        );
        self::assertSame('[][]', $out);
    }

    public function testFilterNamespacesAndSanitizes(): void
    {
        self::assertSame(' thallo-style-promo', $this->render('{{ "promo"|style_hook }}'));
        // Malicious input is dropped AND autoescaped — no raw <script> reaches output.
        $out = $this->render('{{ "\"><script>"|style_hook }}');
        self::assertStringNotContainsString('<script>', $out);
        self::assertSame('', trim($out));
    }
}
