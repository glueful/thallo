<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A target's typeface becomes one utility (block typeface spec §3.3; plan Task 5): a built-in or a
 * current library family its own `t-font-*`; a removed or unknown ID `t-font-inherit` (the stored
 * document keeps the ID); a reset `t-font-reset`. A block's typeface lands on the target the
 * typography group maps to; a part (`settings.parts.<name>`) holds its own, and the block's style
 * classes never reach it. Real block templates, rendered through the extension.
 */
final class TypefaceResolutionTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const UPLOADED = 'Ab3dE5fG7hJ9';
    private const REMOVED = 'Rm3dE5fG7hJ9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $now = gmdate('Y-m-d H:i:s');
        foreach ([self::UPLOADED => null, self::REMOVED => $now] as $id => $removed) {
            $this->connection()->table('font_families')->insert([
                'id' => $id, 'name' => 'Family ' . $id, 'fallback' => 'serif', 'removed_at' => $removed,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection()->table('font_faces')->insert([
                'id' => 'face' . substr($id, 0, 8), 'family_id' => $id, 'blob_uuid' => 'blob' . substr($id, 0, 8),
                'weight_min' => 400, 'weight_max' => 400, 'italic' => false, 'variable' => false,
                'unknown' => false, 'created_at' => $now,
            ]);
        }
    }

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => $blocks]);
    }

    /** @return array{type: string, value?: string} */
    private static function font(string $id): array
    {
        return ['type' => 'font', 'value' => $id];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function links(string $id, array $settings, ?string $title = 'Explore'): array
    {
        return [
            'id' => $id,
            'type' => 'links',
            'data' => ['title' => $title, 'items' => [
                ['label' => 'One', 'url' => '/one'],
                ['label' => 'Two', 'url' => '/two'],
            ]],
            'settings' => $settings,
        ];
    }

    /** @return list<string> the class attribute of every element carrying the class `$marker` */
    private static function classesOf(string $html, string $marker): array
    {
        $token = '(?<![\\w-])' . preg_quote($marker, '/') . '(?![\\w-])';
        preg_match_all('/class="([^"]*' . $token . '[^"]*)"/', $html, $m);
        return $m[1];
    }

    /** @return list<string> the t-font-* utilities in one class attribute */
    private static function fonts(string $classAttr): array
    {
        preg_match_all('/\bt-font-[A-Za-z0-9-]+/', $classAttr, $m);
        return $m[0];
    }

    /**
     * The Links block's title class attribute, rendered with `$settings`.
     *
     * @param array<string,mixed> $settings
     * @return list<string>
     */
    private function titleClasses(array $settings): array
    {
        return self::classesOf($this->render([self::links('links0000001', $settings)]), 'thallo-block-links__title');
    }

    private function styleClass(string $id, string $fontId): void
    {
        $this->connection()->table('style_classes')->insert([
            'id' => $id, 'name' => 'Class ' . $id, 'name_key' => strtolower($id),
            'style' => json_encode(['typography' => ['family' => self::font($fontId)]]),
            'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(StyleClassProvider::class)->refresh();
    }

    public function testATargetAndAPartUseDifferentFamilies(): void
    {
        $html = $this->render([self::links('links0000001', [
            'style' => ['typography' => ['family' => self::font('serif')]],
            'parts' => ['link' => ['typography' => ['family' => self::font(self::UPLOADED)]]],
        ])]);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-serif'], self::fonts($title));
        $links = self::classesOf($html, 'thallo-block-links__link');
        self::assertCount(2, $links);
        foreach ($links as $link) {
            self::assertSame(['t-font-' . self::UPLOADED], self::fonts($link));
        }
        [$root] = self::classesOf($html, 'thallo-block-links');
        self::assertSame([], self::fonts($root), 'the typography group maps to the title, not the root');
    }

    public function testABlockClassDoesNotReachAPart(): void
    {
        $this->styleClass('monoclass001', 'mono');
        $html = $this->render([self::links('links0000001', ['classes' => ['monoclass001']])]);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-mono'], self::fonts($title));
        foreach (self::classesOf($html, 'thallo-block-links__link') as $link) {
            self::assertSame([], self::fonts($link));
        }
    }

    public function testAMissingOptionalTargetEmitsNothingAndBreaksNothing(): void
    {
        $html = $this->render([self::links('links0000001', [
            'style' => ['typography' => ['family' => self::font('slab')]],
        ], title: null)]);
        self::assertSame([], self::classesOf($html, 'thallo-block-links__title'));
        self::assertStringNotContainsString('t-font-', $html);
        self::assertCount(2, self::classesOf($html, 'thallo-block-links__link'));
    }

    public function testANestedBlocksTypefaceStaysItsOwn(): void
    {
        $html = $this->render([[
            'id' => 'card00000001', 'type' => 'card',
            'data' => ['title' => 'Outer', 'body' => [self::links('links0000001', [
                'style' => ['typography' => ['family' => self::font('mono')]],
            ])]],
            'settings' => ['style' => ['typography' => ['family' => self::font('humanist')]]],
        ]]);
        [$cardTitle] = self::classesOf($html, 'thallo-block-card__title');
        self::assertSame(['t-font-humanist'], self::fonts($cardTitle));
        [$linksTitle] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-mono'], self::fonts($linksTitle));
    }

    public function testARemovedFamilyInheritsAndKeepsItsId(): void
    {
        $settings = ['style' => ['typography' => ['family' => self::font(self::REMOVED)]]];
        $html = $this->render([self::links('links0000001', $settings)]);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-inherit'], self::fonts($title));
        self::assertStringNotContainsString(self::REMOVED, $html, 'a removed ID never reaches the page');
    }

    public function testAnUnknownIdFromAnotherWorkspaceInherits(): void
    {
        $settings = ['style' => ['typography' => ['family' => self::font('Zz9yX8wV7uT6')]]];
        [$title] = $this->titleClasses($settings);
        self::assertSame(['t-font-inherit'], self::fonts($title));
    }

    public function testAClassRemovedFamilyBeatsAnOlderClassFamily(): void
    {
        $this->styleClass('serifclass01', 'serif');
        $this->styleClass('removedcls01', self::REMOVED);
        $html = $this->render([self::links('links0000001', ['classes' => ['serifclass01', 'removedcls01']])]);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-inherit'], self::fonts($title));
    }

    public function testResetEmitsResetAndSuppressesClasses(): void
    {
        $this->styleClass('serifclass01', 'serif');
        $html = $this->render([self::links('links0000001', [
            'classes' => ['serifclass01'],
            'style' => ['typography' => ['family' => ['type' => 'reset']]],
        ])]);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertSame(['t-font-reset'], self::fonts($title));
    }

    public function testAbsentEmitsNothing(): void
    {
        $html = $this->render([self::links('links0000001', [])]);
        self::assertStringNotContainsString('t-font-', $html);
    }

    public function testTheSnapshotIsTakenOncePerRenderAndRefreshedForTheNext(): void
    {
        $settings = ['style' => ['typography' => ['family' => self::font(self::UPLOADED)]]];
        [$title] = $this->titleClasses($settings);
        self::assertSame(['t-font-' . self::UPLOADED], self::fonts($title));
        $this->connection()->table('font_families')->where('id', '=', self::UPLOADED)
            ->update(['removed_at' => gmdate('Y-m-d H:i:s')]);
        [$after] = $this->titleClasses($settings);
        self::assertSame(['t-font-inherit'], self::fonts($after), 'the next render reads the library again');
    }
}
