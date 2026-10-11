<?php

declare(strict_types=1);

// A recovery runner stand-in (LivenessTest): it claims a recovery with its OWN process group, leaves
// a grandchild holding the run lock as descriptor 3, then dies.

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Thallo\Core\Updater\ChildProcess;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

[, $base] = $argv;
posix_setpgid(0, 0);
$paths = new Paths($base);
$lock = FileLock::exclusive($paths->runLock());
$ops = new OperationStore($paths, static fn (): int => time());
$op = $ops->current();
$ops->claimRecovery($op['id'], getmypid(), posix_getpgid(0) ?: null);
$grandchild = 'sleep 30 & echo $! > ' . escapeshellarg($base . '/grandchild.pid');
ChildProcess::run(['sh', '-c', $grandchild], $base, [], [$lock], $base . '/log', static function (): void {
}, 30);
posix_kill(getmypid(), SIGKILL);
