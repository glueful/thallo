<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

use function config;

/**
 * The account pack's one page-render seam. Mirrors {@see \Thallo\Commerce\Http\Shop\ShopPageRenderer}
 * (which itself mirrors RenderController's reset-before-render discipline): the account pages extend
 * the theme's `layout.twig`, so they render through the SAME TwigFactory-built environment carrying
 * `RenderContextExtension`. That extension is a process-shared singleton, so every render must reset
 * its per-render state first — never inherit a previous render's tags, asset base or locale.
 *
 * Both account controllers render through here: the page controller for the eight pages, the auth
 * controller when it re-renders a form after a failed submit.
 */
final class AccountPageRenderer
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
    ) {
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function render(Request $request, string $template, array $extra = [], int $status = 200): Response
    {
        $env = $this->twigFactory->environment();
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');

        $this->extension->resetTags();
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
        $this->extension->setBlockAnnotations(false);
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);

        $context = [
            'site' => [
                'name' => (string) config($this->context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            'current_path' => RenderPageCache::normalizePath($request->getPathInfo()),
            'presentation' => [
                'show_title' => true,
                'layout' => 'centered',
                'header' => 'default',
                'footer' => 'default',
            ],
        ] + $extra;

        return new Response(
            $env->render($template, $context),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
