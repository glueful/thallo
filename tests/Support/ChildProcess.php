<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * One child PHP process with a line protocol: it prints markers on stdout (waitFor) and blocks on
 * a line of stdin to resume (signal). Used for races and paused runners.
 */
final class ChildProcess
{
    private string $seen = '';

    /** @param resource $proc @param array<int, resource> $pipes */
    public function __construct(private $proc, private array $pipes)
    {
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function waitFor(string $marker, int $timeoutSeconds = 30): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $this->seen .= (string) stream_get_contents($this->pipes[1]);
            if (preg_match('/^' . preg_quote($marker, '/') . '$/m', $this->seen) === 1) {
                return;
            }
            if (!$this->isFinished()) {
                usleep(20_000);
                continue;
            }
            $this->seen .= (string) stream_get_contents($this->pipes[1]);
            if (preg_match('/^' . preg_quote($marker, '/') . '$/m', $this->seen) === 1) {
                return;
            }
            break;
        }
        Assert::fail("child never printed {$marker}; stdout: {$this->seen}; stderr: "
            . (string) stream_get_contents($this->pipes[2]));
    }

    public function signal(string $line): void
    {
        fwrite($this->pipes[0], $line . "\n");
        fflush($this->pipes[0]);
    }

    public function isFinished(): bool
    {
        return !proc_get_status($this->proc)['running'];
    }

    /** Waits for exit; returns everything the child printed (stdout, then stderr). */
    public function finish(int $timeoutSeconds = 60): string
    {
        @fclose($this->pipes[0]);
        $deadline = microtime(true) + $timeoutSeconds;
        $err = '';
        while (!$this->isFinished() && microtime(true) < $deadline) {
            $this->seen .= (string) stream_get_contents($this->pipes[1]);
            $err .= (string) stream_get_contents($this->pipes[2]);
            usleep(20_000);
        }
        if (!$this->isFinished()) {
            proc_terminate($this->proc);
            Assert::fail("child did not finish within {$timeoutSeconds}s; stdout: {$this->seen}");
        }
        $this->seen .= (string) stream_get_contents($this->pipes[1]);
        $err .= (string) stream_get_contents($this->pipes[2]);
        proc_close($this->proc);
        return $this->seen . $err;
    }
}
