<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The code block (website plan, phase 1): a snippet with a language label and a copy button,
 * for the install command on the landing page. The no-JS floor is the plain <pre><code>; the
 * copy button is created by the runtime asset, so nothing dead ships in the markup.
 */
final class CodeBlockRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $data */
    private function render(array $data): string
    {
        // block_script() emits a block's script once per render: without this the test passes only
        // when no test before it in the process has rendered a code block.
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'c1', 'type' => 'code', 'data' => $data]],
        ]);
    }

    public function testItRendersTheSnippetEscapedWithItsLanguageAndLabel(): void
    {
        $out = $this->render([
            'code' => "composer create-project --stability=beta glueful/thallo my-site && cd my-site\n<b>not html</b>",
            'language' => 'bash',
            'label' => 'Terminal',
        ]);

        self::assertStringContainsString('class="thallo-block thallo-block-code"', $out);
        self::assertStringContainsString('<figure class="thallo-block-code__panel">', $out);
        self::assertStringContainsString('data-language="bash"', $out);
        self::assertStringContainsString('data-copy="1"', $out, 'copy defaults to on');
        self::assertStringContainsString('<code class="language-bash">', $out);
        self::assertStringContainsString('&lt;b&gt;not html&lt;/b&gt;', $out, 'the snippet is text, never markup');
        self::assertStringContainsString('Terminal', $out);
        self::assertStringNotContainsString('<button', $out, 'the button is the runtime\'s, not the floor\'s');
        self::assertStringContainsString('/_thallo/runtime/block-code.js', $out);
    }

    public function testACompactSnippetAndANoteInTheCaption(): void
    {
        // Two additions for dense snippets — an API response, a config excerpt: `size = compact`
        // sets the code smaller and closer, and `note` is short text on the caption's right
        // ("200 OK", "~/my-site"), beside the Copy button or in its place.
        $out = $this->render([
            'code' => '{ "data": {} }', 'language' => 'json', 'label' => 'GET /v1/content/pages/home',
            'size' => 'compact', 'note' => '200 <OK>', 'copy' => false,
        ]);

        self::assertStringContainsString('class="thallo-block thallo-block-code thallo-block-code--compact"', $out);
        self::assertStringContainsString(
            '<span class="thallo-block-code__note">200 &lt;OK&gt;</span>',
            $out,
            'a note is text',
        );
        // The note comes before the actions, so the runtime's Copy button still lands last.
        self::assertLessThan(strpos($out, 'thallo-block-code__actions'), strpos($out, 'thallo-block-code__note'));

        // Unset, neither shows: the default size, and no empty note element.
        $plain = $this->render(['code' => 'x', 'language' => 'text']);
        self::assertStringNotContainsString('--compact', $plain);
        self::assertStringNotContainsString('thallo-block-code__note', $plain);
        // An unknown size is the default, not a class built from input.
        self::assertStringNotContainsString('thallo-block-code--', $this->render(['code' => 'x', 'size' => 'huge"']));
    }

    public function testTheLanguageIsTheLabelWhenNoneIsGivenAndCopyCanBeOff(): void
    {
        $out = $this->render(['code' => 'SELECT 1;', 'language' => 'sql', 'copy' => false]);

        self::assertStringContainsString('data-copy="0"', $out);
        self::assertStringContainsString('>sql<', $out);
    }

    public function testAnUnknownLanguageFallsBackToText(): void
    {
        $out = $this->render(['code' => 'x', 'language' => 'klingon']);

        self::assertStringContainsString('data-language="text"', $out);
        self::assertStringContainsString('<code class="language-text">', $out);
    }

    public function testCornersAndShadowLandOnTheFramedPanelAndTheSnippetWraps(): void
    {
        $this->syncBlockStyleDeclarations();
        $out = $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => [[
            'id' => 'code0000001', 'type' => 'code',
            'data' => ['code' => 'ls', 'language' => 'bash'],
            'settings' => ['style' => [
                'radius' => ['type' => 'token', 'value' => 'radius.none'],
                'shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.md']],
                'spacing' => ['margin' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]],
            ]],
        ]]]);
        self::assertStringContainsString('class="thallo-block thallo-block-code t-mt-lg"', $out, 'spacing on the root');
        self::assertStringContainsString('<figure class="thallo-block-code__panel t-shadow-md t-radius-none">', $out);
        $css = (string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/blocks.css',
        );
        self::assertMatchesRegularExpression('~\\.thallo-block-code__pre \\{[^}]*white-space: pre-wrap~', $css);
        self::assertDoesNotMatchRegularExpression('~\\.thallo-block-code__pre \\{[^}]*overflow-x~', $css);
        self::assertMatchesRegularExpression('~\\.thallo-block-code__panel \\{[^}]*border-radius~', $css);
    }
}
