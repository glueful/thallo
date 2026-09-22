<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Jobs;

use Thallo\Core\Jobs\RunConsoleCommandJob;
use Thallo\Core\Tests\Support\AppTestCase;

/** The scheduler runs maintenance commands through this job. */
final class RunConsoleCommandJobTest extends AppTestCase
{
    public function testItRunsTheNamedCommand(): void
    {
        $this->connection()->table('analytics_facts')->insert([
            'occurred_at' => gmdate('Y-m-d H:i:s', time() - 400 * 86400), 'event' => 'auth.login',
            'category' => 'auth', 'subject_type' => 'user', 'subject_id' => 'u-job', 'actor_type' => 'user',
            'actor_id' => 'u-job', 'metadata' => null,
        ]);

        $prune = \Thallo\Analytics\Console\PruneAnalyticsCommand::class;
        (new RunConsoleCommandJob(['command' => $prune], $this->appContext()))->handle();

        $left = $this->connection()->table('analytics_facts')->where('subject_id', '=', 'u-job')->count();
        self::assertSame(0, (int) $left);
    }

    public function testACommandThatIsNotInstalledIsSkipped(): void
    {
        (new RunConsoleCommandJob(['command' => 'Nowhere\\MissingCommand'], $this->appContext()))->handle();

        $this->addToAssertionCount(1);
    }

    public function testEveryScheduledCommandIsACommand(): void
    {
        $jobs = (require dirname(__DIR__, 3) . '/config/schedule.php')['jobs'];
        $named = 0;
        foreach ($jobs as $job) {
            if (($job['handler_class'] ?? null) !== RunConsoleCommandJob::class) {
                continue;
            }
            $named++;
            $class = (string) $job['parameters']['command'];
            self::assertTrue(
                is_subclass_of($class, \Symfony\Component\Console\Command\Command::class),
                "{$job['name']} schedules {$class}, which is not a console command",
            );
        }
        self::assertGreaterThan(0, $named);
    }
}
