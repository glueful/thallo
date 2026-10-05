<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

/**
 * A second database connection, in its own process, that runs statements in one transaction the way
 * a concurrent request would: it runs `$hold` (taking row locks), signals, sleeps, then runs each
 * `$probe` statement (reporting whether it succeeded), and commits. Started before the call under
 * test, which then waits on its locks.
 */
final class SqlLockHolder
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param list<array{0: string, 1: list<string|int>}> $hold statements run before signalling
     * @param list<array{0: string, 1: list<string|int>}> $probe statements tried after the sleep,
     *        each reported as "ok" or "failed:<SQLSTATE>" on its own line
     */
    public static function hold(array $hold, float $seconds, array $probe = []): self
    {
        $holder = new self();
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
            getenv('DB_PGSQL_PORT') ?: '5432',
            getenv('DB_PGSQL_DATABASE') ?: 'app_test',
        );
        $script = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            . '$p->beginTransaction();'
            . 'foreach (%s as [$sql, $args]) { $p->prepare($sql)->execute($args); }'
            . 'fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(%d);'
            . 'foreach (%s as [$sql, $args]) { try { $p->exec("SAVEPOINT probe");'
            . ' $p->prepare($sql)->execute($args); $p->exec("RELEASE SAVEPOINT probe"); fwrite(STDOUT, "ok\n"); }'
            . ' catch (PDOException $e) { $p->exec("ROLLBACK TO SAVEPOINT probe");'
            . ' fwrite(STDOUT, "failed:" . $e->getCode() . "\n"); } }'
            . '$p->commit();',
            var_export($dsn, true),
            var_export(getenv('DB_PGSQL_USERNAME') ?: 'postgres', true),
            var_export(getenv('DB_PGSQL_PASSWORD') ?: 'postgres', true),
            var_export($hold, true),
            (int) ($seconds * 1_000_000),
            var_export($probe, true),
        );
        $holder->process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $holder->pipes = $pipes;
        $line = fgets($holder->pipes[1]);
        if (trim((string) $line) !== 'locked') {
            throw new \RuntimeException('The lock holder did not start: ' . stream_get_contents($holder->pipes[2]));
        }
        return $holder;
    }

    /**
     * Waits for the holder to commit and returns its probe results, in order.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        if ($this->process === null) {
            return [];
        }
        $out = (string) stream_get_contents($this->pipes[1]);
        $err = (string) stream_get_contents($this->pipes[2]);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $status = proc_close($this->process);
        $this->process = null;
        if ($status !== 0) {
            throw new \RuntimeException("The lock holder failed ({$status}): {$err}");
        }
        return array_values(array_filter(explode("\n", $out), static fn (string $l): bool => $l !== ''));
    }

    public function __destruct()
    {
        if ($this->process !== null) {
            $this->finish();
        }
    }
}
