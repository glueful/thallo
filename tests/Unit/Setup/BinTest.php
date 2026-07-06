<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class BinTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = dirname(__DIR__, 3) . '/thallo';
    }

    public function testBinExistsAndIsExecutable(): void
    {
        self::assertFileExists($this->bin);
        self::assertTrue(is_executable($this->bin), 'thallo bin must be chmod +x');
    }

    public function testForwardsKnownCommands(): void
    {
        $src = (string) file_get_contents($this->bin);
        // Branded setup verbs map to the thallo: namespace.
        self::assertStringContainsString('thallo:', $src);
        // It has the special two-process setup verb.
        self::assertStringContainsString('setup', $src);
        // It invokes the app's own glueful console, not a global binary.
        self::assertStringContainsString('glueful', $src);
    }

    /**
     * Actually RUN the launcher against a fake `glueful` that echoes its argv, proving the
     * forwarding (and that quoting survives a path with spaces). Skips on Windows.
     */
    public function testExecutionForwardsArgsToGlueful(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX sh launcher');
        }

        $dir = sys_get_temp_dir() . '/thallo bin ' . uniqid('', true);
        mkdir($dir, 0755, true);
        copy($this->bin, $dir . '/thallo');
        chmod($dir . '/thallo', 0755);
        file_put_contents(
            $dir . '/glueful',
            "<?php\nforeach (array_slice(\$argv, 1) as \$a) { echo \$a, \"\\n\"; }\n",
        );

        $run = static function (string $argline) use ($dir): string {
            return (string) shell_exec('"' . $dir . '/thallo" ' . $argline . ' 2>&1');
        };

        // `setup` runs the two layers as two processes: provision then create-admin.
        self::assertSame("thallo:provision\nthallo:create-admin\n", $run('setup'));
        // Branded shortcuts: a bare setup verb maps to its thallo: command.
        self::assertSame("thallo:doctor\n", $run('doctor'));
        self::assertSame("thallo:provision\nfoo\n", $run('provision foo'));
        // Everything else passes straight through to the full framework console.
        self::assertSame("thallo:doctor\n", $run('thallo:doctor'));
        self::assertSame("cache:clear\n", $run('cache:clear'));
        self::assertSame("migrate:run\n--limit=5\n", $run('migrate:run --limit=5'));

        array_map('unlink', [$dir . '/thallo', $dir . '/glueful']);
        rmdir($dir);
    }

    /**
     * Running the launcher with PHP by mistake (`php thallo`) must NOT dump the script source —
     * the sh/PHP polyglot guard prints a hint and exits non-zero instead.
     */
    public function testRunningWithPhpPrintsHintNotTheScript(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX sh launcher');
        }

        $out = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' doctor 2>&1',
        );

        self::assertStringContainsString('shell launcher', $out, 'should print the use-./thallo hint');
        self::assertStringNotContainsString('case "$cmd"', $out, 'must not dump the launcher logic');
        self::assertStringNotContainsString('Parse error', $out, 'no PHP parse error should surface');
    }

    /**
     * The launcher must resolve its OWN real location through a symlink, so it can be linked
     * onto $PATH (e.g. /usr/local/bin/thallo) and still find its sibling `glueful` — not look
     * for a `glueful` next to the symlink.
     */
    public function testResolvesSiblingGluefulThroughASymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX sh launcher');
        }

        // A "project" dir holds the real launcher + a fake glueful that echoes its argv.
        $project = sys_get_temp_dir() . '/thallo proj ' . uniqid('', true);
        mkdir($project, 0755, true);
        copy($this->bin, $project . '/thallo');
        chmod($project . '/thallo', 0755);
        file_put_contents(
            $project . '/glueful',
            "<?php\nforeach (array_slice(\$argv, 1) as \$a) { echo \$a, \"\\n\"; }\n",
        );

        // A separate "bin" dir (with NO glueful) holds only a symlink to the launcher.
        $binDir = sys_get_temp_dir() . '/thallo path ' . uniqid('', true);
        mkdir($binDir, 0755, true);
        symlink($project . '/thallo', $binDir . '/thallo');

        // Invoking via the symlink must still run the PROJECT's glueful.
        $out = (string) shell_exec('"' . $binDir . '/thallo" doctor 2>&1');
        self::assertSame("thallo:doctor\n", $out);

        unlink($binDir . '/thallo');
        rmdir($binDir);
        array_map('unlink', [$project . '/thallo', $project . '/glueful']);
        rmdir($project);
    }
}
