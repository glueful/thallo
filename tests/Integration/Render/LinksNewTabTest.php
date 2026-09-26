<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A Links block's link can open in a new tab, as a menu item can (`new_tab`). Such a link is
 * `target="_blank"` with `rel="noopener noreferrer"`, so the page it opens cannot reach back into
 * this one; every other link opens where it is.
 */
final class LinksNewTabTest extends AppTestCase
{
    /** @param list<array<string,mixed>> $items */
    private function links(array $items): string
    {
        $base = $this->appContext()->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $locator = new ThemeLocator('default', $base . '/themes');
        $env = (new TwigFactory($locator, $extension, $base . '/storage/cache/twig'))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'links0000001', 'type' => 'links', 'data' => ['title' => 'Project', 'items' => $items],
        ]]]);
    }

    public function testALinkMarkedNewTabOpensInOneSafelyAndTheOthersStayPut(): void
    {
        $html = $this->links([
            ['label' => 'GitHub', 'url' => 'https://github.com/glueful/thallo', 'new_tab' => true],
            ['label' => 'Changelog', 'url' => '/docs/changelog'],
            ['label' => 'Licence', 'url' => '/docs/license', 'new_tab' => false],
        ]);

        self::assertMatchesRegularExpression(
            '~<a [^>]*href="https://github.com/glueful/thallo" target="_blank" rel="noopener noreferrer">~',
            $html,
        );
        self::assertSame(1, substr_count($html, 'target="_blank"'), 'only the link marked new tab');
        self::assertMatchesRegularExpression('~<a [^>]*href="/docs/changelog">~', $html);
        self::assertMatchesRegularExpression('~<a [^>]*href="/docs/license">~', $html);
    }
}
