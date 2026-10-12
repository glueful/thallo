<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Thallo\Core\Updater\ChildProcess;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Paths;

[$script, $base, $grandchild] = $argv; // $grandchild: shell for the background process
posix_setpgid(0, 0);
$paths = new Paths($base);
$lock = FileLock::exclusive($paths->runLock());
file_put_contents($base . '/pgid', (string) posix_getpgid(0));
$script = $grandchild . ' & echo $! > ' . escapeshellarg($base . '/grandchild.pid');
ChildProcess::run(['sh', '-c', $script], $base, [], [$lock], $base . '/log', static function (): void {
}, 30);
posix_kill(getmypid(), SIGKILL);
