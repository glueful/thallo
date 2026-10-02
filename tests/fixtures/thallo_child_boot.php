<?php

declare(strict_types=1);

// Shared boot for Thallo test children: mirror the process env into $_ENV (the framework's env()
// reads $_ENV only — see checkout_attempt_race_child.php), boot the testing app, return its container.

require __DIR__ . '/../../vendor/autoload.php';

foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

$root = dirname(__DIR__, 2);
$app = Glueful\Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();

return $app->getContext()->getContainer();
