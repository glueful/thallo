<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Content\Blocks\BlockDepth;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Visual builder spec §5.2: depth five, justified by a composition fixture — section > columns
 * > card > container > heading (and a button leaf) — validated by the real validator and
 * rendered by the shipped templates with a setting landing on the depth-five block.
 */
final class CompositionFixturesTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        $path = __DIR__ . '/../../fixtures/composition/five-deep.json';
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    public function testTheCapIsFiveOnEverySurface(): void
    {
        self::assertSame(5, BlockDepth::MAX);
        self::assertSame(BlockDepth::MAX, RenderContextExtension::MAX_BLOCK_DEPTH);
        $mirror = (string) file_get_contents(__DIR__ . '/../../../admin/src/queries/blockTypes.ts');
        self::assertStringContainsString('export const MAX_BLOCK_DEPTH = 5', $mirror);
    }

    public function testTheFiveDeepCompositionValidatesAndASixthLevelDoesNot(): void
    {
        $uuid = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'page', 'name' => 'Page', 'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $schema = $this->container()->get(ContentTypeRepository::class)->findByUuid($uuid)['schema'];
        $schema = \Thallo\Core\Content\Schema\ContentTypeSchema::fromArray((array) $schema);
        $validator = $this->container()->get(FieldValidator::class);
        $clean = $validator->validate($schema, ['title' => 'Deep', 'body' => self::fixture()['body']]);
        $card = $clean['body'][0]['data']['content'][0]['data']['content'][0];
        $heading = $card['data']['body'][0]['data']['content'][0];
        self::assertSame('heading', $heading['type']);
        self::assertSame('spacing.lg', $heading['settings']['style']['spacing']['padding']['top']['md']['value']);

        $six = self::fixture()['body'];
        $six[0]['data']['content'][0]['data']['content'][0]['data']['body'][0]['data']['content'] = [[
            'id' => 'cont00000009', 'type' => 'container', 'data' => ['content' => [
                ['id' => 'head00000009', 'type' => 'heading', 'data' => ['text' => 'six']],
            ]],
        ]];
        try {
            $validator->validate($schema, ['title' => 'Deep', 'body' => $six]);
            self::fail('depth six must not validate');
        } catch (\Thallo\Core\Content\Validation\ValidationException $e) {
            $path = 'body.0.content.0.content.0.body.0.content.0.content';
            self::assertArrayHasKey($path, $e->errors(), json_encode(array_keys($e->errors())));
        }
    }

    public function testTheShippedTemplatesRenderTheDepthFiveBlockWithItsSetting(): void
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        $html = $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => self::fixture()['body']]);
        self::assertSame(1, preg_match('~<h2[^>]*class="[^"]*md:t-pt-lg[^"]*"[^>]*>Five deep</h2>~', $html), $html);
        self::assertSame(1, preg_match('~<h2[^>]*class="[^"]*md:t-pt-xl[^"]*"[^>]*>Five deep again</h2>~', $html));
        self::assertStringContainsString('t-radius-none', $html, 'the button leaf at depth five');
        self::assertStringNotContainsString('thallo-block--too-deep', $html);
    }
}
