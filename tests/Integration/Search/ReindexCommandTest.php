<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Console\ReindexCommand;

/**
 * `search:reindex` joins the coordinated lifecycle (search block spec §3.5.10): by default it only
 * records a rebuild request; `--wait` runs the rebuild in the foreground under the normal claim and
 * fences — waiting for a build already under way rather than starting a second — and the removed
 * `--type` / `--locale` filters fail without touching the index.
 */
final class ReindexCommandTest extends AppTestCase
{
    private LifecycleKit $kit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new LifecycleKit($this->appContext(), $this->connection());
        $this->kit->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B']];
    }

    public function testRecordsDemandOnly(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Rebuild requested for entries.', $tester->getDisplay());
        self::assertNotNull($this->kit->demand()->pending('entries'), 'a rebuild is due');
        self::assertSame(0, $this->connection()->table('search_documents')->where('kind', '=', 'entries')->count());
        self::assertSame(1, $this->connection()->table('search_index_demand')->count());
    }

    public function testWaitRunsUnderTheFences(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--wait' => true, '--kind' => 'entries']));
        self::assertStringContainsString('entries: rebuilt', $tester->getDisplay());
        self::assertSame(2, $this->connection()->table('search_documents')->where('kind', '=', 'entries')->count());
        self::assertNull($this->kit->demand()->pending('entries'));
    }

    public function testReindexWaitDoesNotStartASecondBuilder(): void
    {
        $held = $this->kit->state->claimBuild('entries', 120, 0, 1);
        $sleeps = 0;
        $tester = $this->tester(function () use (&$sleeps, $held): void {
            $sleeps++;
            if ($sleeps === 3) {
                // The other builder finishes and releases its claim.
                $this->kit->state->releaseBuild(\Thallo\Search\Lifecycle\Fence::builder(
                    'entries',
                    $held->token,
                    $held->generation,
                ));
            }
        });
        self::assertSame(0, $tester->execute(['--wait' => true, '--kind' => 'entries']));
        self::assertSame(3, $sleeps, 'it waited for the claim instead of building alongside it');
        self::assertStringContainsString('entries: rebuilt', $tester->getDisplay());
    }

    public function testRemovedFiltersFailWithoutTouchingTheIndex(): void
    {
        foreach ([['--type' => 'post'], ['--locale' => 'en']] as $options) {
            $tester = $this->tester();
            self::assertSame(1, $tester->execute($options));
            self::assertStringContainsString(
                '`--type`/`--locale` are no longer supported: search rebuilds whole kinds. '
                . 'Use `search:reindex --kind=entries`.',
                $tester->getDisplay(),
            );
        }
        self::assertSame(0, $this->connection()->table('search_index_demand')->count());
        self::assertSame(0, $this->connection()->table('search_documents')->where('kind', '=', 'entries')->count());
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['--kind' => 'reviews']));
        self::assertStringContainsString("No search kind 'reviews' is available.", $tester->getDisplay());
    }

    private function tester(?\Closure $sleep = null): CommandTester
    {
        $command = new ReindexCommand(
            $this->kit->requests(),
            $this->kit->reconciler(),
            $this->kit->availability(),
            $this->kit->state,
            $sleep ?? static function (): void {
            },
        );
        return new CommandTester($command);
    }
}
