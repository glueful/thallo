<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\StarterBlockTypes;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;

final class StarterTemplatesTest extends LemmaTestCase
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

    /**
     * Representative data per starter slug (media uuids resolve to null harmlessly).
     *
     * @return array<string,mixed>
     */
    private function fixture(string $slug): array
    {
        return match ($slug) {
            'section' => ['title' => 'Band', 'background' => 'subtle',
                'content' => [['id' => 'x1', 'type' => 'quote', 'data' => ['text' => 'Inner']]]],
            'columns' => ['layout' => '2',
                'col_1' => [['id' => 'x2', 'type' => 'quote', 'data' => ['text' => 'Left']]],
                'col_2' => [['id' => 'x3', 'type' => 'quote', 'data' => ['text' => 'Right']]],
                'col_3' => []],
            'divider' => ['style' => 'line'],
            'spacer' => ['size' => 'large'],
            'hero' => ['heading' => 'Big', 'subheading' => 'Sub', 'image' => 'blob00000000',
                'alignment' => 'center', 'cta_label' => 'Go', 'cta_url' => '/start'],
            'rich_text' => ['body' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>'],
            'quote' => ['text' => 'Wise words', 'attribution' => 'Someone'],
            'cta' => ['heading' => 'Act now', 'body' => 'Because.', 'button_label' => 'Do it',
                'button_url' => 'https://example.com', 'variant' => 'primary'],
            'image' => ['image' => 'blob00000000', 'alt' => 'A pic', 'caption' => 'Cap', 'width' => 'wide'],
            'gallery' => ['images' => ['blob00000000', 'blob00000001'], 'columns' => '3'],
            default => [],
        };
    }

    public function testEveryStarterRendersWithRootAndModifierClasses(): void
    {
        $env = $this->env();
        foreach (StarterBlockTypes::definitions() as $definition) {
            $slug = $definition['slug'];
            $out = $env->createTemplate("{{ blocks(list) }}")->render(['list' => [
                ['id' => 'b1', 'type' => $slug, 'data' => $this->fixture($slug)],
            ]]);
            self::assertNotSame('', trim($out), "empty render for {$slug}");
            self::assertStringContainsString("lemma-block-{$slug}", $out, $slug);
        }
        // rich_text renders SANITIZED through safe_html — markup survives, attacks
        // never reach output (the no-|raw pin, now with the sanitizer shipped).
        $rich = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'rt', 'type' => 'rich_text', 'data' => $this->fixture('rich_text')]]]);
        self::assertStringContainsString('<strong>world</strong>', $rich);
        self::assertStringNotContainsString('<script', $rich);

        // Spot-check modifier classes (the style-convention pin).
        $section = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 's', 'type' => 'section', 'data' => $this->fixture('section')]]]);
        self::assertStringContainsString('lemma-block-section--subtle', $section);
        self::assertStringContainsString('Inner', $section); // children composed
    }

    public function testColumnsRendersPerLayoutEnum(): void
    {
        $env = $this->env();
        $two = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c', 'type' => 'columns', 'data' => $this->fixture('columns')]]]);
        self::assertStringContainsString('Left', $two);
        self::assertStringContainsString('Right', $two);
        self::assertStringContainsString('lemma-block-columns--2', $two);

        $data = $this->fixture('columns');
        $data['layout'] = '3';
        $data['col_3'] = [['id' => 'x4', 'type' => 'quote', 'data' => ['text' => 'Third']]];
        $three = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c3', 'type' => 'columns', 'data' => $data]]]);
        self::assertStringContainsString('Third', $three);
        self::assertStringContainsString('lemma-block-columns--3', $three);
    }

    public function testUnsafeCtaUrlsRenderNoLinkThroughTheRealTemplates(): void
    {
        $env = $this->env();
        foreach (['javascript:alert(1)', '//evil.com'] as $bad) {
            foreach (['hero' => 'cta_url', 'cta' => 'button_url'] as $slug => $field) {
                $data = $this->fixture($slug);
                $data[$field] = $bad;
                $out = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
                    ['id' => 'u', 'type' => $slug, 'data' => $data]]]);
                self::assertStringNotContainsString('<a href', $out, "{$slug} with {$bad}");
            }
        }
    }

    public function testMediaTemplatesSkipTheImageOnUnresolvableBlobs(): void
    {
        // The suite's uploads.access default is private → media() nulls; the image
        // element must be absent while the block still renders.
        $out = $this->env()->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'i', 'type' => 'image', 'data' => $this->fixture('image')]]]);
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('lemma-block-image', $out);
    }
}
