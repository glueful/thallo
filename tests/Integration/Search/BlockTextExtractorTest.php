<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Search\BlockTextExtractor;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The words a block page shows, read through the block type registry: every string and text field
 * of each block (rich text as its words), nested blocks included, links and paths left out.
 */
final class BlockTextExtractorTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(\Thallo\Core\Content\Blocks\StarterBlockTypeSeeder::class)->seedMissing();
    }

    public function testTheWordsOfEveryBlockAreReadInOrderAndLinksAreLeftOut(): void
    {
        $extractor = $this->container()->get(BlockTextExtractor::class);

        $text = $extractor->textOf([
            ['type' => 'heading', 'data' => ['text' => 'Pricing plans', 'level' => 2]],
            ['type' => 'rich_text', 'data' => ['body' => '<p>Every plan <strong>includes</strong> support.</p>']],
            ['type' => 'button', 'data' => ['label' => 'Compare plans', 'url' => 'https://example.com/compare']],
            ['type' => 'unknown_type', 'data' => ['text' => 'never read']],
            'not a block',
        ]);

        self::assertSame(['Pricing plans', 'Every plan includes support.', 'Compare plans'], $text);
    }

    public function testNestedBlocksAreRead(): void
    {
        $extractor = $this->container()->get(BlockTextExtractor::class);
        $schemas = $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class)->schemasBySlug();
        $container = null;
        foreach ($schemas as $slug => $schema) {
            foreach ($schema->fields() as $field) {
                if ($field->type === 'blocks') {
                    $container = [$slug, $field->name];
                    break 2;
                }
            }
        }
        self::assertNotNull($container, 'a starter block type holds child blocks');

        $text = $extractor->textOf([
            ['type' => $container[0], 'data' => [$container[1] => [
                ['type' => 'heading', 'data' => ['text' => 'Inside the container']],
            ]]],
        ]);

        self::assertContains('Inside the container', $text);
    }
}
