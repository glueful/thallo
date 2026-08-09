<?php

/**
 * Standalone subprocess for `WorkspaceBillingSelfServeTest`'s pgsql-gated concurrent plan-A/
 * plan-B race (Task 16, workspace self-serve checkout plan, spec §5.2/§7): runs ONE real
 * `SelfBillingController::checkout()` call end to end in a genuinely separate OS process (and
 * therefore a genuinely separate database connection/session), so
 * `CheckoutSubjectGuardRepository::lockAndClaim()`'s row-level claim really contends with a
 * SIBLING child racing for the SAME subject under a DIFFERENT plan/idempotency key. Boots the
 * REAL Thallo application (mirrors `checkout_attempt_race_child.php`'s identical convention).
 *
 * The parent test process seeds the tenant, the self-serve switch, the actor account, and both
 * purchasable plans into the shared `app_test` database BEFORE launching any child; each child
 * independently re-points its OWN `GatewayManager` at a fresh `RecordingSubscriptionCheckoutGateway`
 * (no cross-process state needed -- only a fixed successful payload).
 *
 * argv: 1=args JSON {tenant, actor, gateway, planKey, idempotencyKey}
 * stdout: JSON {ok:bool, status:?int, body:?array, exceptionClass:?string, message:?string}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Tests\Support\RecordingSubscriptionCheckoutGateway;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Http\SelfBillingController;

// Mirrors checkout_attempt_race_child.php's env preamble: CI supplies DB_* via job env, which
// PHP's variables_order may leave absent from $_ENV, silently defaulting config and missing the
// test database entirely.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

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

$out = ['ok' => false, 'status' => null, 'body' => null, 'exceptionClass' => null, 'message' => null];

try {
    $gatewayName = (string) $args['gateway'];
    $double = new RecordingSubscriptionCheckoutGateway();
    /** @var GatewayManager $gateways */
    $gateways = $container->get(GatewayManager::class);
    $gateways->registerDriver($gatewayName, RecordingSubscriptionCheckoutGateway::class);
    // registerDriver() stores the CLASS NAME as a container id; bind the double under its own
    // class id so GatewayManager::gateway()'s $container->get($class) resolves this instance.
    if (method_exists($container, 'load')) {
        $container->load([RecordingSubscriptionCheckoutGateway::class => $double]);
    }

    $request = Request::create(
        '/v1/admin/billing/checkout',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        (string) json_encode(['plan_key' => $args['planKey']]),
    );
    $request->attributes->set('auth.user', new UserIdentity(uuid: (string) $args['actor']));
    $request->headers->set('Idempotency-Key', (string) $args['idempotencyKey']);

    /** @var SelfBillingController $controller */
    $controller = $container->get(SelfBillingController::class);
    $response = $controller->checkout($request);

    $out['ok'] = true;
    $out['status'] = $response->getStatusCode();
    $out['body'] = json_decode((string) $response->getContent(), true);
} catch (\Throwable $e) {
    $out['exceptionClass'] = get_class($e);
    $out['message'] = $e->getMessage();
}

echo json_encode($out);
