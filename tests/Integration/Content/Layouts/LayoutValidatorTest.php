<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Core\Content\Layouts\EntrySurface;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Style\Classes\StyleClassArchived;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The layout validator (type layouts spec §5.6): a layout's blocks validate as a page save would,
 * every block is one a layout may hold, the primary body is placed exactly once, each blocks field
 * at most once, and every field a block names exists on the type with a type the block can show.
 * Errors name the block and its field.
 */
final class LayoutValidatorTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function validator(): LayoutValidator
    {
        return $this->container()->get(LayoutValidator::class);
    }

    /** @param list<array<string,mixed>> $schema */
    private function type(string $slug, string $name, array $schema): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => $slug, 'name' => $name, 'public_delivery' => true, 'schema' => $schema,
        ]);
    }

    private function seedShapes(): void
    {
        $this->type('category', 'Categories', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]);
        $this->type('post', 'Posts', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'body', 'type' => 'blocks', 'required' => true],
            ['name' => 'sidebar', 'type' => 'blocks'],
            ['name' => 'reading_time', 'type' => 'number'],
            ['name' => 'categories', 'type' => 'reference', 'multiple' => true, 'filterable' => true,
                'reference_type' => 'category', 'reference_slug_field' => 'slug'],
        ]);
        $this->type('pages', 'Pages', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
        $this->type('guide', 'Guides', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'content', 'type' => 'blocks'],
        ]);
        $this->type('note', 'Notes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]);
        $this->type('quote', 'Quotes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'author', 'type' => 'string'],
        ]);
    }

    /**
     * A tree with an id on every block, as the editor sends it.
     *
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'lay'): array
    {
        $out = [];
        foreach ($tree as $i => $block) {
            $block['id'] ??= str_pad($prefix . $i, 12, '0');
            $block['settings'] ??= [];
            foreach ($block['data'] ?? [] as $field => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $block['data'][$field] = self::withIds($value, substr($prefix, 0, 3) . $i . 'n');
                }
            }
            $out[] = $block;
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function block(string $type, array $data = []): array
    {
        return ['type' => $type, 'data' => $data, 'settings' => []];
    }

    /**
     * The errors a tree draws for the post type, keyed by path.
     *
     * @param list<array<string,mixed>> $tree
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    private function errors(array $tree, array $settings = [], string $target = 'post'): array
    {
        try {
            $this->validator()->validate('entry', $target, self::withIds($tree), $settings);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        return [];
    }

    public function testEveryStarterShapeValidates(): void
    {
        $this->seedShapes();
        $surface = $this->container()->get(EntrySurface::class);
        foreach (['post', 'pages', 'guide', 'note', 'quote'] as $slug) {
            $starter = $surface->starter($slug);
            self::assertSame([], $this->errors($starter, [], $slug), $slug);
        }
        $clean = $this->validator()->validate('entry', 'post', self::withIds($surface->starter('post')), []);
        self::assertSame(['blocks', 'settings'], array_keys($clean));
        self::assertCount(count($surface->starter('post')), $clean['blocks']);
    }

    public function testThePrimaryBodyIsPlacedExactlyOnceAndOnlyAsABlocksField(): void
    {
        $this->seedShapes();
        $title = self::block('entry_title', ['level' => 'h1']);
        $body = self::block('entry_content', ['field' => 'body']);

        self::assertArrayHasKey('blocks', $this->errors([$title]));
        self::assertStringContainsString("'body'", $this->errors([$title])['blocks']);

        self::assertArrayHasKey('blocks.2.data.field', $this->errors([$title, $body, $body]));

        $errors = $this->errors([$title, $body, self::block('entry_content', ['field' => 'excerpt'])]);
        self::assertArrayHasKey('blocks.2.data.field', $errors);

        // An optional blocks field: once is fine, twice is not.
        self::assertSame([], $this->errors([$title, $body, self::block('entry_content', ['field' => 'sidebar'])]));
        $twice = [$title, $body, self::block('entry_content', ['field' => 'sidebar']),
            self::block('entry_content', ['field' => 'sidebar'])];
        self::assertArrayHasKey('blocks.3.data.field', $this->errors($twice));

        // Placed inside a container still counts, and the path reaches it.
        $nested = [$title, self::block('container', ['content' => [$body]])];
        self::assertSame([], $this->errors($nested));
        $nestedTwice = [$body, self::block('container', ['content' => [$body]])];
        self::assertArrayHasKey('blocks.1.data.content.0.data.field', $this->errors($nestedTwice));
    }

    public function testAFieldBlockShowsOnlyAFieldItCanShow(): void
    {
        $this->seedShapes();
        $body = self::block('entry_content', ['field' => 'body']);
        foreach (
            [
                ['entry_cover', 'excerpt'],
                ['entry_terms', 'excerpt'],
                ['entry_field', 'body'],
                ['entry_excerpt', 'cover'],
                ['entry_cover', 'nope'],
            ] as [$type, $field]
        ) {
            $errors = $this->errors([$body, self::block($type, ['field' => $field])]);
            self::assertArrayHasKey('blocks.1.data.field', $errors, "{$type} → {$field}");
        }
        $good = [
            $body,
            self::block('entry_cover', ['field' => 'cover']),
            self::block('entry_terms', ['field' => 'categories']),
            self::block('entry_field', ['field' => 'reading_time', 'format' => 'number']),
            self::block('entry_excerpt', ['field' => 'excerpt']),
            // A field left unchosen shows the block's own default; nothing to refuse yet.
            self::block('entry_cover'),
        ];
        self::assertSame([], $this->errors($good));
    }

    /**
     * A format a field cannot take would fail every page of the type when it renders (a date read
     * from a word): the validator refuses it, so Save never sends it live.
     */
    public function testAnEntryFieldsFormatMustSuitItsField(): void
    {
        $this->seedShapes();
        $this->type('event', 'Events', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
            ['name' => 'starts', 'type' => 'datetime'],
            ['name' => 'seats', 'type' => 'number'],
            ['name' => 'venue', 'type' => 'string'],
        ]);
        $body = self::block('entry_content', ['field' => 'body']);
        $field = static fn (string $name, string $format): array => self::block(
            'entry_field',
            ['field' => $name, 'format' => $format],
        );
        $refused = [['venue', 'date'], ['seats', 'date'], ['venue', 'number'], ['starts', 'number']];
        foreach ($refused as [$name, $format]) {
            $errors = $this->errors([$body, $field($name, $format)], [], 'event');
            self::assertArrayHasKey('blocks.1.data.format', $errors, "{$name} as {$format}");
        }
        foreach ([['starts', 'date'], ['seats', 'number'], ['venue', 'text'], ['seats', 'text']] as [$name, $format]) {
            self::assertSame([], $this->errors([$body, $field($name, $format)], [], 'event'), "{$name} as {$format}");
        }
    }

    /**
     * A Cover, Excerpt or Terms block left without a field shows the type's `cover`, `excerpt` or
     * `categories`: that binding is written into the block, so a rename moves it and a delete is
     * refused like any other (spec §5.7). Where the type has no such field, nothing is written.
     */
    public function testAFieldLeftUnchosenIsBoundToTheFieldItShows(): void
    {
        $this->seedShapes();
        $clean = $this->validator()->validate('entry', 'post', self::withIds([
            self::block('entry_content'),
            self::block('entry_cover'),
            self::block('entry_excerpt'),
            self::block('container', ['content' => [self::block('entry_terms')]]),
        ]), []);
        self::assertSame('body', $clean['blocks'][0]['data']['field']);
        self::assertSame('cover', $clean['blocks'][1]['data']['field']);
        self::assertSame('excerpt', $clean['blocks'][2]['data']['field']);
        self::assertSame('categories', $clean['blocks'][3]['data']['content'][0]['data']['field']);

        // Pages has no cover: the block stays unbound (it shows nothing), and nothing refuses it.
        $pages = $this->validator()->validate('entry', 'pages', self::withIds([
            self::block('entry_content'),
            self::block('entry_cover'),
        ]), []);
        self::assertArrayNotHasKey('field', $pages['blocks'][1]['data']);
    }

    public function testGeneralBlocksAreWelcomeAndUnknownOnesAreNot(): void
    {
        $this->seedShapes();
        $body = self::block('entry_content', ['field' => 'body']);
        $tree = [
            self::block('heading', ['text' => 'Read on']),
            self::block('container', ['content' => [self::block('entry_title', ['level' => 'h2']), $body]]),
        ];
        self::assertSame([], $this->errors($tree));

        $errors = $this->errors([$body, self::block('no_such_block')]);
        self::assertArrayHasKey('blocks.1.type', $errors);
        $errors = $this->errors([self::block('container', ['content' => [self::block('no_such_block')]]), $body]);
        self::assertArrayHasKey('blocks.0.data.content.0.type', $errors);
    }

    public function testTheFrameSettingsAreAFixedVocabulary(): void
    {
        $this->seedShapes();
        $body = [self::block('entry_content', ['field' => 'body'])];
        $clean = $this->validator()->validate(
            'entry',
            'post',
            self::withIds($body),
            ['width' => 'full', 'header' => 'hidden', 'footer' => 'default'],
        );
        self::assertSame(['width' => 'full', 'header' => 'hidden', 'footer' => 'default'], $clean['settings']);
        self::assertArrayHasKey('settings.width', $this->errors($body, ['width' => 'wide']));
        self::assertArrayHasKey('settings.header', $this->errors($body, ['header' => 'gone']));
        self::assertArrayHasKey('settings.sticky', $this->errors($body, ['sticky' => true]));
    }

    public function testAnUnknownSurfaceOrTargetIsRefused(): void
    {
        $this->seedShapes();
        $body = [self::block('entry_content', ['field' => 'body'])];
        self::assertArrayHasKey('surface', $this->errorsFor('nowhere', 'post', $body));
        self::assertArrayHasKey('target', $this->errorsFor('entry', 'no_such_type', $body));
    }

    public function testANewlyAppliedArchivedStyleClassIsRefusedAndAStoredOneIsNot(): void
    {
        $this->seedShapes();
        $classes = $this->container()->get(StyleClassRepository::class);
        $old = $classes->create(['name' => 'Old', 'style' => []]);
        $classes->archive($old['id']);
        $styled = self::withIds([
            self::block('entry_content', ['field' => 'body']),
            ['type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => ['classes' => [$old['id']]]],
        ]);

        // Stored already: an edit elsewhere still validates.
        $this->validator()->validate('entry', 'post', $styled, [], $styled);

        $this->expectException(StyleClassArchived::class);
        $this->validator()->validate('entry', 'post', $styled, []);
    }

    /**
     * A surface's required block without a field — the product page's Product buy box — must be placed
     * exactly once, anywhere in the tree (type layouts spec §2.7, §3): missing, the error names the
     * block by its label; twice, the second is refused at its path.
     */
    public function testARequiredBlockWithoutAFieldIsNeededExactlyOnce(): void
    {
        $registry = $this->container()->get(\Thallo\Contracts\Layouts\LayoutSurfaceRegistry::class);
        $registry->register(new \Thallo\Core\Tests\Support\FixtureLayoutSurface());
        try {
            $button = self::block('button', ['label' => 'Go', 'url' => '/go']);
            $heading = self::block('heading', ['text' => 'Hi']);

            $none = $this->errorsFor('fixture', '@site', [$heading]);
            self::assertSame(['blocks' => 'the layout must show the Button block'], $none);

            $twice = $this->errorsFor('fixture', '@site', [
                $button,
                self::block('container', ['content' => [$button]]),
            ]);
            self::assertSame(["blocks.1.data.content.0.type" => "'button' can appear only once in a layout"], $twice);

            self::assertSame([], $this->errorsFor('fixture', '@site', [
                $heading,
                self::block('container', ['content' => [$button]]),
            ]));
        } finally {
            \Thallo\Core\Tests\Support\FixtureLayoutSurface::unregister($registry);
        }
    }

    /**
     * A required block without a field cannot disappear with the block that holds it (type layouts
     * plan C1): a container around it hidden at any size — by its own Visibility or by a style class —
     * is refused at that container, naming the block it holds. Visible, or hidden elsewhere, is fine.
     */
    public function testNoBlockHoldingARequiredBlockWithoutAFieldCanBeHidden(): void
    {
        $registry = $this->container()->get(\Thallo\Contracts\Layouts\LayoutSurfaceRegistry::class);
        $registry->register(new \Thallo\Core\Tests\Support\FixtureLayoutSurface());
        try {
            $button = self::block('button', ['label' => 'Go', 'url' => '/go']);
            $hidden = static fn (array $visibility, array $content): array => [
                'type' => 'container', 'data' => ['content' => $content],
                'settings' => ['style' => ['visibility' => $visibility]],
            ];
            $choice = static fn (string $value): array => ['type' => 'choice', 'value' => $value];
            $holds = 'this block holds the Button block, which every page shows: it cannot be hidden';

            self::assertSame(
                ['blocks.0.settings.style.visibility' => $holds],
                $this->errorsFor('fixture', '@site', [$hidden(['base' => $choice('hidden')], [$button])]),
            );
            // Hidden at one size only, two containers out.
            self::assertSame(
                ['blocks.0.settings.style.visibility' => $holds],
                $this->errorsFor('fixture', '@site', [$hidden(
                    ['md' => $choice('hidden'), 'lg' => $choice('visible')],
                    [['id' => 'innerbox0001'] + self::block('container', [
                        'content' => [['id' => 'innerbutton1'] + $button],
                    ])],
                )]),
            );
            // Visible everywhere, or a hidden sibling: nothing to refuse.
            self::assertSame([], $this->errorsFor('fixture', '@site', [
                $hidden(['base' => $choice('visible')], [$button]),
                $hidden(['base' => $choice('hidden')], [self::block('heading', ['text' => 'Aside'])]),
            ]));

            $classes = $this->container()->get(StyleClassRepository::class);
            $class = $classes->create(['name' => 'Tuck away', 'style' => [
                'visibility' => ['lg' => $choice('hidden')],
            ]]);
            self::assertSame(
                ['blocks.0.settings.classes' => $holds],
                $this->errorsFor('fixture', '@site', [[
                    'type' => 'container', 'data' => ['content' => [$button]],
                    'settings' => ['classes' => [$class['id']]],
                ]]),
            );
            // The block's own Visibility at that size overrides the class; at a smaller size it does not.
            $own = static fn (array $visibility): array => [[
                'type' => 'container', 'data' => ['content' => [$button]],
                'settings' => ['classes' => [$class['id']], 'style' => ['visibility' => $visibility]],
            ]];
            self::assertSame([], $this->errorsFor('fixture', '@site', $own(['lg' => $choice('visible')])));
            self::assertSame(
                ['blocks.0.settings.classes' => $holds],
                $this->errorsFor('fixture', '@site', $own(['base' => $choice('visible')])),
            );
        } finally {
            \Thallo\Core\Tests\Support\FixtureLayoutSurface::unregister($registry);
        }
    }

    /**
     * A surface's loops (type layouts spec §5.6, plan B): the blocks a loop's card holds live inside
     * that card only, and the surface's page-level blocks — the loop itself, its required blocks —
     * never go inside one; general content blocks go anywhere.
     */
    public function testCardBlocksLiveInsideTheCardOnly(): void
    {
        $this->withFixtureLoop(function (): void {
            $button = self::block('button', ['label' => 'Go', 'url' => '/go']);
            $loop = static fn (array $card): array => ['type' => 'fixture_loop', 'data' => ['card' => $card],
                'settings' => []];
            // Every layout here holds the surface's required block, at the root.
            $required = ['id' => 'fixrequire01'] + self::block('fixture_required');

            self::assertSame(
                ['blocks.1.type' => "'button' goes inside the Fixture loop's card"],
                $this->errorsFor('fixture', '@site', [
                    $required,
                    $button,
                    $loop([self::block('heading', ['text' => 'A'])]),
                ]),
            );
            self::assertSame([], $this->errorsFor('fixture', '@site', [$required, $loop([$button])]));
            self::assertSame([], $this->errorsFor('fixture', '@site', [
                $required,
                $loop([['id' => 'cardbox00001'] + self::block('container', [
                    'content' => [['id' => 'cardbutton01'] + $button],
                ])]),
            ]));
            self::assertSame([], $this->errorsFor('fixture', '@site', [
                $required,
                $loop([$button, self::block('heading', ['text' => 'In a card'])]),
            ]));
        });
    }

    public function testPageBlocksNeverGoInsideACard(): void
    {
        $this->withFixtureLoop(function (): void {
            $button = self::block('button', ['label' => 'Go', 'url' => '/go']);
            $loop = static fn (array $card): array => ['type' => 'fixture_loop', 'data' => ['card' => $card],
                'settings' => []];
            // Every layout here holds the surface's required block, at the root.
            $required = ['id' => 'fixrequire01'] + self::block('fixture_required');

            // The loop inside its own card.
            self::assertSame(
                ['blocks.1.data.card.1.type' => "'fixture_loop' cannot go inside a card"],
                $this->errorsFor('fixture', '@site', [$required, $loop([
                    $button,
                    ['id' => 'innerloop001'] + $loop([['id' => 'innerbutton1'] + $button]),
                ])]),
            );
            // The surface's required block inside the card: refused there, and still counted — so
            // the layout is not also told it lacks one.
            self::assertSame(
                ['blocks.0.data.card.1.type' => "'fixture_required' cannot go inside a card"],
                $this->errorsFor('fixture', '@site', [$loop([
                    $button,
                    self::block('fixture_required'),
                ])]),
            );
        });
    }

    public function testCardRulesDoNotTouchSurfacesWithoutLoops(): void
    {
        $this->seedShapes();
        self::assertSame([], $this->errorsFor('entry', 'post', [
            self::block('entry_title', ['level' => 'h1']),
            self::block('entry_content', ['field' => 'body']),
        ]));
    }

    /**
     * The fixture surface with a loop: `fixture_loop` (a blocks field `card`) whose card holds
     * `button`, and a required block without a field, `fixture_required`.
     */
    private function withFixtureLoop(callable $test): void
    {
        $blockTypes = $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class);
        $blockTypes->create([
            'slug' => 'fixture_loop', 'label' => 'Fixture loop', 'category' => 'Fields',
            'schema' => [['name' => 'card', 'type' => 'blocks']],
        ]);
        $blockTypes->create(['slug' => 'fixture_required', 'label' => 'Fixture required', 'schema' => []]);
        $registry = $this->container()->get(\Thallo\Contracts\Layouts\LayoutSurfaceRegistry::class);
        \Thallo\Core\Tests\Support\FixtureLayoutSurface::$loops = [
            ['type' => 'fixture_loop', 'card' => 'card', 'items' => ['button']],
        ];
        \Thallo\Core\Tests\Support\FixtureLayoutSurface::$required = [['type' => 'fixture_required']];
        $registry->register(new \Thallo\Core\Tests\Support\FixtureLayoutSurface());
        try {
            $test();
        } finally {
            \Thallo\Core\Tests\Support\FixtureLayoutSurface::$loops = [];
            \Thallo\Core\Tests\Support\FixtureLayoutSurface::$required = null;
            \Thallo\Core\Tests\Support\FixtureLayoutSurface::unregister($registry);
        }
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return array<string,string>
     */
    private function errorsFor(string $surface, string $target, array $tree): array
    {
        try {
            $this->validator()->validate($surface, $target, self::withIds($tree), []);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        return [];
    }
}
