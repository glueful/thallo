<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\CustomBlockStyle;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Content\Http\DTOs\BlockTypeData;
use Thallo\Core\Content\Http\DTOs\FieldDefinitionData;
use Thallo\Core\Content\Http\DTOs\UpdateBlockTypeData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateRepository;

/**
 * A block type made in the admin could be given fields and a template, but never style settings:
 * its Style tab stayed empty, because only a code-declared type carried a style declaration. An
 * admin now chooses which SETTING GROUPS the block supports. The block has one style target, its
 * outermost element (`root`, a box), so the choice is of groups a box can carry — and the
 * template has to emit them, which the save checks rather than letting the block break.
 */
final class CustomBlockStyleDeclarationApiTest extends AppTestCase
{
    private function api(): BlockTypeController
    {
        return $this->container()->get(BlockTypeController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    /** @param list<string>|null $capabilities */
    private function create(string $slug, ?array $capabilities): \Glueful\Http\Response
    {
        return $this->api()->store(new BlockTypeData(
            slug: $slug,
            label: ucfirst($slug),
            icon: null,
            description: null,
            schema: [new FieldDefinitionData(name: 'title', type: 'string')],
            style_capabilities: $capabilities,
        ), $this->req());
    }

    /** @param list<string>|null $capabilities */
    private function update(string $slug, ?array $capabilities): \Glueful\Http\Response
    {
        return $this->api()->update(new UpdateBlockTypeData(
            label: ucfirst($slug),
            icon: null,
            description: null,
            schema: [new FieldDefinitionData(name: 'title', type: 'string')],
            style_capabilities: $capabilities,
        ), Request::create('/x', 'PATCH'), $slug);
    }

    public function testACustomBlockIsCreatedWithTheSettingGroupsChosenForItOnOneRootTarget(): void
    {
        $res = $this->create('promo', ['spacing', 'colors', 'radius']);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $type = $this->json($res)['data']['block_type'];
        self::assertSame(['spacing', 'colors', 'radius'], $type['style_capabilities']);
        // One target, the outermost element; the Advanced fields land there too, as on every block.
        self::assertSame(['root' => ['kind' => 'box']], $type['style_targets']['targets']);
        foreach (['spacing', 'colors', 'radius', 'advanced.anchor'] as $mapped) {
            self::assertSame('root', $type['style_targets']['map'][$mapped] ?? null, $mapped);
        }

        // With none chosen the block is what a custom block always was: no declaration at all.
        $plain = $this->json($this->create('plain', null))['data']['block_type'];
        self::assertNull($plain['style_capabilities']);
        self::assertNull($plain['style_targets']);
    }

    public function testAnUpdateChangesThemClearsThemOrLeavesThemAlone(): void
    {
        $this->create('promo', ['spacing']);
        $repo = new BlockTypeRepository($this->connection());

        self::assertSame(200, $this->update('promo', ['spacing', 'shadow'])->getStatusCode());
        self::assertSame(['spacing', 'shadow'], $repo->findBySlug('promo')['style_capabilities']);

        // Absent from the payload: untouched — an editor that only renames a field sends none.
        self::assertSame(200, $this->update('promo', null)->getStatusCode());
        self::assertSame(['spacing', 'shadow'], $repo->findBySlug('promo')['style_capabilities']);

        // An empty list clears the declaration, targets and all.
        self::assertSame(200, $this->update('promo', [])->getStatusCode());
        self::assertNull($repo->findBySlug('promo')['style_capabilities']);
        self::assertNull($repo->findBySlug('promo')['style_targets']);
    }

    public function testOnlyGroupsABoxCanCarryAreAccepted(): void
    {
        // Offered by the API, so the admin's picker is the server's list and not a copy of it.
        $index = $this->json($this->api()->index(Request::create('/x', 'GET')));
        self::assertSame(CustomBlockStyle::GROUPS, $index['data']['style_capability_options']);
        self::assertContains('spacing', CustomBlockStyle::GROUPS);
        self::assertContains('typography', CustomBlockStyle::GROUPS);
        // Text alignment needs a TEXT target and the parent layout groups a STACK: a custom block's
        // one target is a box, so they are not offered — and are refused if sent anyway.
        self::assertNotContains('alignment.text', CustomBlockStyle::GROUPS);
        self::assertNotContains('layout.display', CustomBlockStyle::GROUPS);

        foreach (['alignment.text', 'layout.display', 'sparkle'] as $bad) {
            $res = $this->create('bad' . substr(md5($bad), 0, 6), ['spacing', $bad]);
            self::assertSame(422, $res->getStatusCode(), $bad);
            self::assertArrayHasKey('style_capabilities', $this->json($res)['error']['details'], $bad);
        }
    }

    public function testACodeDeclaredTypesDeclarationIsNotTheAdminsToChange(): void
    {
        // `button` is declared by Thallo (provision re-syncs it on every upgrade): an admin's
        // change would be overwritten, so it is refused. Its fields are still editable, as before.
        $definition = null;
        foreach (\Thallo\Core\Content\Blocks\StarterBlockTypes::definitions() as $candidate) {
            if ($candidate['slug'] === 'button') {
                $definition = $candidate;
            }
        }
        self::assertNotNull($definition);
        (new BlockTypeRepository($this->connection()))->create($definition);

        $res = $this->api()->update(new UpdateBlockTypeData(
            label: 'Button',
            icon: null,
            description: null,
            schema: array_map(
                static fn (array $f): FieldDefinitionData => new FieldDefinitionData(...$f),
                [['name' => 'label', 'type' => 'string']],
            ),
            style_capabilities: ['spacing'],
        ), Request::create('/x', 'PATCH'), 'button');
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString(
            'declared by Thallo',
            (string) $this->json($res)['error']['details']['style_capabilities'],
        );

        // The index names the code-declared types, so the editor shows theirs read-only rather
        // than offering a save that will be refused.
        $this->create('promo', ['spacing']);
        $index = $this->json($this->api()->index(Request::create('/x', 'GET')))['data'];
        self::assertContains('button', $index['code_declared_slugs']);
        self::assertNotContains('promo', $index['code_declared_slugs']);
    }

    public function testSettingsAreNotSwitchedOnWhileTheBlocksTemplateDoesNotEmitThem(): void
    {
        // A stored template that does not style a declared target refuses to LOAD (the template
        // lint), so switching settings on under it would break the block on every page. The save
        // says what to add instead.
        $this->create('promo', null);
        $templates = new TemplateRepository($this->connection());
        $templates->save('default', 'blocks/promo.twig', '<div class="promo">{{ data.title }}</div>', null);

        $res = $this->update('promo', ['spacing']);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getContent());
        $message = (string) $this->json($res)['error']['details']['style_capabilities'];
        self::assertStringContainsString("style_classes('root')", $message);
        self::assertStringContainsString('blocks/promo.twig', $message);
        self::assertNull((new BlockTypeRepository($this->connection()))->findBySlug('promo')['style_capabilities']);

        // Once the template emits them, the same save goes through.
        $templates->save(
            'default',
            'blocks/promo.twig',
            '<div class="promo{{ style_classes(\'root\') }}"{{ style_attrs(\'root\') }}>{{ data.title }}</div>',
            null,
        );
        self::assertSame(200, $this->update('promo', ['spacing'])->getStatusCode());

        // A block with no template yet has nothing to break: the lint will hold the template to
        // the declaration when it is written.
        self::assertSame(201, $this->create('later', ['spacing'])->getStatusCode());
    }

    public function testAPageSavesTheChosenSettingsOnTheBlockAndIsRefusedTheOthers(): void
    {
        // What the declaration is FOR: the block's Style tab now writes settings a save accepts.
        $this->create('promo', ['spacing', 'radius']);
        $validator = new \Thallo\Core\Content\Validation\FieldValidator(
            $this->connection(),
            $this->appContext(),
            new BlockTypeRepository($this->connection()),
        );
        $schema = \Thallo\Core\Content\Schema\ContentTypeSchema::fromArray(
            [['name' => 'body', 'type' => 'blocks']],
        );
        $block = static fn (array $style): array => ['body' => [[
            'id' => 'promo0000001', 'type' => 'promo', 'data' => ['title' => 'Hi'],
            'settings' => ['style' => $style],
        ]]];

        $radius = ['radius' => ['type' => 'token', 'value' => 'radius.lg']];
        $clean = $validator->validate($schema, $block($radius), true);
        self::assertSame($radius, $clean['body'][0]['settings']['style']);

        $this->expectException(\Thallo\Core\Content\Validation\ValidationException::class);
        $shadow = ['shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.md']]];
        $validator->validate($schema, $block($shadow), true);
    }
}
