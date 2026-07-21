<?php

/**
 * Standalone subprocess for `SlugLifecycleRaceTest`'s real-PostgreSQL race lanes
 * (Commerce-Slice-2 Task 8): runs ONE real {@see CatalogService::createProduct()} or
 * {@see CatalogService::updateProduct()} call end to end in a genuinely separate OS process —
 * and therefore a genuinely separate database connection/session — so
 * {@see \Thallo\Commerce\Shop\PackSlugLifecycleAuthority}'s advisory-lock claim really contends
 * with the parent test process's own held lock. Boots the REAL Thallo application (mirrors
 * `product_link_race_child.php`'s identical convention) rather than a hand-built minimal
 * container, so `CatalogService` resolves its real dependencies AND the real
 * `SlugLifecycleAuthority` binding exactly as it would in production. Schema is already
 * migrated by `composer test:migrate` before the parent test process runs; this child performs
 * no schema work of its own and does NOT touch `thallo_system_flags` itself (mode (b) tenant
 * resolution): the PARENT test process writes those flags into the shared `app_test` database
 * before launching any child, so every process resolves the identical tenant from the same
 * live row.
 *
 * argv: 1=action, 2=args JSON
 * actions: create | rename
 * stdout: JSON {ok:bool, exceptionClass:?string, message:?string, productUuid:?string}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Framework;

[, $action, $argsJson] = $argv;
/** @var array<string,mixed> $args */
$args = json_decode((string) $argsJson, true, 512, JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);
$app = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();
/** @var ApplicationContext $context */
$context = $app->getContext();

$catalog = $context->getContainer()->get(CatalogService::class);

$out = [];
try {
    switch ($action) {
        case 'create':
            $slug = (string) $args['slug'];
            $product = $catalog->createProduct($context, [
                'slug' => $slug,
                'name' => $slug,
                'type' => 'physical',
                'status' => 'active',
                'variants' => [[
                    'sku' => 'SKU-' . strtoupper(str_replace('-', '_', $slug)),
                    'option_values' => [],
                    'price' => 1000,
                    'currency' => 'USD',
                ]],
            ]);
            $out = [
                'ok' => true, 'exceptionClass' => null, 'message' => null,
                'productUuid' => (string) $product['uuid'],
            ];
            break;

        case 'rename':
            $catalog->updateProduct($context, (string) $args['productUuid'], [
                'slug' => (string) $args['slug'],
            ]);
            $out = [
                'ok' => true, 'exceptionClass' => null, 'message' => null,
                'productUuid' => (string) $args['productUuid'],
            ];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage(), 'productUuid' => null];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
