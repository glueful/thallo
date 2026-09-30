<?php

declare(strict_types=1);

namespace Glueful\Extensions\Absent\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command of an extension that is installed (the class loads) but not enabled (no provider of
 * `Glueful\Extensions\Absent` is registered). It always fails, so running it shows.
 */
final class AbsentExtensionCommand extends Command
{
    public function __construct()
    {
        parent::__construct('absent:always-fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::FAILURE;
    }
}
