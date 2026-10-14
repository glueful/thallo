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
            '  101     1   01:00:00 root     php-fpm: master process (/etc/php/fpm.conf)',      // started 6_400: listed
            '  102     1      30:00 www      php-fpm: pool www',                                // started 8_200: listed
            '  103     1      30:00 me       /opt/php/bin/php-cgi -b 127.0.0.1:9019',           // started 8_200: listed
            '  104     1      30:00 me       /opt/php/bin/php /srv/site/glueful import:run',     // site path: listed
            '  105     1      30:00 me       nginx: worker process',                          // runs no PHP: not listed
            '  106     1      05:00 me       /opt/php/bin/php-cgi -b 127.0.0.1:9019',       // started 9_700: not listed
            '  107     1      30:00 me       php bin/thallo-updater tick',                     // the runner: not listed
            // another site: not listed
            '  108     1      30:00 other    /usr/bin/php /home/other/app/artisan queue:work',
            // cwd is the site: listed
            '  109     1      30:00 me       /opt/php/bin/php -r require "bootstrap/app.php";',
            '  110     1      30:00 me       /opt/php/bin/php -r echo 1;',                  // cwd elsewhere: not listed
            // Whole seconds on both sides: only a start 2 s after the install is certainly guarded.
            '  111     1      16:40 www      php-fpm: pool www',                                // started 9_000: listed
            '  112     1      16:39 www      php-fpm: pool www',                                // started 9_001: listed
            '  113     1      16:38 www      php-fpm: pool www',                            // started 9_002: not listed
        ]);
        $host = self::host($ps);

        $found = RunnerReport::unguardedProcesses(new Paths($base), $host, 9_000);

        self::assertSame([101, 102, 103, 104, 109, 111, 112], array_column($found, 'pid'));
    }

    /**
     * A server with a second PHP-FPM that serves something else, such as CloudPanel's own panel: the
     * probe names the master that answers for this site, and only that master and its pool count.
     */
    public function testOnlyThePhpFpmThatServesThisSiteCounts(): void
    {
        $found = RunnerReport::unguardedProcesses(new Paths('/srv/site'), self::host(self::twoFpms()), 9_000, 201);

        self::assertSame([201, 202], array_column($found, 'pid'));
    }

    /** A master the probe names that `ps` does not show as PHP-FPM (another pid namespace): every PHP-FPM counts. */
    public function testEveryPhpFpmCountsWhenTheNamedMasterIsNotSeen(): void
    {
        $found = RunnerReport::unguardedProcesses(new Paths('/srv/site'), self::host(self::twoFpms()), 9_000, 1);

        self::assertSame([201, 202, 506, 507], array_column($found, 'pid'));
    }

    private static function twoFpms(): string
    {
        return implode("\n", [
            '  201     1   01:00:00 root      php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)',
            '  202   201      30:00 site      php-fpm: pool site',
            '  506     1   01:00:00 root      php-fpm: master process (/home/clp/services/php-fpm/fpm/php-fpm.conf)',
            '  507   506      30:00 clp       php-fpm: pool clp',
        ]);
    }

    private static function host(string $ps): Host
    {
        return new class ($ps) implements Host {
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
    }
}
