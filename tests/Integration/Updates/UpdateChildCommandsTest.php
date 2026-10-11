<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Updates;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Updates\Console\UpdateBackupCommand;
use Thallo\Core\Updates\Console\UpdateVerifyCommand;

final class UpdateChildCommandsTest extends AppTestCase
{
    public function testVerifyReportsTheInstalledVersion(): void
    {
        $tester = new CommandTester($this->container()->get(UpdateVerifyCommand::class));
        self::assertSame(0, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsString($data['version']);
        self::assertSame(PHP_VERSION, $data['php']);
    }

    public function testABackupCountsOnlyWhenPgRestoreCanReadIt(): void
    {
        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            self::markTestSkipped('pg_dump not installed');
        }
        $dir = sys_get_temp_dir() . '/thallo-backup-' . bin2hex(random_bytes(4));
        $tester = new CommandTester($this->container()->get(UpdateBackupCommand::class));
        self::assertSame(0, $tester->execute(['--to' => $dir, '--json' => true]), $tester->getDisplay());
        $manifest = json_decode($tester->getDisplay(), true);
        self::assertGreaterThan(0, $manifest['bytes']);
        self::assertSame(hash_file('sha256', $dir . '/database.dump'), $manifest['sha256']);
        self::assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($dir . '/database.dump')), -4));
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testAnUnreadableDumpFails(): void
    {
        // A pg_dump that "succeeds" but writes garbage: PATH points at a stub.
        $bin = sys_get_temp_dir() . '/thallo-fake-pg-' . bin2hex(random_bytes(4));
        mkdir($bin);
        $stub = "#!/bin/sh\n"
            . "for a in \"\$@\"; do case \$a in --file=*) echo garbage > \"\${a#--file=}\";; esac; done\n"
            . "exit 0\n";
        file_put_contents($bin . '/pg_dump', $stub);
        chmod($bin . '/pg_dump', 0755);
        $old = getenv('PATH');
        putenv('PATH=' . $bin . ':' . $old);
        try {
            $tester = new CommandTester($this->container()->get(UpdateBackupCommand::class));
            self::assertSame(1, $tester->execute(['--to' => $bin . '/out', '--json' => true]));
            self::assertStringContainsString('pg_restore', $tester->getDisplay());
        } finally {
            putenv('PATH=' . $old);
            exec('rm -rf ' . escapeshellarg($bin));
        }
    }
}
