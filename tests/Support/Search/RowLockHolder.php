<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

/**
 * Holds a search state row's lock from another process, the way a concurrent worker would: it
 * locks the row, signals, sleeps, then commits. Started before the call under test, which then
 * waits on the lock.
 */
final class RowLockHolder
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    public static function hold(string $kind, float $seconds): self
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
            . '$s = $p->prepare("UPDATE search_index_state SET locked_at = locked_at WHERE kind = ?");'
            . '$s->execute([%s]); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(%d); $p->commit();',
            var_export($dsn, true),
            var_export(getenv('DB_PGSQL_USERNAME') ?: 'postgres', true),
            var_export(getenv('DB_PGSQL_PASSWORD') ?: 'postgres', true),
            var_export($kind, true),
            (int) ($seconds * 1_000_000),
        );
        $pipes = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $holder->process = proc_open([PHP_BINARY, '-r', $script], $pipes, $holder->pipes);
        $line = fgets($holder->pipes[1]);
        if (trim((string) $line) !== 'locked') {
            throw new \RuntimeException('The lock holder did not start: ' . stream_get_contents($holder->pipes[2]));
        }
        return $holder;
    }

    public function release(): void
    {
        if ($this->process !== null) {
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
