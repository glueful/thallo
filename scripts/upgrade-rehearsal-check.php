<?php

/**
 * Upgrade rehearsal check (visual builder plan A1.6): after the fixture was restored into the
 * test database and this checkout's migrations ran, boot this checkout against it and prove the
 * fixture still serves: every route in the manifest renders 200 and carries its title.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
$_ENV['DB_POOLING_ENABLED'] = 'false';
$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    fwrite(STDERR, "Refusing to run the rehearsal outside APP_ENV=testing.\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($root . '/tests/Fixtures/upgrade/beta28.manifest.json'), true);
$app = Framework::create($root)->withConfigDir($root . '/config')->withEnvironment('testing')->boot();

$failures = 0;
// Public paths are type-prefixed unless the type is root-mounted; the fixture's type is `page`.
foreach ($manifest['routes'] as $slug) {
    $path = '/' . ($manifest['type'] ?? 'page') . '/' . $slug;
    $response = $app->handle(Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']));
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    $ok = $status === 200 && str_contains($body, 'Published');
    fwrite(STDOUT, sprintf("%s %s -> %d%s\n", $ok ? 'ok  ' : 'FAIL', $path, $status, $ok ? '' : ' (title missing)'));
    if (!$ok) {
        $failures++;
    }
}
exit($failures === 0 ? 0 : 1);
