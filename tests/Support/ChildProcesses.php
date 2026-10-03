<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

/** Starts child PHP processes from tests/fixtures (they inherit the test environment). */
trait ChildProcesses
{
    /** @param list<string> $args */
    protected function startChild(string $fixture, array $args = []): ChildProcess
    {
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/fixtures/' . $fixture, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \PHPUnit\Framework\Assert::assertIsResource($proc);
        return new ChildProcess($proc, $pipes);
    }

    /**
     * Starts one child per argument list at once and returns each one's last stdout line.
     *
     * @param list<list<string>> $argsPerChild
     * @return list<string>
     */
    protected function runChildren(string $fixture, array $argsPerChild): array
    {
        $children = array_map(fn (array $args): ChildProcess => $this->startChild($fixture, $args), $argsPerChild);
        return array_map(static function (ChildProcess $child): string {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $child->finish()))));
            return $lines === [] ? '' : $lines[count($lines) - 1];
        }, $children);
    }
}
