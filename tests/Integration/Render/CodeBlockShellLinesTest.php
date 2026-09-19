<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A shell snippet reads as a terminal: the prompt is drawn, comments are quiet. The rule that
 * shapes all of it is that the Copy button copies the code element's TEXT — so the prompt must
 * never be text. An author writes `$ composer install` as they always have; the template takes
 * the marker off and the theme draws it, and what is copied, or selected by hand, is a command
 * that can be pasted. Other languages are left as one text node for a highlighter to pick up.
 */
final class CodeBlockShellLinesTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $data */
    private function code(array $data): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render([
            'l' => [['id' => 'code00000001', 'type' => 'code', 'data' => $data, 'settings' => []]],
        ]);
    }

    /** What the Copy button copies: the code element's text. */
    private function copied(string $html): string
    {
        self::assertSame(1, preg_match('~<code[^>]*>(.*?)</code>~s', $html, $m), $html);
        return html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);
    }

    private const SNIPPET = "$ composer create-project glueful/thallo my-site \\\n"
        . "    --stability=beta\n"
        . "$ cd my-site\n"
        . "\n"
        . "# environment checked";

    public function testThePromptIsTakenOffTheTextAndMarkedForTheThemeToDraw(): void
    {
        $html = $this->code(['language' => 'bash', 'code' => self::SNIPPET]);

        self::assertSame(2, substr_count($html, 'thallo-block-code__line--prompt'));
        self::assertSame(1, substr_count($html, 'thallo-block-code__line--comment'));
        self::assertSame(5, preg_match_all('~class="thallo-block-code__line(?: [^"]*)?"~', $html));
        self::assertStringContainsString('<code class="language-bash">', $html, 'the highlighter hook stays');
        // The continuation of a command is not a prompt, and keeps the indent it was given.
        self::assertStringContainsString('<span class="thallo-block-code__line">    --stability=beta', $html);
    }

    public function testWhatIsCopiedIsCommandsThatCanBePasted(): void
    {
        $copied = $this->copied($this->code(['language' => 'bash', 'code' => self::SNIPPET]));

        self::assertSame(
            "composer create-project glueful/thallo my-site \\\n    --stability=beta\n"
                . "cd my-site\n\n# environment checked",
            $copied,
        );
        // No trailing newline: pasted into a terminal, that would RUN the last command.
        self::assertStringEndsNotWith("\n", $copied);
    }

    public function testOnlyADollarAndASpaceAtTheStartOfALineIsAPrompt(): void
    {
        $copied = $this->copied($this->code([
            'language' => 'bash',
            'code' => "\$HOME/bin/tool\necho \$ 5\n\$\n  \$ indented\n\$ real",
        ]));
        self::assertSame("\$HOME/bin/tool\necho \$ 5\n\$\n  \$ indented\nreal", $copied);
    }

    public function testTheSnippetIsStillTextNeverMarkupAndWindowsLineEndingsDoNotLeak(): void
    {
        $html = $this->code(['language' => 'bash', 'code' => "\$ echo \"<b>hi</b>\"\r\n# <script>x</script>"]);

        self::assertStringContainsString('echo &quot;&lt;b&gt;hi&lt;/b&gt;&quot;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString("\r", $html);
        self::assertSame("echo \"<b>hi</b>\"\n# <script>x</script>", $this->copied($html));
    }

    public function testAnotherLanguageIsLeftAsOneTextNodeForAHighlighter(): void
    {
        $php = "\$ total = 1; # not a shell comment\n\$ more";
        $html = $this->code(['language' => 'php', 'code' => $php]);

        self::assertStringNotContainsString('thallo-block-code__line', $html);
        self::assertSame($php, $this->copied($html), 'nothing is taken off a language that is not a shell');
    }

    public function testTheThemeDrawsThePromptAndKeepsTheSnippetWrapping(): void
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $css = (string) file_get_contents($base . '/packages/thallo-render/themes/default/assets/blocks.css');

        // Drawn, not written: generated content is in neither the text that is copied nor a selection.
        self::assertMatchesRegularExpression(
            '~\.thallo-block-code__line--prompt::before\s*\{[^}]*content:\s*"\$ "~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-code__line--comment\s*\{[^}]*color:\s*var\(--muted\)~',
            $css,
        );
        // A long command wraps — the snippet never scrolls sideways — and wraps under the command.
        self::assertStringContainsString('white-space: pre-wrap', $css);
        self::assertMatchesRegularExpression(
            '~\.thallo-block-code__line--prompt\s*\{[^}]*text-indent:\s*-2ch~',
            $css,
        );
        // The window: a light body under a tinted title bar with its three lights.
        self::assertMatchesRegularExpression(
            '~\.thallo-block-code__panel\s*\{[^}]*background:\s*var\(--bg\)~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-code__caption\s*\{[^}]*background:\s*var\(--surface\)~',
            $css,
        );
        self::assertStringContainsString('.thallo-block-code__label::before', $css);
    }
}
