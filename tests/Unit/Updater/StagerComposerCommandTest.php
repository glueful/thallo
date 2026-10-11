<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Stager;

/** Composer is started through PHP only when it is a PHP script or phar; a shell shim runs itself. */
final class StagerComposerCommandTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function binaries(): iterable
    {
        yield 'php shebang' => ['composer', "#!/usr/bin/env php\n<?php\n", true];
        yield 'php open tag' => ['composer', "<?php\n", true];
        yield 'phar by name' => ['composer.phar', "anything", true];
        yield 'shell shim' => ['composer', "#!/bin/sh\nexec php /opt/composer.phar \"\$@\"\n", false];
        yield 'bash shim' => ['composer', "#!/usr/bin/env bash\nexec /usr/bin/php8.4 composer.phar \"\$@\"\n", false];
    }

    /** @dataProvider binaries */
    public function testHowComposerIsStarted(string $name, string $head, bool $throughPhp): void
    {
        $dir = sys_get_temp_dir() . '/thallo-composer-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/' . $name, $head);
        $command = Stager::composerCommand('/usr/bin/php', $dir . '/' . $name);
        self::assertSame($throughPhp ? ['/usr/bin/php', $dir . '/' . $name] : [$dir . '/' . $name], $command);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
