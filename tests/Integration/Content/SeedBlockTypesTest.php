<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Console\SeedBlockTypesCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NOTE (harness caveat): CommandTester runs the command object directly — it does
 * not prove console-manifest registration; that is covered by the provider diff
 * (consoleCommandServices + commands list) and the commands:cache note.
 */
final class SeedBlockTypesTest extends AppTestCase
{
    private function runSeed(): CommandTester
    {
        $command = $this->container()->get(SeedBlockTypesCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);
        return $tester;
    }

    public function testFirstRunCreatesAllDefinitionsThroughTheRepository(): void
    {
        $tester = $this->runSeed();
        $repo = new BlockTypeRepository($this->connection());
        $expected = count(StarterBlockTypes::definitions()); // not a literal (spec §8)
        self::assertCount($expected, $repo->all());
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("Created {$expected}, skipped 0.", $tester->getDisplay());
        self::assertStringContainsString('created hero', $tester->getDisplay());

        // Every definition passed create() → §2 rules validated the starters themselves.
        $section = $repo->findBySlug('section');
        self::assertSame('Layout', $section['category']);
        self::assertContains('blocks', array_column($section['schema'], 'type'));

        // Block-library expansion + theme-rewrite reconciliation: legacy blocks
        // dropped, new primitives added (incl. footer + color_mode + heading + file),
        // item carriers renamed (accordion_item, stepper_item). footer_columns removed
        // — columns + links compose it. 37 types; html seeds DEACTIVATED; hero/cta
        // carry the Nuxt UI shapes; container declares value constraints.
        // blog_posts (blog-posts spec): dynamic listing of published posts as cards.
        // modern-blocks spec §2/§3: animated_text (Content) + gallery (Media) added.
        self::assertSame(46, $expected);
        // Style block (style-block spec §3): scoped accent/neutral re-skin + class hook.
        $style = $repo->findBySlug('style');
        self::assertSame('Layout', $style['category']);
        $fields = array_column($style['schema'], 'type', 'name');
        self::assertSame('enum', $fields['accent']);
        self::assertSame('enum', $fields['neutral']);
        self::assertSame('blocks', $fields['content']);
        $accentField = array_values(array_filter($style['schema'], fn ($f) => $f['name'] === 'accent'))[0];
        self::assertContains('inherit', $accentField['enum']);
        self::assertContains('rose', $accentField['enum']);
        // Shadow-system plan: presentation controls on the style block.
        self::assertSame('enum', $fields['shadow']);
        self::assertSame('number', $fields['shadow_opacity']);
        self::assertSame('enum', $fields['padding']);
        self::assertSame('enum', $fields['margin']);
        $shadowField = array_values(array_filter($style['schema'], fn ($f) => $f['name'] === 'shadow'))[0];
        self::assertContains('2xl', $shadowField['enum']);
        self::assertSame(0, (int) $repo->findBySlug('html')['active']);
        self::assertSame('Items', $repo->findBySlug('accordion_item')['category']);
        self::assertSame('Content', $repo->findBySlug('color_mode')['category']);
        // Removed legacy blocks are gone; new primitives present with their enums.
        self::assertNull($repo->findBySlug('quote'));
        self::assertNull($repo->findBySlug('divider'));
        self::assertNull($repo->findBySlug('logo_cloud'));
        $separator = array_column($repo->findBySlug('separator')['schema'], null, 'name');
        self::assertSame(['solid', 'dashed', 'dotted'], $separator['type']['enum']);
        $accordion = array_column($repo->findBySlug('accordion')['schema'], null, 'name');
        self::assertSame(['accordion_item'], $accordion['items']['block_types']);
        $heroFields = array_column($repo->findBySlug('hero')['schema'], 'name');
        self::assertSame(
            ['headline', 'title', 'description', 'links', 'image', 'orientation', 'reverse', 'heading_level'],
            $heroFields,
        );
        $heroSchema = array_column($repo->findBySlug('hero')['schema'], null, 'name');
        self::assertSame(['h1', 'h2', 'h3'], $heroSchema['heading_level']['enum']);

        // Carousel gains a `style` variant enum (modern-blocks spec §4).
        $carousel = array_column($repo->findBySlug('carousel')['schema'], null, 'name');
        self::assertSame(['default', 'hero'], $carousel['style']['enum']);

        // animated_text (modern-blocks spec §3): reveal heading with an optional
        // rotating word list — capped at 5 alternatives by FieldValidator (Task 2).
        $animatedText = $repo->findBySlug('animated_text');
        self::assertSame('Content', $animatedText['category']);
        $animatedTextFields = array_column($animatedText['schema'], null, 'name');
        self::assertSame(
            ['prefix', 'rotate_words', 'suffix', 'effect', 'loop', 'interval', 'tag',
                'prefix_color', 'prefix_size', 'prefix_bold', 'prefix_italic',
                'rotate_color', 'rotate_size', 'rotate_bold', 'rotate_italic',
                'suffix_color', 'suffix_size', 'suffix_bold', 'suffix_italic'],
            array_column($animatedText['schema'], 'name'),
        );
        self::assertSame('string', $animatedTextFields['prefix']['type']);
        self::assertSame('text', $animatedTextFields['rotate_words']['type']);
        self::assertSame('string', $animatedTextFields['suffix']['type']);
        self::assertSame(['fade', 'slide-up', 'blur'], $animatedTextFields['effect']['enum']);
        self::assertSame(['h1', 'h2', 'h3', 'p'], $animatedTextFields['tag']['enum']);

        // gallery (modern-blocks spec §2): a responsive image grid, hard-enforced to
        // only accept `image` child blocks (enforce_block_types opts into that).
        $gallery = $repo->findBySlug('gallery');
        self::assertSame('Media', $gallery['category']);
        $galleryFields = array_column($gallery['schema'], null, 'name');
        self::assertSame(
            ['items', 'columns', 'aspect', 'lightbox'],
            array_column($gallery['schema'], 'name'),
        );
        self::assertSame('blocks', $galleryFields['items']['type']);
        self::assertSame(['image'], $galleryFields['items']['block_types']);
        self::assertTrue($galleryFields['items']['enforce_block_types']);
        self::assertSame(['2', '3', '4'], $galleryFields['columns']['enum']);
        self::assertSame(['natural', 'square', 'landscape'], $galleryFields['aspect']['enum']);
        self::assertSame('boolean', $galleryFields['lightbox']['type']);
        $ctaFields = array_column($repo->findBySlug('cta')['schema'], 'name');
        self::assertSame(
            ['title', 'description', 'variant', 'orientation', 'reverse', 'links'],
            $ctaFields,
        );
        $container = array_column($repo->findBySlug('container')['schema'], null, 'name');
        self::assertSame('#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?', $container['background_color']['pattern']);
        self::assertSame(0, $container['overlay_opacity']['min']);
        self::assertSame(100, $container['overlay_opacity']['max']);
        // Shadow-system plan: container gains a shadow-depth enum.
        self::assertArrayHasKey('shadow', $container);
        self::assertContains('2xl', $container['shadow']['enum']);

        // Button enrichment (Nuxt UI shape): full variant/size sets, primary|neutral
        // color (the navigation-parity decision), and leading/trailing icon fields.
        $button = array_column($repo->findBySlug('button')['schema'], null, 'name');
        self::assertSame(['solid', 'outline', 'soft', 'subtle', 'ghost', 'link'], $button['variant']['enum']);
        self::assertSame(['primary', 'neutral'], $button['color']['enum']);
        self::assertSame(['xs', 'sm', 'md', 'lg', 'xl'], $button['size']['enum']);
        self::assertSame('icon', $button['leading_icon']['format']);

        // Columns sizing (columns-sizing spec): ratio presets + vertical alignment.
        $columns = array_column($repo->findBySlug('columns')['schema'], null, 'name');
        self::assertContains('33-67', $columns['widths']['enum']);
        self::assertContains('25-25-50', $columns['widths']['enum']);
        self::assertSame(['stretch', 'top', 'center', 'bottom'], $columns['align']['enum']);

        // Icon-picker formats (icon-picker spec §2): editor hints paired with
        // patterns; brand-icon PAIRS the brand-prefixed pattern (P2 pin).
        $iconBlock = array_column($repo->findBySlug('icon')['schema'], null, 'name');
        self::assertSame('icon', $iconBlock['icon']['format']);
        $featureType = array_column($repo->findBySlug('feature')['schema'], null, 'name');
        self::assertSame('icon', $featureType['icon']['format']);
        $socialLink = array_column($repo->findBySlug('social_link')['schema'], null, 'name');
        self::assertSame('brand-icon', $socialLink['icon']['format']);
        self::assertSame('brand:[a-z0-9]+(-[a-z0-9]+)*', $socialLink['icon']['pattern']);

        // Navigation v2 (nav-v2 spec §1): styling + submenu enums. The style model
        // is variant/color/highlight (navigationMenu-derived), not the old
        // hover_style/active_style pair.
        $nav = array_column($repo->findBySlug('navigation')['schema'], null, 'name');
        self::assertSame(['start', 'center', 'end'], $nav['align']['enum']);
        self::assertSame(['sm', 'md', 'lg'], $nav['size']['enum']);
        self::assertSame(['pill', 'link'], $nav['variant']['enum']);
        self::assertSame(['primary', 'neutral'], $nav['color']['enum']);
        self::assertSame(['none', 'underline', 'bar'], $nav['highlight']['enum']);
        self::assertSame(['dropdown', 'columns'], $nav['submenu_layout']['enum']);
        self::assertArrayNotHasKey('active_style', $nav);
        self::assertArrayNotHasKey('hover_style', $nav);
        self::assertSame(['chevron-down', 'chevron-right', 'plus', 'none'], $nav['submenu_icon']['enum']);
        self::assertSame(['hover', 'click'], $nav['submenu_trigger']['enum']);
        // Accessible landmark label (theme-runtime spec §7): optional string,
        // the template defaults to 'Navigation' when absent.
        self::assertSame('string', $nav['aria_label']['type'] ?? null);
        self::assertArrayNotHasKey('required', $nav['aria_label']);
    }

    public function testRerunSkipsEverythingAndPreservesAdminEdits(): void
    {
        $this->runSeed();
        $repo = new BlockTypeRepository($this->connection());
        // Admin edits hero (ADDITIVELY — updateSchema is additive-only since the
        // block-migrations spec §1; destructive edits go through migrations)…
        $hero = $repo->findBySlug('hero');
        $repo->updateSchema(
            (string) $hero['uuid'],
            [...$hero['schema'], ['name' => 'badge_text', 'type' => 'string']],
            'My Hero',
            null,
            null,
            'Custom',
        );
        // …and deactivates rich_text (also an admin decision the seeder must respect).
        $repo->setActive((string) $repo->findBySlug('rich_text')['uuid'], false);

        $tester = $this->runSeed();
        $expected = count(StarterBlockTypes::definitions());
        self::assertStringContainsString("Created 0, skipped {$expected}.", $tester->getDisplay());
        self::assertStringContainsString('skipped hero (exists)', $tester->getDisplay());

        $after = $repo->findBySlug('hero');
        self::assertSame('My Hero', $after['label']);                      // byte-identical edit survives
        self::assertContains('badge_text', array_column($after['schema'], 'name'));
        self::assertSame(0, (int) $repo->findBySlug('rich_text')['active']);   // deactivation survives
    }
}
