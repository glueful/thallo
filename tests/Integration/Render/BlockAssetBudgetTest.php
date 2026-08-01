<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

/** Per-block-asset gzip budgets (modern-blocks spec §5): 3,072 bytes each; raising
 *  one is its own reviewed decision — never a silent bump. */
final class BlockAssetBudgetTest extends AppTestCase
{
    public function testEveryBlockAssetIsWithinItsBudget(): void
    {
        $dir = $this->appContext()->getBasePath() . '/packages/thallo-render/runtime';
        foreach (RenderContextExtension::BLOCK_SCRIPT_ASSETS as $name) {
            $path = $dir . '/block-' . $name . '.js';
            self::assertFileExists($path, "catalog entry '{$name}' has no asset file");
            $gz = strlen((string) gzencode((string) file_get_contents($path), 9));
            self::assertLessThanOrEqual(
                3072,
                $gz,
                "block-{$name}.js is {$gz} bytes gzip against a 3,072-byte budget",
            );
        }
    }
}
