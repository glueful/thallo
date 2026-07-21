<?php

/**
 * Standalone subprocess for `ShopCheckoutRaceTest`'s real-PostgreSQL race lanes (Commerce-Slice-2
 * Task 10): runs ONE real {@see CheckoutService::placeOrder()} call end to end in a genuinely
 * separate OS process — and therefore a genuinely separate database connection/session — so
 * {@see \Thallo\Commerce\Shop\PackCheckoutAttemptAuthority}'s advisory-lock claim really contends
 * with the parent test process's own held lock (or, for the two-real-subprocess race, with a
 * SIBLING child's held lock). Boots the REAL Thallo application (mirrors
 * `product_slug_race_child.php`'s identical convention) so `CheckoutService` resolves its real
 * bound `CheckoutAttemptAuthority` ({@see \Thallo\Commerce\Shop\PackCheckoutAttemptAuthority})
 * exactly as production would. Schema is already migrated by `composer test:migrate` before the
 * parent test process runs; this child performs no schema work of its own and does NOT touch
 * `thallo_system_flags` itself (mode (b) tenant resolution): the PARENT test process writes those
 * flags into the shared `app_test` database before launching any child, so every process resolves
 * the identical tenant from the same live row. Each child seeds its OWN product/variant/cart (a
 * distinct cart per racer) — only the idempotency key + fingerprint are ever shared between
 * racers, exactly as two real duplicate browser submissions would.
 *
 * argv: 1=args JSON
 * args: {tenant, sku, price, email, country, idempotencyKey, fingerprint}
 * stdout: JSON {ok:bool, exceptionClass:?string, message:?string, orderUuid:?string,
 *               orderRef:?string, guestToken:?string}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Framework;

[, $argsJson] = $argv;
/** @var array<string,mixed> $args */
$args = json_decode((string) $argsJson, true, 512, JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);
$app = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();
/** @var ApplicationContext $context */
$context = $app->getContext();
$container = $context->getContainer();

$out = [];
try {
    $sku = (string) $args['sku'];
    $product = $container->get(CatalogService::class)->createProduct($context, [
        'slug' => strtolower($sku),
        'name' => $sku,
        'type' => 'digital',
        'status' => 'active',
        'variants' => [[
            'sku' => $sku,
            'option_values' => [],
            'price' => (int) $args['price'],
            'currency' => 'USD',
        ]],
    ]);
    $variantUuid = (string) $product['variants'][0]['uuid'];

    $carts = $container->get(CartService::class);
    ['cart' => $cart, 'token' => $token] = $carts->create($context);
    $carts->putLine($context, $cart, $variantUuid, 1);

    $checkout = $container->get(CheckoutService::class);
    $result = $checkout->placeOrder(
        $context,
        $token,
        ['email' => (string) $args['email'], 'user_uuid' => null],
        ['shipping' => ['country' => (string) $args['country']], 'billing' => ['country' => (string) $args['country']]],
        null,
        new CheckoutAttemptContext((string) $args['idempotencyKey'], (string) $args['fingerprint']),
    );

    $out = [
        'ok' => true,
        'exceptionClass' => null,
        'message' => null,
        'orderUuid' => (string) $result['order']['uuid'],
        'orderRef' => (string) $result['order']['order_number'],
        'guestToken' => $result['guest_token'],
    ];
} catch (\Throwable $e) {
    $out = [
        'ok' => false,
        'exceptionClass' => $e::class,
        'message' => $e->getMessage(),
        'orderUuid' => null,
        'orderRef' => null,
        'guestToken' => null,
    ];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
