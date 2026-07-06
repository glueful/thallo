<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class BinTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = dirname(__DIR__, 3) . '/lemma';
    }

    public function testBinExistsAndIsExecutable(): void
    {
        self::assertFileExists($this->bin);
        self::assertTrue(is_executable($this->bin), 'lemma bin must be chmod +x');
    }

    public function testForwardsKnownCommands(): void
    {
        $src = (string) file_get_contents($this->bin);
        // Branded setup verbs map to the lemma: namespace.
        self::assertStringContainsString('lemma:', $src);
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

        $dir = sys_get_temp_dir() . '/lemma bin ' . uniqid('', true);
        mkdir($dir, 0755, true);
        copy($this->bin, $dir . '/lemma');
        chmod($dir . '/lemma', 0755);
        file_put_contents(
            $dir . '/glueful',
            "<?php\nforeach (array_slice(\$argv, 1) as \$a) { echo \$a, \"\\n\"; }\n",
        );

        $run = static function (string $argline) use ($dir): string {
            return (string) shell_exec('"' . $dir . '/lemma" ' . $argline . ' 2>&1');
        };

        // `setup` runs the two layers as two processes: provision then create-admin.
        self::assertSame("lemma:provision\nlemma:create-admin\n", $run('setup'));
        // Branded shortcuts: a bare setup verb maps to its lemma: command.
        self::assertSame("lemma:doctor\n", $run('doctor'));
        self::assertSame("lemma:provision\nfoo\n", $run('provision foo'));
        // Everything else passes straight through to the full framework console.
        self::assertSame("lemma:doctor\n", $run('lemma:doctor'));
        self::assertSame("cache:clear\n", $run('cache:clear'));
        self::assertSame("migrate:run\n--limit=5\n", $run('migrate:run --limit=5'));

        array_map('unlink', [$dir . '/lemma', $dir . '/glueful']);
        rmdir($dir);
    }

    /**
     * Running the launcher with PHP by mistake (`php lemma`) must NOT dump the script source —
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

        self::assertStringContainsString('shell launcher', $out, 'should print the use-./lemma hint');
        self::assertStringNotContainsString('case "$cmd"', $out, 'must not dump the launcher logic');
        self::assertStringNotContainsString('Parse error', $out, 'no PHP parse error should surface');
    }

    /**
     * The launcher must resolve its OWN real location through a symlink, so it can be linked
     * onto $PATH (e.g. /usr/local/bin/lemma) and still find its sibling `glueful` — not look
     * for a `glueful` next to the symlink.
     */
    public function testResolvesSiblingGluefulThroughASymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX sh launcher');
        }

        // A "project" dir holds the real launcher + a fake glueful that echoes its argv.
        $project = sys_get_temp_dir() . '/lemma proj ' . uniqid('', true);
        mkdir($project, 0755, true);
        copy($this->bin, $project . '/lemma');
        chmod($project . '/lemma', 0755);
        file_put_contents(
            $project . '/glueful',
            "<?php\nforeach (array_slice(\$argv, 1) as \$a) { echo \$a, \"\\n\"; }\n",
        );

        // A separate "bin" dir (with NO glueful) holds only a symlink to the launcher.
        $binDir = sys_get_temp_dir() . '/lemma path ' . uniqid('', true);
        mkdir($binDir, 0755, true);
        symlink($project . '/lemma', $binDir . '/lemma');

        // Invoking via the symlink must still run the PROJECT's glueful.
        $out = (string) shell_exec('"' . $binDir . '/lemma" doctor 2>&1');
        self::assertSame("lemma:doctor\n", $out);

        unlink($binDir . '/lemma');
        rmdir($binDir);
        array_map('unlink', [$project . '/lemma', $project . '/glueful']);
        rmdir($project);
    }
}
