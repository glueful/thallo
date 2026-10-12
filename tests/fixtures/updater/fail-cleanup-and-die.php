<?php

declare(strict_types=1);

// A runner whose descendant cleanup failed (RunnerTest): it claims the operation with its own process
// group, closes the site, leaves a writer that has closed every inherited descriptor, records
// recovery_required as a failed stopDescendants would, and exits. The writer stays in the group but no
// longer holds the run lock.

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\ChildProcess;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;

[, $base] = $argv;
posix_setpgid(0, 0);
$paths = new Paths($base);
$lock = FileLock::exclusive($paths->runLock());
$ops = new OperationStore($paths, static fn (): int => time());
$op = $ops->claim($ops->current()['id'], getmypid(), posix_getpgid(0) ?: null);
(new Barrier($paths))->close();
$writer = 'exec 3>&- 4>&-; echo $$ > ' . escapeshellarg($base . '/writer.pid')
    . '; while :; do date >> ' . escapeshellarg($base . '/writes') . '; sleep 0.1; done';
// A separate sh, so its $$ is its own pid; its stdio goes nowhere, so it holds no caller's pipe.
$spawn = 'sh -c ' . escapeshellarg($writer) . ' < /dev/null > /dev/null 2>&1 &';
ChildProcess::run(['sh', '-c', $spawn], $base, [], [$lock], $base . '/log', static function (): void {
}, 30);
$ops->finish($op['id'], $op['owner'], Status::RECOVERY_REQUIRED, 'cleanup failed');
exit(0);
