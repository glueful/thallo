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

    public function testNestedBlocksComposeThroughContainerTemplates(): void
    {
        $this->saveBlockTemplate('section', 'SECTION[{{ data.title }}|{{ blocks(data.content) }}]');
        $this->saveBlockTemplate('hero', 'HERO[{{ data.heading }}]');
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'a', 'type' => 'section', 'data' => ['title' => 'S', 'content' => [
                ['id' => 'b', 'type' => 'hero', 'data' => ['heading' => 'Inner']],
            ]]],
        ]]);
        self::assertStringContainsString('SECTION[S|HERO[Inner]]', $out);
    }

    public function testOverDeepDataRendersNothingAndTheCounterRecovers(): void
    {
        self::assertSame(
            \App\Content\Blocks\BlockDepth::MAX,
            RenderContextExtension::MAX_BLOCK_DEPTH,
        ); // §A2: the surfaces agree

        $this->saveBlockTemplate('nest', 'N({{ blocks(data.inner) }})');
        $this->saveBlockTemplate('leaf', 'LEAF');
        $wrap = fn (array $inner): array => ['id' => 'x', 'type' => 'nest', 'data' => ['inner' => $inner]];
        // depth 4: nest > nest > nest > leaf — the innermost list renders EMPTY.
        $deep = [$wrap([$wrap([$wrap([['id' => 'l', 'type' => 'leaf', 'data' => []]])])])];
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => $deep]);
        self::assertStringNotContainsString('LEAF', $out);
        // The over-deep marker is a prod HTML comment or a debug placeholder div
        // (the suite runs debug) — strip both; the SHAPE is what's asserted.
        $shape = preg_replace('/<!--.*?-->|<div[^>]*>.*?<\/div>/s', '', $out) ?? $out;
        self::assertStringContainsString('N(N(N()))', $shape);

        // The counter is render-scoped: a fresh render at depth 1 works immediately.
        $this->container()->get(RenderContextExtension::class)->resetBlockDepth();
        $ok = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'l2', 'type' => 'leaf', 'data' => []],
        ]]);
        self::assertStringContainsString('LEAF', $ok);
    }

    public function testDepthCounterUnwindsAfterAMidRenderException(): void
    {
        // The failure mode §A5 names: a block template THROWS while nested blocks()
        // frames are on the stack. try/finally must unwind every frame — a leaked
        // count would make the NEXT render start above depth 1 and falsely hit the
        // cap. No resetBlockDepth() between renders here: the unwind alone must hold.
        $this->saveBlockTemplate('nest', 'N({{ blocks(data.inner) }})');
        $this->saveBlockTemplate('leaf', 'LEAF');
        $this->saveBlockTemplate('boom', '{{ undefined_function_boom() }}');

        try {
            $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
                ['id' => 'a', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'b', 'type' => 'boom', 'data' => []], // throws at depth 2
                ]]],
            ]]);
            self::fail('expected the boom template to throw');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A FULL-DEPTH (3) render right after must succeed — proves depth is 0 again.
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'c', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'd', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'e', 'type' => 'leaf', 'data' => []],
                ]]],
            ]]],
        ]]);
        self::assertStringContainsString('N(N(LEAF))', $out);
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
