<?php

declare(strict_types=1);

// A child that ignores SIGTERM, with a grandchild that ignores it too (LivenessTest): the supervisor's
// own escalation must end both, within bounds, before it returns.

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Thallo\Core\Updater\ChildProcess;

[, $base] = $argv;
posix_setpgid(0, 0);
$started = time();
$script = 'trap "" TERM; sleep 60 & echo $! > ' . escapeshellarg($base . '/grandchild.pid') . '; sleep 60';
$code = ChildProcess::run(['sh', '-c', $script], $base, [], [], $base . '/log', static function (): void {
}, 2, null, posix_getpgid(0) ?: null, getmypid());
file_put_contents($base . '/result', $code . ' ' . (time() - $started));
exit(0);
