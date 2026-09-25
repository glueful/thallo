<?php

/**
 * Shared by scripts/build-theme-screenshot and scripts/build-pattern-thumbnails: boot the TEST
 * application with the starter block types' style declarations in place, and wrap rendered blocks
 * in a page carrying a theme's real stylesheets and typeface. Both scripts capture that page in
 * Chromium, so what they produce is the theme, not a mock-up of it.
 *
 * Declares functions only: the script that includes it loads the autoloader first.
 */

declare(strict_types=1);

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Tests\Support\TestApplication;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeStylesheetArtifact;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/** The test application's container, refusing anything but a test database. */
function showcase_boot(string $root): \Psr\Container\ContainerInterface
{
    foreach (getenv() as $key => $value) {
        $_ENV[$key] ??= $value;
    }
    $_ENV['DB_POOLING_ENABLED'] = 'false';
    if (is_file($root . '/.env')) {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }
    $env = static fn (string $key, ?string $default = null): ?string
        => $_ENV[$key] ?? (getenv($key) === false ? $default : getenv($key));
    $database = (string) $env('DB_PGSQL_DATABASE', '');
    $isTestDatabase = $database === 'app_test' || str_ends_with($database, '_test');
    if ($env('APP_ENV', 'development') !== 'testing' || !$isTestDatabase) {
        fwrite(STDERR, "Refusing to build outside APP_ENV=testing on a test database (it syncs block types).\n");
        exit(1);
    }

    $container = TestApplication::instance()->getContainer();
    $container->get(StarterBlockTypeSeeder::class)->seedMissing();
    $blockTypes = $container->get(BlockTypeRepository::class);
    foreach ($container->get(BlockTypeKind::class)->definitions() as $definition) {
        $payload = $definition->payload;
        $row = $blockTypes->findBySlug((string) $payload['slug']);
        if ($row !== null) {
            $blockTypes->updateStyle(
                (string) $row['uuid'],
                $payload['style_capabilities'] ?? null,
                $payload['style_targets'] ?? null,
                $payload['flags'] ?? null,
                $row['starter_content'] ?? $payload['starter_content'] ?? null,
            );
        }
    }
    $container->get(BlockStyleRegistry::class)->reset();
    return $container;
}

/**
 * A renderer for one theme: blocks in, a complete page out.
 *
 * @return callable(list<array<string,mixed>>, string): string
 */
function showcase_renderer(\Psr\Container\ContainerInterface $container, string $root, string $themeName): callable
{
    $theme = new ThemeLocator($themeName, $root . '/themes');
    if ($theme->activePaths()['name'] !== $themeName) {
        fwrite(STDERR, "No loadable theme '{$themeName}'.\n");
        exit(1);
    }
    $vocabulary = $theme->vocabulary();
    $themeDir = $theme->themeDir();
    $sheets = array_map(static fn (string $rel): string => $themeDir . '/' . $rel, $vocabulary->stylesheets());
    $css = implode("\n", [
        (string) file_get_contents($root . '/packages/thallo-render/assets/style/layers.css'),
        ThemeStylesheetArtifact::build($sheets)->css,
        StyleCompiler::compile($vocabulary),
    ]);
    // The theme's own faces, when it ships them where the default does: a picture in a fallback
    // face is not the theme.
    $faces = '';
    foreach (glob($themeDir . '/assets/fonts/*-roman-*.woff2') ?: [] as $font) {
        $family = ucfirst((string) strstr(basename($font), '-', true));
        $faces .= "@font-face { font-family: \"{$family}\"; src: url(\"file://{$font}\") format(\"woff2\"); "
            . "font-weight: 100 900; font-display: block; }\n";
    }
    $extension = $container->get(RenderContextExtension::class);
    $twig = (new TwigFactory($theme, $extension, $root . '/storage/cache/twig'))->environment();

    return static function (array $blocks, string $title) use ($extension, $twig, $faces, $css): string {
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $body = $twig->createTemplate('{{ blocks(l) }}')->render(['l' => $blocks]);
        return "<!doctype html>\n<meta charset=\"utf-8\">\n<title>{$title}</title>\n<style>\n{$faces}\n{$css}\n"
            . "body { margin: 0; }\n</style>\n<main>{$body}</main>\n";
    };
}

/** Ids for a block tree that has none (the editor mints them; a script has to as well). */
function showcase_with_ids(array $blocks, int &$n = 0): array
{
    return array_map(static function (array $block) use (&$n): array {
        $block['id'] = 'sc' . str_pad((string) ++$n, 10, '0', STR_PAD_LEFT);
        foreach ($block['data'] as $key => $value) {
            if (is_array($value) && $value !== [] && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                $block['data'][$key] = showcase_with_ids($value, $n);
            }
        }
        return $block;
    }, $blocks);
}
