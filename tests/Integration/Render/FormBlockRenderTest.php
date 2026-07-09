<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * The form block's render surface (form-block spec §4/§6): a routable form seals a
 * descriptor and renders the no-JS baseline; an un-routable form renders the disabled
 * notice with no submittable markup; form_render() is gated to `form` blocks so no
 * other block type can seal a descriptor.
 */
final class FormBlockRenderTest extends AppTestCase
{
    /**
     * @param list<array<string,mixed>> $list
     */
    private function renderBlocks(array $list, string $currentPath = '/'): string
    {
        $base = $this->appContext()->getBasePath();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(list) }}')
            ->render(['list' => $list, 'current_path' => $currentPath]);
    }

    public function testRoutableFormRendersSealedInputAndNoJsAction(): void
    {
        $html = $this->renderBlocks([[
            'id' => 'f1', 'type' => 'form',
            'data' => ['recipient' => 'owner@site.test', 'form_name' => 'Contact'],
        ]], currentPath: '/contact');

        self::assertStringContainsString('name="_form"', $html);
        self::assertStringContainsString('action="/_forms/submit"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('type="email"', $html); // derived email field
        self::assertStringNotContainsString('owner@site.test', $html); // recipient never in markup
    }

    public function testUnroutableFormRendersDisabledNotice(): void
    {
        $html = $this->renderBlocks([[
            'id' => 'f2', 'type' => 'form', 'data' => [], // no recipient, no default
        ]], currentPath: '/c');

        self::assertStringContainsString('thallo-block-form--disabled', $html);
        self::assertStringNotContainsString('name="_form"', $html);
    }

    public function testFormRenderIsGatedToFormBlocks(): void
    {
        // Defense in depth: even called directly, form_render only seals `form` blocks.
        $ext = $this->container()->get(RenderContextExtension::class);
        self::assertNull($ext->formRender(
            ['current_path' => '/c'],
            ['id' => 'x', 'type' => 'image', 'data' => ['recipient' => 'a@b.test']],
        ));
    }
}
