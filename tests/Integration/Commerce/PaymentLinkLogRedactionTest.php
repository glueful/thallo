<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Routing\Middleware\RequestResponseLoggingMiddleware;
use Glueful\Support\SensitiveParamRedactor;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FRAMEWORK-LEVEL redaction of the payment-link bearer token, proven IN THIS APP.
 *
 * `glueful/framework` 1.78.0 added {@see SensitiveParamRedactor::configureSensitivePaths()} and
 * wires it from `logging.sensitive_paths` at the end of boot. Only the app knows which of its
 * routes carry a credential in the PATH, so the framework default is empty and this app registers
 * `/checkout/pay/{token}` in `config/logging.php`.
 *
 * The pack README's reverse-proxy `log_format` recipe is still the answer for the web server's
 * OWN access log — which the framework cannot reach — but everything the framework writes
 * (request/response logging, exception reports, activity logs, the CSRF/auth/security middleware,
 * tracing spans, persisted API metrics) now redacts through this one facility. This suite proves
 * the registration took effect on a real boot and that a real framework log record comes out
 * redacted, rather than trusting the vendored unit tests.
 */
final class PaymentLinkLogRedactionTest extends AppTestCase
{
    /** Distinctive enough that a partial redaction cannot hide behind a coincidence. */
    private const SENTINEL = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    public function testTheAppRegistersThePaymentLinkTemplateWithTheFrameworkRedactor(): void
    {
        self::assertContains(
            '/checkout/pay/{token}',
            SensitiveParamRedactor::sensitivePathPatterns(),
            'config/logging.php must register the payment-link path; the framework default is empty',
        );
    }

    /**
     * The pinned pair: the landing GET's path AND the initiate POST's path.
     *
     * ONE template covers both because the matcher anchors at the start of the path and PRESERVES
     * trailing segments beyond the template (`redactSegments()` skips a pattern only when it is
     * LONGER than the path, and rebuilds the untouched tail) — so `/checkout/pay/{token}` redacts
     * segment 2 of `/checkout/pay/<secret>/initiate` and leaves `initiate` legible. That is
     * behaviour we depend on, so it is pinned here rather than assumed.
     *
     * @dataProvider redactedPaths
     */
    public function testAFrameworkRequestLogRecordCarriesNoPaymentToken(string $path, string $method): void
    {
        $records = $this->captureRequestLog(Request::create($path, $method));

        self::assertNotSame([], $records, 'the middleware must have emitted a request record');

        $encoded = json_encode($records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SENTINEL, $encoded, 'the token reached a log record');

        $request = $this->firstOfType($records, 'http_request');
        self::assertStringStartsWith(
            '/checkout/pay/' . SensitiveParamRedactor::REDACTED,
            (string) ($request['path'] ?? ''),
        );
        self::assertStringContainsString(SensitiveParamRedactor::REDACTED, (string) ($request['uri'] ?? ''));
        self::assertStringContainsString('/checkout/pay/', (string) ($request['uri'] ?? ''));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function redactedPaths(): array
    {
        return [
            'the landing GET' => ['/checkout/pay/' . self::SENTINEL, 'GET'],
            'the initiate POST' => ['/checkout/pay/' . self::SENTINEL . '/initiate', 'POST'],
        ];
    }

    public function testTheInitiatePostKeepsItsTrailingSegmentLegibleWhileRedactingTheToken(): void
    {
        $records = $this->captureRequestLog(
            Request::create('/checkout/pay/' . self::SENTINEL . '/initiate', 'POST')
        );

        $path = (string) ($this->firstOfType($records, 'http_request')['path'] ?? '');

        self::assertSame('/checkout/pay/' . SensitiveParamRedactor::REDACTED . '/initiate', $path);
    }

    /**
     * Literal segments are matched case-insensitively and after percent-decoding, so the
     * encoded/mixed-case forms that still reach a live route cannot slip past the matcher.
     *
     * @dataProvider hostileEncodings
     */
    public function testEncodedAndMixedCasePathFormsAreStillRedacted(string $path): void
    {
        $records = $this->captureRequestLog(Request::create($path));

        self::assertStringNotContainsString(
            self::SENTINEL,
            json_encode($records, JSON_THROW_ON_ERROR),
            $path,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function hostileEncodings(): array
    {
        return [
            // NOTE: a doubled leading slash (`//checkout/pay/<token>`) is NOT exercised here.
            // `Request::create()` reads such a value as a protocol-relative URL and parses
            // `//checkout` as the HOST, so the request never carries that path at all — the
            // framework's own unit tests cover the router-normalized form.
            'an encoded separator' => ['/checkout%2Fpay/' . self::SENTINEL],
            'a mixed-case literal' => ['/Checkout/Pay/' . self::SENTINEL],
        ];
    }

    public function testAnUnrelatedPathIsLoggedByteIdentically(): void
    {
        // Registering a template must not start redacting the rest of the app.
        $records = $this->captureRequestLog(Request::create('/shop/products/some-slug'));

        self::assertSame(
            '/shop/products/some-slug',
            $this->firstOfType($records, 'http_request')['path'] ?? null,
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * Drive the framework's OWN request-logging middleware over a captured logger — the real
     * emission path, not a re-implementation of it. The redactor is process-global state
     * configured at boot, so this exercises exactly what a live request would.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function captureRequestLog(Request $request): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $middleware = new RequestResponseLoggingMiddleware(
            logMode: 'request',
            logHeaders: false,
            logBodies: false,
            logger: $logger,
            container: $this->container(),
            context: $this->appContext(),
        );

        $middleware->handle($request, static fn (): Response => new Response('', 200));

        return $logger->records;
    }

    /**
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     * @return array<string, mixed>
     */
    private function firstOfType(array $records, string $type): array
    {
        foreach ($records as $record) {
            if (($record['context']['type'] ?? null) === $type) {
                return $record['context'];
            }
        }

        self::fail("no {$type} log record was emitted");
    }
}
