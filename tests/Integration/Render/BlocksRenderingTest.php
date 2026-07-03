<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplatePolicy;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;

final class BlocksRenderingTest extends LemmaTestCase
{
    /** Environment WITH the DB loader (block templates are DB-overridable — spec §6). */
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
            new DatabaseTemplateLoader(
                new TemplateRepository($this->connection()),
                $this->container()->get(TemplateLinter::class),
                'default',
            ),
        ))->environment();
    }

    private function saveBlockTemplate(string $type, string $source): void
    {
        (new TemplateRepository($this->connection()))
            ->save('default', "blocks/{$type}.twig", $source, null);
    }

    public function testRendersBlocksInOrderWithThePinnedContext(): void
    {
        $this->saveBlockTemplate(
            'hero',
            'HERO[{{ index }}:{{ data.heading }}:{{ block.id }}:{{ entry.slug }}]',
        );
        $this->saveBlockTemplate('quote', 'QUOTE[{{ index }}:{{ data.text }}]');

        $out = $this->env()->createTemplate("{{ blocks(entry.fields.body) }}")->render([
            'entry' => ['slug' => 'hello', 'fields' => ['body' => [
                ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => ['heading' => 'Hi']],
                ['id' => 'bbbbbbbbbbbb', 'type' => 'quote', 'data' => ['text' => 'Words']],
            ]]],
        ]);
        self::assertStringContainsString('HERO[0:Hi:aaaaaaaaaaaa:hello]', $out);
        self::assertStringContainsString('QUOTE[1:Words]', $out);
        self::assertLessThan(strpos($out, 'QUOTE['), strpos($out, 'HERO[')); // order
    }

    public function testMissingTemplateAndMalformedItemsAreSafe(): void
    {
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'x', 'type' => 'ghost', 'data' => []],   // no template anywhere
            'not-a-block',                                     // malformed → skipped
            ['id' => 'y', 'type' => '../evil', 'data' => []], // unsafe slug → skipped
        ]]);
        // Debug envs may render a placeholder; either way NOTHING throws and the
        // unsafe slug never becomes a template path.
        self::assertStringNotContainsString('evil', $out);
        $this->addToAssertionCount(1);
    }

    public function testNonListValueRendersNothing(): void
    {
        self::assertSame(
            '',
            trim($this->env()->createTemplate("{{ blocks(x) }}")->render(['x' => 'nope'])),
        );
    }

    public function testBlocksJoinsTheSandboxAllowlistWithACacheVersionBump(): void
    {
        self::assertContains('blocks', TemplatePolicy::FUNCTIONS);
        self::assertSame(2, TemplatePolicy::CACHE_VERSION); // spec §6 pin

        // A DB template calling blocks() lints clean.
        $linter = $this->container()->get(TemplateLinter::class);
        self::assertSame([], $linter->lint('{{ blocks(entry.fields.body) }}'));
    }
}
