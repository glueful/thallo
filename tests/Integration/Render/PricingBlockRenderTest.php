<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Console\SeedBlockTypesCommand;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

final class PricingBlockRenderTest extends AppTestCase
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

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testPricingPlanRendersVariantFeaturesBadgeAndCta(): void
    {
        $out = $this->render([[
            'id' => 'p1', 'type' => 'pricing_plan',
            'data' => [
                'title' => 'Pro', 'price' => '$29', 'badge' => 'Popular',
                'variant' => 'soft', 'highlight' => true,
                'features' => "Unlimited projects\nPriority support",
                'button_label' => 'Choose Pro', 'button_url' => 'https://example.com/buy',
            ],
        ]]);

        self::assertStringContainsString('thallo-block-pricing_plan--variant-soft', $out);
        self::assertStringContainsString('thallo-block-pricing_plan--highlight', $out);
        self::assertStringContainsString('Popular', $out);
        self::assertStringContainsString('Unlimited projects', $out);
        self::assertStringContainsString('Priority support', $out);
        self::assertStringContainsString('href="https://example.com/buy"', $out);
        // Rounded corners come from CSS; assert the block class is present so CSS can bind.
        self::assertStringContainsString('thallo-block-pricing_plan', $out);
    }

    public function testPricingPlanDropsUnsafeCtaUrlAndUnknownIcon(): void
    {
        $out = $this->render([[
            'id' => 'p2', 'type' => 'pricing_plan',
            'data' => [
                'title' => 'Free', 'price' => '$0',
                'features' => 'One project',
                'feature_icon' => 'definitely-not-a-real-icon',
                'button_label' => 'Start', 'button_url' => 'javascript:alert(1)',
            ],
        ]]);

        // Unsafe url dropped → CTA is a <span>, no href.
        self::assertStringNotContainsString('javascript:alert(1)', $out);
        self::assertStringNotContainsString('href=', $out);
        // Unknown icon name must NOT be echoed as raw text.
        self::assertStringNotContainsString('definitely-not-a-real-icon', $out);
    }

    public function testPricingPlansSetsCountAndOrientationAndScaleCascade(): void
    {
        $out = $this->render([[
            'id' => 'ps1', 'type' => 'pricing_plans',
            'data' => [
                'orientation' => 'horizontal', 'scale' => true,
                'plans' => [
                    ['id' => 'a', 'type' => 'pricing_plan', 'data' => ['title' => 'Basic', 'price' => '$0']],
                    ['id' => 'b', 'type' => 'pricing_plan',
                        'data' => ['title' => 'Pro', 'price' => '$29', 'highlight' => true]],
                    ['id' => 'c', 'type' => 'pricing_plan', 'data' => ['title' => 'Team', 'price' => '$99']],
                ],
            ],
        ]]);

        self::assertStringContainsString('--count: 3', $out);
        self::assertStringContainsString('thallo-block-pricing_plans--orientation-horizontal', $out);
        self::assertStringContainsString('thallo-block-pricing_plans--scale', $out);
        // The three child plans rendered.
        self::assertStringContainsString('Basic', $out);
        self::assertStringContainsString('Pro', $out);
        self::assertStringContainsString('Team', $out);
    }

    /** @return array<string,mixed> a table with $tierCount tiers, a section + one feature row */
    private function tableBlock(int $tierCount = 3): array
    {
        $tiers = [];
        foreach (['Basic', 'Pro', 'Team', 'Extra'] as $i => $name) {
            if ($i >= $tierCount) {
                break;
            }
            $tiers[] = ['id' => 't' . $i, 'type' => 'pricing_tier',
                'data' => ['title' => $name, 'price' => '$' . ($i * 10), 'highlight' => $name === 'Pro']];
        }
        return [
            'id' => 'tbl', 'type' => 'pricing_table',
            'data' => [
                'highlight' => true,
                'tiers' => $tiers,
                'features' => [
                    ['id' => 's1', 'type' => 'pricing_feature',
                        'data' => ['is_section' => true, 'title' => 'Core',
                            'value_1' => 'stale', 'value_2' => 'stale', 'value_3' => 'stale']],
                    ['id' => 'f1', 'type' => 'pricing_feature',
                        'data' => ['title' => 'Projects', 'value_1' => '3', 'value_2' => 'yes', 'value_3' => '-']],
                ],
            ],
        ];
    }

    public function testPricingTableRendersTiersCellsAndTokens(): void
    {
        $out = $this->render([$this->tableBlock()]);

        self::assertStringContainsString('Projects', $out);
        self::assertStringContainsString('3', $out);                                  // literal text cell
        self::assertStringContainsString('thallo-block-pricing_table__check', $out);  // 'yes' → check
        self::assertStringContainsString('thallo-block-pricing_table__dash', $out);   // '-' → dash
        self::assertStringContainsString('thallo-block-pricing_table--highlight', $out);
    }

    public function testPricingTableSectionRowIsLabelOnlyIgnoringStaleValues(): void
    {
        // Regression: a former feature row toggled into a section (is_section: true)
        // still carries stale value_1/value_2 data. The section row must render as a
        // label only and ignore those values completely.
        $out = $this->render([$this->tableBlock()]);

        self::assertStringContainsString('thallo-block-pricing_table__section', $out);
        self::assertStringContainsString('Core', $out);
        // 1) The stale values must not appear anywhere.
        self::assertStringNotContainsString('stale', $out);
        // 2) Structural guard: only the one real feature row emits value cells
        //    (1 row × 3 tiers = 3 desktop __cell tds). A leaking section row would
        //    push the count higher — so this fails independently of the sentinel.
        self::assertSame(3, substr_count($out, 'thallo-block-pricing_table__cell'));
    }

    public function testPricingTableCapsAtFourTiersAndSurvivesOneAndZero(): void
    {
        $five = $this->tableBlock(4);
        $five['data']['tiers'][] = ['id' => 't4', 'type' => 'pricing_tier', 'data' => ['title' => 'Fifth']];
        $out = $this->render([$five]);
        self::assertStringNotContainsString('Fifth', $out); // 5th column dropped

        $one = $this->render([$this->tableBlock(1)]);
        self::assertStringContainsString('Basic', $one);

        $zero = $this->render([[
            'id' => 'z', 'type' => 'pricing_table',
            'data' => ['tiers' => [], 'features' => []],
        ]]);
        self::assertStringContainsString('thallo-block-pricing_table', $zero);
    }

    public function testPricingTableFitsDepthBudgetTopLevelAndOneWrapperDeep(): void
    {
        // The harness truncates block_types per test, so FieldValidator's DB-backed
        // registry starts empty — seed the definitions first (as SeedBlockTypesTest does).
        (new CommandTester($this->container()->get(SeedBlockTypesCommand::class)))->execute([]);

        // Entry schema: a single `body` blocks field (depth 0 field; items depth 1).
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'blocks'],
        ]);

        $table = $this->tableBlock();               // pricing_table with tiers + features
        // The validator needs a DB connection to read the seeded block-type registry.
        $validator = new FieldValidator($this->connection());

        // Top-level: body → table(1) → tiers/features(2) → tier/feature(3). Valid.
        $topLevel = $validator->validate($schema, ['body' => [$table]], true);
        self::assertArrayHasKey('body', $topLevel);

        // One wrapper deep: body → container(1) → content → table(2) → ... deepest
        // child at depth 3. Still valid (no blocks field below depth 3).
        $wrapped = ['id' => 'wrap', 'type' => 'container', 'data' => ['content' => [$table]]];
        $oneDeep = $validator->validate($schema, ['body' => [$wrapped]], true);
        self::assertArrayHasKey('body', $oneDeep);
    }
}
