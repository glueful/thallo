<?php

/**
 * Captures the rendering references the block typeface release is compared against (plan Task 4,
 * Step 0), BEFORE any of its rendering changes: run once, on the code as it was, and commit the output.
 * Later tasks read tests/fixtures/render/pre-typeface/ and never re-capture it.
 *
 *     CACHE_DRIVER=array php scripts/capture-pre-typeface.php
 *
 * Uses the test database (app_test) and a throwaway fixture theme it removes afterwards.
 */

declare(strict_types=1);

use Glueful\Application;
use Glueful\Database\Connection;
use Glueful\Framework;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\Fonts\BlobRouteMediaUrls;
use Thallo\Core\Tests\Support\Fonts\FixedThemeAppearance;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
$_ENV['DB_PGSQL_DATABASE'] = 'app_test';
$_ENV['DB_POOLING_ENABLED'] = 'false';

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$out = $root . '/tests/fixtures/render/pre-typeface';
@mkdir($out, 0755, true);

$app = Framework::create($root)->withConfigDir($root . '/config')->withEnvironment('testing')->boot();
$context = $app->getContext();
$container = $context->getContainer();
$db = $container->get(Connection::class);
$settings = $container->get(SettingsStore::class);

// Two public font files the media library serves, named by uuid in the settings as Custom does today.
$db->table('blobs')->whereIn('uuid', ['fontbody0001', 'fonthead0001'])->forceDelete();
foreach (['fontbody0001', 'fonthead0001'] as $uuid) {
    $db->table('blobs')->insert([
        'uuid' => $uuid, 'name' => $uuid . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
        'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
        'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$heads = [
    'sans' => ['theme_font' => 'sans', 'theme_font_body' => '', 'theme_font_display' => ''],
    'serif' => ['theme_font' => 'serif', 'theme_font_body' => '', 'theme_font_display' => ''],
    'custom' => [
        'theme_font' => 'custom',
        'theme_font_body' => 'fontbody0001',
        'theme_font_display' => 'fonthead0001',
    ],
];
// The home page's <head>, as served: one process per configuration (the appearance is read once
// per request, and a process is one request here).
if (($argv[1] ?? '') === '--head') {
    $settings->putMany($heads[$argv[2]]);
    $html = (string) (new Application($context))->handle(Request::create('/', 'GET'))->getContent();
    if (preg_match('#<head>.*</head>#s', $html, $m) !== 1) {
        throw new RuntimeException('no <head> in the home page');
    }
    echo $m[0], "\n";
    exit(0);
}
foreach (array_keys($heads) as $name) {
    $head = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --head ' . $name);
    if (!is_string($head) || !str_starts_with($head, '<head>')) {
        throw new RuntimeException("no <head> for {$name}");
    }
    file_put_contents("{$out}/head-{$name}.html", $head);
}
foreach (array_keys($heads['sans']) as $key) {
    $settings->forget($key);
}
$db->table('blobs')->whereIn('uuid', ['fontbody0001', 'fonthead0001'])->forceDelete();

// A custom theme without face metadata: unset Heading and Rich text blocks.
$container->get(StarterBlockTypeSeeder::class)->seedMissing();
$themes = $root . '/themes';
$theme = $themes . '/pretypeface';
ThemeFixture::write($theme, 'pretypeface');
try {
    $extension = $container->get(RenderContextExtension::class);
    $locator = new ThemeLocator('pretypeface', $themes);
    $env = (new TwigFactory($locator, $extension, sys_get_temp_dir() . '/pretypeface-twig'))->environment();
    $extension->resetPerRenderState();
    $extension->setAnnotationScope('none');
    $extension->setLocale('en');
    $html = $extension->blocks($env, ['entry' => null, 'site' => ['locale' => 'en', 'locales' => ['en']]], [
        ['id' => 'preheading01', 'type' => 'heading', 'settings' => [],
            'data' => ['text' => 'Unset heading', 'level' => 'h2']],
        ['id' => 'prerichtext1', 'type' => 'rich_text', 'settings' => [],
            'data' => ['body' => '<p>Unset <em>rich</em> text.</p>']],
    ]);
    file_put_contents("{$out}/custom-theme-blocks.html", $html . "\n");
} finally {
    array_map('unlink', array_filter(glob($theme . '/{,*/}*', GLOB_BRACE) ?: [], 'is_file'));
    @rmdir($theme . '/templates');
    @rmdir($theme . '/assets');
    @rmdir($theme);
}

// The appearance <style> for the Custom configurations Task 7 compares against.
$appearance = static function (string $font, array $faces) use ($container, $root): string {
    $ext = new RenderContextExtension(
        null,
        $container->get(EntryTargetResolver::class),
        'en',
        mediaUrls: new BlobRouteMediaUrls(),
        appearance: new ThemeAppearanceSource(new FixedThemeAppearance($font, $faces), new NullLogger()),
    );
    $ext->bindTheme(new ThemeLocator('default', $root . '/themes'));
    $ext->setAssetContext(null, $root . '/packages/thallo-render/themes/default/assets');
    return (string) $ext->themeColorsStyle() . "\n";
};
$configs = [
    'text-only' => ['custom', ['body' => 'fontbody0001']],
    'headings-only' => ['custom', ['display' => 'fonthead0001']],
    'both' => ['custom', ['body' => 'fontbody0001', 'display' => 'fonthead0001']],
    'shared' => ['custom', ['body' => 'fontshared01', 'display' => 'fontshared01']],
    'unselected' => ['sans', ['body' => 'fontbody0001', 'display' => 'fonthead0001']],
];
foreach ($configs as $name => [$font, $faces]) {
    file_put_contents("{$out}/appearance-{$name}.html", $appearance($font, $faces));
}

echo "captured into {$out}\n";
