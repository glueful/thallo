<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;
use Thallo\Search\Console\StatusCommand;

/**
 * `search:status` (search block spec §3.8): the engine's readiness, then one row per kind — the
 * same table as Settings › Search — resolved from a real boot with search on.
 */
final class StatusCommandTest extends AppTestCase
{
    public function testPrintsTheEngineAndEachKind(): void
    {
        $site = new SearchOnApp(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true]],
        ));
        $site->publish('post', 'rose', 'Rose garden', 'Roses in the morning');
        $site->reconcile();

        $command = $site->app->getContainer()->get(StatusCommand::class);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Engine ready', $display);
        self::assertMatchesRegularExpression('/Pages & posts \(entries\)\s*\|\s*ready\s*\|\s*1\s*\|/', $display);
        self::assertStringContainsString('Products (products)', $display);
    }
}
