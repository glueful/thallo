<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Host;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\RunnerReport;

/** Which processes started before the guard count as able to run this site's code (plan Task 13). */
final class RunnerReportTest extends TestCase
{
    public function testWebServersAndThisSitesPhpProcessesStartedBeforeTheGuardAreListed(): void
    {
        $base = '/srv/site';
        // now = 10_000; the guard was installed at 9_000. etime is elapsed time.
        $ps = implode("\n", [
            '  101   01:00:00 root     php-fpm: master process (/etc/php/fpm.conf)',      // started 6_400: listed
            '  102      30:00 www      php-fpm: pool www',                                 // started 8_200: listed
            '  103      30:00 me       /opt/php/bin/php-cgi -b 127.0.0.1:9019',            // started 8_200: listed
            '  104      30:00 me       /opt/php/bin/php /srv/site/glueful import:run',     // site path: listed
            '  105      30:00 me       nginx: worker process',                             // runs no PHP: not listed
            '  106      05:00 me       /opt/php/bin/php-cgi -b 127.0.0.1:9019',            // started 9_700: not listed
            '  107      30:00 me       php bin/thallo-updater tick',                       // the runner: not listed
            '  108      30:00 other    /usr/bin/php /home/other/app/artisan queue:work',   // another site: not listed
            '  109      30:00 me       /opt/php/bin/php -r require "bootstrap/app.php";',  // cwd is the site: listed
            '  110      30:00 me       /opt/php/bin/php -r echo 1;',                       // cwd elsewhere: not listed
        ]);
        $host = new class ($ps) implements Host {
            public function __construct(private readonly string $ps)
            {
            }

            public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int
            {
                return 0;
            }

            public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array
            {
                return [0, ''];
            }

            public function capture(array $argv, string $cwd): array
            {
                if ($argv[0] === 'lsof') { // a process's working directory where /proc does not exist
                    return [0, $argv[3] === '109' ? "p109\nfcwd\nn/srv/site\n" : "p{$argv[3]}\nfcwd\nn/tmp\n"];
                }
                return $argv[0] === 'ps' ? [0, $this->ps] : [0, ''];
            }

            public function http(string $url, array $headers, string $body): array
            {
                return [0, ''];
            }

            public function now(): int
            {
                return 10_000;
            }

            public function sleep(int $seconds): void
            {
            }
        };

        $found = RunnerReport::unguardedProcesses(new Paths($base), $host, 9_000);

        self::assertSame([101, 102, 103, 104, 109], array_column($found, 'pid'));
    }
}
